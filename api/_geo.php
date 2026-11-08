<?php
// Страна и город заявки по IP (для бота и журнала — видно, откуда реально пришла заявка, помогает
// заметить накрутку/спам). Один короткий запрос к бесплатному публичному сервису геолокации IP,
// без ключа. Не бросает исключений и не держит форму: при любой осечке — просто пустые строки.
//
// См. _net.php: этот файл тоже может подключаться и из пульта, и из самого сайта в одном процессе —
// поэтому функции объявляются один раз.
declare(strict_types=1);
require_once __DIR__ . '/_net.php';

if (!function_exists('lead_geo')) {

/** → ['country' => '…', 'city' => '…'] либо пустые строки (приватный IP, нет сети, сервис не ответил). */
function lead_geo(string $ip, ?callable $http = null): array
{
    $empty = ['country' => '', 'city' => ''];
    if ($ip === '' || !net_ip_public($ip)) return $empty;
    if ($http) { $r = $http($ip); return is_array($r) ? array_merge($empty, $r) : $empty; }
    $ch = curl_init('https://ipwho.is/' . urlencode($ip) . '?fields=success,country,city');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1]);
    $body = curl_exec($ch);
    $r = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($r) || empty($r['success'])) return $empty;
    return ['country' => (string) ($r['country'] ?? ''), 'city' => (string) ($r['city'] ?? '')];
}

/** Строка для сообщения/журнала: «Алматы, Казахстан» либо только страна, либо '' — если пусто совсем. */
function lead_geo_line(array $geo): string
{
    return implode(', ', array_filter([$geo['city'] ?? '', $geo['country'] ?? '']));
}

}
