<?php
// Очередь написания статей — одна для всего: кнопка «Написать» в админке, галочки у нескольких тем и автопилот.
//
// Статья в очереди — три задачи: тема (только если тему выбирает автопилот), текст (Claude) и обложка (OpenAI).
// Обложка рисуется сразу по теме, одновременно с текстом, — так статья готова быстрее. Когда текст и обложка
// готовы, статья сама встаёт в план на ближайший свободный день ритма (10:00) — до выхода её можно поправить.
//
// Работа идёт на хостинге отдельными запросами (/api/queue-tick.php с секретом), браузер можно закрыть: каждый
// запрос делает одну задачу и запускает следующие. Одновременно — один текст и одна обложка. Оборванная задача
// повторяется (2 попытки). Cron не нужен: заходы на сайт и админка «пинают» очередь.
//
// Автопилот — тумблер поверх очереди: держит запас готовых статей (текст + обложка, ждут своего дня по ритму
// выхода). Запас — не «подливаем по одной на каждый чих»: пока его хватает (выше низкой отметки, ¼ от цели),
// автопилот молчит; просел до низкой — пишет пачкой, пока не наберёт полный запас снова (но не быстрее
// AUTOPILOT_MAX_PER_DAY в день — это и есть «подстраивается под темп», большой недобор не пишется за один присест).
// Ритм выхода (per_week) этим не затрагивается: он только расставляет готовые статьи по дням, запас может
// растянуться на много недель вперёд.
declare(strict_types=1);
require_once __DIR__ . '/ai.php';
require_once dirname(__DIR__) . '/api/_tg.php';

const QUEUE_LEASE = 330;            // задача дольше этого — оборвана хостингом (PHP-лимит 300 с)
const QUEUE_KICK_EVERY = 20;        // не чаще — повторный пинок очереди
const AUTOPILOT_CHECK_EVERY = 600;  // как часто заходы на сайт проверяют план для автопилота
const AUTOPILOT_MAX_PER_DAY = 3;    // страховка от расходов при сбоях
const BUFFER_TARGET_DEFAULT = 12;   // сколько готовых статей держать про запас, если не меняли в настройках
const BUFFER_TARGET_MIN = 3;
const BUFFER_TARGET_MAX = 60;
const QUEUE_KEEP = 30;              // сколько готовых записей помнить для админки

function queue_file(): string { return DATA_DIR . '/queue.json'; }
function queue_dir(): string { return DATA_DIR . '/queue'; }

function queue_state(): array { return read_json(queue_file()) ?? []; }

function queue_save(array $s): void { write_json(queue_file(), $s); @chmod(queue_file(), 0600); }

