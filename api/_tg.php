<?php
// Telegram-бот заявок: общие функции для send.php (заявки), tg-hook.php (вебхук бота) и админки.
//
// Куда слать заявки: всегда владельцу в личку (tg_chat из tg-config.php рядом с httpdocs) и,
// если привязана, ещё в группу. Владелец привязывает группу так: просит у бота PIN в личке (/pin), добавляет бота в группу и пишет
// там «/link PIN». Привязка и вебхук хранятся в <папка данных>/telegram.json (вне сайта).
declare(strict_types=1);
require_once dirname(__DIR__) . '/_blog/site.php';

const TG_PIN_TTL = 900;        // PIN живёт 15 минут
const TG_PIN_MAX_TRIES = 10;   // столько неверных PIN — и текущий сгорает

function tg_cfg(): array
{
    $external = getenv('ALFIMOV_TG_CONFIG') ?: dirname(__DIR__, 2) . '/tg-config.php';  // переменная — для тестов
    $cfg = is_file($external) ? require $external : (is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);
    return is_array($cfg) ? $cfg : [];
}

function tg_state_file(): string
{
    return (getenv('ALFIMOV_DATA') ?: dirname(__DIR__, 2) . '/' . SITE_DATA) . '/telegram.json';
}

function tg_state(): array
{
    $s = is_file(tg_state_file()) ? json_decode((string) file_get_contents(tg_state_file()), true) : null;
    return is_array($s) ? $s : [];
}

function tg_save_state(array $s): void
{
    $file = tg_state_file();
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0750, true);
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $file);
    @chmod($file, 0600);
}

/** Владелец бота — тот, кому заявки шли изначально (личный чат, положительный id). */
function tg_owner(): string { return (string) (tg_cfg()['tg_chat'] ?? ''); }

/** Привязанная группа (или '' — заявки только в личку). */
function tg_group(): string { return (string) (tg_state()['lead_chat'] ?? ''); }

/** Вызов Bot API → ответ Telegram (массив) или ['ok' => false]. $call — подмена для тестов. */
function tg_api(string $method, array $params, ?callable $call = null): array
{
    if ($call) return $call($method, $params);
    $token = (string) (tg_cfg()['tg_token'] ?? '');
    if ($token === '') return ['ok' => false, 'description' => 'нет токена бота'];
    $ch = curl_init("https://api.telegram.org/bot$token/$method");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = json_decode((string) curl_exec($ch), true);
    return is_array($resp) ? $resp : ['ok' => false, 'description' => 'нет ответа Telegram'];
}

