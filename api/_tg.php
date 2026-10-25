<?php
// Telegram-бот заявок: общие функции для send.php (заявки), tg-hook.php (вебхук бота) и админки.
// Один и тот же файл в репозиториях alfimov.kz и metalbaza.kz.
//
// Один бот на несколько сайтов одного хостинга. Бот и его вебхук живут на «главном» сайте (alfimov.kz:
// tg-config.php рядом с его httpdocs). Остальные сайты (metalbaza.kz) своего tg-config.php не имеют и
// берут токен у главного — вебхук не трогают. Каждый сайт сам записывает себя в общий список
// (<папка хостинга>/tg-sites/<домен>.json), и бот в личке предлагает выбрать сайт, когда привязывает группу.
//
// Куда слать заявки: всегда владельцу в личку и, если к сайту привязана группа, ещё в неё. У каждого сайта
// своя группа: заявки на продвижение и на металл не смешиваются. Привязка группы — PIN: владелец получает его
// в личке, добавляет бота в группу и пишет там PIN. Привязка хранится в <папка данных сайта>/telegram.json.
declare(strict_types=1);
if (is_file(dirname(__DIR__) . '/_blog/site.php')) require_once dirname(__DIR__) . '/_blog/site.php';

const TG_PIN_TTL = 900;        // PIN живёт 15 минут
const TG_PIN_MAX_TRIES = 10;   // столько неверных PIN — и текущий сгорает

/** Этот сайт: домен, о чём он (для кнопок бота), папка данных. */
function tg_host(): string { return defined('SITE_HOST') ? SITE_HOST : 'alfimov.kz'; }
function tg_label(): string { return defined('SITE_TG_LABEL') ? SITE_TG_LABEL : 'продвижение'; }
function tg_data_dir(): string { return getenv('ALFIMOV_DATA') ?: dirname(__DIR__, 2) . '/' . (defined('SITE_DATA') ? SITE_DATA : 'alfimov-data'); }

/** Папка хостинга, где лежат общий tg-config.php бота и список сайтов. */
function tg_hub_dir(): string
{
    return getenv('ALFIMOV_TG_HUB') ?: dirname(__DIR__, 2 + (defined('SITE_TG_HUB_UP') ? (int) SITE_TG_HUB_UP : 0));
}

function tg_own_config(): string { return getenv('ALFIMOV_TG_CONFIG') ?: dirname(__DIR__, 2) . '/tg-config.php'; }

/** Главный сайт — тот, у кого свой tg-config.php: только он ставит вебхук и разбирает сообщения боту. */
function tg_is_hub(): bool { return !getenv('ALFIMOV_TG_SUB') && is_file(tg_own_config()); }

function tg_cfg(): array
{
    static $cache = [];
    foreach ([tg_own_config(), tg_hub_dir() . '/tg-config.php', __DIR__ . '/config.php'] as $f) {
        if (getenv('ALFIMOV_TG_SUB') && $f === tg_own_config()) continue;
        if (!is_file($f)) continue;
        $cache[$f] ??= require $f;
        if (is_array($cache[$f]) && !empty($cache[$f]['tg_token'])) return $cache[$f];
    }
    return [];
}

// ---------------------------------------------------------------- сайты одного бота

/** Привязки (группа, PIN) читаются из файла «текущего» сайта; tg_on_site() временно переключает сайт. */
function tg_state_file(?string $switch = null): string
{
    static $current = null;
    if ($switch !== null) $current = $switch;
    return $current ?? tg_data_dir() . '/telegram.json';
}

function tg_on_site(array $site, callable $fn)
{
    $prev = tg_state_file();
    tg_state_file($site['state']);
    try {
        return $fn();
    } finally {
        tg_state_file($prev);
    }
}

function tg_self(): array
{
    return ['host' => tg_host(), 'label' => tg_label(), 'state' => tg_data_dir() . '/telegram.json'];
}

