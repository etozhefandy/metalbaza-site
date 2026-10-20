<?php
// ИИ для админки: статьи (Claude), картинки (OpenAI), идеи тем (подсказки Google/Яндекса → Claude).
// Ключи хранятся в DATA_DIR/settings.json (вне сайта), вводятся на странице «Настройки».
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/_net.php';
require_once __DIR__ . '/lib.php';

const CLAUDE_MODEL = 'claude-opus-5-5';   // по умолчанию; выбирается в «Настройках» админки
// Модели Claude для статей, тем, правок и перелинковки. Цены — за 1 млн токенов (вход / выход), anthropic.com/pricing.
const CLAUDE_MODELS = [
    'claude-opus-5-5' => 'Opus 5.5 — сильнее всего ($4 / $20)',
    'claude-sonnet-5-5' => 'Sonnet 5.5 — вдвое дешевле, для статей обычно хватает ($2 / $10)',
];
const IMAGE_MODEL = 'gpt-image-2';
const IMAGE_FALLBACK = 'gpt-image-1.5';  // gpt-image-1 и dall-e-3 OpenAI выводит из работы
const IMAGE_QUALITY = 'medium';

class AiError extends RuntimeException {}

function settings(): array { return read_json(DATA_DIR . '/settings.json') ?? []; }

function save_settings(array $s): void
{
    write_json(DATA_DIR . '/settings.json', $s);
    @chmod(DATA_DIR . '/settings.json', 0600);
}

/** Разделы сайта, на которые ведут статьи: товары каталога (slug, name, short, price). */
function services(): array { return parts()['services']; }

/**
 * Тексты ниши (кто пишет, для кого, правила цен, стиль картинок, затравки поиска, вопросы ИИ) — у каждого сайта свои:
 * _blog/prompts.php сайта (образец — prompts.example.php движка). {name} в тексте подставляется из $vars.
 */
function prompts(): array
{
    static $p = null;
    if ($p === null) {
        $file = is_file(__DIR__ . '/prompts.php') ? __DIR__ . '/prompts.php' : __DIR__ . '/prompts.example.php';
        $p = require $file;
    }
    return $p;
}

function P(string $key, array $vars = [])
{
    $v = prompts()[$key] ?? '';
    if (!is_string($v)) return $v;
    $map = [];
    foreach ($vars as $k => $x) $map['{' . $k . '}'] = (string) $x;
    return strtr($v, $map);
}

// ---------------------------------------------------------------- учёт трат Claude (бот autoalfimov)

/**
 * Сообщить боту, сколько токенов ушло на ответ Claude: баланс Anthropic общий у сайтов и ботов, а остаток владелец
 * смотрит в Mini App бота («💰 Балансы API»). Строка подключения — из бота («🔌 Подключить проект»):
 * `https://<бот>/api/public/ai-spend#aisp_…`, хранится в settings.json (`spend_link`, админка → Настройки).
 * Не задана — ничего не шлём. Ошибки сети молча пропускаем: учёт не должен мешать статье.
 */
function report_ai_spend(string $model, array $usage): void
{
    $link = trim((string) (settings()['spend_link'] ?? ''));
    if ($link === '' || !$usage || !str_contains($link, '#')) return;
    [$url, $token] = explode('#', $link, 2);
    $payload = json_encode(['model' => $model, 'usage' => $usage], JSON_UNESCAPED_UNICODE);
    if (getenv('ALFIMOV_OFFLINE')) { $GLOBALS['ai_spend_sent'][] = [$url, $token, json_decode($payload, true)]; return; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'content-type: application/json'], CURLOPT_POSTFIELDS => $payload]);
    @curl_exec($ch);
}

const SPEND_LINK_RE = '~^https://[^\s#]+/api/public/ai-spend#aisp_[A-Za-z0-9_-]{20,}$~';

// ---------------------------------------------------------------- Claude

/**
 * Запрос к Claude со стримингом (длинные ответы без обрыва по таймауту) → [текст, stop_reason].
 * $post — подмена HTTP для тестов: fn(array $body): array [http_code, raw_sse_or_json].
 */
/**
 * Модель для одной статьи из очереди (выбрана при постановке): claude_override('claude-…') — задать,
 * claude_override(null) — сбросить, claude_override() — узнать.
 */
function claude_override(?string $m = null): ?string
{
    static $o = null;
    if (func_num_args()) $o = $m !== null && isset(CLAUDE_MODELS[$m]) ? $m : null;
    return $o;
}

/** Модель: выбранная для статьи, иначе из «Настроек» (неизвестная или пустая — по умолчанию). */
function claude_model(): string
{
    if ($o = claude_override()) return $o;
    $m = (string) (settings()['claude_model'] ?? '');
    return isset(CLAUDE_MODELS[$m]) ? $m : CLAUDE_MODEL;
}

function claude(array $body, ?callable $post = null): array
{
    $key = trim((string) (settings()['anthropic_key'] ?? ''));
    if ($post === null && $key === '') throw new AiError('Нет ключа Claude — вставьте его в «Настройках» админки');
    $body = ['model' => claude_model(), 'stream' => true] + $body;
    if ($post) {
        [$code, $raw] = $post($body);
    } else {
        @set_time_limit(600);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 540, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($raw === false) throw new AiError('Нет связи с Claude API: ' . $err);
    }
    if ($code !== 200) {
        $msg = json_decode((string) $raw, true)['error']['message'] ?? '';
        if ($code === 401) throw new AiError('Claude отклонил ключ — проверьте его в «Настройках»');
        if ($code === 429) throw new AiError('Лимит запросов Claude — попробуйте через минуту');
        if ($code === 529) throw new AiError('Claude сейчас перегружен — попробуйте через пару минут');
        throw new AiError("Ошибка Claude API ($code)" . ($msg ? ": $msg" : ''));
    }
    $text = '';
    $stop = '';
    $usage = [];
    $model = (string) $body['model'];
    foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
        if (!str_starts_with($line, 'data:')) continue;
        $ev = json_decode(trim(substr($line, 5)), true);
        if (!is_array($ev)) continue;
        $type = $ev['type'] ?? '';
        if ($type === 'content_block_delta' && ($ev['delta']['type'] ?? '') === 'text_delta') $text .= $ev['delta']['text'];
        elseif ($type === 'message_start') {
            $usage = (array) ($ev['message']['usage'] ?? []);
            $model = (string) ($ev['message']['model'] ?? $model);
        } elseif ($type === 'message_delta') {
            $stop = $ev['delta']['stop_reason'] ?? $stop;
            $usage = array_merge($usage, (array) ($ev['usage'] ?? []));   // итог по выходу (и поиску) — в конце
            report_ai_spend($model, $usage);
        } elseif ($type === 'error') {
            $t = $ev['error']['type'] ?? '';
            if ($t === 'overloaded_error') throw new AiError('Claude сейчас перегружен — попробуйте через пару минут');
            throw new AiError('Ошибка Claude: ' . ($ev['error']['message'] ?? $t));
        }
    }
    if ($stop === 'refusal') throw new AiError('Claude отказался выполнять запрос — переформулируйте тему');
    if ($stop === 'max_tokens') throw new AiError('Ответ не уместился в лимит — уменьшите объём');
    return [$text, $stop];
}

