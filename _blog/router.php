<?php
// Публичные адреса блога (сюда ведут правила в /.htaccess):
//   /              → главная RU с блоком «Свежие статьи»
//   /blog/         → список статей
//   /blog/<slug>/  → статья (только если её время наступило)
//   /blog/media/*  → картинки, загруженные из админки
//   /sitemap.xml   → карта сайта вместе со статьями
//   /llms.txt      → справка о сайте для нейросетей (llmstxt.org) со списком статей
declare(strict_types=1);
require __DIR__ . '/lib.php';

function not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    readfile(SITE_DIR . '/404.html');
    exit;
}

function send_html(string $html): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo $html;
}

function send_media(string $name): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,120}\.(webp|png|jpe?g)$/', $name)) not_found();
    $file = DATA_DIR . '/media/' . $name;
    if (!is_file($file)) not_found();
    $types = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    $etag = '"' . dechex(filemtime($file)) . '-' . dechex(filesize($file)) . '"';
    header('Content-Type: ' . $types[pathinfo($name, PATHINFO_EXTENSION)]);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

try {
    switch ($_GET['route'] ?? '') {
        case 'home':
            send_html(render_home((string) file_get_contents(SITE_DIR . '/index.html'), live_articles()));
            break;
        case 'index':
            send_html(render_blog_index(live_articles()));
            break;
        case 'article':
            $a = get_article((string) ($_GET['slug'] ?? ''));
            if (!$a || !is_live($a)) not_found();
            send_html(render_article($a, live_articles()));
            // статья вышла по расписанию или обновилась — сообщить Bing и Яндексу (один раз на версию)
            try { indexnow_article($a); } catch (Throwable $ex) { error_log('indexnow: ' . $ex->getMessage()); }
            break;
        case 'media':
            send_media((string) ($_GET['f'] ?? ''));
            break;
        case 'llms':
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo render_llms((string) file_get_contents(__DIR__ . '/llms.tpl'), live_articles());
            break;
        case 'sitemap':
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo render_sitemap((string) file_get_contents(__DIR__ . '/sitemap.tpl'), live_articles());
            break;
        default:
            not_found();
    }
} catch (Throwable $ex) {
    error_log('blog router: ' . $ex->getMessage());
    http_response_code(500);
    echo 'Ошибка сервера';
}
// Не пора ли еженедельной SEO-проверке (раз в 10 минут читает один файл; сама проверка идёт отдельным запросом).
if (in_array($_GET['route'] ?? '', ['home', 'index', 'article'], true)) {
    flush();
    try {
        require_once __DIR__ . '/seo.php';
        seo_maybe_kick();
    } catch (Throwable $ex) {
        error_log('seo kick: ' . $ex->getMessage());
    }
}
