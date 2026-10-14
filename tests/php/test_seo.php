<?php
// Тесты SEO-автопилота (без сети):  php tests/php/test_seo.php   (после python3 build.py)
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/alfimov-seo-' . bin2hex(random_bytes(4));
mkdir("$tmp/data", 0777, true);
file_put_contents("$tmp/tg-config.php", "<?php return ['tg_token' => 'T', 'tg_chat' => '111'];");
putenv("ALFIMOV_TG_CONFIG=$tmp/tg-config.php");
putenv("ALFIMOV_SITE=$root/dist");
putenv("ALFIMOV_DATA=$tmp/data");
putenv("ALFIMOV_TG_HUB=$tmp");
putenv('ALFIMOV_NOW=2026-10-13T12:00:00+05:00');
putenv('ALFIMOV_OFFLINE=1');
require "$root/dist/_blog/seo.php";

$fails = 0;
function check(bool $ok, string $name): void { global $fails; echo ($ok ? '  ok   ' : '  FAIL ') . "$name\n"; if (!$ok) $fails++; }
function throws(callable $fn, string $needle): bool { try { $fn(); } catch (Throwable $e) { return str_contains($e->getMessage(), $needle); } return false; }
$sse = fn(array $data) => [200, 'data: ' . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]) . "\n"
    . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n"];

load_all();   // сначала перенос стартовых статей, потом чистим
foreach (glob(articles_dir() . '/*.json') ?: [] as $f) unlink($f);
$live = ['status' => 'scheduled', 'publish_at' => '2026-10-01T10:00'];
save_article($live + ['slug' => 'target', 'title' => 'Сколько стоит таргетированная реклама', 'keywords' => ['таргет цена'],
    'body_md' => "## Цены\n\nБюджет на таргетированную рекламу зависит от ниши.\n\n## Итог\n\nСчитайте заявки."]);
save_article($live + ['slug' => 'insta', 'title' => 'Ведение Instagram', 'keywords' => ['ведение инстаграм'],
    'body_md' => "## Ведение Instagram для бизнеса\n\nЕсли нужна [реклама](/target/), сначала посчитайте бюджет. "
        . "Многие начинают с таргетированной рекламы в Instagram, а потом думают о контенте.\n\n- Ведение Instagram — это регулярность\n\n| таргетированной рекламы | x |\n|---|---|"]);
save_article(['slug' => 'draft', 'title' => 'Черновик', 'body_md' => 'Текст']);

echo "разбор Search Console\n";
$row = fn($q, $page, $clicks, $impr, $pos) => ['q' => $q, 'page' => "https://metalbaza.kz$page", 'clicks' => $clicks, 'impr' => $impr, 'pos' => $pos];
$an = seo_analyze(['period' => ['2026-09-13', '2026-10-10'], 'cur' => [
    $row('ведение инстаграм цена', '/blog/insta/', 2, 120, 7.4),
    $row('таргет цена', '/blog/target/', 0, 40, 3.0),
    $row('таргет цена', '/blog/insta/#ceny', 0, 30, 12.0),
    $row('далёкий запрос', '/blog/target/', 0, 50, 45.0),
    $row('мелочь', '/blog/target/', 0, 3, 8.0),
], 'prev' => [
    $row('таргет цена', '/blog/target/', 6, 200, 3.0),
]]);
check(array_column($an['quick'], 'q') === ['ведение инстаграм цена', 'таргет цена'], 'близко к топу: 4–20 место и от 10 показов, по убыванию показов');
check($an['quick'][1]['page'] === '/blog/insta/', 'адрес без #якоря');
check($an['decay'][0]['page'] === '/blog/target/' && $an['decay'][0]['impr'] === [200, 93], 'просела: показы 200 → 93');
check($an['cannibal'][0]['q'] === 'таргет цена' && count($an['cannibal'][0]['pages']) === 2, 'две страницы делят один запрос');
check($an['low_ctr'] === [] , 'мало кликов: нет страниц в топ-10 с 50+ показами — пусто');
check($an['totals']['cur']['impr'] === 243 && $an['totals']['prev']['clicks'] === 6, 'итоги за период и прошлый период');
check($an['queries'][0] === ['ведение инстаграм цена', 120, 7.4] && str_starts_with($an['pages'][0][0], 'https://metalbaza.kz/'), 'сводка для правок статей — как выгрузка');
check(str_contains(tune_notes($an), 'делят страницы'), 'подсказки для правок: кто кому мешает');

