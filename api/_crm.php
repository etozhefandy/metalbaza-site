<?php
// Передача заявок в CRM заказчика: Kommo, amoCRM, Bitrix24 или любой вебхук.
//
// Настройки лежат в hub-data/crm.json в папке подписки (вне папок сайтов — наружу не открываются) и
// правятся в пульте admin.alfimov.kz. Сайт, принявший заявку, читает их отсюда же. Ключи в страницах
// пульта не показываются — только последние 4 символа.
//
// Сбой CRM никогда не теряет заявку: в Telegram она уже ушла, а здесь ошибка пишется в журнал hub-data/crm-log.json.
declare(strict_types=1);
require_once __DIR__ . '/_net.php';

const CRM_TYPES = ['kommo' => 'Kommo', 'amocrm' => 'amoCRM', 'bitrix24' => 'Bitrix24', 'webhook' => 'Вебхук (любая CRM)'];

/** Папка данных пульта: из пульта — его, с сайта — hub-data рядом с tg-config.php. */
function crm_dir(): string
{
    if ($d = getenv('CRM_DATA')) return rtrim($d, '/');
    return function_exists('pult_data') ? pult_data() : tg_hub_dir() . '/hub-data';
}

function crm_read(string $name): array
{
    $f = crm_dir() . "/$name.json";
    $v = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($v) ? $v : [];
}

function crm_write(string $name, array $v): void
{
    $dir = crm_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $f = "$dir/$name.json";
    $tmp = "$f.tmp-" . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($tmp, 0600);
    rename($tmp, $f);
}

function crm_config(string $client): array { return (array) (crm_read('crm')[$client] ?? []); }

function crm_save(string $client, array $cfg): void
{
    $all = crm_read('crm');
    $all[$client] = $cfg;
    crm_write('crm', $all);
}

function crm_mask(string $s): string { return $s === '' ? '' : '••••' . mb_substr($s, -4); }

/** Секрет подключения: у Kommo/amoCRM — токен, у Bitrix24 и вебхука — адрес. */
function crm_secret_key(string $type): string { return in_array($type, ['kommo', 'amocrm'], true) ? 'token' : 'url'; }

// ---------------------------------------------------------------- проверка адресов и HTTP

/** Адрес аккаунта Kommo/amoCRM → хост (guestory.kommo.com) или null, если это не их домен. */
function crm_amo_host(string $type, string $v): ?string
{
    $h = strtolower(trim(preg_replace('#^https?://#i', '', trim($v)), " /"));
    $h = preg_replace('#[/?].*$#', '', $h);
    if ($h !== '' && !str_contains($h, '.')) $h .= $type === 'kommo' ? '.kommo.com' : '.amocrm.ru';   // ввели только «guestory»
    return preg_match('/^[a-z0-9-]+\.(kommo\.com|amocrm\.ru|amocrm\.com)$/', $h) ? $h : null;
}

/** Адрес вебхука: только https и обычное имя сайта (не IP и не localhost). */
function crm_url_ok(string $u): bool
{
    $p = parse_url($u);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'])) return false;
    if (!str_contains($p['host'], '.') || filter_var($p['host'], FILTER_VALIDATE_IP) || preg_match('/\.(local|internal|localhost)$/', $p['host'])) return false;
    return isset($GLOBALS['CRM_HTTP']) || net_target($u) !== null;   // имя ведёт только на публичные адреса
}

/** → [код ответа (0 — нет связи), разобранный JSON|null, текст ошибки]. В тестах подменяется $GLOBALS['CRM_HTTP']. */
function crm_http(string $method, string $url, array $headers = [], ?array $json = null): array
{
    if (isset($GLOBALS['CRM_HTTP'])) return $GLOBALS['CRM_HTTP']($method, $url, $headers, $json);
    $t = net_target($url);   // только https на публичный адрес, соединение — с проверенным IP
    if (!$t) return [0, null, 'адрес ведёт не в интернет (внутренняя сеть или не https)'];
    $ch = curl_init($url);
    net_pin($ch, $t);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'alfimov-pult-crm',
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    $data = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    return [$code, is_array($data) ? $data : null, $code ? '' : ($err ?: 'нет связи')];
}

