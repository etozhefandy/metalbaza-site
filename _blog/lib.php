<?php
// Блог сайта на хостинге: хранилище статей, расписание, markdown, страницы.
//
// Основные страницы сайта — статика из build.py. Блог живой: статьи лежат в DATA_DIR
// (вне httpdocs, Plesk Git их не трогает), страницы собираются при открытии. Внешний вид
// берётся из _blog/parts.json — его пишет build.py теми же функциями, что и весь сайт.
// Статья «на завтра» не отдаётся до своего времени — расписанию не нужен ни cron, ни компьютер.
declare(strict_types=1);
require_once __DIR__ . '/site.php';   // домен, бренд, ниша — пишет build.py из src/site.py

if (!defined('SITE_DIR')) define('SITE_DIR', getenv('ALFIMOV_SITE') ?: dirname(__DIR__));
if (!defined('DATA_DIR')) define('DATA_DIR', getenv('ALFIMOV_DATA') ?: dirname(SITE_DIR) . '/' . SITE_DATA);
const BLOG_TZ = 'Asia/Almaty';
const STATUSES = ['draft', 'scheduled'];
const SLUG_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
const COVER_RE = '#^/(assets|blog/media)/[A-Za-z0-9._/-]+$#';
const WORDS_PER_MIN = 180;
const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

// Поля статьи. `seo_title` — <title> для выдачи, `title` — h1; `description` — сниппет,
// `summary` — блок «Коротко», `lead` — подзаголовок под h1.
const FIELDS = [
    'slug' => '', 'title' => '', 'seo_title' => '', 'description' => '', 'lead' => '', 'summary' => '',
    'body_md' => '', 'category' => '', 'keywords' => [], 'cover_path' => '', 'author' => '', 'read_min' => null,
    'faq' => [], 'related' => [], 'status' => 'draft', 'publish_at' => '', 'created_at' => '', 'updated_at' => '',
];

// ---------------------------------------------------------------- время

function tz(): DateTimeZone { static $tz; return $tz ??= new DateTimeZone(BLOG_TZ); }

function now(): DateTimeImmutable
{
    $fixed = getenv('ALFIMOV_NOW');  // для тестов
    return new DateTimeImmutable($fixed ?: 'now', tz());
}

/** '2026-10-01T10:00' (время Алматы) или ISO с поясом → DateTimeImmutable; пусто/мусор → null. */
function parse_dt($v): ?DateTimeImmutable
{
    $v = trim((string) $v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(\.\d+)?)?([+-]\d{2}:\d{2}|Z)?$/', $v)) return null;
    try {
        return new DateTimeImmutable($v, tz());
    } catch (Exception $e) {
        return null;
    }
}

function is_live(array $a, ?DateTimeImmutable $at = null): bool
{
    if (($a['status'] ?? '') !== 'scheduled') return false;
    $dt = parse_dt($a['publish_at'] ?? '');
    return $dt !== null && $dt <= ($at ?? now());
}

/** draft | scheduled (дата впереди) | live (дата наступила — статья на сайте). */
function state(array $a, ?DateTimeImmutable $at = null): string
{
    if (($a['status'] ?? '') !== 'scheduled') return 'draft';
    return is_live($a, $at) ? 'live' : 'scheduled';
}

// ---------------------------------------------------------------- хранилище

function articles_dir(): string { return DATA_DIR . '/articles'; }

function str_list($v): array
{
    $out = [];
    foreach (is_array($v) ? $v : [] as $x) {
        $x = trim((string) $x);
        if ($x !== '') $out[] = $x;
    }
    return $out;
}

