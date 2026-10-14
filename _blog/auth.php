<?php
// Вход в админку: логин + пароль (хэш в DATA_DIR/settings.json), сессия в cookie на 30 дней.
//
// Первая настройка: пока пароля нет, админка просит одноразовый код настройки (его хэш ниже,
// сам код передан владельцу отдельно). Без кода чужой человек не сможет «занять» пустую админку.
// Забыли пароль — удалите <папка данных>/settings.json в Файлах Plesk и пройдите настройку заново
// (ключи ИИ придётся вставить ещё раз; статьи не трогаются).
declare(strict_types=1);
require_once __DIR__ . '/ai.php';

const SETUP_CODE_SHA256 = SITE_SETUP_SHA256;   // хэш кода первой настройки — в настройках сайта (site.php)
const SESSION_COOKIE = SITE_COOKIE;
const SESSION_DAYS = 30;
const LOGIN_WINDOW = 900;      // 15 минут
const LOGIN_MAX_FAILS = 5;     // с одного IP за окно

function client_ip(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? '?'); }

function is_configured(): bool { return !empty(settings()['password_hash']); }

function with_lock(string $name, callable $fn)
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $f = fopen(DATA_DIR . "/.$name.lock", 'c');
    flock($f, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

function sessions_file(): string { return DATA_DIR . '/sessions.json'; }

function current_session(): bool
{
    $token = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    if (strlen($token) !== 64) return false;
    $all = read_json(sessions_file()) ?? [];
    $exp = $all[hash('sha256', $token)] ?? 0;
    return $exp > time();
}

function start_session(): void
{
    $token = bin2hex(random_bytes(32));
    with_lock('sessions', function () use ($token) {
        $all = array_filter(read_json(sessions_file()) ?? [], fn($exp) => $exp > time());
        $all[hash('sha256', $token)] = time() + SESSION_DAYS * 86400;
        write_json(sessions_file(), $all);
        @chmod(sessions_file(), 0600);
    });
    setcookie(SESSION_COOKIE, $token, ['expires' => time() + SESSION_DAYS * 86400, 'path' => '/admin/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}

function end_session(): void
{
    $token = (string) ($_COOKIE[SESSION_COOKIE] ?? '');
    with_lock('sessions', function () use ($token) {
        $all = read_json(sessions_file()) ?? [];
        unset($all[hash('sha256', $token)]);
        write_json(sessions_file(), $all);
    });
    setcookie(SESSION_COOKIE, '', ['expires' => 1, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}

/** Сбросить все входы (после смены пароля), кроме текущего — он переоткрывается заново. */
function drop_all_sessions(): void { write_json(sessions_file(), []); }

function fails_file(): string { return DATA_DIR . '/login-fails.json'; }

function login_blocked(): bool
{
    $fails = read_json(fails_file()) ?? [];
    $recent = array_filter($fails[client_ip()] ?? [], fn($t) => $t > time() - LOGIN_WINDOW);
    return count($recent) >= LOGIN_MAX_FAILS;
}

function note_login_fail(): void
{
    with_lock('fails', function () {
        $fails = read_json(fails_file()) ?? [];
        foreach ($fails as $ip => $ts) {
            $fails[$ip] = array_values(array_filter($ts, fn($t) => $t > time() - LOGIN_WINDOW));
            if (!$fails[$ip]) unset($fails[$ip]);
        }
        $fails[client_ip()][] = time();
        write_json(fails_file(), $fails);
    });
}

/** → текст ошибки или '' при успехе. */
function try_login(string $login, string $password): string
{
    if (login_blocked()) return 'Слишком много попыток. Подождите 15 минут.';
    $s = settings();
    $ok = hash_equals(mb_strtolower((string) ($s['login'] ?? '')), mb_strtolower(trim($login)))
        && password_verify($password, (string) ($s['password_hash'] ?? ''));
    if (!$ok) {
        note_login_fail();
        usleep(400000);
        return 'Неверный логин или пароль';
    }
    start_session();
    return '';
}

function password_problem(string $password): string
{
    if (mb_strlen($password) < 10) return 'Пароль — минимум 10 символов';
    return '';
}

/** Первая настройка: код + логин + пароль. Переносит стартовые статьи из сборки сайта. */
function try_setup(string $code, string $login, string $password, string $password2): string
{
    if (is_configured()) return 'Админка уже настроена';
    if (login_blocked()) return 'Слишком много попыток. Подождите 15 минут.';
    if (!hash_equals(SETUP_CODE_SHA256, hash('sha256', trim($code)))) {
        note_login_fail();
        return 'Неверный код настройки';
    }
    $login = trim($login);
    if (!preg_match('/^[\p{L}\p{N}._@-]{3,40}$/u', $login)) return 'Логин: 3–40 символов, буквы, цифры, точка, дефис';
    if ($p = password_problem($password)) return $p;
    if ($password !== $password2) return 'Пароли не совпадают';
    foreach (['articles', 'media', 'jobs'] as $d) {
        if (!is_dir(DATA_DIR . "/$d") && !mkdir(DATA_DIR . "/$d", 0750, true)) return 'Не удалось создать папку данных на хостинге';
    }
    ensure_articles();
    $s = settings();
    $s['login'] = $login;
    $s['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    save_settings($s);
    start_session();
    return '';
}
