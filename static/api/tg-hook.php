<?php
// Вебхук Telegram-бота заявок : привязка группы по PIN и команды владельца.
// Telegram подписывает запросы секретом (X-Telegram-Bot-Api-Secret-Token) — без него ничего не делаем.
declare(strict_types=1);
require __DIR__ . '/_tg.php';
header('X-Robots-Tag: noindex');
$secret = (string) (tg_state()['hook_secret'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $secret === ''
    || !hash_equals($secret, (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(404);
    exit;
}
$u = json_decode((string) file_get_contents('php://input'), true);
try {
    // меню команд «/» — один раз (если бот подключили до появления меню)
    $st = tg_state();
    if (($st['commands_v'] ?? 0) < 1) { tg_set_commands(); $st['commands_v'] = 1; tg_save_state($st); }
    if (is_array($u)) tg_handle_update($u, null, function (string $data): string {
        require_once dirname(__DIR__) . '/_blog/seo.php';   // кнопки под отчётом SEO
        return seo_tg_callback($data);
    });
} catch (Throwable $ex) {
    error_log('tg-hook: ' . $ex->getMessage());
}
echo 'ok';
