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

/** Не больше $max заявок с одного IP за 10 минут. → true, если лимит превышен. */
function lead_rate_limited(int $max = 5): bool
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $rlFile = sys_get_temp_dir() . '/alfimov_rl_' . md5($ip);
    $now = time();
    $hits = array_filter(
        is_file($rlFile) ? (array) json_decode((string) @file_get_contents($rlFile), true) : [],
        function ($t) use ($now) { return $now - (int) $t < 600; }
    );
    if (count($hits) >= $max) return true;
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode(array_values($hits)));
    return false;
}