echo "ключ Search Console\n";
$pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($pk, $pem);
$pub = openssl_pkey_get_details($pk)['key'];
$keyJson = json_encode(['type' => 'service_account', 'client_email' => 'seo@p.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
check(throws(fn() => gsc_parse_key('{"type":"authorized_user"}'), 'служебного аккаунта'), 'чужой JSON — понятная ошибка');
$sa = gsc_parse_key($keyJson);
[$h, $c, $sig] = explode('.', gsc_jwt($sa, 1000));
$claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
check(openssl_verify("$h.$c", base64_decode(strtr($sig, '-_', '+/') . str_repeat('=', (4 - strlen($sig) % 4) % 4)), $pub, OPENSSL_ALGO_SHA256) === 1
    && $claims['scope'] === GSC_SCOPE && $claims['exp'] === 4600, 'JWT подписан ключом, доступ только на чтение');
$s = settings();
$s['gsc_key'] = $sa;
save_settings($s);
$calls = [];
$google = function ($m, $url, $hd, $body) use (&$calls) {
    $calls[] = $url;
    if (str_contains($url, 'oauth2')) return [200, '{"access_token":"tok","expires_in":3600}'];
    if (str_ends_with($url, '/sites')) return [200, '{"siteEntry":[{"siteUrl":"sc-domain:metalbaza.kz"}]}'];
    $ok = in_array('Authorization: Bearer tok', $hd, true) && json_decode($body, true)['dimensions'] === ['query', 'page'];
    return [200, json_encode(['rows' => $ok ? [['keys' => ['q', 'https://metalbaza.kz/'], 'clicks' => 1, 'impressions' => 9, 'position' => 4.44]] : []])];
};
$g = gsc_fetch($google, new DateTimeImmutable('2026-10-13'));
check($g['period'] === ['2026-09-13', '2026-10-10'] && $g['cur'][0] === ['q' => 'q', 'page' => 'https://metalbaza.kz/', 'clicks' => 1, 'impr' => 9, 'pos' => 4.4],
    '28 дней со сдвигом на 3 дня; строки разобраны');
check(count(array_filter($calls, fn($u) => str_contains($u, 'oauth2'))) === 1 && str_contains($calls[1], 'sc-domain%3Ametalbaza.kz'), 'токен берётся один раз и кэшируется');
check(gsc_check($google) === 'seo@p.iam.gserviceaccount.com', 'проверка доступа к ресурсу');
check(throws(fn() => gsc_check(fn() => [200, '{"siteEntry":[]}']), 'Добавить пользователя'), 'нет доступа к metalbaza.kz — подсказка, что сделать');

echo "перелинковка\n";
$md = "## Таргетированной рекламы\n\nМногие начинают с Таргетированной рекламы, а потом [таргетированной рекламы](/x/).\n\nЕщё про таргетированной рекламы.";
$out = auto_links($md, [['to' => 'target', 'anchor' => 'таргетированной рекламы']]);
check(str_contains($out, 'с [Таргетированной рекламы](/blog/target/), а') && substr_count($out, '/blog/target/') === 1,
    'первое вхождение в абзаце, регистр текста сохраняется, один раз');
check(str_starts_with($out, '## Таргетированной рекламы'), 'в заголовках ссылок нет');
check(auto_links("Про таргет.\n\nСм. [тут](/blog/target/).", [['to' => 'target', 'anchor' => 'таргет']]) === "Про таргет.\n\nСм. [тут](/blog/target/).", 'на статью уже есть ссылка — не дублируем');
check(auto_links('Таргетолог', [['to' => 'target', 'anchor' => 'таргет']]) === 'Таргетолог', 'только целым словом');
check(links_due() === ['insta', 'target'] || links_due() === ['target', 'insta'], 'ссылки нужны обеим вышедшим статьям, черновику — нет');
$n = make_links(['insta', 'target'], fn() => $sse(['links' => [
    ['from' => 'insta', 'to' => 'target', 'anchor' => 'таргетированной рекламы в Instagram'],
    ['from' => 'insta', 'to' => 'draft', 'anchor' => 'регулярность'],
    ['from' => 'target', 'to' => 'insta', 'anchor' => 'фраза, которой нет в тексте'],
]]));
check($n === 1 && links_due() === [], 'неподходящие ссылки отброшены (черновик, фраза не из текста); пересчёт не нужен');
$html = render_article(get_article('insta'), live_articles());
check(str_contains($html, '<a href="/blog/target/">таргетированной рекламы в Instagram</a>'), 'ссылка появляется на странице статьи');
check(str_contains(get_article('insta')['body_md'], 'с таргетированной рекламы в Instagram, а'), 'сам текст статьи не меняется');

echo "ИИ-ответы\n";
$r = geo_result('chatgpt', 'Посоветуй агентство', 'Советую Металбазу — там цены нескольких баз.', ['https://www.metalbaza.kz/blog/x/', 'https://2gis.kz/a', 'https://top.kz/']);
check($r['mentioned'] && $r['cited'] && $r['sites'] === ['2gis.kz', 'top.kz'] && str_contains($r['excerpt'], 'Металбаз'), 'упоминание, ссылка на сайт, чужие сайты');
check(!geo_result('claude', 'q', 'Обратитесь в Webtronics.', [])['mentioned'], 'не упомянули — так и пишем');
$models = [];
[$t, $u] = geo_ask_openai('вопрос', function ($m, $url, $hd, $body) use (&$models) {
    $b = json_decode($body, true);
    $models[] = $b['model'];
    if ($b['model'] === 'gpt-5') return [404, '{"error":{"message":"model not found"}}'];
    return [200, json_encode(['output' => [['type' => 'web_search_call'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Ответ',
        'annotations' => [['type' => 'url_citation', 'url' => 'https://a.kz/']]]]]]])];
});
check($models === ['gpt-5', 'gpt-4.1'] && $t === 'Ответ' && $u === ['https://a.kz/'], 'ChatGPT: недоступная модель — берём следующую; источники из ответа');
[$t, $u] = geo_ask_claude('вопрос', fn($m, $url, $hd, $body) => [200, json_encode(['content' => [
    ['type' => 'server_tool_use'], ['type' => 'web_search_tool_result', 'content' => []],
    ['type' => 'text', 'text' => 'Есть Металбаза.', 'citations' => [['url' => 'https://metalbaza.kz/']]]]])]);
