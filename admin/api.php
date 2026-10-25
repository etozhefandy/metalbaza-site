<?php
// API админки (/admin/api/*, /admin/preview/<slug>/). Только для вошедших.
//
// Долгие операции (статья ИИ 1–3 мин, картинка до минуты, идеи тем) — задачи: браузер создаёт
// задачу, отдельным запросом запускает её (/run) и опрашивает статус (/job). Если связь
// с телефона оборвётся, задача доработает на хостинге, а результат заберётся при опросе.
declare(strict_types=1);
require dirname(__DIR__) . '/_blog/auth.php';
require_once dirname(__DIR__) . '/_blog/seo.php';
require_once dirname(__DIR__) . '/_blog/leads.php';
require_once dirname(__DIR__) . '/_blog/queue.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const MAX_UPLOAD = 20 * 1024 * 1024;
const MAX_BODY = 64 * 1024 * 1024;     // весь запрос (несколько скриншотов в base64) — больше не читаем

const MEDIA_MAX_W = 1800;

function reply($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): void { reply(['error' => $msg], $code); }

function body(): array
{
    $raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if (strlen($raw) > MAX_BODY) fail('Слишком большой запрос — уменьшите картинки или отправьте их по одной', 413);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function article_view(array $a): array
{
    return $a + ['state' => state($a), 'path' => article_path($a), 'read_minutes' => read_minutes($a)];
}

function mask(string $key): string { return $key === '' ? '' : '…' . substr($key, -4); }

// ---------------------------------------------------------------- картинки

function media_dir(): string { return DATA_DIR . '/media'; }

function media_name(string $base, string $ext): string
{
    $base = substr(slugify($base), 0, 60) ?: 'image';
    return $base . '-' . bin2hex(random_bytes(3)) . ".$ext";
}

/** Картинка с телефона: уменьшаем до MEDIA_MAX_W по ширине и сохраняем в webp. */
function store_upload(string $raw, string $stem): string
{
    image_size_ok($raw);
    $img = @imagecreatefromstring($raw);
    if (!$img) throw new InvalidArgumentException('Не удалось прочитать картинку — нужен jpg, png или webp');
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w > MEDIA_MAX_W) {
        $img = imagescale($img, MEDIA_MAX_W, (int) round($h * MEDIA_MAX_W / $w), IMG_BICUBIC);
    }
    $name = media_name($stem, 'webp');
    if (!is_dir(media_dir())) mkdir(media_dir(), 0750, true);
    if (!imagewebp($img, media_dir() . "/$name", 82)) throw new RuntimeException('Не удалось сохранить картинку');
    return "/blog/media/$name";
}

// ---------------------------------------------------------------- задачи

function job_file(string $id): string { return DATA_DIR . "/jobs/$id.json"; }

