<?php
// Автопилот статей: когда в плане выхода есть свободное место, сайт сам берёт тему (из списка тем, а если
// он пуст — подбирает по спросу Google и Яндекса), пишет статью, рисует обложку (по умолчанию — с людьми)
// и ставит статью в план на ближайший свободный день ритма. Владельцу — сообщение в Telegram: статью можно
// поправить или снять в админке до выхода.
//
// Cron не нужен (как у SEO-автопилота, seo.php): заходы на сайт раз в 10 минут проверяют, не пора ли, и
// «пинают» /api/autopost-tick.php. Работа идёт шагами (тема → статья → обложка и план), каждый шаг пинает
// следующий; оборванный шаг повторяется (2 попытки), потом задача снимается, а в следующий раз — заново.
declare(strict_types=1);
require_once __DIR__ . '/ai.php';
require_once dirname(__DIR__) . '/api/_tg.php';

const AUTOPOST_CHECK_EVERY = 600;     // как часто заходы на сайт проверяют план
const AUTOPOST_STEP_LEASE = 700;      // шаг дольше этого — точно оборван (статья пишется до ~5 минут)
const AUTOPOST_MAX_PER_DAY = 3;       // не больше статей в сутки — страховка от расходов при сбоях
const AUTOPOST_WORDS = 1500;
const AUTOPOST_STEPS = ['topic', 'write', 'publish'];

function autopost_file(): string { return DATA_DIR . '/autopost.json'; }

function autopost_state(): array { return read_json(autopost_file()) ?? []; }

function autopost_save(array $s): void { write_json(autopost_file(), $s); @chmod(autopost_file(), 0600); }

function autopost_locked(callable $fn)
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $f = fopen(DATA_DIR . '/.autopost.lock', 'c');
    flock($f, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/** Настройки из «Настроек» админки: включён ли, ритм (статей в неделю), сюжет обложки. Включён по умолчанию. */
function autopost_settings(): array
{
    $s = settings();
    $week = (int) ($s['per_week'] ?? 2);
    $mode = (string) ($s['image_mode'] ?? 'people');
    return ['on' => ($s['autopost'] ?? true) !== false, 'per_week' => isset(SLOT_DAYS[$week]) ? $week : 2,
        'image_mode' => in_array($mode, IMG_MODES, true) ? $mode : 'people',
        'ready' => trim((string) ($s['anthropic_key'] ?? '')) !== '' && trim((string) ($s['openai_key'] ?? '')) !== ''];
}

/** Статьи, которые ещё не вышли и стоят в плане. */
function autopost_queue(?DateTimeImmutable $at = null): array
{
    return array_values(array_filter(load_all(), fn($a) => state($a, $at) === 'scheduled'));
}

/** Есть ли свободное место: в ближайшие 7 дней статей меньше, чем дней выхода по ритму. */
function autopost_due(?DateTimeImmutable $at = null): bool
{
    $at ??= now();
    $cfg = autopost_settings();
    $week_end = $at->modify('+7 days');
    $soon = array_filter(autopost_queue($at), fn($a) => ($dt = parse_dt($a['publish_at'])) && $dt <= $week_end);
    return count($soon) < count(SLOT_DAYS[$cfg['per_week']]);
}

function autopost_maybe_kick(?callable $fire = null, ?int $now = null): void
{
    $now ??= time();
    $s = autopost_state();
    $run = $s['run'] ?? null;
    if ($run && ($now - (int) $run['touched'] < AUTOPOST_STEP_LEASE || $now - (int) ($s['kicked'] ?? 0) < 60)) return;
    if (!$run && $now - (int) ($s['checked'] ?? 0) < AUTOPOST_CHECK_EVERY) return;
    $s = autopost_locked(function () use ($now) {
        $s = autopost_state();
        $s['secret'] ??= bin2hex(random_bytes(16));
        if (empty($s['run'])) {
            $s['checked'] = $now;
            $cfg = autopost_settings();
            $today = array_filter((array) ($s['started'] ?? []), fn($t) => $now - (int) $t < 86400);
            if (!$cfg['on'] || !$cfg['ready'] || count($today) >= AUTOPOST_MAX_PER_DAY || !autopost_due()) { autopost_save($s); return null; }
            $s['started'] = array_values([...$today, $now]);
            $s['run'] = ['i' => 0, 'tries' => 0, 'busy' => false, 'touched' => $now, 'data' => []];
        } elseif ($now - (int) $s['run']['touched'] < AUTOPOST_STEP_LEASE) {
            return null;
        }
        $s['kicked'] = $now;
        autopost_save($s);
        return $s;
    });
    if ($s) autopost_fire($s, $fire);
}

function autopost_fire(array $s, ?callable $fire = null): void
{
    $url = parts()['site_url'] . '/api/autopost-tick.php?k=' . $s['secret'];
    if ($fire) { $fire($url); return; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_NOSIGNAL => true, CURLOPT_CONNECTTIMEOUT_MS => 1000]);
    curl_exec($ch);
}

