<?php
// Тесты PHP-блога и админки (без сети):  php tests/php/test_blog.php
// Нужна свежая сборка: python3 build.py (берём dist/_blog/parts.json).
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$data = sys_get_temp_dir() . '/alfimov-test-' . bin2hex(random_bytes(4));
mkdir($data);
putenv("ALFIMOV_SITE=$root/dist");
putenv("ALFIMOV_DATA=$data");
putenv('ALFIMOV_NOW=2026-10-01T12:00:00+05:00');
require "$root/dist/_blog/auth.php";

$fails = 0;
function check(bool $ok, string $name): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) $fails++;
}
function throws(callable $fn, string $needle): bool
{
    try { $fn(); } catch (Throwable $e) { return str_contains($e->getMessage(), $needle); }
    return false;
}
$art = fn(string $slug, array $kw = []) => $kw + ['slug' => $slug, 'title' => "Статья $slug", 'description' => 'Описание', 'body_md' => "## Раздел\n\nТекст.\n"];

echo "хранилище и расписание\n";
check(load_all() === [] && is_dir(articles_dir()), 'хранилище статей создано (стартовых статей у нового сайта нет)');
foreach (glob(articles_dir() . '/*.json') as $f) unlink($f);
save_article($art('draft'));
save_article($art('later', ['status' => 'scheduled', 'publish_at' => '2026-10-02T10:00']));
save_article($art('due', ['status' => 'scheduled', 'publish_at' => '2026-10-01T11:59', 'faq' => [['В?', 'О.']]]));
$states = [];
foreach (load_all() as $a) $states[$a['slug']] = state($a);
check($states == ['later' => 'scheduled', 'due' => 'live', 'draft' => 'draft'], 'черновик / запланирована / вышла');
check(array_column(live_articles(), 'slug') === ['due'], 'на сайте только вышедшие');
check(throws(fn() => save_article($art('Плохой адрес')), 'Адрес'), 'плохой адрес отклонён');
check(throws(fn() => save_article($art('bez-daty', ['status' => 'scheduled'])), 'дата выхода'), 'без даты не запланировать');
check(throws(fn() => save_article($art('cover', ['cover_path' => 'https://evil.example/x.png'])), 'Обложка'), 'чужая обложка отклонена');
check(throws(fn() => save_article($art('cover2', ['cover_path' => '/blog/media/../../settings.json'])), 'Обложка'), 'обход пути в обложке отклонён');
$a = get_article('draft');
save_article(['slug' => 'draft-new'] + $a, 'draft');
check(get_article('draft') === null && get_article('draft-new')['created_at'] === $a['created_at'], 'переименование сохраняет дату создания');
check(throws(fn() => save_article($art('due'), 'later'), 'занят'), 'занятый адрес отклонён');
check(slugify('Таргет в Алматы: цены 2026') === 'target-v-almaty-tseny-2026' && slugify('Қазақша жарнама') === 'kazaksha-zharnama', 'транслит RU/KZ');

echo "markdown\n";
[$html, $toc] = markdown("Вступление с **жирным** и [ссылкой](/smm/).\n\n## Раздел\n\n- один\n- два\n\n## Раздел\n\n| A | B |\n|---|---|\n| 1 | 2 |\n");
check(str_contains($html, '<strong>жирным</strong>') && str_contains($html, '<a href="/smm/">ссылкой</a>'), 'жирный и ссылка');
check(str_contains($html, '<ul><li>один</li><li>два</li></ul>') && str_contains($html, '<td>1</td>'), 'список и таблица');
check(count($toc) === 2 && $toc[0][0] !== $toc[1][0], 'уникальные якоря в оглавлении');
[$html] = markdown("# Заголовок\n\n<script>alert(1)</script> [x](javascript:alert(1))");
check(!str_contains($html, '<h1') && !str_contains($html, '<script>') && str_contains($html, 'href="#"'), 'h1→h2, HTML и javascript: экранированы');
[$html] = markdown("![подпись](/blog/media/x.webp)\n\n![чужая](https://evil.example/x.png)");
check(str_contains($html, '<img src="/blog/media/x.webp"') && !str_contains($html, 'evil.example/x.png" alt'), 'картинки только со своего сайта');