function new_job(string $type, array $input): array
{
    $id = bin2hex(random_bytes(8));
    $job = ['id' => $id, 'type' => $type, 'input' => $input, 'status' => 'queued', 'created' => time()];
    write_json(job_file($id), $job);
    foreach (glob(DATA_DIR . '/jobs/*') ?: [] as $f) {  // задачи и скриншоты старше суток — убрать
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
    return ['job' => $id];
}

function get_job(string $id): ?array
{
    return preg_match('/^[a-f0-9]{16}$/', $id) ? read_json(job_file($id)) : null;
}

function execute(array $job): array
{
    $in = $job['input'];
    switch ($job['type']) {
        case 'generate':
            $lang = in_array($in['lang'] ?? '', LANGS, true) ? $in['lang'] : 'ru';
            $existing = array_map(fn($a) => ['slug' => $a['slug'], 'title' => $a['title']],
                array_filter(load_all(), fn($a) => ($a['lang'] ?? 'ru') === $lang));
            return ['article' => write_article((string) $in['topic'], $in['keywords'], (string) $in['notes'], (int) $in['words'], $existing,
                null, job_source($in['source'] ?? null), $lang)];
        case 'image':
            $img = generate_image($in['kind'], (string) $in['title'], (string) $in['lead'], (string) $in['idea'], (string) $in['mode'],
                ['summary' => (string) ($in['summary'] ?? ''), 'body' => (string) ($in['body'] ?? '')]);
            $name = media_name(($in['slug'] ?: $in['title'] ?: 'image') . '-' . ($in['kind'] === 'cover' ? 'cover' : 'img'), 'webp');
            if (!is_dir(media_dir())) mkdir(media_dir(), 0750, true);
            write_file(media_dir() . "/$name", $img['bin']);
            return ['path' => "/blog/media/$name", 'main_idea' => $img['main_idea'], 'scene' => $img['scene']];
        case 'trends':
            return ideas_with_plan(niche_trends((string) $in['vector']), (int) $in['per_week']);
        case 'tune':
            return tune_articles($in['queries'], $in['pages']);
        case 'ideas':
            return ideas_with_plan(propose_ideas((string) $in['focus']), (int) $in['per_week']);
    }
    throw new RuntimeException('Неизвестная задача');
}


/** Скриншот источника → jpeg до 1568 px (больше Claude всё равно уменьшит) во временной папке задач. */
function store_source_image(string $raw): string
{
    image_size_ok($raw);
    $img = @imagecreatefromstring($raw);
    if (!$img) throw new InvalidArgumentException('Скриншот не читается — нужен jpg, png или webp');
    $w = imagesx($img);
    $h = imagesy($img);
    $k = min(1, 1568 / max($w, $h));
    if ($k < 1) $img = imagescale($img, (int) round($w * $k), (int) round($h * $k), IMG_BICUBIC);
    if (!is_dir(DATA_DIR . '/jobs')) mkdir(DATA_DIR . '/jobs', 0750, true);
    $file = DATA_DIR . '/jobs/src-' . bin2hex(random_bytes(6)) . '.jpg';
    if (!imagejpeg($img, $file, 85)) throw new RuntimeException('Не удалось сохранить скриншот');
    return $file;
}

/** Запускает задачу один раз (повторный /run того же id ничего не делает). */
function run_job(string $id): array
{
    $job = with_lock('jobs', function () use ($id) {
        $job = get_job($id);
        if (!$job || $job['status'] !== 'queued') return $job;
        $job['status'] = 'running';
        $job['started'] = time();
        write_json(job_file($id), $job);
        $job['_mine'] = true;
        return $job;
    });
    if (!$job) fail('Задача не найдена', 404);
    if (empty($job['_mine'])) return $job;
    unset($job['_mine']);
    ignore_user_abort(true);
    @set_time_limit(600);
    try {
        $job['result'] = execute($job);
        $job['status'] = 'done';
    } catch (AiError | InvalidArgumentException $ex) {
        $job['status'] = 'error';
        $job['error'] = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('admin job: ' . $ex);
        $job['status'] = 'error';
        $job['error'] = 'Ошибка на сервере: ' . $ex->getMessage();
    }
    $job['finished'] = time();
    write_json(job_file($id), $job);
    return $job;
}

function job_view(?array $job): array
{
    if (!$job) fail('Задача не найдена', 404);
    // Запуск оборвался (хостинг убил процесс) — честно сказать, а не крутить вечно.
    if ($job['status'] === 'running' && time() - ($job['started'] ?? time()) > 660) {
        $job['status'] = 'error';
        $job['error'] = 'Задача прервалась на сервере — попробуйте ещё раз';
    }
    return ['status' => $job['status'], 'result' => $job['result'] ?? null, 'error' => $job['error'] ?? null];
}

/** Вход «написать статью» из админки: тема, ключи, пожелания, объём, источник (ссылка, текст, скриншоты). */
function generate_input(array $d): array
{
    $source = null;
    $s_url = trim((string) ($d['source_url'] ?? ''));
    $s_text = trim((string) ($d['source_text'] ?? ''));
    $shots = array_slice(is_array($d['source_images'] ?? null) ? $d['source_images'] : [], 0, 6);
    if ($s_url !== '' || $s_text !== '' || $shots) {
        if ($s_url !== '' && !preg_match('#^https?://#i', $s_url)) fail('Ссылка должна начинаться с http:// или https://');
        $files = [];
        foreach ($shots as $b64) {
            $raw = base64_decode((string) $b64, true);
            if (!$raw || strlen($raw) > MAX_UPLOAD) fail('Скриншот пустой или больше 20 МБ');
            $files[] = store_source_image($raw);
        }
        $source = ['url' => $s_url, 'text' => mb_substr($s_text, 0, 20000), 'images' => $files];
    }
    if (trim((string) ($d['topic'] ?? '')) === '' && !$source && empty($d['auto'])) fail('Впишите тему статьи или добавьте источник');
    return [
        'source' => $source,
        'topic' => (string) ($d['topic'] ?? ''),
        'topic_key' => (string) ($d['topic_key'] ?? ''),
        'keywords' => is_array($d['keywords'] ?? null) ? $d['keywords'] : preg_split('/[\n,;]+/u', (string) ($d['keywords'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
        'notes' => (string) ($d['notes'] ?? ''),
        'words' => max(500, min(4000, (int) ($d['words'] ?? 1500) ?: 1500)),
        'model' => (string) ($d['model'] ?? ''),
        'lang' => in_array($d['lang'] ?? '', LANGS, true) ? $d['lang'] : 'ru',
    ];
}

// ---------------------------------------------------------------- маршруты

if (!is_configured() || !current_session()) fail('Нужно войти в админку', 401);

$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string) ($_GET['r'] ?? ''), '/');

// Предпросмотр — обычная ссылка (новая вкладка), без заголовка X-Admin.
if ($method === 'GET' && preg_match('#^preview/([a-z0-9-]+)$#', $route, $m)) {
    $a = get_article($m[1]);
    if (!$a) fail('Статья не найдена', 404);
    $posts = array_merge(array_values(array_filter(live_articles(), fn($p) => $p['slug'] !== $a['slug'])), [$a]);
    header('Content-Type: text/html; charset=utf-8');
    echo render_article($a, $posts, true);
    exit;
}
if ($method === 'GET' && $route === 'api/export') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . SITE_DATA . '-blog-' . now()->format('Y-m-d') . '.json"');
    echo json_encode(['exported' => now()->format('c'), 'articles' => load_all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Журнал заявок — CSV для расчётов с покупателями заявок (обычная ссылка на скачивание).
if ($method === 'GET' && preg_match('#^api/leads\.csv$#', $route)) {
    $month = (string) ($_GET['m'] ?? '') ?: now()->format('Y-m');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . preg_replace('/[^0-9-]/', '', $month) . '.csv"');
    echo leads_csv(leads_list($month));
    exit;
}

// Остальное — только fetch из самой админки: свой заголовок с чужого сайта без CORS не отправить.
if (($_SERVER['HTTP_X_ADMIN'] ?? '') !== '1') fail('forbidden', 403);

try {
    // очередь написания: кнопка «Написать», галочки у тем, автопилот (см. _blog/queue.php)
    if ($method === 'GET' && $route === 'api/queue') { queue_maybe_kick(); reply(queue_view()); }
    if ($method === 'POST' && $route === 'api/queue') {
        $item = queue_add(generate_input(body()) + ['origin' => 'manual']);
        queue_fire_free();
        reply(['item' => $item] + queue_view());
    }
    if ($method === 'POST' && $route === 'api/queue/topics') {
        $d = body();
        $want = array_map('strval', (array) ($d['ids'] ?? []));
        $n = 0;
        foreach (topics_load() as $t) {
            if (!in_array($t['id'], $want, true)) continue;
            queue_add(['topic' => $t['topic'], 'topic_key' => topic_key($t), 'origin' => 'bulk',
                'words' => (int) ($d['words'] ?? 1500), 'model' => (string) ($d['model'] ?? ''),
                'keywords' => array_values(array_unique(array_filter([(string) $t['main_keyword'], ...array_map('strval', $t['keywords'] ?? [])])))]);
            $n++;
        }
        if (!$n) fail('Отметьте темы галочками');
        queue_fire_free();
        reply(['added' => $n] + queue_view());
    }
    if ($method === 'POST' && preg_match('#^api/queue/([0-9a-f]{16})/cancel$#', $route, $m)) {
        if (!queue_cancel($m[1])) fail('Эту статью уже не отменить');
        reply(queue_view());
    }
    if ($method === 'POST' && $route === 'api/autopilot') {
        $d = body();
        $s = settings();
        if (array_key_exists('on', $d)) $s['autopost'] = (bool) $d['on'];
        if (isset($d['per_week']) && slot_plan((int) $d['per_week']) !== null) $s['per_week'] = (int) $d['per_week'];
        if (isset($d['image_mode']) && in_array($d['image_mode'], IMG_MODES, true)) $s['image_mode'] = $d['image_mode'];
        if (isset($d['buffer_target'])) {
            $t = (int) $d['buffer_target'];
            if ($t < BUFFER_TARGET_MIN || $t > BUFFER_TARGET_MAX) fail("Запас — от " . BUFFER_TARGET_MIN . " до " . BUFFER_TARGET_MAX . " статей");
            $s['buffer_target'] = $t;
        }
        if (isset($d['kk_ratio'])) {
            $k = (int) $d['kk_ratio'];
            if ($k < 0 || $k > KK_RATIO_MAX) fail("Доля на казахском — от 0 до " . KK_RATIO_MAX . "%");
            $s['kk_ratio'] = $k;
        }
        save_settings($s);
        if (!empty($d['on'])) { $q = queue_state(); $q['checked'] = 0; queue_save($q); queue_maybe_kick(); }
        reply(queue_view());
    }
    if ($method === 'GET' && $route === 'api/leads') {
        $months = lead_months();
        $month = (string) ($_GET['m'] ?? '') ?: ($months[0] ?? now()->format('Y-m'));
        reply(['months' => $months, 'month' => $month, 'leads' => leads_list($month)]);
    }
    if ($method === 'GET' && $route === 'api/articles') {
        reply(['articles' => array_map('article_view', load_all()), 'now' => now()->format('Y-m-d\TH:i'),
            'writer_model' => claude_model(), 'models' => CLAUDE_MODELS]);
    }
    if ($method === 'POST' && $route === 'api/articles') {
        $d = body();
        $a = save_article(is_array($d['article'] ?? null) ? $d['article'] : [], ($d['old_slug'] ?? null) ?: null);
        try { indexnow_article($a); } catch (Throwable $ex) { error_log('indexnow: ' . $ex->getMessage()); }
        reply(['article' => article_view($a)]);
    }
    if ($method === 'DELETE' && preg_match('#^api/articles/([a-z0-9-]+)$#', $route, $m)) {
        if (!delete_article($m[1])) fail('Статья не найдена', 404);
        reply(['ok' => true]);
    }
    if ($method === 'POST' && $route === 'api/generate') reply(new_job('generate', generate_input(body())));
    if ($method === 'POST' && $route === 'api/image') {
        $d = body();
        reply(new_job('image', [
            'kind' => ($d['kind'] ?? '') === 'inline' ? 'inline' : 'cover',
            'title' => (string) ($d['title'] ?? ''), 'lead' => (string) ($d['lead'] ?? ''), 'idea' => (string) ($d['idea'] ?? ''),
            'slug' => (string) ($d['slug'] ?? ''), 'mode' => in_array($d['mode'] ?? '', IMG_MODES, true) ? $d['mode'] : 'auto',
            'summary' => mb_substr((string) ($d['summary'] ?? ''), 0, 1000), 'body' => mb_substr((string) ($d['body'] ?? ''), 0, 6000),
        ]));
    }
    if ($method === 'POST' && $route === 'api/ideas') {
        $d = body();
        reply(new_job('ideas', ['focus' => trim((string) ($d['focus'] ?? '')), 'per_week' => (int) ($_GET['per_week'] ?? 2)]));
    }
    if ($method === 'POST' && $route === 'api/trends') {
        $d = body();
        $vector = trim((string) ($d['vector'] ?? ''));
        if (mb_strlen($vector) < 3) fail('Опишите нишу или идею, например: «продвижение мебельного бизнеса»');
        reply(new_job('trends', ['vector' => mb_substr($vector, 0, 300), 'per_week' => (int) ($_GET['per_week'] ?? 2)]));
    }
    if ($method === 'GET' && $route === 'api/trends') {
        reply(ideas_with_plan(read_json(DATA_DIR . '/trends.json'), (int) ($_GET['per_week'] ?? 2)));
    }
    if ($method === 'POST' && $route === 'api/tune') {
        $d = body();
        $rows = fn($x) => array_slice(array_values(array_filter(array_map(fn($r) => is_array($r) ? [mb_substr((string) ($r[0] ?? ''), 0, 300), (float) ($r[1] ?? 0), (float) ($r[2] ?? 0)] : null,
            is_array($x) ? $x : []))), 0, 1000);
        $q = $rows($d['queries'] ?? []);
        if (!$q) fail('В файле не нашлось запросов — загрузите выгрузку «Эффективность» из Search Console');
        reply(new_job('tune', ['queries' => $q, 'pages' => $rows($d['pages'] ?? [])]));
    }
    if ($method === 'GET' && $route === 'api/tune') {
        reply(read_json(DATA_DIR . '/tune.json') ?? ['articles' => []]);
    }
    if ($method === 'POST' && $route === 'api/tune/apply') {
        $d = body();
        $a = apply_tune((string) ($d['slug'] ?? ''), is_array($d['changes'] ?? null) ? $d['changes'] : []);
        tune_mark_applied($a['slug']);
        try { indexnow_article($a); } catch (Throwable $ex) { error_log('indexnow: ' . $ex->getMessage()); }
        reply(['article' => article_view($a)]);
    }
    if ($method === 'GET' && $route === 'api/seo') {
        try { seo_maybe_kick(); } catch (Throwable $ex) { error_log('seo kick: ' . $ex->getMessage()); }
        reply(seo_view());
    }
    if ($method === 'POST' && $route === 'api/seo/run') {
        if (!seo_start()) fail('Проверка уже идёт — результат придёт в Telegram и появится здесь');
        reply(seo_view());
    }
    if ($method === 'POST' && $route === 'api/seo/settings') {
        $d = body();
        $s = settings();
        $msg = 'Сохранено';
        if (($d['gsc_key'] ?? '') !== '') {
            $s['gsc_key'] = gsc_parse_key((string) $d['gsc_key']);
            @unlink(seo_dir() . '/gsc-token.json');
        }
        if (!empty($d['gsc_remove'])) unset($s['gsc_key']);
        if (array_key_exists('geo_prompts', $d)) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $d['geo_prompts'])), 'strlen'));
            $s['geo_prompts'] = array_slice(array_map(fn($l) => mb_substr($l, 0, 300), $lines), 0, 12);
        }
        if (array_key_exists('auto_apply', $d)) $s['seo_auto_apply'] = (bool) $d['auto_apply'];
        save_settings($s);
        if (($d['gsc_key'] ?? '') !== '') {
            try { $msg = 'Search Console подключена: ' . gsc_check(); }
            catch (AiError $ex) { $msg = 'Ключ сохранён. ' . $ex->getMessage(); }
        }
        reply(['message' => $msg] + seo_view());
    }
    if ($method === 'POST' && $route === 'api/seo/gsc-check') {
        try { reply(['message' => 'Доступ есть: ' . gsc_check()]); } catch (AiError $ex) { fail($ex->getMessage()); }
    }
    if ($method === 'GET' && $route === 'api/next-slot') {
        reply(['slot' => next_free_slot((int) ($_GET['per_week'] ?? 2), (string) ($_GET['slug'] ?? ''))]);
    }
    if ($method === 'GET' && $route === 'api/topics') {
        reply(topics_view((int) ($_GET['per_week'] ?? 2)));
    }
    if ($method === 'POST' && $route === 'api/topics/dedupe') {
        reply(['removed' => topics_dedupe()] + topics_view((int) ($_GET['per_week'] ?? 2)));
    }
    if ($method === 'POST' && preg_match('#^api/topics/([a-f0-9]{12})$#', $route, $m)) {
        if (!topics_set_status($m[1], (string) (body()['status'] ?? ''))) fail('Тема не найдена', 404);
        reply(['ok' => true]);
    }
    if ($method === 'GET' && $route === 'api/ideas') {
        reply(ideas_with_plan(read_json(DATA_DIR . '/ideas.json'), (int) ($_GET['per_week'] ?? 2)));
    }
    if ($method === 'POST' && $route === 'api/run') {
        reply(job_view(run_job((string) (body()['job'] ?? ''))));
    }
    if ($method === 'GET' && $route === 'api/job') {
        reply(job_view(get_job((string) ($_GET['id'] ?? ''))));
    }
    if ($method === 'POST' && $route === 'api/upload') {
        $d = body();
        $raw = base64_decode((string) ($d['data'] ?? ''), true);
        if (!$raw || strlen($raw) > MAX_UPLOAD) fail('Файл пустой или больше 20 МБ');
        reply(['path' => store_upload($raw, pathinfo((string) ($d['filename'] ?? 'image'), PATHINFO_FILENAME))]);
    }
    if ($method === 'GET' && $route === 'api/settings') {
        $s = settings();
        reply(['login' => $s['login'] ?? '', 'anthropic_key' => mask((string) ($s['anthropic_key'] ?? '')),
            'openai_key' => mask((string) ($s['openai_key'] ?? '')), 'writer_model' => claude_model(), 'image_model' => IMAGE_MODEL,
            'models' => CLAUDE_MODELS,
            'spend_link' => ($l = (string) ($s['spend_link'] ?? '')) === '' ? '' : (parse_url($l, PHP_URL_HOST) . ' · ключ ' . mask($l))]);
    }
    if ($method === 'POST' && $route === 'api/settings') {
        $d = body();
        $s = settings();
        $changed = [];
        foreach (['anthropic_key' => 'sk-ant-', 'openai_key' => 'sk-'] as $field => $prefix) {
            $v = preg_replace('/\s+/', '', (string) ($d[$field] ?? ''));
            if ($v === '') continue;
            if (!str_starts_with($v, $prefix) || strlen($v) < 20) fail($field === 'anthropic_key' ? 'Ключ Claude начинается с sk-ant-' : 'Ключ OpenAI начинается с sk-');
            $s[$field] = $v;
            $changed[] = $field === 'anthropic_key' ? 'ключ Claude' : 'ключ OpenAI';
        }
        if (isset($d['claude_model'])) {
            $d['claude_model'] = (string) $d['claude_model'];
            if (!isset(CLAUDE_MODELS[$d['claude_model']])) fail('Неизвестная модель');
            if (claude_model() !== $d['claude_model']) $changed[] = 'модель: ' . explode(' —', CLAUDE_MODELS[$d['claude_model']])[0];
            $s['claude_model'] = $d['claude_model'];
        }
        // 🧠💰 строка подключения учёта трат Claude из бота (report_ai_spend); «off» — отключить
        $link = preg_replace('/\s+/', '', (string) ($d['spend_link'] ?? ''));
        if ($link === 'off') {
            unset($s['spend_link']);
            $changed[] = 'учёт трат Claude отключён';
        } elseif ($link !== '') {
            if (!preg_match(SPEND_LINK_RE, $link)) fail('Строка учёта трат — из бота: «💰 Балансы API» → Claude → «🔌 Подключить проект»');
            $s['spend_link'] = $link;
            $changed[] = 'учёт трат Claude';
        }
        if (($d['password_new'] ?? '') !== '') {
            if (!password_verify((string) ($d['password_current'] ?? ''), (string) $s['password_hash'])) fail('Текущий пароль неверный');
            if ($p = password_problem((string) $d['password_new'])) fail($p);
            $s['password_hash'] = password_hash((string) $d['password_new'], PASSWORD_DEFAULT);
            $changed[] = 'пароль';
        }
        save_settings($s);
        if (in_array('пароль', $changed, true)) {
            drop_all_sessions();
            start_session();
        }
        reply(['ok' => true, 'changed' => $changed]);
    }
    if ($method === 'GET' && $route === 'api/telegram') {
        $st = tg_state();
        reply(['ready' => tg_owner() !== '' && !empty(tg_cfg()['tg_token']), 'group' => !empty($st['lead_chat']),
            'title' => (string) ($st['lead_title'] ?? '')]);
    }
    if ($method === 'POST' && $route === 'api/telegram/pin') {
        if (tg_owner() === '' || empty(tg_cfg()['tg_token'])) fail('Бот не настроен на хостинге (нет tg-config.php)');
        if (!tg_ensure_webhook()) fail('Telegram не принял настройку бота — попробуйте ещё раз');
        if (!tg_issue_pin()) fail('Не удалось написать вам в Telegram — откройте бота и нажмите «Старт»');
        reply(['ok' => true]);
    }
    if ($method === 'POST' && $route === 'api/telegram/unlink') {
        tg_unlink();
        reply(['ok' => true]);
    }
    if ($method === 'POST' && $route === 'api/logout') {
        end_session();
        reply(['ok' => true]);
    }
    fail('not found', 404);
} catch (InvalidArgumentException $ex) {
    fail($ex->getMessage());
} catch (Throwable $ex) {
    error_log('admin api: ' . $ex);
    fail('Ошибка на сервере: ' . $ex->getMessage(), 500);
}