function claude_json(array $body, ?callable $post = null): array
{
    [$text] = claude($body, $post);
    $data = json_decode($text, true);
    if (!is_array($data)) throw new AiError('Claude вернул неразборчивый ответ — попробуйте ещё раз');
    return $data;
}

// ---------------------------------------------------------------- статьи

const ARTICLE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'slug' => ['type' => 'string', 'description' => 'латиница, цифры, дефис; 3–6 слов'],
        'title' => ['type' => 'string', 'description' => 'h1 для человека'],
        'seo_title' => ['type' => 'string', 'description' => '<title> до 60 символов, главный ключ в начале'],
        'description' => ['type' => 'string', 'description' => 'meta description 140–160 символов'],
        'lead' => ['type' => 'string', 'description' => 'подзаголовок под h1, 1–2 предложения'],
        'summary' => ['type' => 'string', 'description' => 'блок «Коротко»: суть статьи в 2–3 предложениях'],
        'category' => ['type' => 'string'],
        'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
        'body_md' => ['type' => 'string', 'description' => 'тело статьи в markdown, без h1'],
        'faq' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string'], 'a' => ['type' => 'string']],
            'required' => ['q', 'a'], 'additionalProperties' => false,
        ]],
        'related' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'slug существующих статей'],
    ],
    'required' => ['slug', 'title', 'seo_title', 'description', 'lead', 'summary', 'category', 'keywords', 'body_md', 'faq', 'related'],
    'additionalProperties' => false,
];

function writer_system_prompt(): string
{
    $services = implode("\n", array_map(fn($s) => "- {$s['name']} — /{$s['slug']}/ — {$s['short']}", services()));
    return P('writer', ['services' => $services]);
}

function writer_user_prompt(string $topic, array $keywords, string $notes, int $words, array $existing): string
{
    $lines = [trim($topic) !== '' ? 'Тема статьи: ' . trim($topic) : 'Тема статьи: выбери сам по источнику — то, что полезно читателю сайта.'];
    if ($keywords) $lines[] = 'Поисковые запросы (первый — главный): ' . implode('; ', $keywords);
    $lines[] = "Объём тела: около $words слов.";
    if (trim($notes) !== '') $lines[] = 'Пожелания редактора: ' . trim($notes);
    $lines[] = $existing
        ? "Существующие статьи блога (slug — заголовок):\n" . implode("\n", array_map(fn($a) => "- {$a['slug']} — {$a['title']}", $existing))
        : 'Других статей в блоге пока нет.';
    return implode("\n", $lines);
}

/** Черновик статьи: все SEO-поля + тело. Публикует всегда человек.
 *  $source — материал-повод (статья, пост в Instagram): ['url','title','text','images' => [[mime, base64], …]]. */
function write_article(string $topic, array $keywords, string $notes, int $words, array $existing, ?callable $post = null, ?array $source = null): array
{
    $has_source = $source && (trim((string) ($source['text'] ?? '')) !== '' || !empty($source['images']));
    if (trim($topic) === '' && !$has_source) throw new AiError('Укажите тему статьи или источник');
    $keywords = str_list($keywords);
    $text = writer_user_prompt($topic, $keywords, $notes, $words, $existing);
    $content = $text;
    if ($has_source) {
        $content = [];
        foreach ($source['images'] ?? [] as [$mime, $b64]) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
        }
        $content[] = ['type' => 'text', 'text' => source_prompt($source) . "\n\n" . $text];
    }
    $data = claude_json([
        'max_tokens' => 32000,
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => ARTICLE_SCHEMA]],
        'system' => writer_system_prompt() . ($has_source ? "\n" . source_rules() : ''),
        'messages' => [['role' => 'user', 'content' => $content]],
    ], $post);
    $known = array_column($existing, 'slug');
    $data['faq'] = array_map(fn($f) => [$f['q'] ?? '', $f['a'] ?? ''], $data['faq'] ?? []);
    $data['related'] = array_values(array_filter($data['related'] ?? [], fn($s) => in_array($s, $known, true)));
    $data['keywords'] = ($data['keywords'] ?? []) ?: $keywords;
    if (!preg_match(SLUG_RE, (string) ($data['slug'] ?? ''))) $data['slug'] = slugify((string) ($data['slug'] ?? $data['title'] ?? ''));
    return $data;
}

// ---------------------------------------------------------------- статья по источнику

// Свежий материал (новость, пост в Instagram) — повод для статьи «в повестке». Свой текст, а не пересказ:
// поисковики не ранжируют копии, а чужой текст целиком брать нельзя.
function source_rules(): string { return P('source_rules'); }

function source_prompt(array $source): string
{
    $lines = ['ИСТОЧНИК (информационный повод для статьи):'];
    if (!empty($source['url'])) $lines[] = 'Адрес: ' . $source['url'];
    if (!empty($source['title'])) $lines[] = 'Заголовок: ' . $source['title'];
    if (!empty($source['images'])) $lines[] = 'Скриншоты/картинки источника приложены выше — прочитай текст на них.';
    if (trim((string) ($source['text'] ?? '')) !== '') $lines[] = "Текст:\n" . mb_substr(trim($source['text']), 0, 20000);
    return implode("\n", $lines);
}

/** Только публичные адреса: сервер не должен ходить во внутреннюю сеть хостинга (проверка — api/_net.php). */
function public_url(string $url): bool { return net_target($url, true) !== null; }

function http_get(string $url, string $ua, int $max = 3_000_000, ?array $target = null): array
{
    $ch = curl_init($url);
    if ($target) net_pin($ch, $target);   // соединяемся ровно с проверенным адресом
    $buf = '';
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => ['Accept-Language: ru-RU,ru;q=0.9,kk;q=0.8,en;q=0.5'], CURLOPT_ENCODING => '',
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, $max) {
            $buf .= $chunk;
            return strlen($buf) > $max ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $info = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    return [$buf, $info];
}

/** Скачивает страницу по ссылке (редиректы — вручную, с проверкой каждого адреса). */
function fetch_page(string $url, string $ua): array
{
    for ($hop = 0; $hop < 5; $hop++) {
        $t = net_target($url, true);
        if (!$t) throw new AiError('Ссылка не открывается: нужен обычный адрес сайта http(s)://…');
        [$body, $info] = http_get($url, $ua, 3_000_000, $t);
        if ($info['code'] >= 300 && $info['code'] < 400 && $info['location']) { $url = $info['location']; continue; }
        if ($info['code'] !== 200 || $body === '') throw new AiError("Сайт по ссылке не отдал страницу (код {$info['code']}) — вставьте текст или скриншот");
        return [$body, $info['type'], $url];
    }
    throw new AiError('Слишком много перенаправлений по ссылке');
}