function normalize(array $a): array
{
    $out = FIELDS;
    foreach (FIELDS as $k => $_) {
        if (array_key_exists($k, $a) && $a[$k] !== null) $out[$k] = $a[$k];
    }
    foreach (['slug', 'title', 'seo_title', 'description', 'lead', 'summary', 'category', 'cover_path', 'author', 'status', 'publish_at'] as $k) {
        $out[$k] = trim(is_scalar($out[$k]) ? (string) $out[$k] : '');
    }
    $out['slug'] = strtolower($out['slug']);
    $out['body_md'] = trim(str_replace("\r\n", "\n", is_scalar($out['body_md']) ? (string) $out['body_md'] : '')) . "\n";
    $out['keywords'] = str_list($out['keywords']);
    $faq = [];
    foreach (is_array($out['faq']) ? $out['faq'] : [] as $pair) {
        if (!is_array($pair) || count($pair) < 2) continue;
        [$q, $ans] = [trim((string) array_values($pair)[0]), trim((string) array_values($pair)[1])];
        if ($q !== '' && $ans !== '') $faq[] = [$q, $ans];
    }
    $out['faq'] = $faq;
    $out['related'] = array_values(array_filter(str_list($out['related']), fn($s) => $s !== $out['slug']));
    $rm = $out['read_min'];
    $out['read_min'] = (is_numeric($rm) && (int) $rm > 0) ? (int) $rm : null;
    foreach (['created_at', 'updated_at'] as $k) $out[$k] = is_scalar($out[$k]) ? (string) $out[$k] : '';
    return $out;
}

/** Ошибки человеческим языком; пусто — можно сохранять. */
function validate(array $a): array
{
    $errors = [];
    if (!preg_match(SLUG_RE, $a['slug']) || strlen($a['slug']) > 120) {
        $errors[] = 'Адрес (slug): строчная латиница, цифры и дефис — он попадёт в URL';
    }
    if (mb_strlen($a['title']) < 2) $errors[] = 'Нужен заголовок';
    if (!in_array($a['status'], STATUSES, true)) $errors[] = 'Статус: черновик или запланировано';
    // «Запланировано» без даты не выйдет никогда, а числиться будет запланированной.
    if ($a['status'] === 'scheduled') {
        if ($a['publish_at'] === '') $errors[] = 'Для «запланировано» нужна дата выхода';
        elseif (parse_dt($a['publish_at']) === null) $errors[] = 'Дата выхода не распознана';
    }
    if ($a['cover_path'] !== '' && (!preg_match(COVER_RE, $a['cover_path']) || str_contains($a['cover_path'], '..'))) {
        $errors[] = 'Обложка — путь вида /blog/media/… или /assets/blog/…';
    }
    return $errors;
}

function article_file(string $slug): string { return articles_dir() . "/$slug.json"; }

function read_json(string $file)
{
    if (!is_file($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Атомарная запись: читатель никогда не увидит полфайла. */
function write_file(string $file, string $content): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать папку $dir");
    }
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Не удалось записать ' . basename($file));
    }
}

