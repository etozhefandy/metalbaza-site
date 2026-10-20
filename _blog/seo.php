<?php
// SEO-автопилот: раз в неделю сам смотрит Search Console, предлагает (или применяет) правки статей,
// ставит ссылки между статьями, проверяет, советуют ли сайт ChatGPT и Claude, и присылает отчёт
// в Telegram. Методика — обычные проверки SEO-плагинов (близко к топу, просели, мешают друг другу,
// мало кликов, перелинковка, видимость в ИИ), данные — свои, всё на хостинге.
//
// Cron не нужен: заход на сайт раз в 10 минут проверяет, не пора ли (чтение одного файла), и если пора —
// «пинает» /api/seo-tick.php. Работа идёт шагами: хостинг обрывает запросы на ~125 с, поэтому каждый шаг
// делает одно дело и пинает следующий. Шаг оборвался — следующий заход на сайт перезапустит его (2 попытки).
declare(strict_types=1);
require_once __DIR__ . '/ai.php';
require_once dirname(__DIR__) . '/api/_tg.php';

const SEO_EVERY = 7 * 86400;      // полная проверка раз в неделю
const SEO_STEP_LEASE = 150;       // шаг дольше этого — точно оборван хостингом
const SEO_CHECK_EVERY = 600;      // как часто заходы на сайт проверяют, не пора ли
const GSC_SITE = 'sc-domain:' . SITE_HOST;
const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
const CTR_CURVE = [1 => .28, 2 => .15, 3 => .10, 4 => .07, 5 => .05, 6 => .04, 7 => .03, 8 => .025, 9 => .02, 10 => .018];
const LINKS_PER_STEP = 3;         // статей на один шаг перелинковки
const LINKS_MAX = 3;              // автоссылок в одной статье
const GEO_CLAUDE_MODEL = 'claude-sonnet-5-5';
const GEO_OPENAI_MODELS = ['gpt-5', 'gpt-4.1'];   // первая недоступна — пробуем следующую