echo "страницы\n";
$due = get_article('due');
$page = render_article($due, live_articles());
check(str_contains($page, '<link rel="canonical" href="https://metalbaza.kz/blog/due/">'), 'canonical статьи');
check(str_contains($page, '"@type":"BlogPosting"') && str_contains($page, '"@type":"FAQPage"'), 'разметка BlogPosting и FAQ');
check(substr_count($page, '<h1') === 1 && !str_contains($page, '%%'), 'один h1, все метки подставлены');
$home = render_home((string) file_get_contents("$root/dist/index.html"), live_articles());
check(str_contains($home, 'id="blog"') && str_contains($home, '/blog/due/') && !str_contains($home, 'BLOG-HOME'), 'свежие статьи на главной');
$sm = render_sitemap((string) file_get_contents("$root/dist/_blog/sitemap.tpl"), live_articles());
check(str_contains($sm, 'https://metalbaza.kz/blog/due/') && !str_contains($sm, '/blog/later/'), 'sitemap: вышедшие есть, будущих нет');
check(str_contains(render_article(get_article('later'), [], true), 'content="noindex"'), 'предпросмотр закрыт от индекса');

echo "ИИ-поиск: llms.txt и IndexNow\n";
$llms = render_llms((string) file_get_contents("$root/dist/_blog/llms.tpl"), live_articles());
check(str_contains($llms, '# METALBAZA.KZ') && str_contains($llms, '/armatura/') && str_contains($llms, 'https://metalbaza.kz/blog/due/') && !str_contains($llms, 'later'), 'llms.txt: каталог и только вышедшие статьи');
$pings = [];
$send = function ($body) use (&$pings) { $pings[] = json_decode($body, true); return true; };
indexnow_article(get_article('due'), $send);
indexnow_article(get_article('due'), $send);
indexnow_article(get_article('later'), $send);
check(count($pings) === 1 && $pings[0]['urlList'][0] === 'https://metalbaza.kz/blog/due/' && $pings[0]['key'] === INDEXNOW_KEY, 'IndexNow: вышедшая статья — один раз на версию, будущая — нет');
putenv('ALFIMOV_NOW=2026-10-01T12:05:00+05:00');
save_article(get_article('due'), 'due');
putenv('ALFIMOV_NOW=2026-10-01T12:00:00+05:00');
indexnow_article(get_article('due'), $send);
check(count($pings) === 2, 'IndexNow: после правки статьи — снова');
check(str_contains(file_get_contents("$root/dist/" . INDEXNOW_KEY . '.txt'), INDEXNOW_KEY), 'IndexNow: файл ключа на сайте');

echo "ИИ (без сети)\n";
$sse = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
    . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\"...\"}}\n"
    . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"{\\\"slug\\\":\\\"Плохой\\\",\\\"title\\\":\\\"Т\\\",\"}}\n"
    . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"\\\"faq\\\":[{\\\"q\\\":\\\"В\\\",\\\"a\\\":\\\"О\\\"}],\\\"related\\\":[\\\"due\\\",\\\"net\\\"],\\\"keywords\\\":[]}\"}}\n"
    . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n";
$sent = null;
$out = write_article('Тема', ['таргет'], '', 1500, [['slug' => 'due', 'title' => 'x']], function ($body) use ($sse, &$sent) { $sent = $body; return [200, $sse]; });
check($out['faq'] === [['В', 'О']] && $out['related'] === ['due'] && $out['keywords'] === ['таргет'] && $out['slug'] === 'plohoy', 'ответ Claude из стрима разобран и почищен');
check($sent['stream'] === true && $sent['model'] === CLAUDE_MODEL && $sent['output_config']['format']['type'] === 'json_schema', 'запрос: стрим, модель, json-схема');
check(throws(fn() => write_article('Т', [], '', 1500, [], fn() => [401, '{"error":{"message":"bad"}}']), 'ключ'), 'неверный ключ — понятная ошибка');
check(throws(fn() => write_article('Т', [], '', 1500, [], fn() => [200, "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"refusal\"}}\n"]), 'отказался'), 'отказ Claude — понятная ошибка');
$prompt = image_prompt('cover', 'Заголовок', 'лид', '', 'people');
check(!preg_match('/#[0-9a-f]{3,6}\b/i', $prompt) && str_contains($prompt, 'PEOPLE:') && str_contains($prompt, 'steel'), 'картинка: люди, стиль ниши, без hex-кодов');
check(str_contains(image_prompt('cover', 'З', '', '', 'abstract'), 'NO PEOPLE'), 'картинка: абстракция без людей');
$scene_sse = fn(string $json) => "data: " . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $json]]) . "\n"
    . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n";
$asked = null;
$img = generate_image('cover', 'Таргет или контекст', 'Разница', '', 'abstract', ['summary' => 'Контекст ловит спрос, таргет создаёт.', 'body' => 'Текст статьи'],
    function ($body) use ($scene_sse, &$asked) { $asked = $body; return [200, $scene_sse('{"main_idea":"Контекст ловит готовый спрос","scene":"Two paths on a white floor, a cobalt-blue slab #1769ff marks the shorter one."}')]; });