function to_utf8(string $html, string $ctype): string
{
    if (preg_match('/charset=([\w-]+)/i', $ctype, $m) || preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m)) {
        $cs = strtoupper($m[1]);
        if ($cs !== 'UTF-8' && $cs !== 'UTF8') $html = (string) @mb_convert_encoding($html, 'UTF-8', $cs);
    }
    return mb_check_encoding($html, 'UTF-8') ? $html : (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1251');
}

function meta_tag(string $html, string $name): string
{
    foreach (['property', 'name'] as $attr) {
        if (preg_match('/<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']*)/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*' . $attr . '=["\']' . preg_quote($name, '/') . '["\']/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    return '';
}

/** HTML статьи → заголовок и основной текст (без меню, скриптов и подвала). */
function extract_source(string $html): array
{
    $title = meta_tag($html, 'og:title');
    if ($title === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $desc = meta_tag($html, 'og:description') ?: meta_tag($html, 'description');
    $body = $html;
    foreach (['article', 'main'] as $tag) {
        if (preg_match_all("#<$tag\b[^>]*>(.*?)</$tag>#is", $html, $m)) {
            usort($m[1], fn($a, $b) => strlen($b) - strlen($a));
            if (strlen(strip_tags($m[1][0])) > 500) { $body = $m[1][0]; break; }
        }
    }
    $body = preg_replace(['#<!--.*?-->#s', '#<(script|style|noscript|svg|nav|header|footer|aside|form|iframe)\b.*?</\1>#is'], ' ', $body);
    $body = preg_replace('#<(br|/p|/h[1-6]|/li|/div|/tr)\b[^>]*>#i', "\n", $body);
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace(["/[ \t\x{00A0}]+/u", "/\n\s*\n+/"], [' ', "\n\n"], $text));
    if (mb_strlen($text) < 200 && $desc !== '') $text = $desc . ($text !== '' ? "\n\n$text" : '');
    return ['title' => $title, 'text' => mb_substr($text, 0, 20000), 'image_url' => meta_tag($html, 'og:image')];
}

/** Картинка по ссылке → [mime, base64] для Claude (до ~4 МБ), иначе null. */
function fetch_image(string $url): ?array
{
    if ($url === '' || !public_url($url)) return null;
    [$bin, $info] = http_get($url, 'Mozilla/5.0', 4_500_000);
    $mime = strtolower(trim(explode(';', $info['type'])[0]));
    if ($info['code'] !== 200 || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || strlen($bin) > 4_400_000) return null;
    return [$mime, base64_encode($bin)];
}

/** og:description поста: «12 likes, 3 comments - user on October 1, 2026: "подпись"» → подпись. */
function insta_caption(string $desc): string
{
    return trim(preg_match('/^[^:]{0,200}:\s*["“](.*)["”]\s*\.?$/su', $desc, $m) ? $m[1] : $desc);
}

/**
 * Источник по ссылке. Instagram отдаёт подпись поста и картинку только «превью-роботам» (как при
 * отправке ссылки в мессенджер), поэтому для него — заголовок facebookexternalhit и разбор og-тегов.
 */
function fetch_source(string $url): array
{
    $url = trim($url);
    $insta = (bool) preg_match('#^https?://(www\.)?instagram\.com/#i', $url);
    $ua = $insta ? 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'
        : 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
    [$html, $ctype, $final] = fetch_page($url, $ua);
    $src = extract_source(to_utf8($html, $ctype));
    if ($insta) {
        $src['text'] = insta_caption(meta_tag($html, 'og:description'));
        if ($src['text'] === '') throw new AiError('Instagram не отдал текст поста — сделайте скриншоты поста и прикрепите их');
    }
    $src['url'] = $final;
    $src['images'] = [];
    if ($insta && ($img = fetch_image($src['image_url']))) $src['images'][] = $img;
    if (mb_strlen($src['text']) < 80 && !$src['images']) throw new AiError('По ссылке почти нет текста — вставьте текст или скриншот');
    return $src;
}

// ---------------------------------------------------------------- картинки

// Визуальный язык сайта. Промпт из слоёв (подход agency-os): постоянные слои держат стиль и качество,
// переменные (сюжет, приём съёмки) дают разнообразие. Цвета — ТОЛЬКО словами: hex-код модель печатает в кадре.
function img_style(): string { return P('img_style'); }
function img_bans(): string { return P('img_bans'); }
function people_rule(): string { return P('people_rule'); }
function no_people_rule(): string { return P('no_people_rule'); }
function people_scenes(): array { return P('people_scenes'); }
// Приёмы съёмки из словаря agency-os, отобраны под спокойный стиль сайта.
const TECHNIQUES = [
    'shot perfectly top-down, objects and people arranged on a plane',
    'low camera angle looking up, heroic monumental perspective',
    'wide shot, the subject small inside a large architectural space',
    'subject rendered as a backlit silhouette against a luminous wall',
    'hard direct light with crisp graphic shadow shapes',
    'composition built on reflections in glass and polished surfaces',
    'graphic cast shadows used as the main compositional element',
    'deliberate play of scale, an ordinary object made giant',
    'vast negative space, the subject small and isolated',
    'perfectly symmetrical centred framing, balanced geometry',
    'shallow depth of field, one subject tack-sharp, the rest melting into soft bokeh',
    'soft atmospheric haze and volumetric window light',
];
const IMG_MODES = ['auto', 'people', 'abstract'];

/** Запасной промпт без Claude: тема + шаблонный сюжет + приём съёмки + стиль. */
function image_prompt(string $kind, string $title, string $lead, string $idea, string $mode = 'auto', ?bool $people = null): string
{
    [$idea, $title, $lead] = [trim($idea), trim($title), trim($lead)];
    if ($idea !== '') $topic = "Idea to show: $idea.";
    elseif ($title !== '') $topic = "A visual metaphor for an article titled «{$title}»" . ($lead !== '' ? " — $lead" : '') . '.';
    else throw new AiError('Опишите картинку или сначала заполните заголовок статьи');
    $people ??= pick_people($mode);
    $scene = $people
        ? 'Scene: ' . people_scenes()[array_rand(people_scenes())] . ', connected to the idea above. ' . people_rule()
        : 'Scene: an abstract still life of objects and materials expressing the idea. ' . no_people_rule();
    return "$topic $scene Camera: " . TECHNIQUES[array_rand(TECHNIQUES)] . '. ' . image_frame($kind) . ' ' . img_style() . ' ' . img_bans();
}

function pick_people(string $mode): bool
{
    return $mode === 'people' || ($mode !== 'abstract' && random_int(0, 1) === 1);
}

function image_frame(string $kind): string
{
    return $kind === 'cover' ? 'Wide 3:2 cover image.' : 'Wide 3:2 in-article illustration that explains the idea at a glance.';
}

// Сначала мысль, потом сцена: Claude формулирует основную мысль статьи (или берёт идею пользователя)
// и придумывает под неё конкретную сцену — картинка объясняет статью, а не просто «про маркетинг».
const SCENE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'main_idea' => ['type' => 'string', 'description' => 'основная мысль одним коротким предложением (до 20 слов), по-русски'],
        'scene' => ['type' => 'string', 'description' => 'описание сцены для генератора картинок, по-английски, 60–110 слов'],
    ],
    'required' => ['main_idea', 'scene'],
    'additionalProperties' => false,
];