function local_time(int $ts, string $fmt): string { return (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format($fmt); }

function seo_dir(): string { return DATA_DIR . '/seo'; }

function seo_locked(callable $fn)
{
    if (!is_dir(seo_dir())) mkdir(seo_dir(), 0750, true);
    $f = fopen(seo_dir() . '/.state.lock', 'c');
    flock($f, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

function seo_state(): array { return read_json(seo_dir() . '/state.json') ?? []; }

function seo_save(array $s): void { write_json(seo_dir() . '/state.json', $s); @chmod(seo_dir() . '/state.json', 0600); }

function seo_settings(): array
{
    $s = settings();
    $prompts = array_values(array_filter(array_map('trim', (array) ($s['geo_prompts'] ?? [])), 'strlen'));
    return ['auto_apply' => !empty($s['seo_auto_apply']), 'geo_prompts' => $prompts ?: P('geo_questions'), 'geo_custom' => (bool) $prompts];
}

/** HTTP для Google и OpenAI → [код, json]. $http — подмена в тестах: fn(method, url, headers, body): [код, сырой ответ]. */
function http_json(string $method, string $url, array $headers, ?string $body, int $timeout = 60, ?callable $http = null): array
{
    if ($http) {
        [$code, $raw] = $http($method, $url, $headers, $body);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) throw new AiError('Нет связи с ' . parse_url($url, PHP_URL_HOST) . ': ' . curl_error($ch));
    }
    $data = json_decode((string) $raw, true);
    return [(int) $code, is_array($data) ? $data : []];
}

// ---------------------------------------------------------------- Search Console

// Доступ — через служебный аккаунт Google: владелец один раз создаёт его в Google Cloud, добавляет его адрес
// пользователем в Search Console и загружает JSON-ключ в админку. Ключ лежит в settings.json вне сайта.

function gsc_account(): ?array
{
    $k = settings()['gsc_key'] ?? null;
    return is_array($k) && !empty($k['client_email']) && !empty($k['private_key']) ? $k : null;
}

function gsc_parse_key(string $json): array
{
    $k = json_decode($json, true);
    if (!is_array($k) || ($k['type'] ?? '') !== 'service_account' || empty($k['client_email']) || empty($k['private_key'])) {
        throw new InvalidArgumentException('Это не ключ служебного аккаунта Google — нужен JSON-файл: Google Cloud → IAM → Сервисные аккаунты → Ключи → Добавить ключ → JSON');
    }
    if (!function_exists('openssl_sign')) throw new InvalidArgumentException('На хостинге нет OpenSSL для PHP — ключ не подписать');
    if (!openssl_pkey_get_private((string) $k['private_key'])) throw new InvalidArgumentException('Закрытый ключ в файле повреждён — скачайте новый');
    return ['client_email' => (string) $k['client_email'], 'private_key' => (string) $k['private_key'],
        'token_uri' => (string) ($k['token_uri'] ?? 'https://oauth2.googleapis.com/token')];
}

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function gsc_jwt(array $sa, int $now): string
{
    $head = b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = b64url((string) json_encode(['iss' => $sa['client_email'], 'scope' => GSC_SCOPE, 'aud' => $sa['token_uri'],
        'iat' => $now, 'exp' => $now + 3600], JSON_UNESCAPED_SLASHES));
    openssl_sign("$head.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
    return "$head.$claims." . b64url($sig);
}

function gsc_token(?callable $http = null): string
{
    $sa = gsc_account();
    if (!$sa) throw new AiError('Search Console не подключена — загрузите ключ во вкладке «SEO»');
    $file = seo_dir() . '/gsc-token.json';
    $cache = read_json($file);
    if ($cache && ($cache['email'] ?? '') === $sa['client_email'] && ($cache['exp'] ?? 0) > time() + 120) return (string) $cache['token'];
    [$code, $d] = http_json('POST', $sa['token_uri'], ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => gsc_jwt($sa, time())]), 30, $http);
    if ($code !== 200 || empty($d['access_token'])) {
        throw new AiError('Google не принял ключ Search Console: ' . ($d['error_description'] ?? $d['error'] ?? "код $code"));
    }
    write_json($file, ['email' => $sa['client_email'], 'token' => $d['access_token'], 'exp' => time() + (int) ($d['expires_in'] ?? 3600)]);
    @chmod($file, 0600);
    return (string) $d['access_token'];
}

/** Проверка из админки: видит ли служебный аккаунт ресурс сайта. → адрес аккаунта. */
function gsc_check(?callable $http = null): string
{
    $email = gsc_account()['client_email'] ?? '';
    [$code, $d] = http_json('GET', 'https://www.googleapis.com/webmasters/v3/sites', ['Authorization: Bearer ' . gsc_token($http)], null, 30, $http);
    if ($code !== 200) throw new AiError("Search Console ответила ошибкой ($code): " . ($d['error']['message'] ?? ''));
    if (!in_array(GSC_SITE, array_column($d['siteEntry'] ?? [], 'siteUrl'), true)) {
        throw new AiError("Ключ рабочий, но доступа к " . SITE_HOST . " нет. Search Console → Настройки → Пользователи и разрешения → "
            . "Добавить пользователя: $email, разрешение «Ограниченный доступ». Google иногда применяет это несколько минут.");
    }
    return $email;
}

function gsc_query(string $start, string $end, ?callable $http = null): array
{
    [$code, $d] = http_json('POST', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode(GSC_SITE) . '/searchAnalytics/query',
        ['Authorization: Bearer ' . gsc_token($http), 'Content-Type: application/json'],
        (string) json_encode(['startDate' => $start, 'endDate' => $end, 'dimensions' => ['query', 'page'], 'rowLimit' => 5000]), 60, $http);
    if ($code === 403) throw new AiError('Нет доступа к Search Console: добавьте ' . (gsc_account()['client_email'] ?? '') . ' в Search Console → Настройки → Пользователи');
    if ($code !== 200) throw new AiError("Search Console ответила ошибкой ($code): " . ($d['error']['message'] ?? ''));
    return array_map(fn($r) => ['q' => (string) $r['keys'][0], 'page' => (string) $r['keys'][1], 'clicks' => (int) $r['clicks'],
        'impr' => (int) $r['impressions'], 'pos' => round((float) $r['position'], 1)], $d['rows'] ?? []);
}

/** Последние 28 дней и 28 дней до них (данные Google отстают на 2–3 дня). */
function gsc_fetch(?callable $http = null, ?DateTimeImmutable $today = null): array
{
    $end = ($today ?? now())->modify('-3 days');
    $f = fn(DateTimeImmutable $d) => $d->format('Y-m-d');
    $cur = [$f($end->modify('-27 days')), $f($end)];
    $prev = [$f($end->modify('-55 days')), $f($end->modify('-28 days'))];
    return ['period' => $cur, 'cur' => gsc_query($cur[0], $cur[1], $http), 'prev' => gsc_query($prev[0], $prev[1], $http)];
}

// ---------------------------------------------------------------- разбор данных

function page_path(string $url): string { return (string) (parse_url($url, PHP_URL_PATH) ?: '/'); }

/** Строки запрос×страница → по страницам (адрес без #якоря): клики, показы, средняя позиция. */
function by_page(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $p = page_path($r['page']);
        $o = $out[$p] ?? ['clicks' => 0, 'impr' => 0, 'posw' => 0.0];
        $o['clicks'] += $r['clicks'];
        $o['impr'] += $r['impr'];
        $o['posw'] += $r['pos'] * $r['impr'];
        $out[$p] = $o;
    }
    foreach ($out as &$o) $o['pos'] = $o['impr'] ? round($o['posw'] / $o['impr'], 1) : 0.0;
    return $out;
}

function totals(array $rows): array
{
    $c = array_sum(array_column($rows, 'clicks'));
    $i = array_sum(array_column($rows, 'impr'));
    $pw = array_sum(array_map(fn($r) => $r['pos'] * $r['impr'], $rows));
    return ['clicks' => $c, 'impr' => $i, 'pos' => $i ? round($pw / $i, 1) : 0.0];
}

/**
 * Что делать на этой неделе: запросы рядом с топом, просевшие страницы, страницы, мешающие друг другу
 * по одному запросу, и страницы с показами без кликов. Плюс сводка запросов и страниц для правок статей.
 */
