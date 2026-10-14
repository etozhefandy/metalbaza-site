<?php
// Шаг автопилота статей (см. _blog/autopost.php). Вызывает сам сайт, с секретом; запрос можно бросить —
// шаг доработает на хостинге и запустит следующий.
declare(strict_types=1);
ignore_user_abort(true);
@set_time_limit(600);
require dirname(__DIR__) . '/_blog/autopost.php';
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
$secret = (string) (autopost_state()['secret'] ?? '');
if ($secret === '' || !hash_equals($secret, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);   // не 403: пачка 403 включает защиту хостинга (fail2ban)
    exit;
}
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
try {
    autopost_tick();
} catch (Throwable $ex) {
    error_log('autopost tick: ' . $ex);
}
