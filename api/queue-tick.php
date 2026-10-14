<?php
// Шаг очереди написания статей (см. _blog/queue.php). Вызывает сам сайт, с секретом; запрос можно бросить —
// задача доработает на хостинге и запустит следующие.
declare(strict_types=1);
ignore_user_abort(true);
@set_time_limit(300);
require dirname(__DIR__) . '/_blog/queue.php';
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
$secret = (string) (queue_state()['secret'] ?? '');
if ($secret === '' || !hash_equals($secret, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);   // не 403: пачка 403 включает защиту хостинга (fail2ban)
    exit;
}
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
try {
    queue_tick();
} catch (Throwable $ex) {
    error_log('queue tick: ' . $ex);
}