function seo_analyze(array $gsc): array
{
    $cur = $gsc['cur'];
    $quick = array_values(array_filter($cur, fn($r) => $r['pos'] >= 4 && $r['pos'] <= 20 && $r['impr'] >= 10));
    usort($quick, fn($a, $b) => $b['impr'] <=> $a['impr']);
    $quick = array_map(fn($r) => ['q' => $r['q'], 'page' => page_path($r['page']), 'impr' => $r['impr'], 'pos' => $r['pos']], array_slice($quick, 0, 12));

    $pages = by_page($cur);
    $prevPages = by_page($gsc['prev']);
    $low = [];
    foreach ($pages as $p => $o) {
        $ctr = $o['impr'] ? $o['clicks'] / $o['impr'] : 0;
        $expect = CTR_CURVE[max(1, min(10, (int) round($o['pos'])))];
        if ($o['impr'] >= 50 && $o['pos'] <= 10 && $ctr < $expect * 0.5) $low[] = ['page' => $p, 'impr' => $o['impr'], 'ctr' => round($ctr * 100, 1), 'pos' => $o['pos']];
    }
    $decay = [];
    foreach ($prevPages as $p => $was) {
        $now = $pages[$p] ?? ['clicks' => 0, 'impr' => 0, 'pos' => 0.0];
        if (($was['impr'] >= 30 && $now['impr'] < $was['impr'] * 0.6) || ($was['clicks'] >= 5 && $now['clicks'] <= $was['clicks'] * 0.5)) {
            $decay[] = ['page' => $p, 'impr' => [$was['impr'], $now['impr']], 'clicks' => [$was['clicks'], $now['clicks']]];
        }
    }
    usort($decay, fn($a, $b) => ($b['impr'][0] - $b['impr'][1]) <=> ($a['impr'][0] - $a['impr'][1]));

    $byQuery = [];
    foreach ($cur as $r) {
        $q = $byQuery[$r['q']] ?? ['impr' => 0, 'posw' => 0.0, 'pages' => []];
        $q['impr'] += $r['impr'];
        $q['posw'] += $r['pos'] * $r['impr'];
        $p = page_path($r['page']);
        $q['pages'][$p] = [($q['pages'][$p][0] ?? 0) + $r['impr'], $r['pos']];
        $byQuery[$r['q']] = $q;
    }
    $cannibal = [];
    foreach ($byQuery as $q => $o) {
        if ($o['impr'] < 10) continue;
        $rivals = array_filter($o['pages'], fn($x) => $x[0] >= 3 && $x[0] >= $o['impr'] * 0.15);
        if (count($rivals) >= 2) {
            arsort($rivals);
            $cannibal[] = ['q' => (string) $q, 'impr' => $o['impr'], 'pages' => array_map(fn($p, $x) => [$p, $x[0], $x[1]], array_keys($rivals), $rivals)];
        }
    }
    usort($cannibal, fn($a, $b) => $b['impr'] <=> $a['impr']);
    uasort($byQuery, fn($a, $b) => $b['impr'] <=> $a['impr']);
    $site = parts()['site_url'];
    return [
        'period' => $gsc['period'],
        'totals' => ['cur' => totals($cur), 'prev' => totals($gsc['prev'])],
        'quick' => $quick, 'low_ctr' => array_slice($low, 0, 8), 'decay' => array_slice($decay, 0, 8), 'cannibal' => array_slice($cannibal, 0, 8),
        // для правок статей — в том же виде, что выгрузка Search Console: [запрос|адрес, показы, позиция]
        'queries' => array_slice(array_map(fn($q, $o) => [(string) $q, $o['impr'], $o['impr'] ? round($o['posw'] / $o['impr'], 1) : 0],
            array_keys($byQuery), $byQuery), 0, 300),
        'pages' => array_map(fn($p, $o) => [$site . $p, $o['impr'], $o['pos']], array_keys($pages), $pages),
    ];
}

/** Подсказки для правок: кто кому мешает и где мало кликов. */
function tune_notes(array $an): string
{
    $out = [];
    foreach ($an['cannibal'] as $c) {
        $out[] = "Запрос «{$c['q']}» делят страницы " . implode(' и ', array_column($c['pages'], 0))
            . ' — разведи их: у каждой свой главный запрос, вторую подстрой под соседний запрос, не дублируй.';
    }
    foreach ($an['low_ctr'] as $l) {
        $out[] = "Страница {$l['page']}: позиция {$l['pos']}, но CTR {$l['ctr']}% — заголовок и описание не цепляют, перепиши их под запрос.";
    }
    return $out ? "Наблюдения по данным:\n- " . implode("\n- ", $out) : '';
}

// ---------------------------------------------------------------- проверка статей

/** Что не так у статьи с точки зрения поиска (то же, что «SEO-проверка» в редакторе). */
function seo_lint(array $a): array
{
    $title = $a['seo_title'] ?: $a['title'];
    $len = mb_strlen($title);
    $dl = mb_strlen($a['description']);
    $main = mb_strtolower((string) ($a['keywords'][0] ?? ''));
    $issues = [];
    if ($len < 30 || $len > 60) $issues[] = "title $len симв. (лучше 30–60)";
    if ($dl < 120 || $dl > 170) $issues[] = $dl ? "описание $dl симв. (лучше 140–160)" : 'нет описания для поиска';
    if ($main === '') $issues[] = 'не указан главный запрос';
    elseif (!str_contains(mb_strtolower($title), $main)) $issues[] = 'главного запроса нет в title';
    if (count(md_h2($a['body_md'])) < 3) $issues[] = 'меньше 3 разделов';
    if (count($a['faq']) < 2) $issues[] = 'мало вопросов в FAQ';
    if ($a['cover_path'] === '') $issues[] = 'нет обложки';
    return $issues;
}

