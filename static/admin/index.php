<?php
// Админка блога: /admin/ — вход, первая настройка, само приложение (_blog/admin.tpl).
declare(strict_types=1);
require dirname(__DIR__) . '/_blog/auth.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $o = parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $origin = isset($o['host']) ? $o['host'] . (isset($o['port']) ? ':' . $o['port'] : '') : '';
    if ($origin !== '' && $origin !== ($_SERVER['HTTP_HOST'] ?? '')) {
        http_response_code(403);
        exit;
    }
    $error = isset($_POST['code'])
        ? try_setup((string) $_POST['code'], (string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''))
        : try_login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($error === '') {
        header('Location: /admin/', true, 303);
        exit;
    }
}

if (current_session() && is_configured()) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(dirname(__DIR__) . '/_blog/admin.tpl');
    exit;
}

$setup = !is_configured();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Вход — админка</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: #f5f6f7; color: #0c1011;
  font: 16px/1.5 "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
form { width: 100%; max-width: 380px; background: #fff; border: 1px solid #e3e5e8; border-radius: 10px; padding: 28px 24px; display: grid; gap: 14px; }
h1 { font-size: 20px; margin: 0; }
p { margin: 0; color: #6b7178; font-size: 14px; }
label { display: grid; gap: 6px; font-size: 14px; font-weight: 500; color: #3d434a; }
input { width: 100%; padding: 12px 14px; border: 1px solid #cfd3d8; border-radius: 6px; font: inherit; font-size: 16px; }
input:focus { outline: 2px solid #1769ff; outline-offset: -1px; border-color: #1769ff; }
button { padding: 13px; border: 0; border-radius: 6px; background: #1769ff; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.err { color: #c62828; font-size: 14px; }
</style>
</head>
<body>
<form method="post" autocomplete="on">
<?php if ($setup): ?>
  <h1>Первая настройка админки</h1>
  <p>Введите код настройки, который вам прислали, и придумайте логин и пароль. Код нужен один раз.</p>
  <label>Код настройки<input name="code" required autocomplete="off" autocapitalize="off" spellcheck="false"></label>
  <label>Логин<input name="login" required autocomplete="username" autocapitalize="off" value="<?= $h($_POST['login'] ?? '') ?>"></label>
  <label>Пароль (от 10 символов)<input name="password" type="password" required minlength="10" autocomplete="new-password"></label>
  <label>Пароль ещё раз<input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>
  <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif ?>
  <button type="submit">Сохранить и войти</button>
<?php else: ?>
  <h1>Админка сайта</h1>
  <label>Логин<input name="login" required autocomplete="username" autocapitalize="off" value="<?= $h($_POST['login'] ?? '') ?>"></label>
  <label>Пароль<input name="password" type="password" required autocomplete="current-password"></label>
  <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif ?>
  <button type="submit">Войти</button>
<?php endif ?>
</form>
</body>
</html>