/** → ['main_idea' => …, 'scene' => …]. $article: title, lead, summary, body (что есть). */
function image_scene(string $kind, array $article, string $idea, bool $people, string $technique, ?callable $post = null): array
{
    $ctx = [];
    foreach (['title' => 'Заголовок', 'lead' => 'Лид', 'summary' => 'Коротко', 'body' => 'Текст (начало)'] as $k => $label) {
        $v = trim((string) ($article[$k] ?? ''));
        if ($v !== '') $ctx[] = "$label: " . mb_substr($v, 0, $k === 'body' ? 5000 : 600);
    }
    if (!$ctx && trim($idea) === '') throw new AiError('Опишите картинку или сначала заполните заголовок статьи');
    $task = $kind === 'cover' ? 'обложку статьи блога' : 'иллюстрацию внутрь статьи';
    $prompt = implode("\n", [
        P('art_director', ['task' => $task]),
        $ctx ? "Статья:\n" . implode("\n", $ctx) : '',
        trim($idea) !== '' ? "Идея картинки от редактора (главное, опирайся на неё): " . trim($idea) : '',
        '',
        'Шаг 1. main_idea — основная мысль ' . (trim($idea) !== '' ? 'картинки (по идее редактора)' : 'статьи') . ': одно короткое предложение (до 20 слов), что читатель должен понять.',
        'Шаг 2. scene — одна конкретная сцена, которая с первого взгляда передаёт эту мысль: визуальная метафора или ситуация, '
        . 'конкретные предметы, место, свет и композиция. Без абстрактных слов вроде «успех» и «рост» — только то, что можно нарисовать.',
        P('scene_rule'),
        'Приём съёмки (встрои в сцену): ' . $technique . '.',
        $people ? 'Люди: ' . people_rule() : 'Людей нет: ' . no_people_rule(),
        P('scene_bans'),
        'scene пиши по-английски, 60–110 слов, без вступлений.',
    ]);
    $data = claude_json([
        'max_tokens' => 2000,
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => SCENE_SCHEMA]],
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], $post);
    $scene = trim((string) ($data['scene'] ?? ''));
    if ($scene === '') throw new AiError('Claude не придумал сцену');
    return ['main_idea' => trim((string) ($data['main_idea'] ?? '')), 'scene' => preg_replace('/#[0-9a-f]{3,8}\b/i', '', $scene)];
}

function image_error(int $status, string $msg): string
{
    $t = strtolower($msg);
    if ($status === 401 || str_contains($t, 'incorrect api key') || str_contains($t, 'invalid_api_key')) return 'OpenAI отклонил ключ — проверьте его в «Настройках»';
    if (str_contains($t, 'insufficient_quota') || str_contains($t, 'billing') || str_contains($t, 'quota')) return 'На аккаунте OpenAI закончились средства или лимит';
    if ($status === 429) return 'OpenAI перегружен запросами — повторите через минуту';
    if (str_contains($t, 'safety') || str_contains($t, 'moderation') || str_contains($t, 'content_policy') || str_contains($t, 'content policy')) {
        return 'OpenAI отклонил запрос по правилам безопасности — переформулируйте идею картинки';
    }
    return "OpenAI вернул ошибку ($status)" . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '');
}

function openai_image(array $payload, string $key): string
{
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($raw === false) throw new AiError('Нет связи с OpenAI: ' . $err);
    $data = json_decode((string) $raw, true);
    if ($code !== 200) throw new AiError(image_error($code, (string) ($data['error']['message'] ?? '')));
    $bin = base64_decode((string) ($data['data'][0]['b64_json'] ?? ''), true);
    if (!$bin) throw new AiError('OpenAI вернул пустой ответ');
    return $bin;
}

/** → ['bin' => байты webp, 'main_idea' => …, 'scene' => …]. Без ключа Claude — запасной шаблонный промпт. */
function generate_image(string $kind, string $title, string $lead, string $idea, string $mode, array $context = [], ?callable $post = null): array
{
    $key = trim((string) (settings()['openai_key'] ?? ''));
    if ($key === '' && !$post) throw new AiError('Нет ключа OpenAI — вставьте его в «Настройках» админки');
    @set_time_limit(600);
    $mode = in_array($mode, IMG_MODES, true) ? $mode : 'auto';
    $people = pick_people($mode);
    $technique = TECHNIQUES[array_rand(TECHNIQUES)];
    $meta = ['main_idea' => '', 'scene' => ''];
    try {
        $meta = image_scene($kind, ['title' => $title, 'lead' => $lead] + $context, $idea, $people, $technique, $post);
        $prompt = $meta['scene'] . ' ' . image_frame($kind) . ' ' . img_style() . ' ' . img_bans();
    } catch (AiError $ex) {
        if (str_contains($ex->getMessage(), 'Опишите картинку')) throw $ex;
        error_log('image scene fallback: ' . $ex->getMessage());
        $prompt = image_prompt($kind, $title, $lead, $idea, $mode, $people);
    }
    if ($post) return ['bin' => '', 'prompt' => $prompt] + $meta;  // тесты: без похода в OpenAI
    $payload = ['model' => IMAGE_MODEL, 'prompt' => $prompt, 'size' => '1536x1024', 'n' => 1,
        'output_format' => 'webp', 'output_compression' => 82, 'quality' => IMAGE_QUALITY];
    try {
        $bin = openai_image($payload, $key);
    } catch (AiError $first) {
        if (str_contains($first->getMessage(), 'ключ') || str_contains($first->getMessage(), 'средства')) throw $first;
        try {
            $bin = openai_image(['model' => IMAGE_FALLBACK] + $payload, $key);
        } catch (AiError $ignored) {
            throw $first;
        }
    }
    return ['bin' => $bin] + $meta;
}

// ---------------------------------------------------------------- идеи тем