function crm_http_error(int $code, ?array $body, string $err): string
{
    if ($code === 0) return "нет связи с CRM ($err)";
    if ($code === 401) return 'CRM не приняла ключ (401) — проверьте токен или адрес вебхука';
    if ($code === 403) return 'CRM запретила доступ (403) — у ключа нет прав на сделки/контакты';
    if ($code === 404) return 'адрес не найден (404) — проверьте домен аккаунта или адрес вебхука';
    $msg = $body['detail'] ?? $body['title'] ?? $body['error_description'] ?? $body['error'] ?? '';
    return "CRM ответила ошибкой $code" . ($msg ? ': ' . mb_substr(is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE), 0, 200) : '');
}

// ---------------------------------------------------------------- текст заявки

/** Название вопроса без значка в начале («📦 Нужно» → «Нужно») — для CRM. */
function crm_label(string $l): string { return trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $l)) ?: $l; }

/** Заявка словами — для примечания в CRM. */
function crm_note(array $lead): string
{
    $l = ["Заявка с рекламной страницы: {$lead['page_title']}"];
    foreach ($lead['answers'] as [$label, $value]) $l[] = crm_label($label) . ": $value";
    foreach (['city' => 'Город', 'comment' => 'Комментарий'] as $k => $label) if (($lead[$k] ?? '') !== '') $l[] = "$label: {$lead[$k]}";
    foreach ($lead['utm'] as $k => $v) $l[] = "$k: $v";
    return implode("\n", $l);
}

// ---------------------------------------------------------------- Kommo / amoCRM

function crm_amo(array $c, string $method, string $path, ?array $json = null): array
{
    return crm_http($method, "https://{$c['host']}/api/v4$path", ['Authorization: Bearer ' . $c['token']], $json);
}

/** Воронки с этапами, сотрудники и поля сделки (для меток UTM). */
function crm_amo_lists(array $c): array
{
    [$code, $b, $err] = crm_amo($c, 'GET', '/leads/pipelines');
    if ($code !== 200) return ['error' => crm_http_error($code, $b, $err)];
    $pipes = [];
    foreach ((array) ($b['_embedded']['pipelines'] ?? []) as $p) {
        $st = [];
        foreach ((array) ($p['_embedded']['statuses'] ?? []) as $s) if (!in_array((int) $s['id'], [142, 143], true)) $st[] = [(int) $s['id'], (string) $s['name']];   // 142/143 — «успешно/не реализовано»
        $pipes[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'statuses' => $st];
    }
    $users = [];
    [$code, $b] = crm_amo($c, 'GET', '/users?limit=250');
    if ($code === 200) foreach ((array) ($b['_embedded']['users'] ?? []) as $u) $users[] = [(int) $u['id'], (string) $u['name']];
    return ['pipelines' => $pipes, 'users' => $users];
}

/** Поля сделки, куда amoCRM/Kommo кладёт метки (utm_source…): ключ метки → id поля. */
function crm_amo_utm_fields(array $c): array
{
    [$code, $b] = crm_amo($c, 'GET', '/leads/custom_fields?limit=250');
    if ($code !== 200) return [];
    $want = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'];
    $map = [];
    foreach ((array) ($b['_embedded']['custom_fields'] ?? []) as $f) {
        foreach ([strtolower((string) ($f['code'] ?? '')), strtolower(trim((string) ($f['name'] ?? '')))] as $k) {
            if (in_array($k, $want, true) && !isset($map[$k])) $map[$k] = (int) $f['id'];
        }
    }
    return $map;
}

