<?php
// Нажатие важной кнопки на сайте (WhatsApp, Telegram, звонок, Instagram, почта) → сообщение владельцу в Telegram:
// какую кнопку, откуда человек пришёл (2ГИС, Google, реклама…), с какой страницы начал и где нажал.
// Шлёт main.js сайта через navigator.sendBeacon. Это не заявка: в журнал заявок и в группу поставщика не идёт.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_blog/site.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function click_reply(int $code = 204): void { http_response_code($code); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') click_reply(405);
if (preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) click_reply();

const CLICK_KINDS = ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'call' => 'Позвонить', 'instagram' => 'Instagram', 'email' => 'Почта'];
$f = fn(string $k, int $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
$kind = $f('kind', 20);
if (!isset(CLICK_KINDS[$kind])) click_reply(422);
$clean = fn(string $s) => preg_replace('/[\x00-\x1f\x7f]/u', '', $s);
$page = $clean($f('page', 200));
$first = $clean($f('first', 200));
$from = $clean($f('from', 60));
$href = $clean($f('href', 300));

require __DIR__ . '/_phone.php';
// не больше 30 уведомлений с одного IP за 10 минут, и одна и та же кнопка на той же странице — раз в 10 минут
if (lead_rate_limited(30, 'click|' . SITE_HOST, false) || lead_rate_limited(1, 'click|' . SITE_HOST . "|$kind|$page", false)) click_reply();

require __DIR__ . '/_tg.php';
$cfg = tg_cfg();
if (empty($cfg['tg_token']) || tg_owner() === '') click_reply();
require __DIR__ . '/_geo.php';
$geo = lead_geo_line(lead_geo((string) ($_SERVER['REMOTE_ADDR'] ?? '')));

$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$text = "👆 <b>На " . SITE_HOST . " нажали «" . CLICK_KINDS[$kind] . "»</b>\n"
    . "🧭 " . $esc($from !== '' ? $from : 'Неизвестно') . "\n"
    . ($first !== '' ? "🚪 Первая страница: " . $esc($first) . "\n" : '')
    . ($page !== '' ? "📄 Где нажали: " . $esc($page) . "\n" : '')
    . ($geo !== '' ? "🌍 По IP: " . $esc($geo) . "\n" : '')
    . ($href !== '' && in_array($kind, ['telegram', 'instagram', 'email'], true) ? "🔗 " . $esc($href) : '');
tg_send(tg_owner(), rtrim($text));
click_reply();