// ---------------------------------------------------------------- перелинковка

// Claude подбирает в тексте статьи фразы, которые естественно ведут на другие статьи блога. Сам текст статьи
// не меняется: ссылки ставятся при показе страницы (auto_links в lib.php), поэтому их легко пересчитать,
// а старые статьи получают ссылки на новые без правки руками.
const LINKS_SCHEMA = [
    'type' => 'object',
    'properties' => ['links' => ['type' => 'array', 'items' => [
        'type' => 'object',
        'properties' => [
            'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
            'anchor' => ['type' => 'string', 'description' => 'фраза 2–6 слов, дословно из текста статьи from'],
        ],
        'required' => ['from', 'to', 'anchor'],
        'additionalProperties' => false,
    ]]],
    'required' => ['links'],
    'additionalProperties' => false,
];

/** Отпечаток: статья и набор вышедших статей не менялись — ссылки пересчитывать не нужно. */
function links_fp(array $a, array $live): string
{
    $slugs = array_column($live, 'slug');
    sort($slugs);
    return md5($a['slug'] . '|' . md5($a['body_md']) . '|' . implode(',', $slugs));
}

function links_due(): array
{
    $live = live_articles();
    if (count($live) < 2) return [];
    $map = read_json(links_file()) ?? [];
    return array_values(array_map(fn($a) => $a['slug'],
        array_filter($live, fn($a) => ($map[$a['slug']]['fp'] ?? '') !== links_fp($a, $live))));
}

function make_links(array $slugs, ?callable $post = null): int
{
    $live = live_articles();
    $by = array_column($live, null, 'slug');
    $from = array_values(array_filter(array_map(fn($s) => $by[$s] ?? null, $slugs)));
    if (!$from) return 0;
    $catalog = implode("\n", array_map(fn($a) => "- {$a['slug']}: {$a['title']} (запросы: " . implode(', ', array_slice($a['keywords'], 0, 3)) . ')', $live));
    $texts = implode("\n\n", array_map(fn($a) => "### Статья {$a['slug']}\n" . $a['body_md'], $from));
    $data = claude_json([
        'max_tokens' => 4000,
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => LINKS_SCHEMA]],
        'messages' => [['role' => 'user', 'content' => implode("\n\n", [
            P('links_role'),
            "Все статьи блога (slug: заголовок):\n$catalog",
            "Статьи, в которые ставим ссылки (from):\n$texts",
            'Для каждой статьи from выбери 1–' . LINKS_MAX . ' другие статьи из списка, на которые читателю действительно полезно перейти, '
            . 'и фразу-якорь: 2–6 слов, ДОСЛОВНО скопированных из обычного абзаца или пункта списка статьи from (не из заголовков, таблиц, '
            . 'не из уже существующих ссылок), по смыслу описывающих статью, на которую ведёт ссылка. Без «здесь», «читайте». '
            . 'Не ставь ссылку на статью, на которую from уже ссылается. Нет хорошей пары — не ставь.',
        ])]],
    ], $post);
    $map = read_json(links_file()) ?? [];
    $n = 0;
    foreach ($from as $a) {
        $mine = [];
        foreach ($data['links'] ?? [] as $l) {
            if (($l['from'] ?? '') !== $a['slug'] || count($mine) >= LINKS_MAX) continue;
            $to = (string) ($l['to'] ?? '');
            $anchor = trim((string) ($l['anchor'] ?? ''));
            if ($to === $a['slug'] || !isset($by[$to]) || in_array($to, array_column($mine, 'to'), true)) continue;
            if (mb_strlen($anchor) < 4 || auto_links($a['body_md'], [['to' => $to, 'anchor' => $anchor]]) === $a['body_md']) continue;
            $mine[] = ['to' => $to, 'anchor' => $anchor];
        }
        $map[$a['slug']] = ['fp' => links_fp($a, $live), 'links' => $mine];
        $n += count($mine);
    }
    write_json(links_file(), $map);
    return $n;
}

// ---------------------------------------------------------------- видимость в ИИ-ответах (GEO)

