<?php
// Тесты бота заявок (без сети):  php tests/php/test_tg.php   (после python3 build.py)
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/alfimov-tg-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents("$tmp/tg-config.php", "<?php return ['tg_token' => 'T', 'tg_chat' => '111'];");
putenv("ALFIMOV_TG_CONFIG=$tmp/tg-config.php");
putenv("ALFIMOV_DATA=$tmp/data");
require "$root/dist/api/_tg.php";

$fails = 0;
function check(bool $ok, string $name): void { global $fails; echo ($ok ? '  ok   ' : '  FAIL ') . "$name\n"; if (!$ok) $fails++; }
$sent = [];
$tg = function ($method, $p) use (&$sent) { $sent[] = [$method, $p]; return ['ok' => true]; };
$pinFrom = function () use (&$sent) { foreach (array_reverse($sent) as [, $p]) if (preg_match('#<code>(\d{6})</code>#', $p['text'] ?? '', $m)) return $m[1]; return null; };
$msg = fn($chat, $type, $from, $text, $title = 'Отдел продаж') => ['message' => ['chat' => ['id' => $chat, 'type' => $type, 'title' => $title], 'from' => ['id' => $from], 'text' => $text]];

check(tg_group() === '', 'по умолчанию группы нет — заявки только в личку');
tg_handle_update($msg(222, 'private', 222, '/pin'), $tg);
check($pinFrom() === null && str_contains(end($sent)[1]['text'], 'служебный'), 'чужому в личке PIN не выдаётся');
tg_handle_update($msg(111, 'private', 111, '/pin'), $tg);
$pin = $pinFrom();
check($pin !== null && end($sent)[1]['chat_id'] === '111', 'владелец получает PIN в личку');
tg_handle_update($msg(-500, 'group', 333, '/link 000000'), $tg);
check(tg_group() === '' && str_contains(end($sent)[1]['text'], 'неверный'), 'неверный PIN — группа не привязана');
tg_handle_update($msg(-500, 'group', 333, "/link@metalbaza_bot $pin"), $tg);
check(tg_group() === '-500' && tg_state()['lead_title'] === 'Отдел продаж', 'верный PIN в группе — группа привязана');
tg_handle_update($msg(-600, 'group', 333, "/link $pin"), $tg);
check(tg_group() === '-500', 'PIN одноразовый');
tg_handle_update($msg(111, 'private', 111, '/pin'), $tg);
$p2 = $pinFrom();
for ($i = 0; $i < TG_PIN_MAX_TRIES; $i++) tg_handle_update($msg(-700, 'group', 1, '/link 123456'), $tg);
tg_handle_update($msg(-700, 'group', 1, "/link $p2"), $tg);
check(tg_group() === '-500', 'после 10 неверных попыток PIN сгорает (перебор не пройдёт)');

$sent = [];
$r = tg_send_lead('Заявка', function ($m, $p) use (&$sent) { $sent[] = $p['chat_id']; return $p['chat_id'] === '-500' ? ['ok' => false, 'parameters' => ['migrate_to_chat_id' => -100500]] : ['ok' => true]; });
check($r && $sent === ['111', '-500', '-100500'] && tg_group() === '-100500', 'заявка — в личку и в группу; супергруппа — привязка переносится сама');
$sent = [];
$r = tg_send_lead('Заявка', function ($m, $p) use (&$sent) { $sent[] = $p['chat_id']; return ['ok' => $p['chat_id'] === '111']; });
check($r && $sent === ['111', '-100500', '111'] && tg_group() === '', 'бота убрали из группы — заявка всё равно в личке, привязка снята, предупреждение');
tg_link_group('-1', 'x');
tg_handle_update($msg(111, 'private', 111, '/unlink'), $tg);
check(tg_group() === '', '/unlink отвязывает группу');
$sent = [];
tg_handle_update($msg(111, 'private', 111, TG_BTN_PIN), $tg);
check($pinFrom() !== null && str_contains($sent[0][1]['reply_markup'] ?? '', TG_BTN_UNLINK), 'кнопка «PIN» в личке — PIN приходит, кнопки остаются');
tg_link_group('-9', 'Продажи');
tg_handle_update($msg(111, 'private', 111, TG_BTN_STATUS), $tg);
check(str_contains(end($sent)[1]['text'], 'Продажи'), 'кнопка «Куда идут заявки» — показывает группу');
tg_handle_update($msg(111, 'private', 111, TG_BTN_UNLINK), $tg);
check(tg_group() === '' && str_contains(end($sent)[1]['text'], 'отвязана'), 'кнопка «Отвязать группу» работает');
tg_handle_update($msg(222, 'private', 222, TG_BTN_UNLINK), $tg);
check(!isset(end($sent)[1]['reply_markup']), 'чужому кнопки не показываются и не работают');
$calls = [];
tg_ensure_webhook(function ($m, $p) use (&$calls) { $calls[] = $m; return ['ok' => true]; });
check($calls === ['setWebhook', 'setMyCommands'], 'при подключении бота ставится меню команд');
$sent = [];
tg_send_lead('Заявка', function ($m, $p) use (&$sent) { $sent[] = $p['chat_id']; return ['ok' => true]; });
check($sent === ['111'], 'без группы — только в личку');

array_map('unlink', glob("$tmp/data/*") ?: []);
echo $fails ? "\nПРОВАЛЕНО: $fails\n" : "\nВсе проверки пройдены\n";
exit($fails ? 1 : 0);
