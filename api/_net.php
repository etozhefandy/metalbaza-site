<?php
// Исходящие запросы по адресам, которые вводят люди (ссылка-источник статьи, CRM, вебхук, домены под наблюдением):
// сервер не должен ходить во внутреннюю сеть хостинга (SSRF). Проверяем все адреса имени (A и AAAA) и соединяемся
// ровно с проверенным IP (CURLOPT_RESOLVE) — подменить DNS между проверкой и соединением не выйдет. Редиректы не
// следуем: кому нужно — обрабатывает вручную, проверяя каждый шаг через net_target().
//
// Пульт в одном процессе подключает и свою копию этого файла, и копию сайта (admin сайта выполняется внутри
// пульта — hub/pult.php: pult_site_admin). Это два разных файла на диске с одинаковыми функциями, поэтому всё
// объявляем только при первой загрузке — иначе на двух сайтах сразу admin.alfimov.kz падал «Cannot redeclare».
declare(strict_types=1);

if (!function_exists('net_ip_public')) {

/** Публичный ли адрес: не loopback, не частная сеть, не link-local, не служебные и не multicast (IPv4 и IPv6, в т. ч. ::ffff:a.b.c.d). */
function net_ip_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    $bin = inet_pton($ip);
    if ($bin === false) return false;
    if (strlen($bin) === 16) {
        $mapped = substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff";   // ::ffff:a.b.c.d
        $nat64 = substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8);   // 64:ff9b::a.b.c.d
        if ($mapped || $nat64) return net_ip_public(inet_ntop(substr($bin, 12)));
        foreach (['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '100::/64', '2001::/23'] as $cidr) {
            if (net_in_cidr($bin, $cidr)) return false;
        }
        return true;
    }
    foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
        if (net_in_cidr($bin, $cidr)) return false;
    }
    return true;
}

function net_in_cidr(string $bin, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $n = inet_pton($net);
    if ($n === false || strlen($n) !== strlen($bin)) return false;
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($bin, 0, $bytes) !== substr($n, 0, $bytes)) return false;
    if ($bits % 8 === 0) return true;
    $mask = chr((0xff << (8 - $bits % 8)) & 0xff);
    return (($bin[$bytes] & $mask) === ($n[$bytes] & $mask));
}

/** Все адреса имени (A и AAAA). В тестах подменяется $GLOBALS['NET_RESOLVE']. */
function net_resolve(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    if (isset($GLOBALS['NET_RESOLVE'])) return $GLOBALS['NET_RESOLVE']($host);
    $ips = [];
    foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
        if (!empty($r['ip'])) $ips[] = $r['ip'];
        if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) $ips = gethostbynamel($host) ?: [];
    return array_values(array_unique($ips));
}

/** Можно ли идти по адресу. → ['host', 'port', 'ip', 'scheme'] или null. Порты — только обычные 443/80 (у http). */
function net_target(string $url, bool $allowHttp = false): ?array
{
    $p = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return null;
    if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) return null;
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port !== ($scheme === 'https' ? 443 : 80)) return null;
    $host = strtolower(trim($p['host'], '[]'));
    $ips = net_resolve($host);
    if (!$ips) return null;
    foreach ($ips as $ip) if (!net_ip_public($ip)) return null;   // хоть один внутренний адрес — нельзя (публичный A + внутренний AAAA)
    return ['host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
}

/** Настроить curl: соединение только с проверенным IP (имя для TLS/SNI сохраняется), без редиректов, только http(s). */
function net_pin($ch, array $t): void
{
    $ip = str_contains($t['ip'], ':') ? '[' . $t['ip'] . ']' : $t['ip'];
    curl_setopt_array($ch, [CURLOPT_RESOLVE => ["{$t['host']}:{$t['port']}:$ip"], CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
}

}