/** Спросить Claude с поиском в интернете, как спросил бы человек. → [текст, адреса источников]. */
function geo_ask_claude(string $q, ?callable $http = null): array
{
    $key = trim((string) (settings()['anthropic_key'] ?? ''));
    [$code, $d] = http_json('POST', 'https://api.anthropic.com/v1/messages',
        ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        (string) json_encode(['model' => GEO_CLAUDE_MODEL, 'max_tokens' => 2000, 'messages' => [['role' => 'user', 'content' => $q]],
            // без user_location: поиск Claude не принимает регион KZ (ошибка 400); город и страна есть в самих вопросах
            'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]]],
            JSON_UNESCAPED_UNICODE), 100, $http);
    if ($code !== 200) throw new AiError("Claude ($code): " . ($d['error']['message'] ?? 'ошибка'));
    report_ai_spend((string) ($d['model'] ?? GEO_CLAUDE_MODEL), (array) ($d['usage'] ?? []));
    $text = '';
    $urls = [];
    foreach ($d['content'] ?? [] as $b) {
        if (($b['type'] ?? '') !== 'text') continue;
        $text .= $b['text'];
        foreach ($b['citations'] ?? [] as $c) if (!empty($c['url'])) $urls[] = $c['url'];
    }
    return [$text, array_values(array_unique($urls))];
}

/** То же в ChatGPT (OpenAI Responses API с поиском). */
function geo_ask_openai(string $q, ?callable $http = null): array
{
    $key = trim((string) (settings()['openai_key'] ?? ''));
    $last = '';
    foreach (GEO_OPENAI_MODELS as $model) {
        $body = ['model' => $model, 'input' => $q,
            'tools' => [['type' => 'web_search', 'user_location' => ['type' => 'approximate', 'country' => 'KZ', 'city' => 'Almaty']]]];
        if (str_starts_with($model, 'gpt-5')) $body['reasoning'] = ['effort' => 'low'];
        [$code, $d] = http_json('POST', 'https://api.openai.com/v1/responses',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], (string) json_encode($body, JSON_UNESCAPED_UNICODE), 100, $http);
        if ($code === 200) {
            $text = '';
            $urls = [];
            foreach ($d['output'] ?? [] as $item) {
                if (($item['type'] ?? '') !== 'message') continue;
                foreach ($item['content'] ?? [] as $c) {
                    if (($c['type'] ?? '') !== 'output_text') continue;
                    $text .= $c['text'];
                    foreach ($c['annotations'] ?? [] as $an) if (!empty($an['url'])) $urls[] = $an['url'];
                }
            }
            return [$text, array_values(array_unique($urls))];
        }
        $last = "ChatGPT ($code): " . ($d['error']['message'] ?? 'ошибка');
        if (!in_array($code, [400, 403, 404], true)) break;   // модель недоступна этому ключу — пробуем следующую
    }
    throw new AiError($last);
}

function host_of(string $url): string { return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))); }

/** Ответ ИИ → упомянули ли сайт, сослались ли на сайт, на какие сайты ссылались вместо нас. */
function geo_result(string $engine, string $prompt, string $text, array $urls): array
{
    $hosts = array_values(array_unique(array_filter(array_map('host_of', $urls))));
    $excerpt = '';
    if (preg_match(P('brand_re'), $text, $m, PREG_OFFSET_CAPTURE)) {
        $start = max(0, $m[0][1] - 120);
        $excerpt = '…' . trim(mb_strcut($text, $start, 320)) . '…';
    }
    return ['engine' => $engine, 'prompt' => $prompt, 'mentioned' => $excerpt !== '',
        'cited' => in_array(SITE_HOST, $hosts, true), 'excerpt' => $excerpt,
        'sites' => array_values(array_filter($hosts, fn($h) => $h !== SITE_HOST))];
}

function geo_engines(): array
{
    $s = settings();
    return array_merge(trim((string) ($s['openai_key'] ?? '')) !== '' ? ['chatgpt'] : [],
        trim((string) ($s['anthropic_key'] ?? '')) !== '' ? ['claude'] : []);
}

const ENGINE_NAMES = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];

// ---------------------------------------------------------------- расписание

function seo_plan(bool $full = true): array
{
    $steps = [];
    if ($full && gsc_account()) array_push($steps, 'gsc', 'tune');
    if (trim((string) (settings()['anthropic_key'] ?? '')) !== '') {
        foreach (array_chunk(links_due(), LINKS_PER_STEP) as $chunk) $steps[] = 'links:' . implode(',', $chunk);
    }
    if ($full) {
        foreach (geo_engines() as $e) foreach (seo_settings()['geo_prompts'] as $i => $_) $steps[] = "geo:$e:$i";
        if ($steps) $steps[] = 'report';
    }
    return $steps;
}

function seo_new_run(array $steps, bool $full): array
{
    return ['id' => bin2hex(random_bytes(4)), 'started' => time(), 'full' => $full, 'steps' => $steps, 'i' => 0,
        'tries' => 0, 'busy' => false, 'touched' => 0, 'results' => [], 'errors' => []];
}

/** Запустить полную проверку сейчас (кнопка в админке). → false, если проверка уже идёт. */
function seo_start(?callable $fire = null): bool
{
    $s = seo_locked(function () {
        $s = seo_state();
        if (!empty($s['run'])) return null;
        $steps = seo_plan();
        if (!$steps) throw new InvalidArgumentException('Нечего проверять: подключите Search Console или добавьте ключи ИИ в «Настройках»');
        $s['secret'] ??= bin2hex(random_bytes(16));
        $s['run'] = seo_new_run($steps, true);
        $s['kicked'] = time();
        seo_save($s);
        return $s;
    });
    if (!$s) return false;
    seo_fire($s, $fire);
    return true;
}

/**
 * Вызывается при заходах на сайт: не пора ли проверять, и не застряла ли проверка. Дёшево — раз в 10 минут
 * читает один файл. Пора — запускает проверку на хостинге отдельным запросом и сразу возвращается.
 */