function queue_locked(callable $fn)
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $f = fopen(DATA_DIR . '/.queue.lock', 'c');
    flock($f, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/** Настройки из админки: автопилот (по умолчанию выключен), ритм выхода, сюжет обложки (по умолчанию — с людьми),
 *  запас готовых статей (по умолчанию 12). */
function queue_settings(): array
{
    $s = settings();
    $week = (int) ($s['per_week'] ?? 2);
    $mode = (string) ($s['image_mode'] ?? 'people');
    $target = (int) ($s['buffer_target'] ?? BUFFER_TARGET_DEFAULT);
    return ['autopilot' => !empty($s['autopost']), 'per_week' => isset(SLOT_DAYS[$week]) ? $week : 2,
        'image_mode' => in_array($mode, IMG_MODES, true) ? $mode : 'people',
        'buffer_target' => $target >= BUFFER_TARGET_MIN && $target <= BUFFER_TARGET_MAX ? $target : BUFFER_TARGET_DEFAULT,
        'ready' => trim((string) ($s['anthropic_key'] ?? '')) !== '' && trim((string) ($s['openai_key'] ?? '')) !== ''];
}

// ---------------------------------------------------------------- добавить в очередь

/**
 * $spec: topic, keywords[], notes, words, source (как в admin/api.php: url, text, images — пути файлов), origin
 * (manual | bulk | auto), topic_key (ключ темы из списка). Без темы и источника — тему выберет задача «тема».
 */
function queue_add(array $spec): array
{
    $id = now()->format('ymdHis') . bin2hex(random_bytes(2));
    $topic = trim((string) ($spec['topic'] ?? ''));
    $source = $spec['source'] ?? null;
    if ($source) {
        if (!is_dir(queue_dir())) mkdir(queue_dir(), 0750, true);
        write_json(queue_dir() . "/$id-source.json", $source);
    }
    $todo = ['state' => 'todo', 'tries' => 0, 'touched' => 0];
    $item = [
        'id' => $id, 'created' => now()->format('Y-m-d H:i'), 'origin' => (string) ($spec['origin'] ?? 'manual'),
        'topic' => $topic, 'topic_key' => (string) ($spec['topic_key'] ?? ''),
        'keywords' => array_values(str_list($spec['keywords'] ?? [])), 'notes' => (string) ($spec['notes'] ?? ''),
        'words' => max(500, min(4000, (int) ($spec['words'] ?? 1500) ?: 1500)), 'source' => (bool) $source,
        // модель, выбранная при постановке; пусто — из «Настроек» на момент написания
        'model' => isset(CLAUDE_MODELS[(string) ($spec['model'] ?? '')]) ? (string) $spec['model'] : '',
        'stage' => 'queued', 'tasks' => ($topic === '' && !$source ? ['topic' => $todo] : []) + ['text' => $todo, 'cover' => $todo],
        'slug' => '', 'title' => '', 'publish_at' => '', 'cover_path' => '', 'error' => '',
    ];
    queue_locked(function () use ($item) {
        $s = queue_state();
        $s['items'][] = $item;
        queue_save($s);
    });
    return $item;
}

/** Отменить статью, пока её не начали (или если она застряла). */
function queue_cancel(string $id): bool
{
    return queue_locked(function () use ($id) {
        $s = queue_state();
        foreach ($s['items'] ?? [] as $i => $it) {
            if ($it['id'] !== $id || in_array($it['stage'], ['done', 'error', 'cancelled'], true)) continue;
            $s['items'][$i]['stage'] = 'cancelled';
            queue_save($s);
            return true;
        }
        return false;
    });
}

// ---------------------------------------------------------------- исполнение

function queue_open(array $it): bool { return in_array($it['stage'], ['queued', 'working'], true); }

/** Взять следующую задачу (под замком). Одновременно — один текст и одна обложку; тема — раньше всего у статьи. */
function queue_claim(array &$s): ?array
{
    $busy = ['text' => false, 'cover' => false, 'topic' => false];
    $now = time();
    foreach ($s['items'] ?? [] as $i => $it) {
        if (!queue_open($it)) continue;
        foreach ($it['tasks'] as $t => $task) {
            if ($task['state'] !== 'busy') continue;
            if ($now - (int) $task['touched'] < QUEUE_LEASE) { $busy[$t === 'topic' ? 'text' : $t] = true; continue; }
            // оборвалась на хостинге: повторить один раз, потом — ошибка
            $s['items'][$i]['tasks'][$t]['state'] = ++$s['items'][$i]['tasks'][$t]['tries'] >= 2 ? 'error' : 'todo';
            if ($s['items'][$i]['tasks'][$t]['state'] === 'error') $s['items'][$i]['tasks'][$t]['error'] = 'прервалась на хостинге';
        }
    }
    foreach ($s['items'] ?? [] as $i => $it) {
        if (!queue_open($it)) continue;
        if (isset($it['tasks']['topic']) && $it['tasks']['topic']['state'] !== 'done') {
            if ($it['tasks']['topic']['state'] === 'todo' && !$busy['text']) return queue_take($s, $i, 'topic');
            continue;   // пока нет темы — ни текст, ни обложку не начать
        }
        if ($it['tasks']['text']['state'] === 'todo' && !$busy['text']) { $busy['text'] = true; return queue_take($s, $i, 'text'); }
        // статья по источнику без темы: обложку — когда станет известен заголовок
        $cover_ready = $it['topic'] !== '' || $it['tasks']['text']['state'] === 'done';
        if ($it['tasks']['cover']['state'] === 'todo' && !$busy['cover'] && $cover_ready) { $busy['cover'] = true; return queue_take($s, $i, 'cover'); }
    }
    return null;
}

function queue_take(array &$s, int $i, string $task): array
{
    $s['items'][$i]['tasks'][$task]['state'] = 'busy';
    $s['items'][$i]['tasks'][$task]['touched'] = time();
    $s['items'][$i]['stage'] = 'working';
    return [$s['items'][$i], $task];
}

/** Сколько задач можно запустить прямо сейчас (для пинков: 0–2). */
function queue_free_slots(array $s): int
{
    $copy = $s;
    $n = 0;
    while ($n < 3 && queue_claim($copy)) $n++;
    return $n;
}

/** Один запрос хостинга — одна задача. $exec — подмена в тестах: fn(string $task, array $item): array. */
function queue_tick(?callable $fire = null, ?callable $exec = null): ?string
{
    $claim = queue_locked(function () {
        $s = queue_state();
        $c = queue_claim($s);
        queue_save($s);
        return $c;
    });
    if (!$claim) return null;
    [$item, $task] = $claim;
    @set_time_limit(300);
    $result = null;
    $error = null;
    try {
        $result = $exec ? $exec($task, $item) : queue_run($task, $item);
    } catch (Throwable $ex) {
        if (!$ex instanceof AiError) error_log("queue $task: $ex");
        $error = $ex->getMessage();
    }
    $finish = queue_locked(function () use ($item, $task, $result, $error) {
        $s = queue_state();
        foreach ($s['items'] ?? [] as $i => $it) {
            if ($it['id'] !== $item['id']) continue;
            if (!queue_open($it)) return null;   // отменили, пока шла задача
            $t = &$s['items'][$i]['tasks'][$task];
            if ($error !== null) { $t['state'] = 'error'; $t['error'] = $error; } else { $t['state'] = 'done'; }
            unset($t);
            if ($error === null && $task === 'topic') $s['items'][$i] = array_merge($s['items'][$i], $result);
            if ($error === null && $task === 'cover') $s['items'][$i]['cover_path'] = (string) ($result['path'] ?? '');
            if ($error === null && $task === 'text') $s['items'][$i]['title'] = (string) ($result['title'] ?? '');
            $it = $s['items'][$i];
            if (in_array($it['tasks']['text']['state'], ['error'], true) || (isset($it['tasks']['topic']) && $it['tasks']['topic']['state'] === 'error')) {
                $s['items'][$i]['stage'] = 'error';
                $s['items'][$i]['error'] = (isset($it['tasks']['topic']) && $it['tasks']['topic']['state'] === 'error')
                    ? 'тема: ' . $it['tasks']['topic']['error'] : 'текст: ' . $it['tasks']['text']['error'];
                @unlink(queue_dir() . "/{$it['id']}-source.json");
            } elseif ($it['tasks']['text']['state'] === 'done' && in_array($it['tasks']['cover']['state'], ['done', 'error'], true)) {
                $s['items'][$i]['stage'] = 'finishing';
                queue_save($s);
                return $s['items'][$i];
            }
            queue_save($s);
            return null;
        }
        return null;
    });
    if ($finish) queue_finish($finish);
    queue_fire_free($fire);
    return $task;
}

/** Запустить столько запросов, сколько задач можно начать. */
function queue_fire_free(?callable $fire = null): void
{
    $n = queue_free_slots(queue_state());
    $s = queue_state();
    $s['secret'] ??= bin2hex(random_bytes(16));
    if ($n > 0) { $s['kicked'] = time(); queue_save($s); }
    for ($k = 0; $k < $n; $k++) queue_fire($s['secret'], $fire);
}

function queue_fire(string $secret, ?callable $fire = null): void
{
    $url = parts()['site_url'] . '/api/queue-tick.php?k=' . $secret;
    if ($fire) { $fire($url); return; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_NOSIGNAL => true, CURLOPT_CONNECTTIMEOUT_MS => 1000]);
    curl_exec($ch);
}

