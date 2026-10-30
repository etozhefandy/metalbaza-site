<?php
// Дорисовать обложку одной статье, у которой её нет (см. _blog/queue.php). Как и queue-tick.php —
// можно бросить, не дожидаясь ответа; пинает сам queue_maybe_kick(), только когда есть что дорисовывать.
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
    $slug = cover_backfill_claim();
    if ($slug !== '' && ($a = cover_backfill_run($slug, queue_settings()['image_mode']))) queue_notify($a, true);
} catch (Throwable $ex) {
    error_log('cover tick: ' . $ex);
}
