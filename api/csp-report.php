<?php
// Сюда браузер шлёт отчёты о нарушениях Content-Security-Policy (см. _csp.php). Ответ всегда пустой.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_blog/site.php';
require __DIR__ . '/_csp.php';
header('X-Robots-Tag: noindex');
http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') csp_store((string) file_get_contents('php://input', false, null, 0, 16384), csp_dir());