function write_json(string $file, $data): void
{
    write_file($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

/**
 * Первый запуск на хостинге: переносим статьи из репозитория (_blog/seed, кладёт build.py)
 * в хранилище, чтобы блог не опустел при переезде. Дальше источник правды — DATA_DIR.
 */
function ensure_articles(): void
{
    $mark = DATA_DIR . '/.seeded';
    if (is_file($mark)) return;
    if (!is_dir(articles_dir()) && !@mkdir(articles_dir(), 0750, true) && !is_dir(articles_dir())) return;
    foreach (glob(SITE_DIR . '/_blog/seed/*.json') ?: [] as $f) {
        $a = read_json($f);
        $slug = (string) ($a['slug'] ?? '');
        if ($a && preg_match(SLUG_RE, $slug) && !is_file(article_file($slug))) write_json(article_file($slug), normalize($a));
    }
    @file_put_contents($mark, now()->format('c') . "\n");
}

function load_all(): array
{
    ensure_articles();
    $items = [];
    foreach (glob(articles_dir() . '/*.json') ?: [] as $f) {
        $a = read_json($f);
        if ($a) $items[] = normalize($a);
    }
    usort($items, fn($x, $y) => strcmp($y['publish_at'] ?: $y['created_at'], $x['publish_at'] ?: $x['created_at']));
    return $items;
}

function get_article(string $slug): ?array
{
    if (!preg_match(SLUG_RE, $slug)) return null;
    $a = read_json(article_file($slug));
    return $a ? normalize($a) : null;
}

/** Сохраняет статью; при смене адреса старый файл удаляется. Бросает InvalidArgumentException. */
function save_article(array $article, ?string $old_slug = null): array
{
    $a = normalize($article);
    if ($a['status'] === 'scheduled' && $a['publish_at'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $a['publish_at'])) {
        $a['publish_at'] = substr($a['publish_at'], 0, 16);
    }
    $errors = validate($a);
    if ($errors) throw new InvalidArgumentException(implode('; ', $errors));
    if ($a['slug'] !== $old_slug && is_file(article_file($a['slug']))) {
        throw new InvalidArgumentException('Такой адрес уже занят другой статьёй');
    }
    $prev = $old_slug ? get_article($old_slug) : null;
    $stamp = now()->format('Y-m-d\TH:i:sP');
    $a['created_at'] = ($prev['created_at'] ?? '') ?: ($a['created_at'] ?: $stamp);
    $a['updated_at'] = $stamp;
    write_json(article_file($a['slug']), $a);
    if ($old_slug && $old_slug !== $a['slug'] && preg_match(SLUG_RE, $old_slug)) @unlink(article_file($old_slug));
    return $a;
}

function delete_article(string $slug): bool
{
    if (!preg_match(SLUG_RE, $slug) || !is_file(article_file($slug))) return false;
    return unlink(article_file($slug));
}

function live_articles(?DateTimeImmutable $at = null): array
{
    return array_values(array_filter(load_all(), fn($a) => is_live($a, $at)));
}

function read_minutes(array $a): int
{
    if (!empty($a['read_min'])) return (int) $a['read_min'];
    $words = preg_match_all('/[\p{L}\p{N}_]+/u', $a['body_md'] ?? '');
    return max(1, (int) round($words / WORDS_PER_MIN));
}

// ---------------------------------------------------------------- slug

function slugify(string $text): string
{
    static $tr = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'ә' => 'a', 'ғ' => 'g', 'қ' => 'k', 'ң' => 'n', 'ө' => 'o', 'ұ' => 'u', 'ү' => 'u', 'һ' => 'h', 'і' => 'i',
    ];
    $s = strtr(mb_strtolower($text), $tr);
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = rtrim(substr($s, 0, 80), '-');
    return $s !== '' ? $s : 'section';
}

// ---------------------------------------------------------------- markdown

/** Как html.escape в Python: & < > " ' — страницы совпадают с прежней сборкой байт в байт. */
function e($s): string
{
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], (string) $s);
}

function e_text(string $s): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

function safe_url(string $u): string
{
    $u = trim($u);
    return preg_match('#^(https?://|/|\#|mailto:|tel:)#', $u) ? $u : '#';
}

/** Инлайн-разметка поверх экранированного текста: `код`, **жирный**, *курсив*, [ссылка](url). */
function md_inline(string $text): string
{
    $codes = [];
    $text = preg_replace_callback('/`([^`]+)`/u', function ($m) use (&$codes) {
        $codes[] = '<code>' . e($m[1]) . '</code>';
        return "\x00" . (count($codes) - 1) . "\x00";
    }, $text);
    $links = [];
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) use (&$links) {
        $href = safe_url($m[2]);
        $external = str_starts_with($href, 'http') && !str_contains($href, SITE_HOST);
        $rel = $external ? ' target="_blank" rel="noopener"' : '';
        $links[] = ['<a href="' . e($href) . '"' . $rel . '>', $m[1]];
        return "\x01" . (count($links) - 1) . "\x01";
    }, $text);
    $text = e_text($text);
    $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
    $text = preg_replace('/(*UCP)(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $text);
    $text = preg_replace_callback('/\x01(\d+)\x01/', fn($m) => $links[(int) $m[1]][0] . md_inline($links[(int) $m[1]][1]) . '</a>', $text);
    $text = preg_replace_callback('/\x00(\d+)\x00/', fn($m) => $codes[(int) $m[1]], $text);
    return $text;
}

