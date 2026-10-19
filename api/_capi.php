<?php
// Заявка → Meta с сервера (Conversions API). Пиксель в браузере теряет часть заявок (блокировщики, iOS, закрыли вкладку),
// сервер шлёт то же событие Lead с тем же event_id — Meta склеит дубли и посчитает заявку один раз.
// Настройки заказчика — hub-data/capi.json (пиксель, токен из Events Manager, тестовый код), правятся в пульте.
// Телефон и имя уходят только в виде SHA-256, как требует Meta.
declare(strict_types=1);
require_once __DIR__ . '/_crm.php';

const CAPI_GRAPH = 'https://graph.facebook.com/v24.0';

function capi_config(string $client): array { return (array) (crm_read('capi')[$client] ?? []); }

function capi_save(string $client, array $cfg): void
{
    $all = crm_read('capi');
    $all[$client] = $cfg;
    crm_write('capi', $all);
}

function capi_enabled(array $c): bool { return preg_match('/^\d{10,20}$/', (string) ($c['pixel'] ?? '')) && strlen((string) ($c['token'] ?? '')) > 20; }

function capi_hash(string $v): string { return hash('sha256', $v); }

/** fbc из cookie пикселя или из fbclid рекламы (формат Meta: fb.1.<время клика мс>.<fbclid>). */
function capi_fbc(array $cookies, string $fbclid, int $now): string
{
    $c = (string) ($cookies['_fbc'] ?? '');
    if (preg_match('/^fb\.\d\.\d+\.[\w-]+$/', $c)) return $c;
    return preg_match('/^[\w-]{10,500}$/', $fbclid) ? 'fb.1.' . ($now * 1000) . '.' . $fbclid : '';
}

/** Событие Lead для Meta. $ctx: ip, ua, url (страница), cookies, event_id. */
function capi_event(array $lead, array $ctx, ?int $now = null): array
{
    $now ??= time();
    $first = mb_strtolower(trim(explode(' ', trim($lead['name']))[0] ?? ''));
    $user = ['ph' => [capi_hash($lead['phone'])], 'country' => [capi_hash('kz')],
        'client_ip_address' => (string) ($ctx['ip'] ?? ''), 'client_user_agent' => mb_substr((string) ($ctx['ua'] ?? ''), 0, 400)];
    if ($first !== '') $user['fn'] = [capi_hash($first)];
    $fbp = (string) ($ctx['cookies']['_fbp'] ?? '');
    if (preg_match('/^fb\.\d\.\d+\.\d+$/', $fbp)) $user['fbp'] = $fbp;
    $fbc = capi_fbc((array) ($ctx['cookies'] ?? []), (string) ($lead['utm']['fbclid'] ?? ''), $now);
    if ($fbc !== '') $user['fbc'] = $fbc;
    $ev = ['event_name' => 'Lead', 'event_time' => $now, 'action_source' => 'website', 'user_data' => $user,
        'custom_data' => ['content_name' => $lead['page']]];
    $url = (string) ($ctx['url'] ?? '');
    if (preg_match('#^https://#', $url)) $ev['event_source_url'] = mb_substr($url, 0, 500);
    $id = (string) ($ctx['event_id'] ?? '');
    if (preg_match('/^[\w-]{6,64}$/', $id)) $ev['event_id'] = $id;   // тот же id, что у пикселя в браузере — дубль склеится
    return $ev;
}

/** Отправить событие. $test — в «Тестовые события» Events Manager (нужен тестовый код), в статистику не идёт. → ['ok', 'error'?, 'skipped'?]. */
function capi_send(string $client, array $event, bool $test = false): array
{
    $c = capi_config($client);
    if (!capi_enabled($c)) return ['ok' => true, 'skipped' => true];
    $body = ['data' => [$event], 'access_token' => $c['token']];
    $code = trim((string) ($c['test_code'] ?? ''));
    if ($test) {
        if ($code === '') return ['ok' => false, 'error' => 'для проверки нужен тестовый код из Events Manager → «Тестовые события»'];
        $body['test_event_code'] = $code;
    }
    try {
        [$http, $b, $err] = crm_http('POST', CAPI_GRAPH . '/' . $c['pixel'] . '/events', [], $body);
        $r = $http === 200 && (int) ($b['events_received'] ?? 0) > 0 ? ['ok' => true]
            : ['ok' => false, 'error' => $http ? 'Meta: ' . mb_substr((string) ($b['error']['error_user_msg'] ?? $b['error']['message'] ?? "ошибка $http"), 0, 200) : "нет связи с Meta ($err)"];
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => 'сбой: ' . $e->getMessage()];
    }
    $log = crm_read('capi-log');
    array_unshift($log, ['t' => time(), 'client' => $client, 'test' => $test, 'ok' => $r['ok'], 'error' => $r['error'] ?? '']);
    crm_write('capi-log', array_slice($log, 0, 40));
    return $r;
}
