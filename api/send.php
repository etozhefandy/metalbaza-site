<?php
// Приём заявки с сайта: журнал заявок (<папка данных>/leads/ГГГГ-ММ.jsonl) и отправка в Telegram (общий бот, api/_tg.php).
// Поля формы: contact (телефон, обязательно), name, message, product, city, source (страница), lang, consent.
// Что обязательно, задаёт сайт (site.php): SITE_LEAD_NAME — имя, SITE_LEAD_CONSENT — галочка согласия на передачу контактов.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_blog/site.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function reply(bool $ok, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 405);

$field = function (string $name, int $max): string {
    $v = isset($_POST[$name]) ? trim((string) $_POST[$name]) : '';
    return mb_substr($v, 0, $max);
};

// Антиспам: скрытое поле должно быть пустым, форма не может быть заполнена быстрее 2 секунд.
if ($field('company', 100) !== '') reply(true);
$ts = (int) $field('ts', 20);
if ($ts > 0 && (microtime(true) * 1000 - $ts) < 2000) reply(true);

$name = $field('name', 80);
$contact = $field('contact', 80);
$message = $field('message', 1000);
$product = $field('product', 80);
$city = $field('city', 60);
$source = $field('source', 80);
$lang = $field('lang', 5);
// откуда человек пришёл и с какой страницы начал (main.js сайта: visit_from / visit_first)
$from = $field('visit_from', 60);
$first = $field('visit_first', 120);
if ($contact === '' || (SITE_LEAD_NAME && $name === '')) reply(false, 422);
// Согласие на передачу контактов третьим лицам (поставщикам) — по закону РК о персональных данных.
if (SITE_LEAD_CONSENT && $field('consent', 5) !== '1') reply(false, 422);
require __DIR__ . '/_phone.php';
$phone = lead_phone($contact);
if ($phone === null) reply(false, 422);
[$contact, $digits] = $phone;
if (lead_rate_limited()) reply(false, 429);

require __DIR__ . '/_tg.php';
require __DIR__ . '/_geo.php';
require_once dirname(__DIR__) . '/_blog/leads.php';
$geo = lead_geo((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$lead = lead_add(['phone' => $contact, 'name' => $name, 'product' => $product, 'city' => $city,
    'message' => $message, 'source' => $source . ($lang !== '' && $lang !== 'ru' ? " ($lang)" : ''), 'consent' => SITE_LEAD_CONSENT,
    'geo_country' => $geo['country'], 'geo_city' => $geo['city']]);

$cfg = tg_cfg();
if (empty($cfg['tg_token']) || empty($cfg['tg_chat'])) reply($lead !== null, $lead !== null ? 200 : 503);

$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$text = "🔥 <b>Новая заявка с " . SITE_HOST . "</b>" . ($lead ? " · №" . $esc($lead['id']) : '') . "\n\n"
    . ($name !== '' ? "👤 " . $esc($name) . "\n" : '')
    . "📞 " . $esc($contact) . " · <a href=\"https://wa.me/$digits\">WhatsApp</a>\n"
    . ($product !== '' ? "🧱 " . $esc($product) . "\n" : '')
    . ($city !== '' ? "📍 " . $esc($city) . "\n" : '')
    . ($message !== '' ? "💬 " . $esc($message) . "\n" : '')
    . (lead_geo_line($geo) !== '' ? "🌍 По IP: " . $esc(lead_geo_line($geo)) . "\n" : '')
    . "\n📄 Страница: " . $esc($source) . ($lang !== '' ? " (" . $esc($lang) . ")" : '')
    . ($from !== '' ? "\n🧭 Откуда пришёл: " . $esc($from) : '')
    . ($first !== '' ? "\n🚪 Первая страница: " . $esc($first) : '');

$sent = tg_send_lead($text);
$ok = $sent !== [] || $lead !== null;
// Заявка уже в журнале — даже если Telegram не ответил, она не потеряется. Человеку отвечаем сразу,
// в CRM (если в пульте подключена для этого сайта) отправляем уже после ответа — не ждёт её заявитель.
http_response_code($ok ? 200 : 502);
echo json_encode(['ok' => $ok]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { header('Connection: close'); header('Content-Length: ' . ob_get_length()); @ob_end_flush(); @flush(); }

require_once __DIR__ . '/_crm.php';
$crm = ['client' => SITE_HOST, 'client_name' => SITE_BRAND, 'page' => $source, 'page_title' => $source,
    'name' => $name, 'phone' => $digits, 'answers' => $product !== '' ? [['Товар', $product]] : [],
    'city' => $city, 'comment' => $message, 'utm' => [], 'geo_country' => $geo['country'], 'geo_city' => $geo['city']];
$r = crm_send(SITE_HOST, $crm);
if ($sent && ($status = crm_status_text(SITE_HOST, $r))) tg_edit_lead($sent, $text . "\n\n" . $status);
exit;
