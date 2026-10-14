<?php
// Журнал заявок: каждая заявка с сайта — строка JSON в DATA_DIR/leads/ГГГГ-ММ.jsonl (вне сайта).
// По журналу считаемся с покупателями заявок: дата, телефон, товар, город, кому передана.
// Пишет api/send.php, читает админка (список и выгрузка CSV).
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function leads_dir(): string { return DATA_DIR . '/leads'; }

/** Сохраняет заявку → запись с id и временем; null, если записать не удалось (заявка всё равно уйдёт в Telegram). */
function lead_add(array $f): ?array
{
    $at = now();
    $lead = [
        'id' => $at->format('ymd') . '-' . strtoupper(bin2hex(random_bytes(2))),
        'at' => $at->format('Y-m-d\TH:i:sP'),
        'phone' => (string) ($f['phone'] ?? ''),
        'name' => (string) ($f['name'] ?? ''),
        'product' => (string) ($f['product'] ?? ''),
        'city' => (string) ($f['city'] ?? ''),
        'message' => (string) ($f['message'] ?? ''),
        'source' => (string) ($f['source'] ?? ''),
        'consent' => !empty($f['consent']),
        'sent_to' => [],
    ];
    try {
        if (!is_dir(leads_dir()) && !@mkdir(leads_dir(), 0750, true) && !is_dir(leads_dir())) return null;
        $file = leads_dir() . '/' . $at->format('Y-m') . '.jsonl';
        $ok = file_put_contents($file, json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
        return $ok === false ? null : $lead;
    } catch (Throwable $ex) {
        error_log('lead_add: ' . $ex->getMessage());
        return null;
    }
}

/** Месяцы, за которые есть заявки, новые первыми: ['2026-10', …]. */
function lead_months(): array
{
    $m = array_map(fn($f) => basename($f, '.jsonl'), glob(leads_dir() . '/*.jsonl') ?: []);
    rsort($m);
    return array_values(array_filter($m, fn($x) => (bool) preg_match('/^\d{4}-\d{2}$/', $x)));
}

/** Заявки за месяц, новые первыми. */
function leads_list(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return [];
    $file = leads_dir() . "/$month.jsonl";
    $out = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $l = json_decode($line, true);
        if (is_array($l)) $out[] = $l;
    }
    return array_reverse($out);
}

/** CSV для расчётов с покупателями заявок (Excel открывает UTF-8 с BOM и «;»). */
function leads_csv(array $leads): string
{
    $rows = [['№', 'Дата', 'Телефон', 'Имя', 'Товар', 'Город', 'Комментарий', 'Страница', 'Кому передана']];
    foreach ($leads as $l) {
        $rows[] = [$l['id'], str_replace('T', ' ', substr($l['at'], 0, 16)), $l['phone'], $l['name'], $l['product'], $l['city'],
            $l['message'], $l['source'], implode(', ', (array) ($l['sent_to'] ?? []))];
    }
    $csv = "\xEF\xBB\xBF";
    foreach ($rows as $r) {
        $csv .= implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)) . "\r\n";
    }
    return $csv;
}