function md_table(array $rows): string
{
    $cells = array_map(fn($r) => array_map('trim', explode('|', trim(trim($r), '|'))), $rows);
    $th = implode('', array_map(fn($c) => '<th>' . md_inline($c) . '</th>', $cells[0]));
    $trs = '';
    foreach (array_slice($cells, 2) as $r) {
        $trs .= '<tr>' . implode('', array_map(fn($c) => '<td>' . md_inline($c) . '</td>', $r)) . '</tr>';
    }
    return "<div class=\"tbl\"><table><thead><tr>$th</tr></thead><tbody>$trs</tbody></table></div>";
}

/**
 * Markdown статьи → [html, оглавление [[id, текст h2], ...]].
 * ## и ### (с якорями), абзацы, списки, цитаты, таблицы, **жирный**, *курсив*, `код`, ссылки,
 * картинки отдельной строкой ![подпись](/blog/media/…) — только со своего сайта. Сырой HTML
 * экранируется. # превращается в ## — h1 на странице один, это заголовок статьи.
 */
function markdown(string $md): array
{
    $lines = explode("\n", str_replace("\r\n", "\n", $md));
    $out = [];
    $toc = [];
    $used = [];
    $para = [];
    $n = count($lines);
    $i = 0;
    $flush = function () use (&$para, &$out) {
        if ($para) {
            $out[] = '<p>' . md_inline(implode(' ', array_map('trim', $para))) . '</p>';
            $para = [];
        }
    };
    $anchor = function (string $text) use (&$used) {
        $base = slugify((string) preg_replace('/[*`\[\]()]/u', '', $text));
        $a = $base;
        $k = 2;
        while (isset($used[$a])) $a = $base . '-' . $k++;
        $used[$a] = true;
        return $a;
    };
    $item_re = '/^\s*([-*+]|\d+[.)])\s+/';
    while ($i < $n) {
        $line = $lines[$i];
        $s = trim($line);
        if ($s === '') { $flush(); $i++; continue; }
        if (preg_match('#^!\[([^\]]*)\]\((/(?:assets|blog/media)/[^)\s]+)\)$#u', $s, $m)) {
            $flush();
            $alt = trim($m[1]);
            $cap = $alt !== '' ? '<figcaption>' . md_inline($alt) . '</figcaption>' : '';
            $out[] = '<figure><img src="' . e($m[2]) . '" alt="' . e($alt) . '" loading="lazy">' . $cap . '</figure>';
            $i++;
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+?)\s*#*$/u', $s, $m)) {
            $flush();
            $hashes = strlen($m[1]);
            $level = max(2, min(3, $hashes > 1 ? $hashes : 2));
            $text = $m[2];
            $aid = $anchor($text);
            if ($level === 2) $toc[] = [$aid, preg_replace('/[*`]/u', '', $text)];
            $out[] = "<h$level id=\"$aid\">" . md_inline($text) . "</h$level>";
            $i++;
            continue;
        }
        if (str_starts_with($s, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}/', trim($lines[$i + 1]))) {
            $flush();
            $rows = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '|')) $rows[] = $lines[$i++];
            $out[] = md_table($rows);
            continue;
        }
        if (preg_match('/^([-*+]|\d+[.)])\s+/', $s)) {
            $flush();
            $ordered = (bool) preg_match('/^\d/', $s);
            $items = [];
            while ($i < $n && preg_match($item_re, $lines[$i])) {
                $item = preg_replace($item_re, '', $lines[$i]);
                $i++;
                while ($i < $n && str_starts_with($lines[$i], '  ') && trim($lines[$i]) !== '' && !preg_match($item_re, $lines[$i])) {
                    $item .= ' ' . trim($lines[$i]);
                    $i++;
                }
                $items[] = '<li>' . md_inline($item) . '</li>';
            }
            $tag = $ordered ? 'ol' : 'ul';
            $out[] = "<$tag>" . implode('', $items) . "</$tag>";
            continue;
        }
        if (str_starts_with($s, '>')) {
            $flush();
            $quote = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '>')) $quote[] = trim(substr(trim($lines[$i++]), 1));
            $out[] = '<blockquote><p>' . md_inline(implode(' ', $quote)) . '</p></blockquote>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', $s)) { $flush(); $out[] = '<hr>'; $i++; continue; }
        $para[] = $line;
        $i++;
    }
    $flush();
    return [implode("\n", $out), $toc];
}