$q = $asked['messages'][0]['content'];
check($img['main_idea'] === 'Контекст ловит готовый спрос' && str_contains($img['prompt'], 'Two paths on a white floor'), 'картинка: сначала мысль, потом сцена из неё');
check(str_contains($q, 'Контекст ловит спрос') && str_contains($q, 'Людей нет') && str_contains($q, 'Приём съёмки'), 'в запрос сцены — статья, сюжет и приём съёмки');
check(!preg_match('/#[0-9a-f]{3,8}\b/i', $img['prompt']) && str_contains($img['prompt'], 'cobalt-blue'), 'сцена: hex-коды вычищены, стиль сайта на месте');
$img = generate_image('cover', 'Заголовок', '', '', 'people', [], fn() => [529, '{}']);
check(str_contains($img['prompt'], 'PEOPLE:') && $img['main_idea'] === '', 'Claude недоступен — запасной промпт, картинка всё равно рисуется');
check(throws(fn() => generate_image('cover', '', '', '', 'auto', [], fn() => [200, '']), 'Опишите картинку'), 'без заголовка и идеи — понятная ошибка');
$plan = ideas_with_plan(['ideas' => [['topic' => 'A', 'main_keyword' => 'x'], ['topic' => 'B', 'main_keyword' => 'y'], ['topic' => 'C', 'main_keyword' => 'z']]], 2,
    new DateTimeImmutable('2026-10-01T09:00', tz()));
check(array_column($plan['ideas'], 'suggested_date') === ['2026-10-06T10:00', '2026-10-08T10:00', '2026-10-13T10:00'], 'план: вт и чт в 10:00, занятые дни пропущены');

echo "спрос по нише\n";
$asked = [];
$seeds = niche_seeds('продвижение мебельного бизнеса', function ($b) use ($scene_sse, &$asked) { $asked[] = $b; return [200, $scene_sse('{"seeds":["Реклама мебели "," реклама мебели","мебельный магазин маркетинг"]}')]; });
check($seeds === ['реклама мебели', 'мебельный магазин маркетинг'], 'затравки: строчные, без повторов');
$tr = niche_trends('продвижение мебельного бизнеса', function ($b) use ($scene_sse, &$asked) { $asked[] = $b; return [200, $scene_sse(json_encode(['summary' => ['Как продвигать мебель в Instagram'], 'relevant' => ['реклама мебели алматы', 'продвижение мебели', 'выдуманный запрос'],
    'ideas' => [['topic' => 'Как продвигать мебельный бизнес', 'main_keyword' => 'продвижение мебели', 'keywords' => ['продвижение мебели'], 'intent' => 'коммерческий', 'service' => 'nope', 'why' => 'спрос']]], JSON_UNESCAPED_UNICODE))]; },
    ['продвижение мебели' => ['google' => 1, 'yandex' => 1], 'реклама мебели алматы' => ['google' => 1], 'мебель инстаграм' => ['yandex' => 1], 'как продать мебель' => ['google' => 1], 'мебельный бизнес' => ['google' => 1, 'yandex' => 1]]);
$q = $asked[count($asked) - 2]['messages'][0]['content'];  // последний вызов — проверка дублей
check(str_contains($q, 'продвижение мебельного бизнеса') && str_contains($q, 'Поставщиков, заводы и «кейсы» не выдумывай'), 'ниша и запрет выдуманных кейсов в запросе');
check(str_contains(end($asked)['messages'][0]['content'], 'Найди темы-дубли'), 'после подбора — проверка дублей по смыслу');
check(array_column($tr['top_queries'], 'q') === ['продвижение мебели', 'реклама мебели алматы'] && $tr['top_queries'][0]['both'] && $tr['ideas'][0]['service'] === '', 'итог: только найденные запросы по теме, сначала из обоих поисковиков, чужие slug отброшены');
check(throws(fn() => niche_trends('аб'), 'направление'), 'пустая ниша — понятная ошибка');