// Ритм: дни недели (пн=1 … вс=7) для 1, 2 и 3 статей в неделю; выход в 10:00 Алматы.
// Регулярность важнее объёма: владельцы бизнеса читают в будни утром.
const SLOT_DAYS = [1 => [2], 2 => [2, 4], 3 => [1, 3, 5]];
const SLOT_HOUR = 10;

const IDEAS_SCHEMA = [
    'type' => 'object',
    'properties' => ['ideas' => ['type' => 'array', 'items' => [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'тема статьи — готовый рабочий заголовок'],
            'main_keyword' => ['type' => 'string', 'description' => 'главный запрос из списка'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3–8 запросов из списка'],
            'intent' => ['type' => 'string', 'enum' => ['коммерческий', 'информационный']],
            'service' => ['type' => 'string', 'description' => 'slug услуги, к которой ведёт статья'],
            'why' => ['type' => 'string', 'description' => 'одно предложение: почему тема стоит статьи'],
        ],
        'required' => ['topic', 'main_keyword', 'keywords', 'intent', 'service', 'why'],
        'additionalProperties' => false,
    ]]],
    'required' => ['ideas'],
    'additionalProperties' => false,
];

/** Подсказки Google (gl=kz) и Яндекса (регион Казахстан) параллельно → [запрос => ['google'=>1,'yandex'=>1]]. */
function collect_queries(?array $seeds = null, ?array $patterns = null): array
{
    $seeds ??= P('seeds');
    $patterns ??= P('seed_patterns');
    $jobs = [];
    foreach ($seeds as $s) {
        foreach ($patterns as $p) {
            $q = rawurlencode(sprintf($p, $s));
            $jobs[] = ['google', "https://suggestqueries.google.com/complete/search?client=firefox&hl=ru&gl=kz&ie=utf-8&oe=utf-8&q=$q"];
            $jobs[] = ['yandex', "https://suggest.yandex.ru/suggest-ff.cgi?part=$q&lr=159&uil=ru"];
        }
    }
    $found = [];
    foreach (array_chunk($jobs, 12) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $i => [$engine, $url]) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (' . SITE_HOST . ' topic ideas)']);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = [$engine, $ch];
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as [$engine, $ch]) {
            $body = (string) curl_multi_getcontent($ch);
            if (!mb_check_encoding($body, 'UTF-8')) $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
            $data = json_decode($body, true);
            foreach ((is_array($data) && is_array($data[1] ?? null)) ? $data[1] : [] as $item) {
                if (!is_string($item)) continue;
                $k = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($item)));
                if ($k !== '') $found[$k][$engine] = 1;
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
    }
    return $found;
}

function ideas_prompt(array $queries, array $existing, string $focus): string
{
    ksort($queries);
    $services = implode("\n", array_map(fn($s) => "- {$s['slug']} — {$s['name']}: {$s['short']}", services()));
    $lines = [];
    foreach ($queries as $q => $src) $lines[] = $q . (count($src) > 1 ? '  [Google+Яндекс]' : '');
    $parts = [
        P('ideas_role'),
        P('catalog_label') . ":\n$services",
        "Реальные запросы из поисковых подсказок Google и Яндекса по Казахстану (пометка [Google+Яндекс] — встречается в обоих, это более надёжный спрос):\n" . implode("\n", $lines),
    ];
    if ($existing) $parts[] = "Статьи, которые уже есть в блоге (заголовок — главный запрос). Не повторяй вопросы, на которые они уже отвечают:\n" . implode("\n", array_map(fn($t) => "- $t", $existing));
    $bl = topics_load();
    $queued = array_column(array_filter($bl, fn($t) => $t['status'] === 'new'), 'topic');
    $rejected = array_column(array_filter($bl, fn($t) => $t['status'] === 'rejected'), 'topic');
    if ($queued) $parts[] = "Темы, которые уже предложены и ждут в списке (не повторяй их):\n" . implode("\n", array_map(fn($t) => "- $t", array_slice($queued, 0, 60)));
    if ($rejected) $parts[] = "Темы, которые редактор отклонил (не предлагай их и похожие на них):\n" . implode("\n", array_map(fn($t) => "- $t", array_slice($rejected, 0, 60)));
    if ($focus !== '') $parts[] = "Пожелание редактора: $focus";
    $parts[] = P('ideas_rules');
    return implode("\n\n", $parts);
}

function propose_ideas(string $focus, ?callable $post = null, ?array $queries = null): array
{
    $queries ??= collect_queries();
    if (!$queries) throw new AiError('Не удалось получить подсказки Google и Яндекса — попробуйте позже');
    $existing = array_map(fn($a) => $a['title'] . ($a['keywords'] ? ' — ' . $a['keywords'][0] : ''), load_all());
    $data = claude_json([
        'max_tokens' => 16000,
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => IDEAS_SCHEMA]],
        'messages' => [['role' => 'user', 'content' => ideas_prompt($queries, $existing, $focus)]],
    ], $post);
    $slugs = array_column(services(), 'slug');
    $ideas = array_map(function ($i) use ($slugs) {
        if (!in_array($i['service'] ?? '', $slugs, true)) $i['service'] = '';
        return $i;
    }, $data['ideas'] ?? []);
    $result = ['ideas' => $ideas, 'queries' => count($queries), 'focus' => $focus, 'created' => now()->format('Y-m-d H:i')];
    write_json(DATA_DIR . '/ideas.json', $result);
    topics_add($ideas, P('all_label'));
    topics_dedupe($post);
    return $result;
}

// ---------------------------------------------------------------- подтянуть статьи под запросы

// Выгрузка Search Console (запросы и страницы с показами и позициями) → Claude предлагает, как подтянуть
// уже вышедшие статьи: заголовок и описание с точными словами запросов, недостающие разделы и вопросы FAQ.
// Применяет человек — по галочкам. Запросы без своей статьи уходят в «Темы статей».
const TUNE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'articles' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'why' => ['type' => 'string', 'description' => '1–2 предложения: какие запросы рядом с топом и что мешает'],
                'queries' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'запросы из выгрузки, под которые подтягиваем'],
                'seo_title' => ['type' => 'string', 'description' => 'новый <title> до 60 символов или пусто, если менять не нужно'],
                'description' => ['type' => 'string', 'description' => 'новый meta description 140–160 символов или пусто'],
                'add_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'sections' => ['type' => 'array', 'items' => ['type' => 'object',
                    'properties' => ['h2' => ['type' => 'string'], 'text' => ['type' => 'string', 'description' => 'markdown, 120–250 слов, без заголовка']],
                    'required' => ['h2', 'text'], 'additionalProperties' => false]],
                'faq' => ['type' => 'array', 'items' => ['type' => 'object',
                    'properties' => ['q' => ['type' => 'string'], 'a' => ['type' => 'string']],
                    'required' => ['q', 'a'], 'additionalProperties' => false]],
            ],
            'required' => ['slug', 'why', 'queries', 'seo_title', 'description', 'add_keywords', 'sections', 'faq'],
            'additionalProperties' => false,
        ]],
        'new_topics' => IDEAS_SCHEMA['properties']['ideas'],
    ],
    'required' => ['articles', 'new_topics'],
    'additionalProperties' => false,
];

