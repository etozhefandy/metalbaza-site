<?php
// ИИ для админки: статьи (Claude), картинки (OpenAI), идеи тем (подсказки Google/Яндекса → Claude).
// Ключи хранятся в DATA_DIR/settings.json (вне сайта), вводятся на странице «Настройки».
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const CLAUDE_MODEL = 'claude-opus-5-5';
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

// ---------------------------------------------------------------- Claude

/**
 * Запрос к Claude со стримингом (длинные ответы без обрыва по таймауту) → [текст, stop_reason].
 * $post — подмена HTTP для тестов: fn(array $body): array [http_code, raw_sse_or_json].
 */
function claude(array $body, ?callable $post = null): array
{
    $key = trim((string) (settings()['anthropic_key'] ?? ''));
    if ($post === null && $key === '') throw new AiError('Нет ключа Claude — вставьте его в «Настройках» админки');
    $body = ['model' => CLAUDE_MODEL, 'stream' => true] + $body;
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
    foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
        if (!str_starts_with($line, 'data:')) continue;
        $ev = json_decode(trim(substr($line, 5)), true);
        if (!is_array($ev)) continue;
        $type = $ev['type'] ?? '';
        if ($type === 'content_block_delta' && ($ev['delta']['type'] ?? '') === 'text_delta') $text .= $ev['delta']['text'];
        elseif ($type === 'message_delta') $stop = $ev['delta']['stop_reason'] ?? $stop;
        elseif ($type === 'error') {
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
    $about = NICHE_ABOUT;
    $reader = NICHE_READER;
    $cta = NICHE_CTA;
    return <<<TXT
Ты — редактор блога сайта {$about}

Страницы каталога на сайте (название — адрес — коротко):
$services

Ты пишешь SEO-статьи на русском языке. Читатель — $reader. Статья должна ранжироваться в Google и Яндексе
по заданным запросам и при этом быть полезной: конкретика, размеры и марки, расчёты, типичные ошибки, чек-листы.
Без воды и канцелярита.

Требования к статье:
- Первый абзац — прямой ответ на главный вопрос статьи в 2–3 предложениях, с конкретикой (цифры, размеры, «от … до …»).
  Его должны мочь процитировать ИИ-ответы Google, ChatGPT и Алисы как готовый ответ, без контекста остальной статьи.
- Структура: после ответа ещё 1–2 абзаца вступления без заголовка, затем 5–9 разделов `##`, внутри при необходимости `###`.
- Каждый раздел `##` начинается с 1–2 предложений, которые сами по себе отвечают на вопрос заголовка; определения
  давай в форме «X — это …». Сравнения и размеры — таблицей, шаги и расчёты — нумерованным списком.
  Заголовки разделов содержательные и отвечают на вопросы из поиска. `#` (h1) в теле не используй.
- Главный ключ — в title, seo_title, первом абзаце и хотя бы одном `##`; остальные ключи и их вариации
  распредели естественно. Никакого переспама.
- Технические данные (ГОСТ, марки стали, масса метра, размеры) — только общеизвестные справочные; массу считай
  по формулам (сталь 7850 кг/м³) и показывай расчёт. Если не уверен в цифре — не пиши её.
- Цены: сайт цен не назначает. Не называй конкретных цен за тонну или лист как факт; объясняй, из чего складывается
  цена, и предлагай сравнить предложения поставщиков. Пример расчёта стоимости — только с условной ценой, явно помеченной как пример.
- Контекст Казахстана: тенге, города, климат (снег, ветер, мороз, сейсмика), местные реалии стройки.
- НЕ выдумывай поставщиков, заводы, отзывы, статистику с источником и «кейсы».
- Внутренние ссылки: 2–4 уместные ссылки markdown на страницы каталога из списка выше (относительный путь, например
  [арматура](/armatura/)). Внешние ссылки не нужны.
- В конце — короткий раздел с выводом и мягким призывом: $cta (без агрессивной продажи).
- FAQ: 4–6 вопросов, которые реально задают в поиске, ответы по 1–3 предложения, не повторяют текст дословно.
- related: выбери до 3 slug из списка существующих статей, если они по теме; иначе пустой список.
- slug: транслит латиницей по главному ключу, 3–6 слов через дефис, без стоп-слов.
TXT;
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
        'system' => writer_system_prompt() . ($has_source ? "\n" . SOURCE_RULES : ''),
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
const SOURCE_RULES = <<<TXT
Статья по источнику. Пользователь прислал материал (статью, пост, скриншоты) — это информационный повод.
- Напиши СВОЮ статью для читателя сайта в Казахстане: что произошло, что это меняет для него, что делать
  на практике. Повод — во вступлении и в одном из разделов, остальное — полезный разбор.
- Не копируй текст источника и не пересказывай его абзац за абзацем; дословно — максимум одна короткая цитата в кавычках.
- Факты, цифры, даты и имена бери только из источника и подавай со ссылкой на него («по данным …»).
  Не додумывай подробности, которых в источнике нет. Если источник — мнение или реклама, так и скажи.
- В тексте одна внешняя ссылка markdown на источник (если есть адрес): [название источника](адрес).
- Тему и поисковые запросы выбери сам по содержанию, если пользователь их не задал: то, что ищут
  в Казахстане по этой теме; главный запрос — в начале списка keywords.
TXT;

function source_prompt(array $source): string
{
    $lines = ['ИСТОЧНИК (информационный повод для статьи):'];
    if (!empty($source['url'])) $lines[] = 'Адрес: ' . $source['url'];
    if (!empty($source['title'])) $lines[] = 'Заголовок: ' . $source['title'];
    if (!empty($source['images'])) $lines[] = 'Скриншоты/картинки источника приложены выше — прочитай текст на них.';
    if (trim((string) ($source['text'] ?? '')) !== '') $lines[] = "Текст:\n" . mb_substr(trim($source['text']), 0, 20000);
    return implode("\n", $lines);
}

/** Только публичные адреса: сервер не должен ходить во внутреннюю сеть хостинга. */
function public_url(string $url): bool
{
    $p = parse_url($url);
    if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    $ips = filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : (gethostbynamel($p['host']) ?: []);
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return (bool) $ips;
}

function http_get(string $url, string $ua, int $max = 3_000_000): array
{
    $ch = curl_init($url);
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
        if (!public_url($url)) throw new AiError('Ссылка не открывается: нужен обычный адрес сайта http(s)://…');
        [$body, $info] = http_get($url, $ua);
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
const IMG_STYLE = NICHE_IMAGE_STYLE . ' Clean composition, natural colours, sharp detail, believable materials and light.';
const IMG_BANS = 'No text, no letters, no numbers, no logos or app icons of any brands (no Instagram, Facebook, TikTok, '
    . 'Google marks), no watermarks, no UI screenshots, no fake dashboards or charts. '
    . 'Avoid clichés: no handshakes, no floating gradient blobs, no glowing neon, no glassmorphism, no 3D-render plastic look. '
    . 'Commercial-grade finish, crisp composition, natural hands and faces, no extra limbs, no distortions.';
const PEOPLE_RULE = 'PEOPLE: authentic Central Asian (Kazakh) builders, foremen, warehouse workers or homeowners in real '
    . 'Kazakhstan settings, candid and mid-action, in proper work clothes and safety gear, not posing, not looking at the camera, '
    . 'no stock smiles; the scene explains the idea, faces are secondary.';
const NO_PEOPLE_RULE = 'NO PEOPLE: no humans, faces, hands or silhouettes — steel products, tools, buildings and materials only.';
const PEOPLE_SCENES = [
    'a foreman checking rebar cages on a foundation before concrete is poured',
    'a warehouse worker measuring steel pipes in a metal stockyard',
    'a homeowner and a builder choosing profiled sheets for a fence at a yard',
    'workers unloading bundles of rebar from a flatbed truck with a crane',
    'a welder assembling a steel frame for a canopy, sparks in the air',
    'a buyer comparing two steel samples with a caliper at a supply depot',
];
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
        ? 'Scene: ' . PEOPLE_SCENES[array_rand(PEOPLE_SCENES)] . ', connected to the idea above. ' . PEOPLE_RULE
        : 'Scene: an abstract still life of objects and materials expressing the idea. ' . NO_PEOPLE_RULE;
    return "$topic $scene Camera: " . TECHNIQUES[array_rand(TECHNIQUES)] . '. ' . image_frame($kind) . ' ' . IMG_STYLE . ' ' . IMG_BANS;
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
        'Ты арт-директор блога сайта ' . SITE_BRAND . " (" . SITE_HOST . ", Казахстан). Нужна $task.",
        $ctx ? "Статья:\n" . implode("\n", $ctx) : '',
        trim($idea) !== '' ? "Идея картинки от редактора (главное, опирайся на неё): " . trim($idea) : '',
        '',
        'Шаг 1. main_idea — основная мысль ' . (trim($idea) !== '' ? 'картинки (по идее редактора)' : 'статьи') . ': одно короткое предложение (до 20 слов), что читатель должен понять.',
        'Шаг 2. scene — одна конкретная сцена, которая с первого взгляда передаёт эту мысль: визуальная метафора или ситуация, '
        . 'конкретные предметы, место, свет и композиция. Без абстрактных слов вроде «успех» и «рост» — только то, что можно нарисовать.',
        'Стиль: ' . NICHE_IMAGE_STYLE,
        'Приём съёмки (встрои в сцену): ' . $technique . '.',
        $people ? 'Люди: ' . PEOPLE_RULE : 'Людей нет: ' . NO_PEOPLE_RULE,
        'Нельзя: текст, буквы и цифры в кадре, логотипы и значки брендов, экраны с интерфейсом и графиками, '
        . 'рукопожатия, неон, пластиковый 3D-рендер. Цвета называй словами, без кодов.',
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
        $prompt = $meta['scene'] . ' ' . image_frame($kind) . ' ' . IMG_STYLE . ' ' . IMG_BANS;
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

const SEEDS = NICHE_SEEDS;
const PATTERNS = NICHE_SEED_PATTERNS;
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
function collect_queries(array $seeds = SEEDS, array $patterns = PATTERNS): array
{
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
        'Ты SEO-редактор блога сайта ' . NICHE_ABOUT . ' Читатель — ' . NICHE_READER . '.',
        "Страницы каталога (slug — название):\n$services",
        "Реальные запросы из поисковых подсказок Google и Яндекса по Казахстану (пометка [Google+Яндекс] — встречается в обоих, это более надёжный спрос):\n" . implode("\n", $lines),
    ];
    if ($existing) $parts[] = "Статьи, которые уже есть в блоге (заголовок — главный запрос). Не повторяй вопросы, на которые они уже отвечают:\n" . implode("\n", array_map(fn($t) => "- $t", $existing));
    $bl = topics_load();
    $queued = array_column(array_filter($bl, fn($t) => $t['status'] === 'new'), 'topic');
    $rejected = array_column(array_filter($bl, fn($t) => $t['status'] === 'rejected'), 'topic');
    if ($queued) $parts[] = "Темы, которые уже предложены и ждут в списке (не повторяй их):\n" . implode("\n", array_map(fn($t) => "- $t", array_slice($queued, 0, 60)));
    if ($rejected) $parts[] = "Темы, которые редактор отклонил (не предлагай их и похожие на них):\n" . implode("\n", array_map(fn($t) => "- $t", array_slice($rejected, 0, 60)));
    if ($focus !== '') $parts[] = "Пожелание редактора: $focus";
    $parts[] = 'Предложи 8–10 тем статей. Правила: только темы, полезные читателю и ведущие к страницам каталога; '
        . 'игнорируй нерелевантное (вакансии, металлолом и приём лома, игры, чужие бренды и компании); '
        . 'главный запрос и запросы бери только из списка выше, ничего не выдумывай и не пиши частотность; '
        . 'не делай двух статей под один и тот же главный запрос; '
        . 'ОДНА ТЕМА — ОДИН ВОПРОС ЧИТАТЕЛЯ: если вопрос уже закрыт статьёй блога или темой из списка, не предлагай его '
        . 'другими словами (например, «Как выбрать профнастил для забора», «Какой профлист лучше для забора» и '
        . '«Профнастил на забор: что выбрать» — это один и тот же вопрос). Делай темы разного типа: сколько стоит и из чего цена, '
        . 'расчёт количества и веса, как выбрать марку и размер, ошибки при покупке и монтаже, сравнение материалов, '
        . 'чек-лист приёмки металла; не больше двух тем на одну страницу каталога за раз; предпочитай запросы с деньгами и выбором '
        . '(«цена», «сколько стоит», «как выбрать», «вес», город), после которых естественно оставить заявку. '
        . 'service — один slug из списка страниц каталога. '
        . 'Отсортируй темы в порядке публикации: сначала с самым явным спросом и коммерческим интентом, '
        . 'и чередуй товары — две статьи подряд про один товар не ставь.';
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
    topics_add($ideas, 'по всему каталогу');
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
        'Ты SEO-редактор блога сайта ' . NICHE_ABOUT . ' Задача — подтянуть УЖЕ ВЫШЕДШИЕ статьи под запросы, '
        . 'по которым Google их уже показывает, чтобы они поднялись на первую страницу.',
        "Статьи блога:\n" . implode("\n\n", $artLines),
        "Запросы из Google Search Console за последнее время (запрос | показы | средняя позиция):\n" . implode("\n", array_map($fmt, $queries)),
        $pages ? "Страницы (адрес | показы | позиция):\n" . implode("\n", array_map($fmt, array_slice($pages, 0, 60))) : '',
        $notes,
        'Правила. Для каждой статьи, у которой есть запросы с позицией примерно 4–40 (рядом с первой страницей), предложи: '
        . 'seo_title до 60 символов с точными словами самых частых запросов и «Казахстан», если его нет и запросы общие; '
        . 'description 140–160 символов; add_keywords — запросы из выгрузки, которых нет в статье; '
        . 'sections — 0–2 новых раздела под вопросы из запросов, на которые статья сейчас не отвечает (не повторяй существующие разделы); '
        . 'faq — 0–3 вопроса из формулировок запросов, ответы 1–3 предложения. Пустые строки и списки — если менять не нужно. '
        . 'Статьи без подходящих запросов не включай. Факты и цифры не выдумывай; конкретных цен за тонну или лист не называй — сайт цен не назначает. '
        . 'Пиши для читателя: ' . NICHE_READER . ', без воды. '
        . 'new_topics — 0–5 тем под запросы из выгрузки, которым НЕ подходит ни одна статья (не повторяй статьи).',
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
    if ($topics) { topics_add($topics, 'из Search Console'); if ($dedupe) topics_dedupe($post); }
    $result = ['articles' => $out, 'new_topics' => count($topics), 'queries' => count($queries), 'created' => now()->format('Y-m-d H:i')];
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
const NICHE_PATTERNS = ['%s', 'как %s', '%s алматы', '%s астана', '%s казахстан'];

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
        'messages' => [['role' => 'user', 'content' => "Направление для статей сайта " . SITE_HOST . " (Казахстан): «{$vector}».\n"
            . 'Дай 6–10 коротких поисковых фраз (2–4 слова, по-русски, строчными), которые набирают в Google и Яндексе '
            . 'покупатели металла и строители по этой теме: цена, как выбрать, размеры, вес, расчёт. '
            . 'Без кавычек, городов и брендов — это затравки для поисковых подсказок.']],
    ], $post);
    $seeds = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim((string) $x)), $data['seeds'] ?? []))));
    return array_slice($seeds ?: [mb_strtolower(trim($vector))], 0, 10);
}