// ---------------------------------------------------------------- страницы

/** Детали оформления из build.py: каркас страницы, форма заявки, FAQ, иконки, подписи. */
function parts(): array
{
    static $p;
    if ($p === null) {
        $p = json_decode((string) @file_get_contents(SITE_DIR . '/_blog/parts.json'), true);
        if (!is_array($p)) throw new RuntimeException('Нет _blog/parts.json — сайт собран не полностью');
    }
    return $p;
}

function ui(string $k): string { return parts()['ui'][$k]; }

function blog_path(): string { return parts()['blog_path']; }

function article_path(array $a): string { return blog_path() . $a['slug'] . '/'; }

function post_date(array $a, string $field = 'publish_at'): string
{
    $dt = parse_dt($a[$field] ?? '') ?? parse_dt($a['publish_at'] ?? '') ?? now();
    return $dt->format('Y-m-d');
}

function human_date(string $iso): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $iso));
    return $d . ' ' . MONTHS[$m - 1] . ' ' . $y;
}

function post_meta(array $a): string
{
    $parts = [human_date(post_date($a)), read_minutes($a) . ' ' . ui('read')];
    if ($a['category'] !== '') array_unshift($parts, $a['category']);
    return implode(' · ', array_map('e', $parts));
}

function eyebrow(string $num, string $text): string
{
    return '<p class="eyebrow"><span>' . $num . '</span>' . e($text) . '</p>';
}

function post_cards(array $posts): string
{
    $out = '';
    foreach ($posts as $a) {
        $out .= '<a class="post-card rv" href="' . article_path($a) . '">'
            . ($a['cover_path'] !== '' ? '<img class="post-img" src="' . e($a['cover_path']) . '" alt="" loading="lazy">' : '')
            . '<div class="post-body"><span class="post-meta">' . post_meta($a) . '</span>'
            . '<h2>' . e($a['title']) . '</h2><p>' . e($a['description'] ?: $a['lead']) . '</p>'
            . '<span class="tlink">' . e(ui('more')) . ' ' . parts()['icon_arrow_sm'] . '</span></div></a>';
    }
    return $out;
}

function faq_block(array $items, string $num): string
{
    $qs = '';
    foreach ($items as [$q, $ans]) {
        $qs .= strtr(parts()['faq_item'], ['%%Q%%' => e($q), '%%A%%' => e($ans)]);
    }
    return strtr(parts()['faq_wrap'], ['%%NUM%%' => $num, '%%ITEMS%%' => $qs]);
}

function lead_form(string $source): string
{
    return str_replace('%%SOURCE%%', e($source), parts()['lead_form']);
}

function json_ld($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
}

function breadcrumbs_schema(array $trail): array
{
    $site = parts()['site_url'];
    $items = [];
    foreach ($trail as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $site . $path];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

function faq_schema(array $items): array
{
    return ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($qa) => [
        '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
    ], $items)];
}

/** Каркас страницы (шапка, меню, подвал, мета-теги) из build.py с подставленными значениями. */
function page(string $path, string $title, string $desc, string $body, array $schema, string $og_type = 'website', ?string $image = null, bool $noindex = false): string
{
    $html = strtr(parts()['shell'], [
        '%%PATH%%' => $path, '%%TITLE%%' => e($title), '%%DESC%%' => e($desc), '%%OGTYPE%%' => $og_type,
        '%%IMAGE%%' => e($image ?: '/assets/og.png'), '%%BODY%%' => $body,
        '"__SCHEMA__"' => implode(', ', array_map('json_ld', $schema)),
    ]);
    if ($noindex) $html = str_replace('<head>', "<head>\n<meta name=\"robots\" content=\"noindex\">", $html);
    return $html;
}