/** Записать этот сайт в общий список (повторно — только если что-то поменялось). */
function tg_register(): void
{
    $dir = tg_hub_dir() . '/tg-sites';
    $file = "$dir/" . tg_host() . '.json';
    $me = tg_self();
    $old = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if ($old === $me) return;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    @file_put_contents($file, json_encode($me, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Все сайты бота: этот первым, потом остальные из общего списка. */
function tg_sites(): array
{
    tg_register();
    $out = [tg_host() => tg_self()];
    foreach (glob(tg_hub_dir() . '/tg-sites/*.json') ?: [] as $f) {
        $s = json_decode((string) @file_get_contents($f), true);
        if (is_array($s) && !empty($s['host']) && !empty($s['state']) && !isset($out[$s['host']])) {
            $out[$s['host']] = ['host' => (string) $s['host'], 'label' => (string) ($s['label'] ?? ''), 'state' => (string) $s['state']];
        }
    }
    return array_values($out);
}

function tg_site(string $host): ?array
{
    foreach (tg_sites() as $s) if ($s['host'] === $host) return $s;
    return null;
}

function tg_site_name(array $s): string { return $s['host'] . ($s['label'] !== '' ? ' (' . $s['label'] . ')' : ''); }

// ---------------------------------------------------------------- хранилище привязки

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

/** Привязанная к текущему сайту группа (или '' — заявки только в личку). */
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
const TG_BTN_PIN = '🔗 Привязать группу';
const TG_BTN_STATUS = '📍 Куда идут заявки';
const TG_BTN_UNLINK = '✖️ Отвязать группу';
const TG_BTN_HELP = '❓ Как это работает';

function tg_owner_keyboard(): array
{
    return ['keyboard' => [[['text' => TG_BTN_PIN]], [['text' => TG_BTN_STATUS], ['text' => TG_BTN_UNLINK]], [['text' => TG_BTN_HELP]]],
        'resize_keyboard' => true, 'is_persistent' => true];
}

/** Меню команд «/» в личке с ботом. */
function tg_set_commands(?callable $call = null): void
{
    tg_api('setMyCommands', ['scope' => json_encode(['type' => 'all_private_chats']), 'commands' => json_encode([
        ['command' => 'pin', 'description' => 'Привязать группу к сайту'],
        ['command' => 'status', 'description' => 'Куда сейчас идут заявки'],
        ['command' => 'unlink', 'description' => 'Отвязать группу'],
        ['command' => 'help', 'description' => 'Как это работает'],
    ], JSON_UNESCAPED_UNICODE)], $call);
}

/** Ссылка на отправленное сообщение (chat_id + message_id), если Telegram её вернул — чтобы потом дописать в него же. */
function tg_sent_ref(array $r): array
{
    $mid = $r['result']['message_id'] ?? null;
    $cid = $r['result']['chat']['id'] ?? null;
    return $mid !== null && $cid !== null ? [['chat' => (string) $cid, 'id' => (int) $mid]] : [];
}

/**
 * Заявка: всегда владельцу в личку и, если к сайту привязана группа, ещё в неё. Группа стала супергруппой —
 * переносим привязку и повторяем; бота убрали из группы — отвязываем и предупреждаем в личке.
 * → ссылки на отправленные сообщения (для tg_edit_lead); пусто — отправить не удалось никуда.
 */
function tg_send_lead(string $text, ?callable $call = null): array
{
    $r = tg_send(tg_owner(), $text, $call);
    $sent = !empty($r['ok']) ? tg_sent_ref($r) : [];
    $group = tg_group();
    if ($group === '' || $group === tg_owner()) return $sent;
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
        tg_send(tg_owner(), "⚠️ Не удалось отправить заявку с " . tg_host() . " в группу «" . htmlspecialchars($title) . "» — бота, видимо, убрали. "
            . "Группа отвязана, заявки с этого сайта приходят только сюда. Привязать снова — кнопка «" . TG_BTN_PIN . "».", $call);
    } else {
        $sent = array_merge($sent, tg_sent_ref($r));
    }
    return $sent;
}

/** Дописать в уже отправленные сообщения заявки (tg_send_lead) — например, статус CRM, без второго уведомления. */
function tg_edit_lead(array $sent, string $text, ?callable $call = null): void
{
    foreach ($sent as $m) {
        tg_api('editMessageText', ['chat_id' => $m['chat'], 'message_id' => $m['id'], 'text' => $text,
            'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'], $call);
    }
}

/** Новый одноразовый PIN для группы сайта (по умолчанию — этого) — владельцу в личку. */
function tg_issue_pin(?callable $call = null, ?array $site = null): bool
{
    $site ??= tg_self();
    $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    tg_on_site($site, function () use ($pin) {
        $s = tg_state();
        $s['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        $s['pin_expires'] = time() + TG_PIN_TTL;
        $s['pin_tries'] = 0;
        tg_save_state($s);
    });
    $r = tg_send(tg_owner(), "🔑 PIN для заявок с сайта <b>" . htmlspecialchars(tg_site_name($site)) . "</b>: <code>$pin</code>\n\n"
        . "1. Откройте группу, куда должны приходить заявки <b>с этого сайта</b>, и добавьте меня в неё.\n"
        . "2. Отправьте в группе: <code>/link $pin</code> (или просто <code>$pin</code>).\n\n"
        . "PIN одноразовый и действует 15 минут. Никому его не пересылайте.", $call, tg_owner_keyboard());
    return !empty($r['ok']);
}

/** Проверка PIN из группы для текущего сайта. → true, если PIN верный (и уже погашен). */
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

/** Вебхук бота: Telegram шлёт обновления на /api/tg-hook.php главного сайта. Другие сайты вебхук не трогают. */
function tg_ensure_webhook(?callable $call = null): bool
{
    if (!tg_is_hub()) return true;
    $s = tg_state();
    if (empty($s['hook_secret'])) {
        $s['hook_secret'] = bin2hex(random_bytes(24));
        tg_save_state($s);
    }
    $r = tg_api('setWebhook', ['url' => 'https://' . tg_host() . '/api/tg-hook.php', 'secret_token' => $s['hook_secret'],
        'allowed_updates' => json_encode(['message', 'my_chat_member', 'callback_query']), 'drop_pending_updates' => 'true'], $call);
    if (!empty($r['ok'])) tg_set_commands($call);
    return !empty($r['ok']);
}

// ---------------------------------------------------------------- тексты в личке

/** Куда сейчас идут заявки каждого сайта. */
function tg_status_text(): string
{
    $lines = ['📍 <b>Куда идут заявки</b>'];
    foreach (tg_sites() as $site) {
        $st = tg_on_site($site, fn() => tg_state());
        $where = !empty($st['lead_chat']) ? 'вам сюда и в группу «' . htmlspecialchars((string) ($st['lead_title'] ?? '')) . '»' : 'только вам сюда';
        $lines[] = '• <b>' . htmlspecialchars(tg_site_name($site)) . "</b> → $where";
    }
    return implode("\n", $lines);
}

function tg_help_text(): string
{
    $n = count(tg_sites());
    return "👋 Я присылаю заявки с ваших сайтов. Каждая заявка всегда приходит вам сюда, в личку.\n\n"
        . "<b>Чтобы заявки с сайта шли ещё и в группу</b> (менеджерам, поставщикам):\n"
        . "1. Нажмите «" . TG_BTN_PIN . "»" . ($n > 1 ? " и выберите сайт" : '') . ".\n"
        . "2. Добавьте меня в нужную группу.\n"
        . "3. Отправьте в группе PIN, который я пришлю.\n\n"
        . ($n > 1 ? "У каждого сайта своя группа: заявки с разных сайтов не смешиваются.\n" : '')
        . "«" . TG_BTN_STATUS . "» — что куда приходит, «" . TG_BTN_UNLINK . "» — перестать слать заявки в группу.";
}

/** Кнопки выбора сайта под сообщением: $action — pin или unlink. */
function tg_site_buttons(string $action, array $sites): array
{
    return ['inline_keyboard' => array_map(fn($s) => [['text' => tg_site_name($s), 'callback_data' => "grp:$action:" . $s['host']]], $sites)];
}

function tg_ask_site_for_pin(?callable $call = null): void
{
    $sites = tg_sites();
    if (count($sites) === 1) { tg_issue_pin($call, $sites[0]); return; }
    tg_send(tg_owner(), '🔗 Для какого сайта привязать группу? Заявки с этого сайта будут приходить в неё.', $call, tg_site_buttons('pin', $sites));
}

function tg_ask_site_for_unlink(?callable $call = null): void
{
    $linked = array_values(array_filter(tg_sites(), fn($s) => tg_on_site($s, fn() => tg_group()) !== ''));
    if (!$linked) { tg_send(tg_owner(), 'Ни одна группа не привязана — все заявки приходят только сюда.', $call, tg_owner_keyboard()); return; }
    if (count(tg_sites()) === 1) { tg_site_callback('grp:unlink:' . $linked[0]['host'], $call); return; }
    tg_send(tg_owner(), '✖️ От какого сайта отвязать группу? Заявки с него будут приходить только сюда.', $call, tg_site_buttons('unlink', $linked));
}

/** Кнопка выбора сайта (grp:pin:<домен> / grp:unlink:<домен>). */
function tg_site_callback(string $data, ?callable $call = null): void
{
    [, $action, $host] = array_pad(explode(':', $data, 3), 3, '');
    $site = tg_site($host);
    if (!$site) { tg_send(tg_owner(), 'Этого сайта больше нет в списке.', $call, tg_owner_keyboard()); return; }
    if ($action === 'pin') { tg_issue_pin($call, $site); return; }
    if ($action === 'unlink') {
        $had = tg_on_site($site, function () { $g = tg_group(); tg_unlink(); return $g; });
        tg_send(tg_owner(), $had !== '' ? '✅ Группа отвязана: заявки с ' . htmlspecialchars(tg_site_name($site)) . ' приходят только сюда.'
            : 'К этому сайту группа и так не привязана.', $call, tg_owner_keyboard());
    }
}

/**
 * Обработка одного обновления от Telegram. $on_button — другие кнопки под сообщениями бота (отчёт SEO):
 * fn(string $data): string — текст ответа владельцу.
 */
function tg_handle_update(array $u, ?callable $call = null, ?callable $on_button = null): void
{
    $owner = tg_owner();
    $cq = $u['callback_query'] ?? null;
    if ($cq) {
        $mine = (string) ($cq['from']['id'] ?? '') === $owner && $owner !== '';
        $data = (string) ($cq['data'] ?? '');
        tg_api('answerCallbackQuery', ['callback_query_id' => (string) ($cq['id'] ?? ''), 'text' => $mine ? 'Выполняю…' : 'Кнопка только для владельца'], $call);
        if (!$mine) return;
        if (str_starts_with($data, 'grp:')) { tg_site_callback($data, $call); return; }
        if ($on_button && ($reply = $on_button($data)) !== '') tg_send($owner, $reply, $call);
        return;
    }
    // бота добавили в группу — подсказать, как привязать
    $mcm = $u['my_chat_member'] ?? null;
    if ($mcm && in_array($mcm['new_chat_member']['status'] ?? '', ['member', 'administrator'], true)
        && in_array($mcm['chat']['type'] ?? '', ['group', 'supergroup'], true)) {
        tg_send((string) $mcm['chat']['id'], "👋 Я присылаю заявки с сайтов.\nЧтобы сюда приходили заявки нужного сайта, владелец отправит в эту группу PIN — его я пришлю ему в личку.", $call);
        return;
    }
    $m = $u['message'] ?? null;
    if (!$m || !isset($m['chat']['id'])) return;
    $chat = (string) $m['chat']['id'];
    $from = (string) ($m['from']['id'] ?? '');
    $type = $m['chat']['type'] ?? '';
    $text = trim((string) ($m['text'] ?? ''));
    if (isset($m['migrate_to_chat_id'])) {   // группа → супергруппа: переносим привязку сайта, чья это группа
        foreach (tg_sites() as $site) tg_on_site($site, function () use ($chat, $m) {
            if (tg_group() === $chat) tg_link_group((string) $m['migrate_to_chat_id'], (string) (tg_state()['lead_title'] ?? ''));
        });
        return;
    }
    if ($type === 'private') {
        if ($from !== $owner) {
            tg_send($chat, 'Это служебный бот заявок с сайтов ' . implode(', ', array_column(tg_sites(), 'host')) . '.', $call);
            return;
        }
        if ($text === TG_BTN_PIN || preg_match('#^/(pin|link)\b#', $text)) { tg_ask_site_for_pin($call); return; }
        if ($text === TG_BTN_UNLINK || preg_match('#^/unlink\b#', $text)) { tg_ask_site_for_unlink($call); return; }
        if ($text === TG_BTN_STATUS || preg_match('#^/status\b#', $text)) { tg_send($chat, tg_status_text(), $call, tg_owner_keyboard()); return; }
        tg_send($chat, tg_help_text() . "\n\n" . tg_status_text(), $call, tg_owner_keyboard());
        return;
    }
    if (in_array($type, ['group', 'supergroup'], true)
        && preg_match('#^(?:/(?:link|pin)(?:@\w+)?\s+)?(\d{6})$#', $text, $mm)) {
        $title = (string) ($m['chat']['title'] ?? 'группа');
        foreach (tg_sites() as $site) {
            if (!tg_on_site($site, fn() => tg_check_pin($mm[1]))) continue;
            tg_on_site($site, fn() => tg_link_group($chat, $title));
            $also = array_values(array_filter(tg_sites(), fn($s) => $s['host'] !== $site['host'] && tg_on_site($s, fn() => tg_group()) === $chat));
            $name = htmlspecialchars(tg_site_name($site));
            tg_send($chat, "✅ Готово: сюда будут приходить заявки с сайта $name.", $call);
            tg_send($owner, "✅ Группа «" . htmlspecialchars($title) . "» привязана к $name — заявки с него идут и сюда, и туда."
                . ($also ? "\n\n⚠️ Эта же группа получает заявки с " . htmlspecialchars(implode(', ', array_map('tg_site_name', $also)))
                    . ". Если их нужно разделить — «" . TG_BTN_UNLINK . "» и привяжите другую группу." : ''), $call, tg_owner_keyboard());
            return;
        }
        tg_send($chat, '❌ PIN неверный или устарел. Новый PIN владелец получит у меня в личке: кнопка «' . TG_BTN_PIN . '».', $call);
    }
}