/** Сама работа задачи → данные для статьи. */
function queue_run(string $task, array $item, ?callable $post = null): array
{
    claude_override(($item['model'] ?? '') ?: null);
    try {
        return queue_run_task($task, $item, $post);
    } finally {
        claude_override(null);
    }
}

function queue_run_task(string $task, array $item, ?callable $post = null): array
{
    $cfg = queue_settings();
    if ($task === 'topic') {
        $t = queue_pick_topic($cfg['per_week'], $post);
        if (!$t) throw new AiError('Свободных тем не нашлось — добавьте темы в админке («Из запросов»)');
        return ['topic' => (string) $t['topic'], 'topic_key' => topic_key($t),
            'keywords' => array_values(array_unique(array_filter([(string) $t['main_keyword'], ...array_map('strval', $t['keywords'] ?? [])])))];
    }
    if ($task === 'text') {
        $existing = array_map(fn($a) => ['slug' => $a['slug'], 'title' => $a['title']], load_all());
        $src_file = queue_dir() . "/{$item['id']}-source.json";
        $source = $item['source'] && is_file($src_file) ? job_source(read_json($src_file)) : null;
        $a = write_article($item['topic'], $item['keywords'], $item['notes'], $item['words'], $existing, $post, $source);
        if (!is_dir(queue_dir())) mkdir(queue_dir(), 0750, true);
        write_json(queue_dir() . "/{$item['id']}-article.json", $a);
        return ['title' => (string) ($a['title'] ?? '')];
    }
    if ($task === 'cover') {
        // по теме, не дожидаясь текста: так обложка готова к тому же времени, что и статья
        $a = $item['topic'] === '' ? (read_json(queue_dir() . "/{$item['id']}-article.json") ?? []) : [];
        $title = $item['topic'] !== '' ? $item['topic'] : (string) ($a['title'] ?? 'Статья');
        $ctx = $a ? ['summary' => (string) ($a['summary'] ?? ''), 'body' => (string) ($a['body_md'] ?? '')]
            : ['body' => implode(', ', $item['keywords']) . "\n" . $item['notes']];
        $img = generate_image('cover', $title, (string) ($a['lead'] ?? ''), '', $cfg['image_mode'], $ctx, $post);
        if (($img['bin'] ?? '') === '') return ['path' => ''];
        $name = substr(slugify($title), 0, 60) . '-cover-' . bin2hex(random_bytes(3)) . '.webp';
        write_file(DATA_DIR . "/media/$name", $img['bin']);
        return ['path' => "/blog/media/$name"];
    }
    throw new RuntimeException("Неизвестная задача $task");
}