/** Спрос по нише: затравки → подсказки Google/Яндекса → что ищут + темы статей (с датами по плану). */
function niche_trends(string $vector, ?callable $post = null, ?array $queries = null): array
{
    $vector = trim($vector);
    if (mb_strlen($vector) < 3) throw new AiError('Опишите направление, например: «забор из профнастила» или «фундамент частного дома»');
    $seeds = $queries === null ? niche_seeds($vector, $post) : [];
    $queries ??= collect_queries($seeds, NICHE_PATTERNS);
    if (count($queries) < 5) throw new AiError('По этой теме почти нет поисковых подсказок — попробуйте сформулировать шире');
    $existing = array_map(fn($a) => $a['title'] . ($a['keywords'] ? ' — ' . $a['keywords'][0] : ''), load_all());
    $prompt = ideas_prompt($queries, $existing, '') . "\n\n"
        . "НАПРАВЛЕНИЕ ОТ ВЛАДЕЛЬЦА САЙТА: «{$vector}». Все темы — в этом направлении.\n"
        . 'summary: 3–5 коротких пунктов, что именно люди ищут по этой теме (группы вопросов), опираясь только на запросы выше. '
        . 'relevant: до 30 запросов из списка дословно, которые относятся к теме (без мусора вроде игр и чужих брендов). '
        . 'Среди тем обязательно один подробный практический разбор с расчётом материала по шагам. '
        . 'Поставщиков, заводы и «кейсы» не выдумывай: вместо «кейса» — разбор с примером расчёта, явно помеченным как пример.';
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

/** Новые темы — в начало списка; повторы (тот же ключ и заголовок или тот же главный запрос) пропускаются. */
function topics_merge(array $list, array $ideas, string $source, string $created = ''): array
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
    return array_merge($new, $list);
}

function topics_add(array $ideas, string $source): void
{
    with_topics_lock(fn() => write_json(topics_file(), topics_merge(topics_load(), $ideas, $source)));
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
                . "даже другими словами и с другим городом. Пример дублей: «Как выбрать профнастил для забора» и «Какой профлист лучше для забора». "
                . "НЕ дубли — разные вопросы про один товар: цена, расчёт количества, вес, ошибки монтажа, сравнение материалов. В of — заголовок того, что он повторяет. Если дублей нет — пустой список."]],
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