function seo_maybe_kick(?callable $fire = null, ?int $now = null): void
{
    $now ??= time();
    $s = seo_state();
    $run = $s['run'] ?? null;
    if ($run && ($now - (int) $run['touched'] < SEO_STEP_LEASE || $now - (int) ($s['kicked'] ?? 0) < 60)) return;
    if (!$run && $now - (int) ($s['checked'] ?? 0) < SEO_CHECK_EVERY) return;
    $s = seo_locked(function () use ($now) {
        $s = seo_state();
        $s['secret'] ??= bin2hex(random_bytes(16));
        if (empty($s['run'])) {
            $s['checked'] = $now;
            $full = $now - (int) ($s['last_full'] ?? 0) >= SEO_EVERY;
            $steps = seo_plan($full);
            if (!$steps) { seo_save($s); return null; }
            $s['run'] = seo_new_run($steps, $full);
        } elseif ($now - (int) $s['run']['touched'] < SEO_STEP_LEASE) {
            return null;
        }
        $s['kicked'] = $now;
        seo_save($s);
        return $s;
    });
    if ($s) seo_fire($s, $fire);
}

/** Запрос на /api/seo-tick.php без ожидания ответа: шаг доработает на хостинге сам. */
function seo_fire(array $s, ?callable $fire = null): void
{
    $url = parts()['site_url'] . '/api/seo-tick.php?k=' . $s['secret'];
    if ($fire) { $fire($url); return; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1500, CURLOPT_NOSIGNAL => true, CURLOPT_CONNECTTIMEOUT_MS => 1000]);
    curl_exec($ch);
}

/** Один шаг проверки. $exec — подмена шагов в тестах: fn(string $step, array $run): array. */
function seo_tick(?callable $fire = null, ?callable $exec = null): ?string
{
    $claim = seo_locked(function () {
        $s = seo_state();
        $r = $s['run'] ?? null;
        if (!$r) return null;
        if (!empty($r['busy']) && time() - (int) $r['touched'] < SEO_STEP_LEASE) return null;   // шаг уже идёт
        if (!empty($r['busy'])) {   // прошлая попытка оборвалась
            $r['tries']++;
            if ($r['tries'] >= 2) {
                $r['errors'][] = $r['steps'][$r['i']] . ': прервался на хостинге';
                $r['i']++;
                $r['tries'] = 0;
            }
        }
        if ($r['i'] >= count($r['steps'])) { seo_finish($s, $r); return null; }
        $r['busy'] = true;
        $r['touched'] = time();
        $s['run'] = $r;
        seo_save($s);
        return [$r['steps'][$r['i']], $r];
    });
    if (!$claim) return null;
    [$step, $run] = $claim;
    @set_time_limit(300);
    $result = null;
    $error = null;
    try {
        $result = $exec ? $exec($step, $run) : seo_step($step, $run);
    } catch (Throwable $ex) {
        if (!$ex instanceof AiError) error_log("seo $step: $ex");
        $error = $ex->getMessage();
    }
    $s = seo_locked(function () use ($step, $result, $error) {
        $s = seo_state();
        $r = $s['run'] ?? null;
        if (!$r || ($r['steps'][$r['i']] ?? '') !== $step) return null;
        if ($error !== null) $r['errors'][] = "$step: $error";
        else $r['results'][$step] = $result;
        $r['i']++;
        $r['tries'] = 0;
        $r['busy'] = false;
        $r['touched'] = time();
        if ($r['i'] >= count($r['steps'])) { seo_finish($s, $r); return null; }
        $s['run'] = $r;
        $s['kicked'] = time();
        seo_save($s);
        return $s;
    });
    if ($s) seo_fire($s, $fire);
    return $step;
}

function seo_finish(array &$s, array $r): void
{
    if ($r['full']) $s['last_full'] = $r['started'];
    $s['last_run'] = ['id' => $r['id'], 'started' => $r['started'], 'finished' => time(), 'full' => $r['full'], 'errors' => $r['errors']];
    unset($s['run']);
    seo_save($s);
}

function seo_step(string $step, array $run): array
{
    [$kind, $arg] = array_pad(explode(':', $step, 2), 2, '');
    switch ($kind) {
        case 'gsc':
            $an = seo_analyze(gsc_fetch());
            write_json(seo_dir() . '/analysis.json', $an + ['created' => now()->format('Y-m-d H:i')]);
            return ['queries' => count($an['queries'])];
        case 'tune':
            return seo_tune();
        case 'links':
            return ['links' => make_links(explode(',', $arg))];
        case 'geo':
            [$engine, $i] = explode(':', $arg);
            $prompt = seo_settings()['geo_prompts'][(int) $i] ?? '';
            if ($prompt === '') return ['skipped' => true];
            [$text, $urls] = $engine === 'claude' ? geo_ask_claude($prompt) : geo_ask_openai($prompt);
            return geo_result($engine, $prompt, $text, $urls);
        case 'report':
            return seo_report($run);
    }
    throw new RuntimeException("Неизвестный шаг $step");
}