function render_blog_index(array $posts): string
{
    $site = parts()['site_url'];
    $bp = blog_path();
    $body = '
<section class="hero hero-svc">
  <div class="wrap">
    <nav class="crumbs rv" aria-label="breadcrumbs"><a href="/">' . e(ui('breadcrumbs_home')) . '</a><span>/</span><span>' . e(ui('nav')) . '</span></nav>
    <h1 class="rv">' . e(ui('title')) . '</h1>
    <p class="hero-lead rv">' . e(ui('lead')) . '</p>
  </div>
</section>
<section class="sec sec-tight">
  <div class="wrap"><div class="post-grid">' . post_cards($posts) . '</div></div>
</section>
' . lead_form('blog') . '
';
    $schema = [
        ['@type' => 'Blog', '@id' => "$site$bp#blog", 'name' => ui('title'), 'url' => "$site$bp",
         'publisher' => ['@id' => "$site/#org"], 'inLanguage' => 'ru-KZ',
         'blogPost' => array_map(fn($a) => ['@type' => 'BlogPosting', 'headline' => $a['title'],
             'url' => $site . article_path($a), 'datePublished' => post_date($a)], $posts)],
        breadcrumbs_schema([[ui('breadcrumbs_home'), '/'], [ui('nav'), $bp]]),
    ];
    return page($bp, ui('meta_title'), ui('meta_desc'), $body, $schema);
}

/** Страница статьи. $posts — вышедшие статьи (для «Читайте также»). */
function render_article(array $a, array $posts, bool $noindex = false): string
{
    $site = parts()['site_url'];
    $bp = blog_path();
    $arrow = parts()['icon_arrow_sm'];
    $path = article_path($a);
    [$body_html, $toc] = markdown(auto_links($a['body_md'], article_links($a['slug'], $posts)));
    $by_slug = [];
    foreach ($posts as $p) if ($p['slug'] !== $a['slug']) $by_slug[$p['slug']] = $p;
    // «Читайте также»: выбранные вручную + свежие до трёх.
    $chosen = array_values(array_filter(array_map(fn($s) => $by_slug[$s] ?? null, $a['related'])));
    $rest = array_values(array_filter($by_slug, fn($p) => !in_array($p['slug'], $a['related'], true)));
    $related = array_merge($chosen, array_slice($rest, 0, max(0, 3 - count($chosen))));

    $toc_html = '';
    if (count($toc) >= 2) {
        $items = implode('', array_map(fn($t) => '<li><a href="#' . $t[0] . '">' . e($t[1]) . '</a></li>', $toc));
        $toc_html = '<nav class="art-toc" aria-label="' . e(ui('toc')) . '"><p class="eyebrow">' . e(ui('toc')) . '</p><ol>' . $items . '</ol></nav>';
    }
    $cover = $a['cover_path'] !== '' ? '<img class="art-cover" src="' . e($a['cover_path']) . '" alt="' . e($a['title']) . '" loading="lazy">' : '';
    $summary = $a['summary'] !== '' ? '<aside class="art-summary"><p class="eyebrow"><span>→</span>' . e(ui('summary')) . '</p><p>' . e($a['summary']) . '</p></aside>' : '';
    $author = $a['author'] !== '' ? ' · ' . e($a['author']) : '';
    // «обновлено» видно читателю и поисковикам: свежесть — один из сигналов для ИИ-ответов
    if (post_date($a, 'updated_at') > post_date($a)) $author .= ' · обновлено ' . e(human_date(post_date($a, 'updated_at')));
    $faq = $a['faq'] ? faq_block($a['faq'], '?') : '';
    $related_html = '';
    if ($related) {
        $related_html = '
<section class="sec sec-soft">
  <div class="wrap">
    <div class="sec-head rv">' . eyebrow('→', ui('related')) . '<h2>' . e(ui('related')) . '</h2></div>
    <div class="post-grid">' . post_cards($related) . '</div>
    <p class="post-all"><a class="tlink" href="' . $bp . '">' . e(ui('all')) . ' ' . $arrow . '</a></p>
  </div>
</section>';
    }
    $lead = $a['lead'] !== '' ? '<p class="hero-lead rv">' . e($a['lead']) . '</p>' : '';
    $body = '
<section class="hero hero-svc hero-art">
  <div class="wrap">
    <nav class="crumbs rv" aria-label="breadcrumbs"><a href="/">' . e(ui('breadcrumbs_home')) . '</a><span>/</span><a href="' . $bp . '">' . e(ui('nav')) . '</a></nav>
    <p class="post-meta rv">' . post_meta($a) . $author . '</p>
    <h1 class="rv">' . e($a['title']) . '</h1>
    ' . $lead . '
  </div>
</section>
<section class="sec sec-tight">
  <div class="wrap art' . ($toc_html ? ' art-has-toc' : '') . '">
    ' . $toc_html . '
    <article class="art-body">
      ' . $cover . '
      ' . $summary . '
      <div class="md">' . $body_html . '</div>
    </article>
  </div>
</section>
' . $faq . '
' . $related_html . '
' . lead_form('blog:' . $a['slug']) . '
';
    $image = $a['cover_path'] ?: null;
    $posting = [
        '@type' => 'BlogPosting', 'headline' => $a['title'], 'description' => $a['description'],
        'datePublished' => post_date($a), 'dateModified' => post_date($a, 'updated_at'), 'inLanguage' => 'ru-KZ',
        'mainEntityOfPage' => $site . $path, 'image' => $site . ($image ?: '/assets/og.png'),
        'author' => $a['author'] !== '' ? ['@type' => 'Person', 'name' => $a['author']] : ['@id' => "$site/#org"],
        'publisher' => ['@id' => "$site/#org"],
    ];
    if ($a['keywords']) $posting['keywords'] = implode(', ', $a['keywords']);
    if ($a['category'] !== '') $posting['articleSection'] = $a['category'];
    $schema = [$posting, breadcrumbs_schema([[ui('breadcrumbs_home'), '/'], [ui('nav'), $bp], [$a['title'], $path]])];
    if ($a['faq']) $schema[] = faq_schema($a['faq']);
    return page($path, $a['seo_title'] ?: $a['title'] . ' | ' . SITE_BRAND, $a['description'] ?: $a['lead'], $body, $schema, 'article', $image, $noindex);
}

