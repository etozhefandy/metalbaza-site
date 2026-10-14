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
if ($contact === '' || (SITE_LEAD_NAME && $name === '')) reply(false, 422);
// Согласие на передачу контактов третьим лицам (поставщикам) — по закону РК о персональных данных.
if (SITE_LEAD_CONSENT && $field('consent', 5) !== '1') reply(false, 422);
require __DIR__ . '/_phone.php';
$phone = lead_phone($contact);
if ($phone === null) reply(false, 422);
[$contact, $digits] = $phone;
if (lead_rate_limited()) reply(false, 429);

require __DIR__ . '/_tg.php';
require_once dirname(__DIR__) . '/_blog/leads.php';
$lead = lead_add(['phone' => $contact, 'name' => $name, 'product' => $product, 'city' => $city,
    'message' => $message, 'source' => $source . ($lang !== '' && $lang !== 'ru' ? " ($lang)" : ''), 'consent' => SITE_LEAD_CONSENT]);

$cfg = tg_cfg();
if (empty($cfg['tg_token']) || empty($cfg['tg_chat'])) reply($lead !== null, $lead !== null ? 200 : 503);

$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$text = "🔥 <b>Новая заявка с " . SITE_HOST . "</b>" . ($lead ? " · №" . $esc($lead['id']) : '') . "\n\n"
    . ($name !== '' ? "👤 " . $esc($name) . "\n" : '')
    . "📞 " . $esc($contact) . " · <a href=\"https://wa.me/$digits\">WhatsApp</a>\n"
    . ($product !== '' ? "🧱 " . $esc($product) . "\n" : '')
    . ($city !== '' ? "📍 " . $esc($city) . "\n" : '')
    . ($message !== '' ? "💬 " . $esc($message) . "\n" : '')
    . "\n📄 Страница: " . $esc($source) . ($lang !== '' ? " (" . $esc($lang) . ")" : '');

$ok = tg_send_lead($text);
// Заявка уже в журнале — даже если Telegram не ответил, она не потеряется.
reply($ok || $lead !== null, ($ok || $lead !== null) ? 200 : 502);