/** Правки статей по данным недели: предложить (или сразу применить, если включено в настройках). */
function seo_tune(): array
{
    $an = read_json(seo_dir() . '/analysis.json');
    if (!$an || !$an['queries']) return ['articles' => 0, 'note' => 'запросов пока нет'];
    if (!live_articles()) return ['articles' => 0, 'note' => 'нет вышедших статей'];
    $t = tune_articles($an['queries'], $an['pages'], null, tune_notes($an), false);
    $applied = seo_settings()['auto_apply'] ? tune_apply_all() : 0;
    return ['articles' => count($t['articles']), 'new_topics' => count($t['new_topics']), 'applied' => $applied];
}

/** Применить все предложенные и ещё не применённые правки (кнопка в Telegram или автоприменение). */
function tune_apply_all(): int
{
    $t = read_json(DATA_DIR . '/tune.json') ?? ['articles' => []];
    $n = 0;
    foreach ($t['articles'] as $x) {
        if (!empty($x['applied'])) continue;
        try {
            $a = apply_tune($x['slug'], $x);
            tune_mark_applied($x['slug']);
            try { indexnow_article($a); } catch (Throwable $ex) { error_log('indexnow: ' . $ex->getMessage()); }
            $n++;
        } catch (Throwable $ex) {
            error_log('tune apply ' . $x['slug'] . ': ' . $ex->getMessage());
        }
    }
    return $n;
}

// ---------------------------------------------------------------- отчёт

/** « (+12%)» / « (−3)» к прошлому периоду; не с чем сравнивать (данных тогда не было) — пусто. */
function delta(float $now, float $was, bool $pct = false): string
{
    if ($was == $now || $was == 0) return '';
    $d = $pct ? round(($now - $was) / $was * 100) : round($now - $was, 1);
    return ' (' . ($d > 0 ? '+' : '−') . rtrim(rtrim(number_format(abs($d), 1, '.', ''), '0'), '.') . ($pct ? '%' : '') . ')';
}

function seo_report(array $run, ?callable $tg = null): array
{
    $res = $run['results'];
    $an = isset($res['gsc']) ? read_json(seo_dir() . '/analysis.json') : null;
    $geo = array_values(array_filter($res, fn($r, $k) => str_starts_with($k, 'geo:') && empty($r['skipped']), ARRAY_FILTER_USE_BOTH));
    $hist = read_json(seo_dir() . '/history.json') ?? [];
    $prevGeo = $hist ? end($hist) : null;
    $live = live_articles();
    $lint = [];
    foreach ($live as $a) if ($issues = seo_lint($a)) $lint[] = ['slug' => $a['slug'], 'title' => $a['title'], 'issues' => $issues];
    $links = array_sum(array_map(fn($r) => (int) ($r['links'] ?? 0), array_filter($res, fn($k) => str_starts_with($k, 'links:'), ARRAY_FILTER_USE_KEY)));
    $hits = count(array_filter($geo, fn($g) => $g['mentioned'] || $g['cited']));
    $sites = [];
    foreach ($geo as $g) foreach ($g['sites'] as $h) $sites[$h] = ($sites[$h] ?? 0) + 1;
    arsort($sites);

    $report = ['created' => now()->format('Y-m-d H:i'), 'analysis' => $an, 'tune' => $res['tune'] ?? null, 'links' => $links,
        'geo' => $geo, 'geo_hits' => $hits, 'geo_sites' => array_slice($sites, 0, 10, true), 'lint' => $lint, 'errors' => $run['errors']];
    write_json(seo_dir() . '/report.json', $report);
    $hist[] = ['date' => now()->format('Y-m-d'), 'geo_hits' => $hits, 'geo_total' => count($geo)]
        + ($an ? ['clicks' => $an['totals']['cur']['clicks'], 'impr' => $an['totals']['cur']['impr'], 'pos' => $an['totals']['cur']['pos']] : []);
    write_json(seo_dir() . '/history.json', array_slice($hist, -26));

    // --- сообщение в Telegram
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $lines = ['📈 <b>SEO за неделю</b> · ' . SITE_HOST];
    if ($an) {
        [$c, $p] = [$an['totals']['cur'], $an['totals']['prev']];
        $lines[] = "Google, 28 дней: клики {$c['clicks']}" . ($p['impr'] ? delta($c['clicks'], $p['clicks']) : '') . ", показы {$c['impr']}" . delta($c['impr'], $p['impr'], true)
            . ", средняя позиция {$c['pos']}" . ($p['impr'] ? " (была {$p['pos']})" : '');
        if ($an['quick']) {
            $lines[] = "\n🎯 <b>Близко к топу</b> — чуть подтянуть, и будут клики:";
            foreach (array_slice($an['quick'], 0, 5) as $q) $lines[] = "• «{$e($q['q'])}» — {$q['pos']} место, {$q['impr']} показов";
        }
        if ($an['decay']) {
            $lines[] = "\n📉 <b>Просели</b>:";
            foreach (array_slice($an['decay'], 0, 3) as $d) $lines[] = "• {$e($d['page'])} — показы {$d['impr'][0]} → {$d['impr'][1]}";
        }
        if ($an['cannibal']) {
            $lines[] = "\n⚔️ <b>Мешают друг другу</b> (одна тема — две страницы):";
            foreach (array_slice($an['cannibal'], 0, 3) as $c2) $lines[] = "• «{$e($c2['q'])}» — " . $e(implode(' и ', array_column($c2['pages'], 0)));
        }
        if ($an['low_ctr']) {
            $lines[] = "\n👀 <b>Показы есть, кликов мало</b>:";
            foreach (array_slice($an['low_ctr'], 0, 3) as $l) $lines[] = "• {$e($l['page'])} — {$l['pos']} место, CTR {$l['ctr']}%";
        }
    } elseif (!gsc_account()) {
        $lines[] = 'Search Console не подключена — подключите во вкладке «SEO» админки, тогда будут позиции, просадки и правки статей.';
    }
    $tune = $res['tune'] ?? null;
    $pending = 0;
    if ($tune && $tune['articles']) {
        $pending = $tune['articles'] - $tune['applied'];
        $lines[] = "\n✏️ <b>Правки статей</b>: " . ($tune['applied'] ? "применены автоматически в {$tune['applied']} из {$tune['articles']}"
            : "предложены для {$tune['articles']} " . plural($tune['articles'], 'статьи', 'статей', 'статей'))
            . ($tune['new_topics'] ? ", новых тем в «Темах статей»: {$tune['new_topics']}" : '');
    }
    if ($links) $lines[] = "\n🔗 Новых ссылок между статьями: $links";
    if ($geo) {
        $per = [];
        foreach ($geo as $g) {
            $per[$g['engine']][0] = ($per[$g['engine']][0] ?? 0) + (int) ($g['mentioned'] || $g['cited']);
            $per[$g['engine']][1] = ($per[$g['engine']][1] ?? 0) + 1;
        }
        $parts = implode(', ', array_map(fn($k, $v) => (ENGINE_NAMES[$k] ?? $k) . " {$v[0]}/{$v[1]}", array_keys($per), $per));
        $lines[] = "\n🤖 <b>ИИ-ответы</b>: " . P('geo_who') . " упомянули в $hits из " . count($geo) . " ($parts)"
            . ($prevGeo && isset($prevGeo['geo_total']) && $prevGeo['geo_total'] ? ", в прошлый раз {$prevGeo['geo_hits']} из {$prevGeo['geo_total']}" : '');
        if ($sites) $lines[] = 'Чаще ссылаются на: ' . $e(implode(', ', array_slice(array_keys($sites), 0, 5)));
    }
    if ($lint) $lines[] = "\n🧰 Статей с замечаниями по оформлению: " . count($lint) . ' (подробности — во вкладке «SEO»)';
    if ($run['errors']) $lines[] = "\n⚠️ Не получилось: " . $e(implode('; ', array_slice($run['errors'], 0, 3)));
    $kb = [];
    if ($pending > 0) $kb[] = [['text' => "✅ Применить правки ($pending)", 'callback_data' => 'seo:apply']];
    $kb[] = [['text' => 'Открыть отчёт в админке', 'url' => parts()['site_url'] . '/admin/#seo']];
    $sent = false;
    if (tg_owner() !== '') {
        $st = tg_state();
        if (($st['hook_v'] ?? 0) < 2 && !empty($st['hook_secret']) && !$tg) {   // включить кнопки под сообщениями бота
            if (tg_ensure_webhook()) { $st = tg_state(); $st['hook_v'] = 2; tg_save_state($st); }
        }
        $sent = !empty(tg_send(tg_owner(), implode("\n", $lines), $tg, ['inline_keyboard' => $kb])['ok']);
    }
    return ['sent' => $sent, 'geo_hits' => $hits];
}

function plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    if ($n >= 11 && $n <= 19) return $many;
    return [$many, $one, $few, $few, $few, $many, $many, $many, $many, $many][$n % 10];
}

/** Кнопка под отчётом в Telegram. → текст ответа владельцу. */
function seo_tg_callback(string $data): string
{
    if ($data !== 'seo:apply') return '';
    $n = tune_apply_all();
    return $n ? "✅ Правки применены в $n " . plural($n, 'статье', 'статьях', 'статьях') . ' — уже на сайте, Bing и Яндекс уведомлены.'
        : 'Нечего применять — правки уже применены.';
}

/** Сводка для вкладки «SEO» в админке. */
function seo_view(): array
{
    $s = seo_state();
    $run = $s['run'] ?? null;
    $map = read_json(links_file()) ?? [];
    $live = array_column(live_articles(), 'title', 'slug');
    $links = [];
    foreach ($map as $from => $x) foreach ($x['links'] as $l) {
        if (isset($live[$from], $live[$l['to']])) $links[] = ['from' => $live[$from], 'from_slug' => $from, 'to' => $live[$l['to']], 'anchor' => $l['anchor']];
    }
    $last = (int) ($s['last_full'] ?? 0);
    $sa = gsc_account();
    return [
        'running' => $run ? ['step' => $run['steps'][$run['i']] ?? '', 'i' => $run['i'], 'n' => count($run['steps']), 'full' => $run['full']] : null,
        'last' => $last ? local_time($last, 'Y-m-d H:i') : '', 'next' => $last ? local_time($last + SEO_EVERY, 'Y-m-d') : '',
        'report' => read_json(seo_dir() . '/report.json'), 'history' => read_json(seo_dir() . '/history.json') ?? [],
        'links' => $links, 'gsc' => $sa ? $sa['client_email'] : '', 'engines' => geo_engines(),
        'telegram' => tg_owner() !== '',
    ] + seo_settings();
}
