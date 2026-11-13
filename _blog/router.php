<?php
// Публичные адреса блога (сюда ведут правила в /.htaccess):
//   /              → главная RU с блоком «Свежие статьи»
//   /blog/         → список статей
//   /blog/<slug>/  → статья (только если её время наступило)
//   /blog/media/*  → картинки, загруженные из админки
//   /sitemap.xml   → карта сайта вместе со статьями
//   /llms.txt      → справка о сайте для нейросетей (llmstxt.org) со списком статей
//   /llms-full.txt → та же справка, но вместо ссылок на статьи — их содержимое целиком
declare(strict_types=1);
require __DIR__ . '/lib.php';

function not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    // у сайта может быть своя страница «не найдено» для казахской версии (/kz/404.html)
    $kz = SITE_DIR . '/kz/404.html';
    readfile(str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/kz/') && is_file($kz) ? $kz : SITE_DIR . '/404.html');
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

$lang = ($_GET['lang'] ?? '') === 'kk' ? 'kk' : 'ru';   // «/kz/blog/…» — см. static/.htaccess сайта
try {
    switch ($_GET['route'] ?? '') {
        case 'home':
            // главная всегда русская (казахской версии блок статей пока не касается) — независимо от $lang
            send_html(render_home((string) file_get_contents(SITE_DIR . '/index.html'), live_articles(null, 'ru')));
            break;
        case 'index':
            $posts = live_articles(null, $lang);
            // блога на этом языке нет вообще (например казахских статей ещё не писали) — отдаём 404, а не
            // пустую страницу-дубль с тем же title/description, что и у основного /blog/ (ru)
            if ($lang !== 'ru' && !$posts) not_found();
            send_html(render_blog_index($posts, $lang));
            break;
        case 'article':
            $a = get_article((string) ($_GET['slug'] ?? ''));
            // статья своего языка не на своём пути («/kz/blog/<ru-статья>/» и наоборот) — такой страницы нет
            if (!$a || !is_live($a) || ($a['lang'] ?? 'ru') !== $lang) {
                // искажённый адрес статьи (ИИ ошибся в ссылке) — на похожую статью этого языка, иначе 404
                $g = $a ? null : guess_article((string) ($_GET['slug'] ?? ''));
                if ($g && ($g['lang'] ?? 'ru') === $lang) { header('Location: ' . article_path($g), true, 301); exit; }
                not_found();
            }
            send_html(render_article($a, live_articles(null, $lang)));
            // статья вышла по расписанию или обновилась — сообщить Bing и Яндексу (один раз на версию)
            try { indexnow_article($a); } catch (Throwable $ex) { error_log('indexnow: ' . $ex->getMessage()); }
            break;
        case 'guess':
            // адреса, которых нет (/slug/ без /blog/, искажённый адрес статьи — см. static/.htaccess сайта):
            // похожая статья есть → постоянный редирект на неё, иначе обычная страница «не найдено»
            $a = guess_article((string) ($_GET['slug'] ?? ''));
            if (!$a) not_found();
            header('Location: ' . article_path($a), true, 301);
            exit;
        case 'media':
            send_media((string) ($_GET['f'] ?? ''));
            break;
        case 'llms':
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo render_llms((string) file_get_contents(__DIR__ . '/llms.tpl'), live_articles());
            break;
        case 'llms-full':
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            echo render_llms_full((string) file_get_contents(__DIR__ . '/llms.tpl'), live_articles());
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
// Этот сайт — в списке сайтов общего бота заявок (бот живёт на главном сайте хостинга, см. api/_tg.php).
try {
    require_once dirname(__DIR__) . '/api/_tg.php';
    tg_register();
} catch (Throwable $ex) {
    error_log('tg register: ' . $ex->getMessage());
}
// Не пора ли еженедельной SEO-проверке (раз в 10 минут читает один файл; сама проверка идёт отдельным запросом).
if (in_array($_GET['route'] ?? '', ['home', 'index', 'article'], true)) {
    flush();
    try {
        require_once __DIR__ . '/seo.php';
        seo_maybe_kick();
        require_once __DIR__ . '/queue.php';
        queue_maybe_kick();
        require_once dirname(__DIR__) . '/api/_hub.php';
        hub_tick_kick();   // проверка доменов/SSL в пульте — раз в 12 часов
    } catch (Throwable $ex) {
        error_log('seo/queue kick: ' . $ex->getMessage());
    }
}