/** Один шаг. $exec — подмена шагов в тестах: fn(string $step, array $data): array (новые данные задачи). */
function autopost_tick(?callable $fire = null, ?callable $exec = null): ?string
{
    $claim = autopost_locked(function () {
        $s = autopost_state();
        $r = $s['run'] ?? null;
        if (!$r) return null;
        if (!empty($r['busy']) && time() - (int) $r['touched'] < AUTOPOST_STEP_LEASE) return null;   // шаг уже идёт
        if (!empty($r['busy']) && ++$r['tries'] >= 2) {   // дважды оборвался — снимаем задачу
            $s['last'] = ['at' => now()->format('Y-m-d H:i'), 'error' => AUTOPOST_STEPS[$r['i']] . ': прервался на хостинге'];
            unset($s['run']);
            autopost_save($s);
            return null;
        }
        $r['busy'] = true;
        $r['touched'] = time();
        $s['run'] = $r;
        autopost_save($s);
        return [AUTOPOST_STEPS[$r['i']], $r['data']];
    });
    if (!$claim) return null;
    [$step, $data] = $claim;
    @set_time_limit(600);
    $error = null;
    try {
        $data = $exec ? $exec($step, $data) : autopost_step($step, $data);
    } catch (Throwable $ex) {
        if (!$ex instanceof AiError) error_log("autopost $step: $ex");
        $error = $ex->getMessage();
    }
    $s = autopost_locked(function () use ($step, $data, $error) {
        $s = autopost_state();
        $r = $s['run'] ?? null;
        if (!$r || AUTOPOST_STEPS[$r['i']] !== $step) return null;
        if ($error !== null) {
            $s['last'] = ['at' => now()->format('Y-m-d H:i'), 'error' => "$step: $error"];
            unset($s['run']);
            autopost_save($s);
            return null;
        }
        $r['data'] = $data;
        $r['i']++;
        $r['tries'] = 0;
        $r['busy'] = false;
        $r['touched'] = time();
        if ($r['i'] >= count(AUTOPOST_STEPS)) {
            $s['last'] = ['at' => now()->format('Y-m-d H:i'), 'slug' => $data['slug'] ?? '', 'title' => $data['title'] ?? '',
                'publish_at' => $data['publish_at'] ?? ''];
            unset($s['run']);
            autopost_save($s);
            return null;
        }
        $s['run'] = $r;
        autopost_save($s);
        return $s;
    });
    if ($s) autopost_fire($s, $fire);
    return $step;
}

/** Первая новая тема из списка (как «Придумай сам» в админке); список пуст — подбираем по спросу. */
function autopost_pick_topic(int $per_week, ?callable $post = null): ?array
{
    $pick = function () use ($per_week) {
        foreach (topics_view($per_week)['topics'] as $t) if (empty($t['used']) && ($t['status'] ?? 'new') === 'new') return $t;
        return null;
    };
    $t = $pick();
    if (!$t) {
        propose_ideas('', $post);
        $t = $pick();
    }
    return $t;
}