echo "список тем\n";
@unlink(topics_file()); @unlink(DATA_DIR . '/trends.json'); @unlink(DATA_DIR . '/ideas.json');
$idea = fn($t, $k) => ['topic' => $t, 'main_keyword' => $k, 'keywords' => [$k], 'intent' => 'коммерческий', 'service' => 'smm', 'why' => 'w'];
topics_add([$idea('Тема А', 'ключ а'), $idea('Тема Б', 'ключ б')], 'по всем услугам');
topics_add([$idea('Тема А другая формулировка', 'ключ а'), $idea('Тема В', 'ключ в')], 'по нише «x»');
$list = topics_load();
check(array_column($list, 'topic') === ['Тема В', 'Тема А', 'Тема Б'] && $list[0]['source'] === 'по нише «x»', 'темы копятся, новые сверху, повтор по главному запросу пропущен');
check(topics_set_status($list[1]['id'], 'rejected') && !topics_set_status('000000000000', 'rejected') && !topics_set_status($list[1]['id'], 'хак'), 'отклонить тему; чужой id и статус не проходят');
$p = ideas_prompt(['ключ' => ['google' => 1]], [], '');
check(str_contains($p, 'отклонил') && str_contains($p, 'Тема А') && str_contains($p, 'ждут в списке'), 'отклонённые и ждущие темы — в запросе к ИИ');
save_article($art('pro-klyuch-b', ['keywords' => ['ключ б']]));
$v = array_column(topics_view(2)['topics'], null, 'topic');
check($v['Тема Б']['used'] && $v['Тема А']['suggested_date'] === '' && $v['Тема В']['suggested_date'] !== '', 'написанная тема отмечена сама, у отклонённой нет даты');

// дубли по смыслу
@unlink(topics_file());
topics_add([$idea('Как выбрать таргетолога', 'как выбрать таргетолога'), $idea('Услуги таргетолога: как выбрать специалиста', 'таргетолог услуги'), $idea('Ошибки в таргете', 'ошибки таргета')], 'тест');
$n = topics_dedupe(fn() => [200, $scene_sse('{"duplicates":[{"n":2,"of":"Как выбрать таргетолога"}]}')]);
$v = array_column(topics_load(), null, 'topic');
check($n === 1 && $v['Услуги таргетолога: как выбрать специалиста']['status'] === 'rejected' && str_contains($v['Услуги таргетолога: как выбрать специалиста']['reason'], 'Как выбрать таргетолога') && $v['Ошибки в таргете']['status'] === 'new', 'повтор по смыслу отклоняется с пометкой, разные вопросы остаются');
topics_set_status($v['Услуги таргетолога: как выбрать специалиста']['id'], 'new');
topics_dedupe(fn() => [200, $scene_sse('{"duplicates":[{"n":2,"of":"Как выбрать таргетолога"}]}')]);
check(array_column(topics_load(), null, 'topic')['Услуги таргетолога: как выбрать специалиста']['status'] === 'new', 'тему, возвращённую руками, проверка больше не трогает');
check(topics_dedupe(fn() => [529, '{}']) === 0, 'Claude недоступен — подбор тем не ломается');

echo "статья по источнику\n";
$html = '<html><head><meta charset="utf-8"><title>Сайт</title><meta property="og:title" content="Meta меняет правила рекламы в Казахстане">'
    . '<meta property="og:image" content="https://cdn.example/x.jpg"></head><body><nav>Меню Главная Контакты</nav>'
    . '<article><h1>Заголовок</h1><p>' . str_repeat('С 1 ноября реклама в Instagram для бизнеса в Казахстане требует новых документов. ', 12)
    . '</p><script>alert(1)</script></article><footer>© подвал</footer></body></html>';
$src = extract_source($html);
check($src['title'] === 'Meta меняет правила рекламы в Казахстане' && str_contains($src['text'], 'С 1 ноября'), 'статья: заголовок и текст');
check(!str_contains($src['text'], 'Меню') && !str_contains($src['text'], 'alert') && !str_contains($src['text'], 'подвал'), 'статья: без меню, скриптов и подвала');
check(insta_caption('1,234 likes, 56 comments - shop.kz on October 1, 2026: "Новое правило: реклама \"с ценой\" только так"') === 'Новое правило: реклама \"с ценой\" только так', 'Instagram: подпись поста из og:description');
check(!public_url('http://127.0.0.1/admin') && !public_url('http://192.168.1.1/') && !public_url('file:///etc/passwd') && !public_url('ftp://x.kz/'), 'ссылки во внутреннюю сеть запрещены');
$sent = null;
write_article('', [], '', 1500, [], function ($body) use ($sse, &$sent) { $sent = $body; return [200, $sse]; },
    ['url' => 'https://example.kz/news', 'title' => 'Новость', 'text' => 'Текст новости', 'images' => [['image/jpeg', 'QUJD']]]);
$content = $sent['messages'][0]['content'];
check(is_array($content) && $content[0]['type'] === 'image' && $content[0]['source']['data'] === 'QUJD', 'скриншоты уходят в Claude картинками');
$last = end($content)['text'];
check(str_contains($last, 'https://example.kz/news') && str_contains($last, 'Текст новости') && str_contains($last, 'выбери сам'), 'источник и «тему выбери сам» в запросе');
check(str_contains($sent['system'], 'Не копируй текст источника'), 'правила: своя статья, не пересказ');
check(throws(fn() => write_article('', [], '', 1500, []), 'источник'), 'без темы и источника — понятная ошибка');