// Автоссылки между статьями (подбирает SEO-автопилот, seo.php): текст статьи не меняется, ссылка ставится
// при показе на первую подходящую фразу обычного абзаца — только на вышедшие статьи.
function links_file(): string { return DATA_DIR . '/seo/links.json'; }

function article_links(string $slug, array $posts): array
{
    static $map;
    $map ??= read_json(links_file()) ?? [];
    $live = array_column($posts, 'slug');
    return array_values(array_filter($map[$slug]['links'] ?? [], fn($l) => in_array($l['to'] ?? '', $live, true)));
}

function auto_links(string $md, array $links): string
{
    $lines = explode("\n", $md);
    foreach ($links as $l) {
        $href = blog_path() . $l['to'] . '/';
        $anchor = trim((string) $l['anchor']);
        $all = implode("\n", $lines);
        if ($anchor === '' || preg_match('/[\[\]*`_|#]/u', $anchor) || str_contains($all, "]($href") || str_contains($all, SITE_HOST . $href)) continue;
        $re = '/(?<![\p{L}\d])' . preg_quote($anchor, '/') . '(?![\p{L}\d])/iu';
        foreach ($lines as $k => $line) {
            if (preg_match('/^\s*(#|\||!\[|$)/', $line) || !preg_match_all($re, $line, $m, PREG_OFFSET_CAPTURE)) continue;
            preg_match_all('/\[[^\]]*\]\([^)]*\)|`[^`]*`|https?:\/\/\S+/u', $line, $busy, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$text, $pos]) {
                $end = $pos + strlen($text);
                foreach ($busy[0] as [$b, $bp]) if ($pos < $bp + strlen($b) && $end > $bp) continue 2;
                $lines[$k] = substr_replace($line, "[$text]($href)", $pos, strlen($text));
                continue 3;
            }
        }
    }
    return implode("\n", $lines);
}