check($t === 'Есть Металбаза.' && $u === ['https://metalbaza.kz/'], 'Claude: текст и источники');

echo "расписание\n";
$s = settings();
unset($s['gsc_key']);
$s['anthropic_key'] = 'sk-ant-test';
save_settings($s);
save_article(array_merge(get_article('target'), ['body_md' => get_article('target')['body_md'] . "\n\nНовый абзац."]), 'target');   // текст изменился — ссылки пересчитать
$plan = seo_plan();
check($plan[0] === 'links:target' && in_array('geo:claude:4', $plan, true) && end($plan) === 'report' && !in_array('gsc', $plan, true),
    'без Search Console и OpenAI: ссылки, вопросы Claude, отчёт');
$fired = [];
$fire = function ($url) use (&$fired) { $fired[] = $url; };
$t0 = time();
seo_maybe_kick($fire, $t0);
check(count($fired) === 1 && str_contains($fired[0], '/api/seo-tick.php?k=' . seo_state()['secret']), 'пора — проверка запущена отдельным запросом с секретом');
seo_maybe_kick($fire, $t0 + 5);
check(count($fired) === 1, 'повторный заход сразу — ничего не делает');
$done = [];
$exec = function ($step, $run) use (&$done) {
    $done[] = $step;
    if ($step === 'geo:claude:1') throw new AiError('лимит');
    return str_starts_with($step, 'geo') ? ['engine' => 'claude', 'prompt' => 'q', 'mentioned' => $step === 'geo:claude:0', 'cited' => false, 'excerpt' => '', 'sites' => ['a.kz']] : [];
};
while (seo_tick($fire, $exec) !== null);
$st = seo_state();
check($done === $plan && !isset($st['run']) && $st['last_full'] >= $t0 && count($fired) === count($plan), 'шаги по одному, каждый запускает следующий; проверка закрыта');
check($st['last_run']['errors'] === ['geo:claude:1: лимит'], 'ошибка шага не останавливает проверку');
make_links(['target'], fn() => $sse(['links' => []]));
$fired = [];
seo_maybe_kick($fire, $t0 + 700);
check($fired === [], 'через 10 минут: неделя не прошла и ссылки свежие — не запускаем');