echo "подтянуть под запросы\n";
save_article($art('tune-me', ['status' => 'scheduled', 'publish_at' => '2026-09-20T10:00', 'keywords' => ['ведение инстаграм'],
    'body_md' => "Вступление.\n\n## Цены\n\nТекст.\n\n## Вывод\n\nИтог.", 'faq' => [['Старый вопрос?', 'Ответ.']]]));
$tu = tune_articles([['ведение инстаграм цена в месяц', 15, 14.2], ['раскрутка инстаграм цена', 5, 10.2]], [],
    fn() => [200, $scene_sse(json_encode(['articles' => [['slug' => 'tune-me', 'why' => 'рядом с топом', 'queries' => ['ведение инстаграм цена в месяц'],
        'seo_title' => 'Ведение Инстаграм: цена в месяц в Казахстане', 'description' => 'Описание', 'add_keywords' => ['Раскрутка инстаграм цена', 'ведение инстаграм'],
        'sections' => [['h2' => 'Раскрутка Instagram', 'text' => 'Текст раздела.']], 'faq' => [['q' => 'Новый вопрос?', 'a' => 'Ответ.']]],
        ['slug' => 'net-takoy', 'why' => 'x', 'queries' => [], 'seo_title' => '', 'description' => '', 'add_keywords' => [], 'sections' => [], 'faq' => []]],
        'new_topics' => []], JSON_UNESCAPED_UNICODE))]);
check(count($tu['articles']) === 1 && $tu['articles'][0]['add_keywords'] === ['раскрутка инстаграм цена'], 'разбор: только существующие статьи, уже имеющиеся запросы отброшены');
$a2 = apply_tune('tune-me', ['seo_title' => $tu['articles'][0]['seo_title'], 'add_keywords' => ['раскрутка инстаграм цена'],
    'sections' => $tu['articles'][0]['sections'], 'faq' => [['q' => 'Новый вопрос?', 'a' => 'Ответ.'], ['q' => 'Старый вопрос?', 'a' => 'Другой']]]);
check($a2['seo_title'] === 'Ведение Инстаграм: цена в месяц в Казахстане' && $a2['description'] === 'Описание', 'применяется только отмеченное (описание не тронуто)');
check($a2['keywords'] === ['ведение инстаграм', 'раскрутка инстаграм цена'], 'главный запрос остаётся первым');
check(strpos($a2['body_md'], '## Раскрутка Instagram') < strpos($a2['body_md'], '## Вывод') && strpos($a2['body_md'], '## Раскрутка Instagram') > strpos($a2['body_md'], '## Цены'), 'новый раздел встаёт перед выводом');
check(count($a2['faq']) === 2, 'повторный вопрос FAQ не дублируется');
check(throws(fn() => tune_articles([], [], fn() => [200, '']), 'запросов'), 'пустая выгрузка — понятная ошибка');

echo "ближайший слот\n";
$now = new DateTimeImmutable('2026-10-01T12:00', tz());  // четверг, 10:00 уже прошло
check(next_free_slot(2, '', $now) === '2026-10-06T10:00', 'ближайший вторник, если свободен');
save_article($art('slot-tue', ['status' => 'scheduled', 'publish_at' => '2026-10-06T10:00']));
check(next_free_slot(2, '', $now) === '2026-10-08T10:00', 'занятый день пропускается');
check(next_free_slot(2, 'slot-tue', $now) === '2026-10-06T10:00', 'свой день у самой статьи не считается занятым');
check(next_free_slot(3, '', $now) === '2026-10-05T10:00', 'ритм 3 в неделю: пятница занята статьёй «later» — понедельник');
check(next_free_slot(2, '', new DateTimeImmutable('2026-10-06T09:00', tz())) === '2026-10-08T10:00', 'утром дня-слота: сегодня занят — следующий');

echo "вход\n";
check(!is_configured() && try_setup('неверный', 'andrey', 'supersecret1', 'supersecret1') === 'Неверный код настройки', 'без кода настройки не войти');
check(password_problem('short') !== '' && password_problem('long-enough-pass') === '', 'пароль от 10 символов');

array_map('unlink', glob("$data/*/*") ?: []);
echo $fails ? "\nПРОВАЛЕНО: $fails\n" : "\nВсе проверки пройдены\n";
exit($fails ? 1 : 0);
