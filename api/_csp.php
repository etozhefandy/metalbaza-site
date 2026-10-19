<?php
// Отчёты браузеров о нарушениях Content-Security-Policy. Пока политика в режиме «только отчёт» (ничего не блокирует):
// собираем, что реально грузят страницы, и только потом включаем её всерьёз. Сводка — в пульте admin.alfimov.kz.
// Хранение короткое и с потолком: <папка данных>/csp/ГГГГ-ММ-ДД.json — {ключ: [директива, источник, страница, счётчик]}.
declare(strict_types=1);

const CSP_KEYS_PER_DAY = 200;
const CSP_KEEP_DAYS = 30;

function csp_dir(): string
{
    return (getenv('ALFIMOV_DATA') ?: dirname(__DIR__, 2) . '/' . (defined('SITE_DATA') ? SITE_DATA : 'alfimov-data')) . '/csp';
}

/** Источник без лишнего: «inline», «eval» или схема+хост (пути и параметры могут содержать личные данные). */
function csp_source(string $u): string
{
    $u = trim($u);
    if ($u === '' || in_array($u, ['inline', 'eval', 'self', 'data', 'blob', 'wasm-eval', 'trusted-types-policy'], true)) return $u ?: '—';
    $p = parse_url($u);
    if (!$p || empty($p['scheme'])) return mb_substr($u, 0, 40);
    return $p['scheme'] . (isset($p['host']) ? '://' . $p['host'] : ':');
}

/** Разобрать отчёт (старый формат report-uri и новый Reporting API) → [[директива, источник, страница]]. */
function csp_parse(string $raw): array
{
    $j = json_decode($raw, true);
    if (!is_array($j)) return [];
    $items = isset($j['csp-report']) ? [$j['csp-report']] : array_map(fn($r) => (array) ($r['body'] ?? []), array_filter($j, 'is_array'));
    $out = [];
    foreach ($items as $r) {
        $dir = (string) ($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? '');
        $dir = strtok($dir, ' ') ?: '';
        if (!preg_match('/^[a-z-]{3,40}$/', $dir)) continue;
        $doc = parse_url((string) ($r['document-uri'] ?? $r['documentURL'] ?? ''), PHP_URL_PATH) ?: '/';
        $out[] = [$dir, csp_source((string) ($r['blocked-uri'] ?? $r['blockedURL'] ?? '')), mb_substr($doc, 0, 120)];
    }
    return array_slice($out, 0, 20);
}

function csp_store(string $raw, string $dir, ?int $now = null): void
{
    $now ??= time();
    $rows = csp_parse($raw);
    if (!$rows) return;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return;
    $file = $dir . '/' . date('Y-m-d', $now) . '.json';
    $fh = fopen("$dir/.lock", 'c');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $all = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    foreach ($rows as [$d, $src, $doc]) {
        $k = md5("$d|$src|$doc");
        if (isset($all[$k])) $all[$k][3]++;
        elseif (count($all) < CSP_KEYS_PER_DAY) $all[$k] = [$d, $src, $doc, 1];
    }
    file_put_contents($file, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod($file, 0600);
    foreach (glob("$dir/*.json") ?: [] as $f) if (filemtime($f) < $now - CSP_KEEP_DAYS * 86400) @unlink($f);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Сводка за $days дней: [[директива, источник, страница, всего]] — частые сверху. */
function csp_summary(string $dir, int $days = 7, ?int $now = null): array
{
    $now ??= time();
    $sum = [];
    for ($i = 0; $i < $days; $i++) {
        $f = $dir . '/' . date('Y-m-d', $now - $i * 86400) . '.json';
        foreach (is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [] as $k => $r) {
            if (!isset($sum[$k])) $sum[$k] = $r;
            else $sum[$k][3] += $r[3];
        }
    }
    usort($sum, fn($a, $b) => $b[3] <=> $a[3]);
    return array_values($sum);
}
