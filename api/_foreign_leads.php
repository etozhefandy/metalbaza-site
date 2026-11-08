<?php
// Заявки чужих сайтов, которые падают в ту же Telegram-группу, что и наши (например, Алима — agen.kz):
// бот состоит в группе администратором, видит все сообщения, и когда другой бот публикует «Заявка с сайта
// <домен>», мы копируем её сюда — отдельно от наших собственных заявок (leads.php), со статусом обработки.
// Свою же заявку (от нашего бота) не ловим — она и так уже в leads.php по прямому пути, без разбора текста.
//
// Хранится рядом с общими данными пульта (hub-data/foreign-leads.json) — одна точка для всех сайтов
// хостинга, доступна только из пульта (admin.alfimov.kz), владельцу.
//
// Пульт в одном процессе подключает и свою копию этого файла, и копию сайта (admin сайта выполняется
// внутри пульта — hub/pult.php: pult_site_admin). Это два разных файла на диске с одинаковыми функциями,
// поэтому всё объявляем только при первой загрузке — иначе admin.alfimov.kz падал «Cannot redeclare» (см. _net.php).
declare(strict_types=1);

if (!function_exists('foreign_leads_dir')) {

function foreign_leads_dir(): string
{
    return function_exists('pult_data') ? pult_data() : tg_hub_dir() . '/hub-data';
}

function foreign_leads_read(): array
{
    $f = foreign_leads_dir() . '/foreign-leads.json';
    $v = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($v) ? $v : [];
}

function foreign_leads_write(array $v): void
{
    $dir = foreign_leads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $f = "$dir/foreign-leads.json";
    $tmp = "$f.tmp-" . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($tmp, 0600);
    rename($tmp, $f);
}

/** Одна строка текста после эмодзи-метки («👤 Светлана» → «Светлана»); '' — метки в тексте нет. */
function foreign_lead_field(string $text, string $marker): string
{
    if (!preg_match('/^' . preg_quote($marker, '/') . '\s*(.+)$/mu', $text, $m)) return '';
    return trim($m[1]);
}

/** Текст сообщения после «💬 …» до следующей строки-метки (🧭/🚪/📄/👉) или до конца. */
function foreign_lead_message(string $text): string
{
    if (!preg_match('/^💬\s*(.+?)(?=\n[🧭🚪📄👉]|\z)/msu', $text, $m)) return '';
    return trim($m[1]);
}

/**
 * Разобрать и сохранить чужую заявку из группового сообщения Telegram (формат — см. ../../README.md).
 * $m — элемент update['message']. Не наше сообщение (чужой бот) — проверяется до вызова.
 */
function foreign_lead_capture(array $m): void
{
    $text = (string) ($m['text'] ?? '');
    if (!preg_match('/заявка\s+с\s+сайта\s+(\S+)/iu', $text, $dm)) return;
    $domain = rtrim(strtolower($dm[1]), '.,;:!?');
    $phone = '';
    if (preg_match('/(\+?\d[\d\s().-]{7,}\d)/u', $text, $pm)) $phone = trim($pm[1]);
    $lead = [
        'id' => date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'at' => date('c'),
        'domain' => $domain,
        'name' => foreign_lead_field($text, '👤'),
        'phone' => $phone,
        'message' => foreign_lead_message($text),
        'raw' => $text,
        'from_bot' => (string) ($m['from']['username'] ?? $m['from']['first_name'] ?? ''),
        'status' => 'new',
    ];
    $all = foreign_leads_read();
    $all[$lead['id']] = $lead;
    foreign_leads_write($all);
}

/** Все чужие заявки, новые сверху. */
function foreign_leads_list(): array
{
    $all = array_values(foreign_leads_read());
    usort($all, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    return $all;
}

function foreign_lead_set_status(string $id, string $status): bool
{
    $all = foreign_leads_read();
    if (!isset($all[$id])) return false;
    $all[$id]['status'] = $status;
    foreign_leads_write($all);
    return true;
}

}