function autopost_step(string $step, array $data, ?callable $post = null): array
{
    $cfg = autopost_settings();
    if ($step === 'topic') {
        $t = autopost_pick_topic($cfg['per_week'], $post);
        if (!$t) throw new AiError('Свободных тем не нашлось — добавьте темы в админке («Из запросов»)');
        $kw = array_values(array_unique(array_filter([(string) $t['main_keyword'], ...array_map('strval', $t['keywords'] ?? [])])));
        return ['topic' => (string) $t['topic'], 'keywords' => $kw];
    }
    if ($step === 'write') {
        $existing = array_map(fn($a) => ['slug' => $a['slug'], 'title' => $a['title']], load_all());
        $a = write_article($data['topic'], $data['keywords'], '', AUTOPOST_WORDS, $existing, $post);
        return $data + ['article' => $a];
    }
    if ($step === 'publish') {
        $a = $data['article'];
        $cover = '';
        try {   // без обложки статья всё равно выходит — картинку можно добавить в админке
            $img = generate_image('cover', (string) $a['title'], (string) ($a['lead'] ?? ''), '', $cfg['image_mode'],
                ['summary' => (string) ($a['summary'] ?? ''), 'body' => (string) ($a['body_md'] ?? '')], $post);
            if ($img['bin'] !== '') {
                $name = substr(slugify((string) ($a['slug'] ?? $a['title'])), 0, 60) . '-cover-' . bin2hex(random_bytes(3)) . '.webp';
                write_file(DATA_DIR . "/media/$name", $img['bin']);
                $cover = "/blog/media/$name";
            }
        } catch (AiError $ex) {
            error_log('autopost cover: ' . $ex->getMessage());
        }
        $slug = (string) $a['slug'];
        for ($n = 2; get_article($slug); $n++) $slug = $a['slug'] . "-$n";   // адрес занят — не затираем чужую статью
        $at = next_free_slot($cfg['per_week']);
        $saved = save_article(['slug' => $slug, 'title' => $a['title'], 'seo_title' => $a['seo_title'] ?? '', 'description' => $a['description'] ?? '',
            'lead' => $a['lead'] ?? '', 'summary' => $a['summary'] ?? '', 'body_md' => $a['body_md'] ?? '', 'category' => $a['category'] ?? '',
            'keywords' => $a['keywords'] ?? [], 'faq' => $a['faq'] ?? [], 'related' => $a['related'] ?? [], 'cover_path' => $cover,
            'author' => defined('NICHE_AUTHOR') ? NICHE_AUTHOR : '', 'status' => 'scheduled', 'publish_at' => $at]);
        autopost_notify($saved, $cover !== '');
        return ['slug' => $saved['slug'], 'title' => $saved['title'], 'publish_at' => $saved['publish_at']];
    }
    throw new RuntimeException("Неизвестный шаг $step");
}

/** Сообщение владельцу: что и когда выйдет, ссылка на предпросмотр и админку. */
function autopost_notify(array $a, bool $cover, ?callable $call = null): void
{
    if (tg_owner() === '') return;
    $dt = parse_dt($a['publish_at']);
    $when = $dt ? human_date($dt->format('Y-m-d')) . ' в ' . $dt->format('H:i') : $a['publish_at'];
    $site = parts()['site_url'];
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    tg_send(tg_owner(), "🤖 <b>Новая статья в плане</b> · " . $e(parse_url($site, PHP_URL_HOST)) . "\n\n«" . $e($a['title']) . "»\n"
        . "Выйдет " . $e($when) . ($cover ? ', с обложкой.' : ' — обложку не удалось сделать, можно добавить в админке.') . "\n\n"
        . "Посмотреть: <a href=\"$site/admin/preview/" . $e($a['slug']) . "\">предпросмотр</a> (нужен вход в админку). "
        . "Поправить или снять — в <a href=\"$site/admin/\">админке</a>, до выхода статья на сайте не видна.", $call);
}

/** Для админки: включён ли, что сейчас делает, что было последним. */
function autopost_view(): array
{
    $s = autopost_state();
    $run = $s['run'] ?? null;
    return autopost_settings() + ['running' => $run ? AUTOPOST_STEPS[$run['i']] : '', 'topic' => $run['data']['topic'] ?? '',
        'last' => $s['last'] ?? null, 'queue' => count(autopost_queue()), 'due' => autopost_due()];
}
