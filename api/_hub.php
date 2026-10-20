<?php
// Общие данные пульта admin.alfimov.kz, которые пишут сами сайты: журнал заявок с рекламных страниц заказчиков
// и «пинок» проверки доменов/SSL. Лежат в hub-data/ папки подписки (вне сайтов). Пишут сайты, читает пульт.
declare(strict_types=1);

const HUB_URL = 'https://admin.alfimov.kz';
const HUB_TICK_EVERY = 43200;   // проверка доменов и SSL — раз в 12 часов, даже если пульт никто не открывает

function hub_dir(): string
{
    if ($d = getenv('CRM_DATA')) return rtrim($d, '/');
    return function_exists('pult_data') ? pult_data() : tg_hub_dir() . '/hub-data';
}

// ---------------------------------------------------------------- журнал заявок заказчиков

function hub_leads_dir(string $client): string { return hub_dir() . '/leads/' . preg_replace('/[^a-z0-9-]/', '', $client); }

/** Записать заявку с рекламной страницы (с итогом передачи в Telegram и CRM). → id или null. */
function hub_lead_add(array $lead, bool $tg, array $crm, ?int $now = null): ?string
{
    $now ??= time();
    $dir = hub_leads_dir($lead['client']);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
    $id = date('ymd', $now) . '-' . strtoupper(bin2hex(random_bytes(2)));
    $row = ['id' => $id, 't' => $now, 'page' => $lead['page'], 'page_title' => $lead['page_title'], 'name' => $lead['name'],
        'phone' => $lead['phone'], 'answers' => $lead['answers'], 'city' => $lead['city'] ?? '', 'comment' => $lead['comment'] ?? '',
        'utm' => (object) $lead['utm'], 'tg' => $tg,
        'crm' => empty($crm['skipped']) ? ['ok' => !empty($crm['ok']), 'id' => $crm['id'] ?? '', 'error' => $crm['error'] ?? ''] : null];
    $file = "$dir/" . date('Y-m', $now) . '.jsonl';
    $ok = @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
    return $ok === false ? null : $id;
}

function hub_lead_months(string $client): array
{
    $m = array_map(fn($f) => basename($f, '.jsonl'), glob(hub_leads_dir($client) . '/*.jsonl') ?: []);
    $m = array_values(array_filter($m, fn($x) => (bool) preg_match('/^\d{4}-\d{2}$/', $x)));
    rsort($m);
    return $m;
}

/** Заявки заказчика за месяц, новые первыми. */
function hub_leads(string $client, string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return [];
    $f = hub_leads_dir($client) . "/$month.jsonl";
    $out = [];
    foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $l = json_decode($line, true);
        if (is_array($l)) $out[] = $l;
    }
    return array_reverse($out);
}

/** CSV для Excel: формулы из заявок не выполняются (апостроф перед = + - @), обычный телефон не трогаем. */
function hub_leads_csv(array $leads): string
{
    $cell = function ($v): string {
        $v = (string) $v;
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !preg_match('/^\+?[0-9][0-9 ()-]{6,}$/', $v)) $v = "'" . $v;
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $rows = [['№', 'Дата', 'Страница', 'Имя', 'Телефон', 'Ответы', 'Город', 'Комментарий', 'utm_source', 'utm_campaign', 'utm_content', 'utm_term', 'CRM']];
    foreach ($leads as $l) {
        $u = (array) ($l['utm'] ?? []);
        $rows[] = [$l['id'], date('Y-m-d H:i', (int) $l['t']), $l['page_title'], $l['name'], '+' . $l['phone'],
            implode('; ', array_map(fn($a) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $a[0]) . ': ' . $a[1], (array) $l['answers'])),
            $l['city'], $l['comment'], $u['utm_source'] ?? '', $u['utm_campaign'] ?? '', $u['utm_content'] ?? '', $u['utm_term'] ?? '',
            $l['crm'] === null ? '—' : ($l['crm']['ok'] ? 'передана ' . $l['crm']['id'] : 'ошибка: ' . $l['crm']['error'])];
    }
    $csv = "\xEF\xBB\xBF";
    foreach ($rows as $r) $csv .= implode(';', array_map($cell, $r)) . "\r\n";
    return $csv;
}

// ---------------------------------------------------------------- проверка доменов без открытия пульта

/** Пора ли проверить домены/SSL: сайт с посетителями раз в 12 часов будит пульт (запрос без ожидания ответа). */
function hub_tick_kick(?callable $fire = null, ?int $now = null): bool
{
    $now ??= time();
    $dir = hub_dir();
    $mon = "$dir/monitor.json";
    $stamp = "$dir/.tick-kicked";
    if (is_file($mon) && filemtime($mon) > $now - HUB_TICK_EVERY) return false;
    if (is_file($stamp) && filemtime($stamp) > $now - 600) return false;   // будим не чаще раза в 10 минут
    if (!is_dir($dir) || !@touch($stamp, $now)) return false;
    $key = trim((string) @file_get_contents("$dir/tick.key"));   // ключ создаёт пульт; без него будить бесполезно
    if ($key === '') return false;
    if ($fire) { $fire(HUB_URL . '/?tick=1', $key); return true; }
    $ch = curl_init(HUB_URL . '/?tick=1');
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT_MS => 800, CURLOPT_NOSIGNAL => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Pult-Tick: ' . $key]]);
    @curl_exec($ch);   // ответа не ждём: проверка (до минуты) идёт на стороне пульта
    return true;
}