/** Блок «Свежие статьи» на главной: build.py оставляет в index.html метку <!--BLOG-HOME:NN-->. */
function render_home(string $html, array $posts): string
{
    return preg_replace_callback('/<!--BLOG-HOME:([^>]*)-->/', function ($m) use ($posts) {
        if (!$posts) return '';
        return '
<section class="sec" id="blog">
  <div class="wrap">
    <div class="sec-head rv">' . eyebrow($m[1], ui('nav')) . '<h2>' . e(ui('home_title')) . '</h2><p>' . e(ui('lead')) . '</p></div>
    <div class="post-grid">' . post_cards(array_slice($posts, 0, 3)) . '</div>
    <p class="rv" style="margin:32px 0 0"><a class="tlink" href="' . blog_path() . '">' . e(ui('all')) . ' ' . parts()['icon_arrow_sm'] . '</a></p>
  </div>
</section>';
    }, $html, 1);
}

/** sitemap.xml: страницы сайта из build.py + блог. */
function render_sitemap(string $base, array $posts): string
{
    if (!$posts) return $base;
    $site = parts()['site_url'];
    $rows = [];
    $dates = array_map(fn($a) => post_date($a, 'updated_at'), $posts);
    $rows[] = '<url><loc>' . $site . blog_path() . '</loc><lastmod>' . max($dates) . '</lastmod></url>';
    foreach ($posts as $i => $a) {
        $rows[] = '<url><loc>' . $site . article_path($a) . '</loc><lastmod>' . $dates[$i] . '</lastmod></url>';
    }
    return str_replace('</urlset>', implode("\n", $rows) . "\n</urlset>", $base);
}


// ---------------------------------------------------------------- для нейросетей и поисковиков

/** /llms.txt: справка о сайте из build.py + список вышедших статей. */
function render_llms(string $tpl, array $posts): string
{
    $site = parts()['site_url'];
    $list = $posts
        ? implode("\n", array_map(fn($a) => '- [' . $a['title'] . '](' . $site . article_path($a) . ')' . ($a['description'] !== '' ? ': ' . $a['description'] : ''), $posts))
        : 'Статей пока нет.';
    return str_replace('%%ARTICLES%%', $list, $tpl);
}

const INDEXNOW_KEY = SITE_INDEXNOW_KEY;  // из build.py (файл /<ключ>.txt)

/**
 * IndexNow: сообщает Bing (на нём поиск ChatGPT и Copilot) и Яндексу о новой или изменённой статье,
 * чтобы её проиндексировали за часы, а не недели. Каждую версию статьи — один раз.
 */
function indexnow_article(array $a, ?callable $send = null): bool
{
    if (!is_live($a)) return false;
    $site = parts()['site_url'];
    $url = $site . article_path($a);
    $file = DATA_DIR . '/indexnow.json';
    $sent = read_json($file) ?? [];
    if (($sent[$url] ?? '') === $a['updated_at']) return false;
    $sent[$url] = $a['updated_at'];
    write_json($file, $sent);
    $body = json_encode(['host' => parse_url($site, PHP_URL_HOST), 'key' => INDEXNOW_KEY,
        'keyLocation' => "$site/" . INDEXNOW_KEY . '.txt', 'urlList' => [$url, $site . blog_path(), "$site/"]], JSON_UNESCAPED_SLASHES);
    if ($send) return $send($body);
    if (getenv('ALFIMOV_OFFLINE')) return false;   // тесты
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $code >= 200 && $code < 300;
}

// ---------------------------------------------------------------- картинки из админки

const MAX_PIXELS = 25_000_000;   // ~5000×5000: столько GD распакует в пределах памяти PHP (memory_limit 256M)
const MAX_SIDE = 12000;

/** Размеры картинки — до распаковки: маленький файл может распаковаться в гигабайты (картинка-бомба). */
function image_size_ok(string $raw): void
{
    $info = @getimagesizefromstring($raw);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        throw new InvalidArgumentException('Не удалось прочитать картинку — нужен jpg, png или webp');
    }
    if ($info[0] > MAX_SIDE || $info[1] > MAX_SIDE || $info[0] * $info[1] > MAX_PIXELS) {
        throw new InvalidArgumentException("Картинка слишком большая ({$info[0]}×{$info[1]}) — уменьшите до 5000×5000 пикселей");
    }
}
