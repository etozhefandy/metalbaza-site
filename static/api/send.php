<?php
// Приём заявки с сайта: журнал заявок (папка данных/leads/ГГГГ-ММ.jsonl) и отправка в Telegram.
// Токен и chat_id — в tg-config.php рядом с httpdocs; привязанная группа — в папке данных, telegram.json.
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function reply($ok, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 405);

$field = function ($name, $max) {
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
// Согласие на передачу контактов поставщикам обязательно (закон РК о персональных данных).
if ($contact === '' || $field('consent', 5) !== '1') reply(false, 422);
// Только телефон. Казахстан и Россия (+7) — проверяем коды: KZ мобильные 700–708, 747, 771–778 и городские 71x/72x,
// RU — 3xx/4xx/8xx/9xx; «+7 666…» не пройдёт. Другие страны (выбраны в форме) — международный формат, 8–15 цифр.
$digits = preg_replace('/\D/', '', $contact);
$intl = strpos(trim($contact), '+') === 0;
if (!$intl && strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
if (!$intl && strlen($digits) === 10) $digits = '7' . $digits;
if ($digits !== '' && $digits[0] === '7') {
    if (strlen($digits) !== 11 || !preg_match('/^7(?:70[0-8]|747|77[1-8]|7[12]\d|[3489]\d\d)/', $digits)) reply(false, 422);
    $contact = sprintf('+7 (%s) %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
} else {
    if (!$intl || strlen($digits) < 8 || strlen($digits) > 15) reply(false, 422);
    $contact = '+' . $digits;
}

// Простое ограничение частоты: не больше 5 заявок с одного IP за 10 минут.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rlFile = sys_get_temp_dir() . '/' . 'lead_rl_' . md5($ip);
$now = time();
$hits = array_filter(
    is_file($rlFile) ? (array) json_decode((string) @file_get_contents($rlFile), true) : [],
    function ($t) use ($now) { return $now - (int) $t < 600; }
);
if (count($hits) >= 5) reply(false, 429);
$hits[] = $now;
@file_put_contents($rlFile, json_encode(array_values($hits)));

// Секреты лежат вне папки сайта (рядом с httpdocs), чтобы не попадать в публичный репозиторий.
// Куда слать — личка владельца или привязанная по PIN группа (см. _tg.php).
require __DIR__ . '/_tg.php';
require_once dirname(__DIR__) . '/_blog/leads.php';
$lead = lead_add(['phone' => $contact, 'name' => $name, 'product' => $product, 'city' => $city,
    'message' => $message, 'source' => $source, 'consent' => true]);

$cfg = tg_cfg();
if (empty($cfg['tg_token']) || empty($cfg['tg_chat'])) reply($lead !== null, $lead !== null ? 200 : 503);

$esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$text = "🔥 <b>Заявка с " . SITE_HOST . "</b>" . ($lead ? " №" . $esc($lead['id']) : '') . "\n\n"
    . ($product !== '' ? "🧱 " . $esc($product) . "\n" : '')
    . ($city !== '' ? "📍 " . $esc($city) . "\n" : '')
    . ($message !== '' ? "💬 " . $esc($message) . "\n" : '')
    . "\n📞 " . $esc($contact) . " · <a href=\"https://wa.me/$digits\">WhatsApp</a>"
    . ($name !== '' ? "\n👤 " . $esc($name) : '')
    . "\n\n📄 Страница: " . $esc($source);

$ok = tg_send_lead($text);
// Заявка уже в журнале — даже если Telegram не ответил, она не потеряется.
reply($ok || $lead !== null, ($ok || $lead !== null) ? 200 : 502);
