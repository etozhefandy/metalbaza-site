<?php
// Телефон из формы заявки → '+7 (701) 234-56-78' (или '+…' для других стран) и цифры для wa.me; null — не телефон.
// Общий для send.php (форма сайта) и других форм сайта (например, рекламных страниц).
declare(strict_types=1);

function lead_phone(string $contact): ?array
{
    // Казахстан и Россия (+7) — проверяем коды: KZ мобильные 700–708, 747, 771–778 и городские 71x/72x,
    // RU — 3xx/4xx/8xx/9xx; «+7 666…» не пройдёт. Другие страны (выбраны в форме) — международный формат, 8–15 цифр.
    $digits = preg_replace('/\D/', '', $contact);
    $intl = strpos(trim($contact), '+') === 0;
    if (!$intl && strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
    if (!$intl && strlen($digits) === 10) $digits = '7' . $digits;
    if ($digits !== '' && $digits[0] === '7') {
        if (strlen($digits) !== 11 || !preg_match('/^7(?:70[0-8]|747|77[1-8]|7[12]\d|[3489]\d\d)/', $digits)) return null;
        $pretty = sprintf('+7 (%s) %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
        return [$pretty, $digits];
    }
    if (!$intl || strlen($digits) < 8 || strlen($digits) > 15) return null;
    return ['+' . $digits, $digits];
}

/** Не больше $max заявок с одного IP за 10 минут на сайт (и $max*3 на все сайты хостинга вместе). → true, если лимит превышен.
 *  Проверка и запись — под блокировкой файла: пачка параллельных запросов не проскочит лимит. IP — только REMOTE_ADDR
 *  (заголовкам X-Forwarded-For не верим). Не удалось открыть счётчик — пишем в лог и пропускаем заявку: потерять клиента хуже. */
function lead_rate_limited(int $max = 5, string $scope = ''): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $scope = $scope !== '' ? $scope : strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $limits = [md5("site|$scope|$ip") => $max, md5("all|$ip") => $max * 3];
    $dir = getenv('ALFIMOV_RL_DIR') ?: sys_get_temp_dir();
    $now = time();
    $handles = [];
    try {
        foreach ($limits as $key => $lim) {
            $fh = @fopen("$dir/alfimov_rl_$key", 'c+');
            if (!$fh || !flock($fh, LOCK_EX)) { error_log('lead_rate_limited: счётчик недоступен'); return false; }
            $handles[$key] = $fh;
        }
        $hits = [];
        foreach ($handles as $key => $fh) {
            rewind($fh);
            $hits[$key] = array_values(array_filter((array) json_decode((string) stream_get_contents($fh), true), fn($t) => $now - (int) $t < 600));
            if (count($hits[$key]) >= $limits[$key]) return true;
        }
        foreach ($handles as $key => $fh) {
            $hits[$key][] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits[$key]));
            fflush($fh);
        }
        return false;
    } finally {
        foreach ($handles as $fh) { flock($fh, LOCK_UN); fclose($fh); }
    }
}
