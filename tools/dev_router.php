<?php
// Локальная замена правил static/.htaccess для встроенного сервера PHP:
//   ALFIMOV_DATA=/tmp/mb-data php -S 127.0.0.1:8092 -t dist tools/dev_router.php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];
$go = function ($file, $get) use ($root) { $_GET = $get + $_GET; chdir(dirname("$root/$file")); require "$root/$file"; return true; };
if (str_starts_with($uri, '/_blog/') || preg_match('#^/api/(config|_tg)\.php$#', $uri)) { http_response_code(404); return true; }
if ($uri === '/') return $go('_blog/router.php', ['route' => 'home']);
if ($uri === '/sitemap.xml') return $go('_blog/router.php', ['route' => 'sitemap']);
if ($uri === '/llms.txt') return $go('_blog/router.php', ['route' => 'llms']);
if ($uri === '/blog/') return $go('_blog/router.php', ['route' => 'index']);
if (preg_match('#^/blog/media/([A-Za-z0-9._-]+)$#', $uri, $m)) return $go('_blog/router.php', ['route' => 'media', 'f' => $m[1]]);
if (preg_match('#^/blog/([a-z0-9-]+)/$#', $uri, $m)) return $go('_blog/router.php', ['route' => 'article', 'slug' => $m[1]]);
if ($uri === '/admin/') return $go('admin/index.php', []);
if (preg_match('#^/admin/(api|preview)/(.*)$#', $uri, $m)) return $go('admin/api.php', ['r' => "$m[1]/$m[2]"]);
if (preg_match('#[^/]$#', $uri) && is_dir("$root$uri")) { header("Location: $uri/", true, 301); return true; }
if (!file_exists("$root$uri") && !is_file("$root$uri/index.html")) { http_response_code(404); readfile("$root/404.html"); return true; }
return false;