// шаг оборвался (хостинг убил запрос) — повтор, после второго обрыва шаг пропускается
$st = seo_state();
$st['run'] = seo_new_run(['links:insta', 'report'], false);
$st['run']['busy'] = true;
$st['run']['touched'] = time() - 200;
$st['kicked'] = 0;
seo_save($st);
seo_maybe_kick($fire, time());
check(count($fired) === 1, 'зависший шаг — заход на сайт перезапускает проверку');
$st = seo_state();
$st['run']['busy'] = true; $st['run']['tries'] = 1; $st['run']['touched'] = time() - 200;
seo_save($st);
check(seo_tick($fire, fn() => []) === 'report' && seo_state()['last_run']['errors'] === ['links:insta: прервался на хостинге'], 'второй обрыв — шаг пропущен, идём дальше');

echo "отчёт и Telegram\n";
check(delta(666, 0, true) === '' && delta(120, 100, true) === ' (+20%)' && delta(3, 5) === ' (−2)', 'сравнение с прошлым периодом; без данных за прошлый — без сравнения');

write_json(DATA_DIR . '/tune.json', ['articles' => [['slug' => 'target', 'title' => 'T', 'seo_title' => 'Таргет: цены в Казахстане 2026', 'description' => '',
    'add_keywords' => ['таргет алматы'], 'sections' => [], 'faq' => [['q' => 'Сколько стоит?', 'a' => 'От 200 000 ₸.']]]]]);
$msgs = [];
$tg = function ($m, $p) use (&$msgs) { $msgs[] = [$m, $p]; return ['ok' => true]; };
$res = seo_report(['errors' => [], 'results' => ['tune' => ['articles' => 1, 'new_topics' => 0, 'applied' => 0],
    'geo:claude:0' => geo_result('claude', 'Посоветуй сайт', 'Металбаза', []), 'geo:claude:1' => geo_result('claude', 'Где SEO', 'Нет', ['https://x.kz/'])]], $tg);
$text = $msgs[0][1]['text'];
check($res['sent'] && $msgs[0][1]['chat_id'] === '111', 'отчёт уходит владельцу в личку');
check(str_contains($text, 'Search Console не подключена') && str_contains($text, 'упомянули в 1 из 2') && str_contains($text, 'x.kz'), 'в отчёте: подсказка про Search Console, ИИ-ответы');
$kb = json_decode($msgs[0][1]['reply_markup'], true)['inline_keyboard'];
check($kb[0][0]['callback_data'] === 'seo:apply' && $kb[1][0]['url'] === 'https://metalbaza.kz/admin/#seo', 'кнопки: применить правки и открыть админку');
check(read_json(seo_dir() . '/report.json')['geo_hits'] === 1 && count(read_json(seo_dir() . '/history.json')) === 1, 'отчёт и история сохранены');
$msgs = [];
tg_handle_update(['callback_query' => ['id' => 'c1', 'from' => ['id' => 222], 'data' => 'seo:apply']], $tg, 'seo_tg_callback');
check(get_article('target')['seo_title'] === '' && $msgs[0][1]['text'] === 'Кнопка только для владельца', 'чужой не может нажать «Применить»');
$msgs = [];
tg_handle_update(['callback_query' => ['id' => 'c2', 'from' => ['id' => 111], 'data' => 'seo:apply']], $tg, 'seo_tg_callback');
$a = get_article('target');
check($a['seo_title'] === 'Таргет: цены в Казахстане 2026' && $a['faq'] === [['Сколько стоит?', 'От 200 000 ₸.']] && str_contains(end($msgs)[1]['text'], 'применены в 1 статье'),
    'владелец нажал — правки применены, ответ в чат');
check(seo_tg_callback('seo:apply') === 'Нечего применять — правки уже применены.', 'повторное нажатие ничего не ломает');
check(in_array('нет обложки', seo_lint(get_article('insta')), true), 'замечания по оформлению статьи находятся');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\nПРОВАЛЕНО: $fails\n" : "\nВсе проверки пройдены\n";
exit($fails ? 1 : 0);
