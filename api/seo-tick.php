<?php
// Шаг SEO-автопилота (см. _blog/seo.php). Вызывает сам сайт, с секретом; запрос можно бросить —
// шаг доработает на хостинге и запустит следующий.
declare(strict_types=1);
ignore_user_abort(true);
@set_time_limit(300);
require dirname(__DIR__) . '/_blog/seo.php';
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
$secret = (string) (seo_state()['secret'] ?? '');
if ($secret === '' || !hash_equals($secret, (string) ($_GET['k'] ?? ''))) {
    http_response_code(404);   // не 403: пачка 403 включает защиту хостинга (fail2ban)
    exit;
}
echo 'ok';
try {
    seo_tick();
} catch (Throwable $ex) {
    error_log('seo tick: ' . $ex);
}