function md_h2(string $md): array
{
    preg_match_all('/^##\s+(.+)$/m', $md, $m);
    return $m[1];
}

/** $queries/$pages: [[запрос|адрес, показы, позиция], …] из выгрузки Search Console. */
function tune_articles(array $queries, array $pages, ?callable $post = null, string $notes = '', bool $dedupe = true): array
{
    $site = parts()['site_url'];
    $arts = array_values(array_filter(load_all(), fn($a) => is_live($a)));
    if (!$arts) throw new AiError('На сайте ещё нет вышедших статей');
    $queries = array_slice(array_values(array_filter($queries, fn($r) => trim((string) ($r[0] ?? '')) !== '')), 0, 400);
    if (!$queries) throw new AiError('В файле не нашлось запросов — нужна выгрузка «Эффективность» из Search Console');
    $fmt = fn($r) => trim((string) $r[0]) . ' | показы ' . (int) ($r[1] ?? 0) . ' | позиция ' . round((float) ($r[2] ?? 0), 1);
    $artLines = array_map(fn($a) => "### {$a['slug']}\nАдрес: {$site}" . article_path($a) . "\nЗаголовок: {$a['title']}\nSEO title: " . ($a['seo_title'] ?: '—')
        . "\nОписание: " . ($a['description'] ?: '—') . "\nЗапросы: " . implode(', ', array_slice($a['keywords'], 0, 10))
        . "\nРазделы: " . implode(' / ', md_h2($a['body_md'])) . "\nFAQ: " . implode(' / ', array_column($a['faq'], 0)), $arts);
    $prompt = implode("\n\n", [
        P('tune_role'),
        "Статьи блога:\n" . implode("\n\n", $artLines),
        "Запросы из Google Search Console за последнее время (запрос | показы | средняя позиция):\n" . implode("\n", array_map($fmt, $queries)),
        $pages ? "Страницы (адрес | показы | позиция):\n" . implode("\n", array_map($fmt, array_slice($pages, 0, 60))) : '',
        P('tune_prices', ['prices' => implode("\n", array_map(fn($s) => "- {$s['name']}: {$s['price']}", services()))]),
        $notes,
        P('tune_rules'),
    ]);
    $data = claude_json([
        'max_tokens' => 24000,
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => TUNE_SCHEMA]],
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], $post);
    $by = array_column($arts, null, 'slug');
    $out = [];
    foreach ($data['articles'] ?? [] as $t) {
        $a = $by[$t['slug'] ?? ''] ?? null;
        if (!$a) continue;
        $out[] = ['slug' => $a['slug'], 'title' => $a['title'], 'path' => article_path($a), 'why' => (string) $t['why'],
            'queries' => array_values(array_map('strval', $t['queries'] ?? [])),
            'old' => ['seo_title' => $a['seo_title'] ?: $a['title'], 'description' => $a['description']],
            'seo_title' => trim((string) $t['seo_title']), 'description' => trim((string) $t['description']),
            'add_keywords' => array_values(array_filter(array_map(fn($k) => mb_strtolower(trim((string) $k)), $t['add_keywords'] ?? []),
                fn($k) => $k !== '' && !in_array($k, array_map('mb_strtolower', $a['keywords']), true))),
            'sections' => array_values(array_filter($t['sections'] ?? [], fn($x) => trim($x['h2'] ?? '') !== '' && trim($x['text'] ?? '') !== '')),
            'faq' => array_values(array_filter($t['faq'] ?? [], fn($x) => trim($x['q'] ?? '') !== '' && trim($x['a'] ?? '') !== ''))];
    }
    $topics = $data['new_topics'] ?? [];
    $proposed = [];
    if ($topics) {
        $added = topics_add($topics, 'из Search Console');
        if ($dedupe) topics_dedupe($post);
        // после проверки на повторы часть добавленных могла уйти в «отклонённые» — предлагаем написать только то, что реально осталось новым
        $ids = array_column($added, 'id');
        $byId = $ids ? array_column(topics_load(), null, 'id') : [];
        $proposed = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids), fn($t) => $t && $t['status'] === 'new'));
    }
    $result = ['articles' => $out, 'new_topics' => $proposed, 'queries' => count($queries), 'created' => now()->format('Y-m-d H:i')];
    write_json(DATA_DIR . '/tune.json', $result);
    return $result;
}

/** Отметить в разборе, что правки статьи применены (чтобы не применить дважды из Telegram). */
function tune_mark_applied(string $slug): void
{
    $t = read_json(DATA_DIR . '/tune.json');
    if (!$t) return;
    foreach ($t['articles'] as &$x) if ($x['slug'] === $slug) $x['applied'] = true;
    write_json(DATA_DIR . '/tune.json', $t);
}

/** Применить выбранные правки к статье. Новые разделы встают перед выводом (последний ## «Вывод/Итог…»). */
function apply_tune(string $slug, array $ch): array
{
    $a = get_article($slug);
    if (!$a) throw new InvalidArgumentException('Статья не найдена');
    if (trim((string) ($ch['seo_title'] ?? '')) !== '') $a['seo_title'] = trim((string) $ch['seo_title']);
    if (trim((string) ($ch['description'] ?? '')) !== '') $a['description'] = trim((string) $ch['description']);
    $kw = str_list($ch['add_keywords'] ?? []);
    if ($kw) {
        $first = array_slice($a['keywords'], 0, 1);
        $a['keywords'] = array_values(array_unique(array_merge($first, $kw, array_slice($a['keywords'], 1))));
    }
    $add = '';
    foreach ($ch['sections'] ?? [] as $sec) {
        $h = trim((string) ($sec['h2'] ?? ''));
        $t = trim((string) ($sec['text'] ?? ''));
        if ($h !== '' && $t !== '') $add .= "## $h\n\n$t\n\n";
    }
    if ($add !== '') {
        $body = rtrim($a['body_md']);
        if (preg_match_all('/^##\s+(.+)$/m', $body, $m, PREG_OFFSET_CAPTURE) && $m[0]
            && preg_match('/^(вывод|итог|заключение|резюме)/iu', end($m[1])[0])) {
            $pos = end($m[0])[1];
            $body = substr($body, 0, $pos) . $add . substr($body, $pos);
        } else {
            $body .= "\n\n" . rtrim($add);
        }
        $a['body_md'] = $body;
    }
    foreach ($ch['faq'] ?? [] as $f) {
        $q = trim((string) ($f['q'] ?? ''));
        $ans = trim((string) ($f['a'] ?? ''));
        if ($q !== '' && $ans !== '' && !in_array($q, array_column($a['faq'], 0), true)) $a['faq'][] = [$q, $ans];
    }
    return save_article($a, $slug);
}