function crm_amo_push(array $c, array $lead, bool $test = false): array
{
    $deal = ['name' => ($test ? 'ТЕСТ (можно удалить): ' : 'Заявка: ') . $lead['page_title'] . ' — ' . $lead['name'],
        '_embedded' => [
            'contacts' => [['first_name' => $lead['name'], 'custom_fields_values' => [
                ['field_code' => 'PHONE', 'values' => [['value' => '+' . $lead['phone'], 'enum_code' => 'WORK']]]]]],
            'tags' => [['name' => 'Meta Ads'], ['name' => $lead['client_name']]],
        ]];
    foreach (['pipeline_id', 'status_id'] as $k) if (!empty($c[$k])) $deal[$k] = (int) $c[$k];
    if (!empty($c['user_id'])) $deal['responsible_user_id'] = (int) $c['user_id'];
    $fields = [];
    $ids = $lead['utm'] ? crm_amo_utm_fields($c) : [];
    foreach ($lead['utm'] as $k => $v) if (isset($ids[$k])) $fields[] = ['field_id' => $ids[$k], 'values' => [['value' => $v]]];
    if ($fields) $deal['custom_fields_values'] = $fields;

    [$code, $b, $err] = crm_amo($c, 'POST', '/leads/complex', [$deal]);
    if (!in_array($code, [200, 201], true) || empty($b[0]['id'])) return ['ok' => false, 'error' => crm_http_error($code, $b, $err)];
    $id = (int) $b[0]['id'];
    crm_amo($c, 'POST', "/leads/$id/notes", [['note_type' => 'common', 'params' => ['text' => crm_note($lead)]]]);   // полный текст заявки — в ленту сделки
    return ['ok' => true, 'id' => (string) $id];
}

// ---------------------------------------------------------------- Bitrix24

function crm_bitrix(array $c, string $method, ?array $json = null): array
{
    return crm_http('POST', rtrim($c['url'], '/') . "/$method.json", [], $json ?? []);
}

function crm_bitrix_lists(array $c): array
{
    [$code, $b, $err] = crm_bitrix($c, 'crm.status.list', ['filter' => ['ENTITY_ID' => 'STATUS']]);
    if ($code !== 200 || isset($b['error'])) return ['error' => crm_http_error($code, $b, $err)];
    $st = [];
    foreach ((array) ($b['result'] ?? []) as $s) $st[] = [(string) $s['STATUS_ID'], (string) $s['NAME']];
    $users = [];
    [$code, $b] = crm_bitrix($c, 'user.get', ['filter' => ['ACTIVE' => true]]);   // нужно право user у вебхука; нет — выбор сотрудника просто не покажем
    if ($code === 200) foreach ((array) ($b['result'] ?? []) as $u) $users[] = [(int) $u['ID'], trim($u['NAME'] . ' ' . ($u['LAST_NAME'] ?? ''))];
    return ['statuses' => $st, 'users' => $users];
}