function tg_send(string $chat, string $text, ?callable $call = null, ?array $markup = null): array
{
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'];
    if ($markup) $p['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
    return tg_api('sendMessage', $p, $call);
}

// Кнопки в личке владельца — постоянно под полем ввода, чтобы не помнить команды.
const TG_BTN_PIN = '🔑 PIN для группы';
const TG_BTN_STATUS = '📍 Куда идут заявки';
const TG_BTN_UNLINK = '✖️ Отвязать группу';

function tg_owner_keyboard(): array
{
    return ['keyboard' => [[['text' => TG_BTN_PIN]], [['text' => TG_BTN_STATUS], ['text' => TG_BTN_UNLINK]]],
        'resize_keyboard' => true, 'is_persistent' => true];
}

/** Меню команд «/» в личке с ботом. */
function tg_set_commands(?callable $call = null): void
{
    tg_api('setMyCommands', ['scope' => json_encode(['type' => 'all_private_chats']), 'commands' => json_encode([
        ['command' => 'pin', 'description' => 'PIN, чтобы привязать группу'],
        ['command' => 'status', 'description' => 'Куда сейчас идут заявки'],
        ['command' => 'unlink', 'description' => 'Отвязать группу'],
    ], JSON_UNESCAPED_UNICODE)], $call);
}

/**
 * Заявка: всегда владельцу в личку и, если привязана, ещё в группу. Группа стала супергруппой —
 * переносим привязку и повторяем; бота убрали из группы — отвязываем и предупреждаем в личке.
 */
function tg_send_lead(string $text, ?callable $call = null): bool
{
    $ok = !empty(tg_send(tg_owner(), $text, $call)['ok']);
    $group = (string) (tg_state()['lead_chat'] ?? '');
    if ($group === '' || $group === tg_owner()) return $ok;
    $r = tg_send($group, $text, $call);
    $new = $r['parameters']['migrate_to_chat_id'] ?? null;
    if (!$r['ok'] && $new) {
        $s = tg_state();
        $s['lead_chat'] = (string) $new;
        tg_save_state($s);
        $r = tg_send((string) $new, $text, $call);
    }
    if (!$r['ok']) {
        $title = (string) (tg_state()['lead_title'] ?? 'группа');
        tg_unlink();
        tg_send(tg_owner(), "⚠️ Не удалось отправить заявку в группу «" . htmlspecialchars($title) . "» — бота, видимо, убрали. "
            . "Группа отвязана, заявки приходят только сюда. Привязать снова — /pin.", $call);
    }
    return $ok || !empty($r['ok']);
}

/** Новый одноразовый PIN владельцу в личку. */
function tg_issue_pin(?callable $call = null): bool
{
    $s = tg_state();
    $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $s['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
    $s['pin_expires'] = time() + TG_PIN_TTL;
    $s['pin_tries'] = 0;
    tg_save_state($s);
    $r = tg_send(tg_owner(), "🔑 PIN для привязки группы: <code>$pin</code>\n\n"
        . "1. Добавьте меня в группу, куда должны приходить заявки.\n"
        . "2. Отправьте в группе: <code>/link $pin</code>\n\n"
        . "PIN одноразовый и действует 15 минут. Никому его не пересылайте.", $call, tg_owner_keyboard());
    return !empty($r['ok']);
}

/** Проверка PIN из группы. → true, если группа привязана. */
function tg_check_pin(string $pin, ?callable $call = null): bool
{
    $s = tg_state();
    if (empty($s['pin_hash']) || time() > (int) ($s['pin_expires'] ?? 0)) return false;
    if (password_verify($pin, $s['pin_hash'])) {
        unset($s['pin_hash'], $s['pin_expires'], $s['pin_tries']);
        tg_save_state($s);
        return true;
    }
    $s['pin_tries'] = (int) ($s['pin_tries'] ?? 0) + 1;
    if ($s['pin_tries'] >= TG_PIN_MAX_TRIES) unset($s['pin_hash'], $s['pin_expires']);
    tg_save_state($s);
    return false;
}

function tg_link_group(string $chat, string $title): void
{
    $s = tg_state();
    $s['lead_chat'] = $chat;
    $s['lead_title'] = $title;
    tg_save_state($s);
}

function tg_unlink(): void
{
    $s = tg_state();
    unset($s['lead_chat'], $s['lead_title']);
    tg_save_state($s);
}

/** Вебхук бота: Telegram шлёт обновления на /api/tg-hook.php с секретом в заголовке. */
function tg_ensure_webhook(?callable $call = null): bool
{
    $s = tg_state();
    if (empty($s['hook_secret'])) {
        $s['hook_secret'] = bin2hex(random_bytes(24));
        tg_save_state($s);
    }
    $r = tg_api('setWebhook', ['url' => SITE_URL . '/api/tg-hook.php', 'secret_token' => $s['hook_secret'],
        'allowed_updates' => json_encode(['message', 'my_chat_member', 'callback_query']), 'drop_pending_updates' => 'true'], $call);
    if (!empty($r['ok'])) tg_set_commands($call);
    return !empty($r['ok']);
}

/**
 * Обработка одного обновления от Telegram. $on_button — кнопки под сообщениями бота (отчёт SEO):
 * fn(string $data): string — текст ответа владельцу.
 */
function tg_handle_update(array $u, ?callable $call = null, ?callable $on_button = null): void
{
    $owner = tg_owner();
    $cq = $u['callback_query'] ?? null;
    if ($cq) {
        $mine = (string) ($cq['from']['id'] ?? '') === $owner && $owner !== '';
        tg_api('answerCallbackQuery', ['callback_query_id' => (string) ($cq['id'] ?? ''), 'text' => $mine ? 'Выполняю…' : 'Кнопка только для владельца'], $call);
        if ($mine && $on_button && ($reply = $on_button((string) ($cq['data'] ?? ''))) !== '') tg_send($owner, $reply, $call);
        return;
    }
    // бота добавили в группу — подсказать, как привязать
    $mcm = $u['my_chat_member'] ?? null;
    if ($mcm && in_array($mcm['new_chat_member']['status'] ?? '', ['member', 'administrator'], true)
        && in_array($mcm['chat']['type'] ?? '', ['group', 'supergroup'], true)) {
        tg_send((string) $mcm['chat']['id'], "👋 Я передаю заявки с сайта " . SITE_HOST . ".\nЧтобы они приходили в эту группу, владелец отправляет сюда <code>/link PIN</code> — PIN он получит у меня в личке.", $call);
        return;
    }
    $m = $u['message'] ?? null;
    if (!$m || !isset($m['chat']['id'])) return;
    $chat = (string) $m['chat']['id'];
    $from = (string) ($m['from']['id'] ?? '');
    $type = $m['chat']['type'] ?? '';
    $text = trim((string) ($m['text'] ?? ''));
    if (isset($m['migrate_to_chat_id']) && $chat === (string) (tg_state()['lead_chat'] ?? '')) {  // группа → супергруппа
        tg_link_group((string) $m['migrate_to_chat_id'], (string) (tg_state()['lead_title'] ?? ''));
        return;
    }
    if ($type === 'private') {
        if ($from !== $owner) {
            tg_send($chat, 'Это служебный бот сайта ' . SITE_HOST . '. Оставить заявку: ' . SITE_URL . '/', $call);
            return;
        }
        $kb = tg_owner_keyboard();
        if ($text === TG_BTN_PIN || preg_match('#^/(pin|link)\b#', $text)) { tg_issue_pin($call); return; }
        if ($text === TG_BTN_UNLINK || preg_match('#^/unlink\b#', $text)) {
            $had = tg_group() !== '';
            tg_unlink();
            tg_send($chat, $had ? '✅ Группа отвязана: заявки приходят только сюда, в личку.' : 'Группа и так не привязана — заявки приходят сюда.', $call, $kb);
            return;
        }
        $s = tg_state();
        $intro = preg_match('#^/start\b#', $text) ? "👋 Я присылаю заявки с сайта " . SITE_HOST . " — вам сюда и, если привяжете, в группу.\n\n" : '';
        tg_send($chat, $intro . "Заявки сейчас приходят: сюда, в личку" . (!empty($s['lead_chat']) ? " и в группу «" . htmlspecialchars((string) $s['lead_title']) . "»" : '')
            . ".\n\nКнопки внизу: «" . TG_BTN_PIN . "» — привязать группу, «" . TG_BTN_UNLINK . "» — отвязать.", $call, $kb);
        return;
    }
    if (in_array($type, ['group', 'supergroup'], true)
        && preg_match('#^(?:/(?:link|pin)(?:@\w+)?\s+)?(\d{6})$#', $text, $mm)) {
        if (tg_check_pin($mm[1])) {
            $title = (string) ($m['chat']['title'] ?? 'группа');
            tg_link_group($chat, $title);
            tg_send($chat, '✅ Готово: заявки с сайта ' . SITE_HOST . ' теперь приходят и в эту группу.', $call);
            tg_send($owner, "✅ Группа «" . htmlspecialchars($title) . "» привязана — заявки идут и сюда, и туда.", $call, tg_owner_keyboard());
        } else {
            tg_send($chat, '❌ PIN неверный или устарел. Новый PIN владелец получит в личке у бота: /pin', $call);
        }
    }
}