// ---------------------------------------------------------------- спрос по нише

// «Дать вектор»: владелец пишет нишу или идею («продвижение мебельного бизнеса»), мы смотрим, что люди
// реально ищут вокруг неё в Google и Яндексе (Казахстан), и Claude предлагает темы под этот спрос.
const TREND_PATTERNS = ['%s', 'как %s', '%s алматы', '%s астана', '%s казахстан'];

const TRENDS_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'summary' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3–5 пунктов: что именно ищут люди по этой теме'],
        'relevant' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'до 30 запросов из списка, относящихся к теме, — самые показательные'],
        'ideas' => IDEAS_SCHEMA['properties']['ideas'],
    ],
    'required' => ['summary', 'ideas'],
    'additionalProperties' => false,
];

/** Ниша → 6–10 коротких поисковых фраз, как их набирают люди. */
function niche_seeds(string $vector, ?callable $post = null): array
{
    $data = claude_json([
        'max_tokens' => 1500,
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
            'type' => 'object', 'properties' => ['seeds' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['seeds'], 'additionalProperties' => false]]],
        'messages' => [['role' => 'user', 'content' => P('seeds_prompt', ['vector' => $vector])]],
    ], $post);
    $seeds = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim((string) $x)), $data['seeds'] ?? []))));
    return array_slice($seeds ?: [mb_strtolower(trim($vector))], 0, 10);
}

/** Спрос по нише: затравки → подсказки Google/Яндекса → что ищут + темы статей (с датами по плану). */
function niche_trends(string $vector, ?callable $post = null, ?array $queries = null): array
{
    $vector = trim($vector);
    if (mb_strlen($vector) < 3) throw new AiError(P('vector_error'));
    $seeds = $queries === null ? niche_seeds($vector, $post) : [];
    $queries ??= collect_queries($seeds, TREND_PATTERNS);
    if (count($queries) < 5) throw new AiError('По этой теме почти нет поисковых подсказок — попробуйте сформулировать шире');
    $existing = array_map(fn($a) => $a['title'] . ($a['keywords'] ? ' — ' . $a['keywords'][0] : ''), load_all());
    $prompt = ideas_prompt($queries, $existing, '') . "\n\n" . P('trends_direction', ['vector' => $vector]) . P('trends_tail');
    $data = claude_json([
        'max_tokens' => 16000,
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => TRENDS_SCHEMA]],
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], $post);
    $slugs = array_column(services(), 'slug');
    $ideas = array_map(function ($i) use ($slugs) {
        if (!in_array($i['service'] ?? '', $slugs, true)) $i['service'] = '';
        return $i;
    }, $data['ideas'] ?? []);
    // показательные запросы по теме (Claude отсеял мусор вроде игр); только из реально найденных,
    // сначала те, что есть и в Google, и в Яндексе
    $pick = array_values(array_filter(array_map(fn($q) => mb_strtolower(trim((string) $q)), $data['relevant'] ?? []), fn($q) => isset($queries[$q])));
    if (!$pick) $pick = array_keys($queries);
    usort($pick, fn($a, $b) => (count($queries[$b]) <=> count($queries[$a])) ?: strcmp($a, $b));
    $top = array_map(fn($q) => ['q' => $q, 'both' => count($queries[$q]) > 1], array_slice(array_unique($pick), 0, 30));
    $result = ['vector' => $vector, 'seeds' => $seeds, 'summary' => array_values(array_map('strval', $data['summary'] ?? [])),
        'top_queries' => $top, 'ideas' => $ideas, 'queries' => count($queries), 'created' => now()->format('Y-m-d H:i')];
    write_json(DATA_DIR . '/trends.json', $result);
    topics_add($ideas, "по нише «{$vector}»");
    topics_dedupe($post);
    return $result;
}

// ---------------------------------------------------------------- список тем

// Все темы, что когда-либо предлагал ИИ, копятся в одном списке (DATA_DIR/topics.json): их можно
// написать или отклонить. Отклонённые ИИ больше не предлагает; написанные отмечаются сами — по ключу статьи.
function topics_file(): string { return DATA_DIR . '/topics.json'; }

function topics_load(): array
{
    $list = read_json(topics_file());
    if ($list === null) {  // первый запуск: переносим последние подборки
        $list = [];
        foreach ([['trends.json', 'по нише'], ['ideas.json', 'по всем услугам']] as [$f, $src]) {
            $r = read_json(DATA_DIR . "/$f");
            if ($r) $list = topics_merge($list, $r['ideas'] ?? [], $f === 'trends.json' ? "по нише «" . ($r['vector'] ?? '') . "»" : $src, $r['created'] ?? '');
        }
        if ($list) write_json(topics_file(), $list);
    }
    return $list;
}

function topic_key(array $i): string
{
    return mb_strtolower(trim((string) ($i['main_keyword'] ?? ''))) . '|' . mb_strtolower(trim((string) ($i['topic'] ?? '')));
}

/** Новые темы — в начало списка; повторы (тот же ключ и заголовок или тот же главный запрос) пропускаются.
 *  $added — сюда (если передали) кладём только реально добавленные темы, с их id — пригодится вызывающему. */
function topics_merge(array $list, array $ideas, string $source, string $created = '', ?array &$added = null): array
{
    $seen = [];
    foreach ($list as $t) { $seen[topic_key($t)] = 1; $seen['k|' . mb_strtolower(trim($t['main_keyword']))] = 1; }
    $new = [];
    foreach ($ideas as $i) {
        if (trim((string) ($i['topic'] ?? '')) === '') continue;
        $k = topic_key($i);
        $mk = 'k|' . mb_strtolower(trim((string) ($i['main_keyword'] ?? '')));
        if (isset($seen[$k]) || ($mk !== 'k|' && isset($seen[$mk]))) continue;
        $seen[$k] = $seen[$mk] = 1;
        $new[] = ['id' => substr(md5($k), 0, 12), 'topic' => (string) $i['topic'], 'main_keyword' => (string) ($i['main_keyword'] ?? ''),
            'keywords' => array_values(array_map('strval', $i['keywords'] ?? [])), 'intent' => (string) ($i['intent'] ?? ''),
            'service' => (string) ($i['service'] ?? ''), 'why' => (string) ($i['why'] ?? ''), 'source' => $source,
            'created' => $created ?: now()->format('Y-m-d H:i'), 'status' => 'new'];
    }
    $added = $new;
    return array_merge($new, $list);
}

/** → реально добавленные темы (без повторов), с их id. */
function topics_add(array $ideas, string $source): array
{
    $added = [];
    with_topics_lock(function () use ($ideas, $source, &$added) {
        write_json(topics_file(), topics_merge(topics_load(), $ideas, $source, '', $added));
    });
    return $added;
}

