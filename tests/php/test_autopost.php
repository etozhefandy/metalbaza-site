<?php
// Тесты автопилота статей (без сети):  php tests/php/test_autopost.php   (после python3 build.py)
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/mb-autopost-' . bin2hex(random_bytes(4));
mkdir("$tmp/data", 0777, true);
putenv("ALFIMOV_SITE=$root/dist");
putenv("ALFIMOV_DATA=$tmp/data");
putenv("ALFIMOV_TG_HUB=$tmp");
putenv("ALFIMOV_TG_CONFIG=$tmp/none.php");
putenv('ALFIMOV_NOW=2026-10-14T12:00:00+05:00');   // среда
putenv('ALFIMOV_OFFLINE=1');
require "$root/dist/_blog/autopost.php";

$fails = 0;
function check(bool $ok, string $name): void { global $fails; echo ($ok ? '  ok   ' : '  FAIL ') . "$name\n"; if (!$ok) $fails++; }
$sse = fn(array $data) => [200, 'data: ' . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]) . "\n"
    . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n"];

load_all();
save_settings(['anthropic_key' => 'k', 'openai_key' => 'o']);
$cfg = autopost_settings();
check($cfg['on'] && $cfg['per_week'] === 2 && $cfg['image_mode'] === 'people' && $cfg['ready'], 'по умолчанию: включён, 2 в неделю, обложка с людьми');
check(autopost_due(), 'план пуст — есть свободное место');

echo "запуск по заходу на сайт\n";
$fired = [];
autopost_maybe_kick(function ($u) use (&$fired) { $fired[] = $u; });
check(count($fired) === 1 && str_contains($fired[0], '/api/autopost-tick.php?k=') && !empty(autopost_state()['run']), 'есть место — задача создана и запущена');
autopost_maybe_kick(function ($u) use (&$fired) { $fired[] = $u; });
check(count($fired) === 1, 'повторный заход не запускает вторую задачу');

echo "шаги\n";
$steps = [];
$exec = function ($step, $data) use (&$steps) { $steps[] = $step; return $data + [$step => true, 'title' => 'Т', 'slug' => 's', 'publish_at' => '2026-10-15T10:00']; };
$fire = function () {};
while (autopost_tick($fire, $exec) !== null) {}
$st = autopost_state();
check($steps === ['topic', 'write', 'publish'] && empty($st['run']) && $st['last']['title'] === 'Т', 'тема → статья → обложка и план, итог сохранён');

echo "ошибки\n";
$s = autopost_state(); $s['run'] = ['i' => 1, 'tries' => 0, 'busy' => false, 'touched' => time(), 'data' => []]; autopost_save($s);
autopost_tick($fire, function () { throw new AiError('Claude сейчас перегружен'); });
$st = autopost_state();
check(empty($st['run']) && str_contains($st['last']['error'], 'перегружен'), 'ошибка — задача снята, причина видна в админке');
$s = autopost_state(); $s['run'] = ['i' => 1, 'tries' => 1, 'busy' => true, 'touched' => time() - AUTOPOST_STEP_LEASE - 1, 'data' => []]; autopost_save($s);
autopost_tick($fire, $exec);
check(empty(autopost_state()['run']) && str_contains(autopost_state()['last']['error'], 'прервался'), 'дважды оборвался на хостинге — задача снята');

echo "настоящие шаги (ИИ подменён)\n";
write_json(topics_file(), [['id' => 'aaaaaaaaaaaa', 'topic' => 'Сколько весит арматура 12 мм', 'main_keyword' => 'вес арматуры 12', 'keywords' => ['арматура 12 вес метра'],
    'intent' => 'информационный', 'service' => 'armatura', 'why' => 'спрос', 'status' => 'new', 'source' => 'тест', 'created' => '2026-10-14']]);
$d = autopost_step('topic', []);
check($d['topic'] === 'Сколько весит арматура 12 мм' && $d['keywords'] === ['вес арматуры 12', 'арматура 12 вес метра'], 'тема — первая новая из списка, главный запрос первым');
$asked = [];
$post = function ($body) use ($sse, &$asked) {
    $asked[] = $body;
    if (str_contains(json_encode($body), '"scene"')) return $sse(['main_idea' => 'Вес арматуры', 'scene' => 'A foreman weighing rebar']);
    return $sse(['slug' => 'ves-armatury-12', 'title' => 'Сколько весит арматура 12 мм', 'seo_title' => 'Вес арматуры 12 мм', 'description' => 'Д',
        'lead' => 'Л', 'summary' => 'К', 'category' => 'Арматура', 'keywords' => ['вес арматуры 12'], 'body_md' => "## Вес\n\n0,888 кг.", 'faq' => [], 'related' => []]);
};
$d = autopost_step('write', $d, $post);
check($d['article']['slug'] === 'ves-armatury-12' && str_contains($asked[0]['system'], 'Металбаза'), 'статья написана промптом сайта');
save_article(['slug' => 'ves-armatury-12', 'title' => 'Чужая статья', 'body_md' => 'x']);
$r = autopost_step('publish', $d, $post);
$a = get_article($r['slug']);
check($r['slug'] === 'ves-armatury-12-2' && get_article('ves-armatury-12')['title'] === 'Чужая статья', 'адрес занят — чужая статья не затёрта');
check($a['status'] === 'scheduled' && $a['publish_at'] === '2026-10-15T10:00' && $a['author'] === NICHE_AUTHOR, 'статья в плане на ближайший свободный день (чт, 10:00)');
check(str_contains(json_encode($asked, JSON_UNESCAPED_UNICODE), 'Люди: PEOPLE'), 'обложка — сюжет с людьми');
check(count(autopost_queue()) === 1, 'очередь пополнилась');

echo "ограничения\n";
foreach (load_all() as $x) delete_article($x['slug']);
$s = autopost_state(); unset($s['run']); $s['checked'] = 0; $s['started'] = [time(), time(), time()]; autopost_save($s);
$fired = [];
autopost_maybe_kick(function ($u) use (&$fired) { $fired[] = $u; });
check($fired === [], 'не больше 3 статей в сутки');
$s = autopost_state(); $s['checked'] = 0; $s['started'] = []; autopost_save($s);
$x = settings(); $x['autopost'] = false; save_settings($x);
autopost_maybe_kick(function ($u) use (&$fired) { $fired[] = $u; });
check($fired === [], 'выключен в админке — не пишет');
$x['autopost'] = true; unset($x['openai_key']); save_settings($x);
$s = autopost_state(); $s['checked'] = 0; autopost_save($s);
autopost_maybe_kick(function ($u) use (&$fired) { $fired[] = $u; });
check($fired === [], 'нет ключей — не пишет');
$x['openai_key'] = 'o'; save_settings($x);
save_article(['slug' => 'a1', 'title' => 'Статья А', 'body_md' => 'x', 'status' => 'scheduled', 'publish_at' => '2026-10-15T10:00']);
save_article(['slug' => 'a2', 'title' => 'Статья Б', 'body_md' => 'x', 'status' => 'scheduled', 'publish_at' => '2026-10-20T10:00']);
check(!autopost_due(), 'план на неделю заполнен (2 статьи при ритме 2) — новая не нужна');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\nПРОВАЛЕНО: $fails\n" : "\nВсе проверки пройдены\n";
exit($fails ? 1 : 0);
