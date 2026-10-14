<?php
// Тесты журнала заявок (без сети):  php tests/php/test_leads.php   (после python3 build.py)
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/mb-leads-' . bin2hex(random_bytes(4));
putenv("ALFIMOV_SITE=$root/dist");
putenv("ALFIMOV_DATA=$tmp");
putenv('ALFIMOV_NOW=2026-10-14T12:00:00+05:00');
require "$root/dist/_blog/leads.php";

$fails = 0;
function check(bool $ok, string $name): void { global $fails; echo ($ok ? '  ok   ' : '  FAIL ') . "$name\n"; if (!$ok) $fails++; }

check(lead_months() === [] && leads_list('2026-10') === [], 'пустой журнал');
$a = lead_add(['phone' => '+7 (701) 123-45-67', 'product' => 'Арматура', 'city' => 'Астана', 'message' => 'А500С; "12 мм"', 'source' => 'product:armatura', 'consent' => true]);
$b = lead_add(['phone' => '+7 (777) 000-00-00', 'product' => 'Профнастил', 'city' => 'Алматы', 'source' => 'home', 'consent' => true]);
check($a && $b && $a['id'] !== $b['id'] && str_starts_with($a['id'], '261014-'), 'заявки записаны, номера разные');
check(lead_months() === ['2026-10'], 'месяц в списке');
$list = leads_list('2026-10');
check(count($list) === 2 && $list[0]['product'] === 'Профнастил' && $list[1]['consent'] === true, 'новые первыми, согласие сохранено');
check(leads_list('../x') === [], 'кривой месяц — пусто, без обхода пути');
$csv = leads_csv($list);
check(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '"А500С; ""12 мм"""') && substr_count($csv, "\r\n") === 3, 'CSV для Excel: BOM, кавычки экранированы');
check(substr(sprintf('%o', fileperms("$tmp/leads/2026-10.jsonl")), -3) === '600', 'файл журнала закрыт от чужих');
exit($fails ? 1 : 0);