function topics_set_status(string $id, string $status): bool
{
    if (!in_array($status, ['new', 'rejected'], true)) return false;
    return with_topics_lock(function () use ($id, $status) {
        $list = topics_load();
        $found = false;
        foreach ($list as &$t) {
            if ($t['id'] !== $id) continue;
            $t['status'] = $status;
            unset($t['reason']);
            if ($status === 'new') $t['kept'] = true;  // вернули руками — автопроверка дублей её больше не трогает
            $found = true;
        }
        unset($t);
        if ($found) write_json(topics_file(), $list);
        return $found;
    });
}

function with_topics_lock(callable $fn)
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $f = fopen(DATA_DIR . '/.topics.lock', 'c');
    flock($f, LOCK_EX);
    try { return $fn(); } finally { flock($f, LOCK_UN); fclose($f); }
}

/**
 * Дубли по смыслу: Claude сверяет новые темы списка со статьями блога и между собой (старшие темы
 * в приоритете) и отклоняет повторы одного и того же вопроса читателя — с пометкой, на что похожа.
 * Ошибка Claude не мешает подбору тем: тогда просто ничего не отклоняем.
 */
function topics_dedupe(?callable $post = null): int
{
    $list = topics_load();
    $cands = array_values(array_filter(array_reverse($list), fn($t) => $t['status'] === 'new'));  // старые первыми
    $keep = array_column(array_filter($cands, fn($t) => !empty($t['kept'])), 'id');
    if (count($cands) < 2 && !load_all()) return 0;
    $arts = array_map(fn($a) => '- ' . $a['title'] . ($a['keywords'] ? ' — ' . $a['keywords'][0] : ''), load_all());
    $lines = array_map(fn($i, $t) => ($i + 1) . '. ' . $t['topic'] . ' — ' . $t['main_keyword'], array_keys($cands), $cands);
    try {
        $data = claude_json([
            'max_tokens' => 4000,
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
                'type' => 'object',
                'properties' => ['duplicates' => ['type' => 'array', 'items' => ['type' => 'object',
                    'properties' => ['n' => ['type' => 'integer'], 'of' => ['type' => 'string', 'description' => 'заголовок статьи или темы, которую повторяет']],
                    'required' => ['n', 'of'], 'additionalProperties' => false]]],
                'required' => ['duplicates'], 'additionalProperties' => false]]],
            'messages' => [['role' => 'user', 'content' =>
                "Ты редактор блога. Найди темы-дубли.\n\nСтатьи блога (заголовок — главный запрос):\n" . ($arts ? implode("\n", $arts) : '— нет') .
                "\n\nТемы-кандидаты (номер. тема — главный запрос):\n" . implode("\n", $lines) .
                "\n\nКандидат — дубль, если отвечает на тот же вопрос читателя, что статья блога или кандидат с МЕНЬШИМ номером, "
                . "даже другими словами и с другим городом. " . P('dup_rule') . "В of — заголовок того, что он повторяет. Если дублей нет — пустой список."]],
        ], $post);
    } catch (AiError $ex) {
        error_log('topics dedupe: ' . $ex->getMessage());
        return 0;
    }
    $dup = [];
    foreach ($data['duplicates'] ?? [] as $d) {
        $i = (int) ($d['n'] ?? 0) - 1;
        if (isset($cands[$i]) && !in_array($cands[$i]['id'], $keep, true)) $dup[$cands[$i]['id']] = mb_substr(trim((string) ($d['of'] ?? '')), 0, 200);
    }
    if (!$dup) return 0;
    with_topics_lock(function () use ($dup) {
        $all = topics_load();
        foreach ($all as &$t) {
            if (isset($dup[$t['id']]) && $t['status'] === 'new') { $t['status'] = 'rejected'; $t['reason'] = 'похожа на «' . $dup[$t['id']] . '»'; }
        }
        unset($t);
        write_json(topics_file(), $all);
    });
    return count($dup);
}

/** Список тем для экрана: написанные помечены, новым — рекомендуемые даты по ритму. */
function topics_view(int $per_week = 2): array
{
    $plan = ideas_with_plan(['ideas' => topics_load()], $per_week);
    return ['topics' => $plan['ideas'], 'per_week' => $plan['per_week']];
}

/** Ближайший свободный слот по ритму (вт/чт и т. д., 10:00 Алматы): день, на который ещё не стоит другая статья. */
function next_free_slot(int $per_week = 2, string $exclude_slug = '', ?DateTimeImmutable $now = null): string
{
    $per_week = isset(SLOT_DAYS[$per_week]) ? $per_week : 2;
    $now ??= now();
    $taken = [];
    foreach (load_all() as $a) {
        if ($a['slug'] === $exclude_slug || $a['status'] !== 'scheduled') continue;
        if ($dt = parse_dt($a['publish_at'])) $taken[$dt->format('Y-m-d')] = true;
    }
    $day = $now->setTime(SLOT_HOUR, 0);
    if ($day <= $now) $day = $day->modify('+1 day');
    for ($i = 0; $i < 400; $i++, $day = $day->modify('+1 day')) {
        if (in_array((int) $day->format('N'), SLOT_DAYS[$per_week], true) && !isset($taken[$day->format('Y-m-d')])) break;
    }
    return $day->format('Y-m-d\TH:i');
}

/** Идеи + used (по теме уже есть статья) + suggested_date — следующие свободные слоты ритма. */
function ideas_with_plan(?array $result, int $per_week = 2, ?DateTimeImmutable $start = null): array
{
    if (!$result) return ['ideas' => [], 'per_week' => $per_week];
    $per_week = isset(SLOT_DAYS[$per_week]) ? $per_week : 2;
    $articles = load_all();
    $known = [];
    $taken = [];
    foreach ($articles as $a) {
        foreach ($a['keywords'] as $k) $known[mb_strtolower(trim($k))] = true;
        if ($dt = parse_dt($a['publish_at'])) $taken[$dt->format('Y-m-d')] = true;
    }
    $day = ($start ?? now())->setTime(SLOT_HOUR, 0);
    $ideas = [];
    foreach ($result['ideas'] ?? [] as $idea) {
        $idea['used'] = isset($known[mb_strtolower(trim((string) ($idea['main_keyword'] ?? '')))]);
        $idea['suggested_date'] = '';
        if (!$idea['used'] && ($idea['status'] ?? 'new') === 'new') {
            do {
                $day = $day->modify('+1 day');
            } while (!in_array((int) $day->format('N'), SLOT_DAYS[$per_week], true) || isset($taken[$day->format('Y-m-d')]));
            $taken[$day->format('Y-m-d')] = true;
            $idea['suggested_date'] = $day->format('Y-m-d\TH:i');
        }
        $ideas[] = $idea;
    }
    return array_merge($result, ['ideas' => $ideas, 'per_week' => $per_week]);
}