function crm_bitrix_push(array $c, array $lead, bool $test = false): array
{
    $f = ['TITLE' => ($test ? 'ТЕСТ (можно удалить): ' : 'Заявка: ') . $lead['page_title'] . ' — ' . $lead['name'],
        'NAME' => $lead['name'], 'PHONE' => [['VALUE' => '+' . $lead['phone'], 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID' => 'WEB', 'SOURCE_DESCRIPTION' => 'Meta Ads · ' . $lead['client_name'], 'COMMENTS' => nl2br(crm_note($lead), false)];
    foreach ($lead['utm'] as $k => $v) if ($k !== 'fbclid') $f[strtoupper($k)] = $v;   // UTM_SOURCE… — штатные поля лида Bitrix24
    if (!empty($c['status_id'])) $f['STATUS_ID'] = (string) $c['status_id'];
    if (!empty($c['user_id'])) $f['ASSIGNED_BY_ID'] = (int) $c['user_id'];
    [$code, $b, $err] = crm_bitrix($c, 'crm.lead.add', ['fields' => $f, 'params' => ['REGISTER_SONET_EVENT' => 'Y']]);
    if ($code !== 200 || isset($b['error']) || empty($b['result'])) return ['ok' => false, 'error' => crm_http_error($code, $b, $err)];
    return ['ok' => true, 'id' => (string) $b['result']];
}

// ---------------------------------------------------------------- вебхук

function crm_webhook_push(array $c, array $lead, bool $test = false): array
{
    $answers = [];
    foreach ($lead['answers'] as [$label, $value]) $answers[crm_label($label)] = $value;
    [$code, $b, $err] = crm_http('POST', $c['url'], [], ['test' => $test, 'client' => $lead['client'], 'page' => $lead['page'],
        'page_title' => $lead['page_title'], 'name' => $lead['name'], 'phone' => '+' . $lead['phone'], 'answers' => $answers,
        'city' => $lead['city'] ?? '', 'comment' => $lead['comment'] ?? '', 'utm' => (object) $lead['utm'], 'text' => crm_note($lead)]);
    if ($code < 200 || $code >= 300) return ['ok' => false, 'error' => crm_http_error($code, $b, $err)];
    return ['ok' => true, 'id' => ''];
}

// ---------------------------------------------------------------- общий вход

/** Подключена ли CRM у заказчика. */
function crm_enabled(array $c): bool
{
    $t = $c['type'] ?? '';
    return isset(CRM_TYPES[$t]) && !empty($c[crm_secret_key($t)]) && ($t === 'bitrix24' || $t === 'webhook' || !empty($c['host']));
}

/** Проверка подключения: что CRM отвечает и ключ подходит. → ['ok', 'error', 'lists']. */
function crm_check(array $c): array
{
    if (!crm_enabled($c)) return ['ok' => false, 'error' => 'не заполнены адрес и ключ'];
    switch ($c['type']) {
        case 'kommo': case 'amocrm': $l = crm_amo_lists($c); break;
        case 'bitrix24': $l = crm_bitrix_lists($c); break;
        default:
            [$code, , $err] = crm_http('HEAD', $c['url']);   // вебхук-адрес на HEAD может отвечать 404/405 — достаточно, что хост жив
            return $code ? ['ok' => true, 'lists' => []] : ['ok' => false, 'error' => "нет связи ($err)"];
    }
    return isset($l['error']) ? ['ok' => false, 'error' => $l['error']] : ['ok' => true, 'lists' => $l];
}

/** Отправить заявку в CRM заказчика. Ничего не бросает. → ['ok', 'error'?, 'id'?, 'skipped'?]. */
function crm_send(string $client, array $lead, bool $test = false): array
{
    $c = crm_config($client);
    if (!crm_enabled($c)) return ['ok' => true, 'skipped' => true];
    try {
        $r = match ($c['type']) {
            'kommo', 'amocrm' => crm_amo_push($c, $lead, $test),
            'bitrix24' => crm_bitrix_push($c, $lead, $test),
            default => crm_webhook_push($c, $lead, $test),
        };
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => 'сбой: ' . $e->getMessage()];
    }
    $log = crm_read('crm-log');
    array_unshift($log, ['t' => time(), 'client' => $client, 'type' => $c['type'], 'page' => $lead['page'], 'test' => $test,
        'ok' => $r['ok'], 'id' => $r['id'] ?? '', 'error' => $r['error'] ?? '']);
    crm_write('crm-log', array_slice($log, 0, 40));
    return $r;
}

/** Условная заявка — проверить, что сделка создаётся. */
function crm_test_lead(string $client, string $clientName): array
{
    return ['client' => $client, 'client_name' => $clientName, 'page' => 'test', 'page_title' => 'Тестовая заявка',
        'name' => 'Тест Пульта', 'phone' => '77000000000', 'answers' => [['Объект', 'Отель / гостиница'], ['Нужно', 'Текстиль']],
        'city' => 'Астана', 'comment' => 'Проверка подключения из пульта', 'utm' => ['utm_source' => 'facebook', 'utm_campaign' => 'test']];
}