/** Текст и обложка готовы — сохранить статью в план на ближайший свободный день. */
function queue_finish(array $item): void
{
    $a = read_json(queue_dir() . "/{$item['id']}-article.json") ?? [];
    $saved = null;
    $error = '';
    try {
        $saved = queue_locked(function () use ($a, $item) {   // под замком: две статьи не займут один день
            $slug = preg_match(SLUG_RE, (string) ($a['slug'] ?? '')) ? (string) $a['slug'] : slugify((string) ($a['title'] ?? $item['topic']));
            $base = $slug;
            for ($n = 2; get_article($slug); $n++) $slug = "$base-$n";   // адрес занят — не затираем другую статью
            return save_article([
                'slug' => $slug, 'title' => $a['title'] ?? $item['topic'], 'seo_title' => $a['seo_title'] ?? '', 'description' => $a['description'] ?? '',
                'lead' => $a['lead'] ?? '', 'summary' => $a['summary'] ?? '', 'body_md' => $a['body_md'] ?? '', 'category' => $a['category'] ?? '',
                'keywords' => $a['keywords'] ?? $item['keywords'], 'faq' => $a['faq'] ?? [], 'related' => $a['related'] ?? [],
                'cover_path' => $item['cover_path'], 'author' => defined('NICHE_AUTHOR') ? NICHE_AUTHOR : '',
                'status' => 'scheduled', 'publish_at' => next_free_slot(queue_settings()['per_week']),
            ]);
        });
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
    queue_locked(function () use ($item, $saved, $error) {
        $s = queue_state();
        foreach ($s['items'] ?? [] as $i => $it) {
            if ($it['id'] !== $item['id']) continue;
            $s['items'][$i]['stage'] = $saved ? 'done' : 'error';
            $s['items'][$i]['error'] = $error;
            if ($saved) $s['items'][$i] = array_merge($s['items'][$i], ['slug' => $saved['slug'], 'title' => $saved['title'], 'publish_at' => $saved['publish_at']]);
        }
        // помним только последние готовые
        $done = array_keys(array_filter($s['items'] ?? [], fn($x) => !queue_open($x) && $x['stage'] !== 'finishing'));
        foreach (array_slice($done, 0, max(0, count($done) - QUEUE_KEEP)) as $k) unset($s['items'][$k]);
        $s['items'] = array_values($s['items'] ?? []);
        queue_save($s);
    });
    @unlink(queue_dir() . "/{$item['id']}-article.json");
    @unlink(queue_dir() . "/{$item['id']}-source.json");
    if ($saved && $item['origin'] !== 'manual') queue_notify($saved, $item['cover_path'] !== '');
}

/** Первая новая тема из списка, которой ещё нет в очереди; список пуст — подбираем по спросу. */
function queue_pick_topic(int $per_week, ?callable $post = null): ?array
{
    $taken = array_filter(array_column(array_filter(queue_state()['items'] ?? [], 'queue_open'), 'topic_key'));
    $pick = function () use ($per_week, $taken) {
        foreach (topics_view($per_week)['topics'] as $t) {
            if (empty($t['used']) && ($t['status'] ?? 'new') === 'new' && !in_array(topic_key($t), $taken, true)) return $t;
        }
        return null;
    };
    $t = $pick();
    if (!$t) {
        propose_ideas('', $post);
        $t = $pick();
    }
    return $t;
}

/** Источник для статьи: ссылка (скачиваем здесь, в задаче) + вставленный текст + скриншоты. */
function job_source(?array $in): ?array
{
    if (!$in) return null;
    $src = ['url' => '', 'title' => '', 'text' => '', 'images' => []];
    if (($in['url'] ?? '') !== '') {
        try {
            $src = fetch_source($in['url']);
        } catch (AiError $ex) {
            // ссылка не открылась, но есть текст или скриншоты — пишем по ним, адрес оставляем для ссылки
            if (trim($in['text'] ?? '') === '' && empty($in['images'])) throw $ex;
            $src['url'] = $in['url'];
        }
    }
    if (trim($in['text'] ?? '') !== '') $src['text'] = trim(($src['text'] ? $src['text'] . "\n\n" : '') . $in['text']);
    foreach ($in['images'] ?? [] as $f) {
        if (is_file($f)) $src['images'][] = ['image/jpeg', base64_encode((string) file_get_contents($f))];
    }
    return $src;
}

function queue_notify(array $a, bool $cover): void
{
    if (tg_owner() === '') return;
    $dt = parse_dt($a['publish_at']);
    $when = $dt ? human_date($dt->format('Y-m-d')) . ' в ' . $dt->format('H:i') : $a['publish_at'];
    $site = parts()['site_url'];
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    tg_send(tg_owner(), "🗓 <b>Статья готова и стоит в плане</b> · " . $e(parse_url($site, PHP_URL_HOST)) . "\n\n«" . $e($a['title']) . "»\n"
        . "Выйдет " . $e($when) . ($cover ? ', с обложкой.' : ' — обложку не удалось сделать, можно добавить в админке.') . "\n\n"
        . "<a href=\"$site/admin/preview/" . $e($a['slug']) . "\">Предпросмотр</a> · <a href=\"$site/admin/\">админка</a> — до выхода её можно поправить или снять.");
}

// ---------------------------------------------------------------- пинки и автопилот

/** Сколько статей по-настоящему готовы ждать своего дня: запланированные, с текстом и обложкой. Без обложки —
 *  такое бывает, если OpenAI подвёл (queue_finish всё равно её публикует), но «про запас» это не считаем. */
function autopilot_ready_count(?DateTimeImmutable $at = null): int
{
    $at ??= now();
    return count(array_filter(load_all(), fn($a) => state($a, $at) === 'scheduled' && $a['cover_path'] !== ''));
}

/** Нужна ли ещё статья и остаёмся ли «в пополнении» (гистерезис, чтобы не подливать по одной на каждый чих):
 *  просел запас (готовые + уже пишутся) до низкой отметки — пополняем пачкой, пока не наберём цель снова; между
 *  низкой отметкой и целью автопилот молчит. $refilling — текущий флаг (queue_state()['buffer_refill']).
 *  → [нужна статья сейчас?, новое значение флага]. */
function autopilot_due(bool $refilling, ?DateTimeImmutable $at = null): array
{
    $target = queue_settings()['buffer_target'];
    $low = max(1, (int) round($target / 4));
    $total = autopilot_ready_count($at) + count(array_filter(queue_state()['items'] ?? [], 'queue_open'));
    if (!$refilling && $total <= $low) $refilling = true;
    if ($refilling && $total >= $target) $refilling = false;
    return [$refilling && $total < $target, $refilling];
}

/** С заходов на сайт и из админки: продолжить очередь, а раз в 10 минут — проверить, не нужна ли статья автопилоту. */
function queue_maybe_kick(?callable $fire = null, ?int $now = null): void
{
    $now ??= time();
    $s = queue_state();
    if ($now - (int) ($s['checked'] ?? 0) >= AUTOPILOT_CHECK_EVERY) {
        $add = queue_locked(function () use ($now) {
            $s = queue_state();
            $s['checked'] = $now;
            $cfg = queue_settings();
            $today = array_values(array_filter((array) ($s['auto_started'] ?? []), fn($t) => $now - (int) $t < 86400));
            [$due, $refilling] = autopilot_due(!empty($s['buffer_refill']));
            $s['buffer_refill'] = $refilling;
            $ok = $cfg['autopilot'] && $cfg['ready'] && count($today) < AUTOPILOT_MAX_PER_DAY && $due;
            if ($ok) $s['auto_started'] = [...$today, $now];
            queue_save($s);
            return $ok;
        });
        if ($add) queue_add(['origin' => 'auto']);
        $s = queue_state();
    }
    if ($now - (int) ($s['kicked'] ?? 0) < QUEUE_KICK_EVERY) return;
    queue_fire_free($fire);
}

/** Для админки: очередь (новые сверху), настройки и запас (сколько готово из скольких держим целью). */
function queue_view(): array
{
    $items = array_reverse(queue_state()['items'] ?? []);
    $pending = count(array_filter($items, 'queue_open'));
    return queue_settings() + ['items' => array_slice($items, 0, 20), 'pending' => $pending,
        'ready' => autopilot_ready_count(), 'refilling' => !empty(queue_state()['buffer_refill'])];
}
