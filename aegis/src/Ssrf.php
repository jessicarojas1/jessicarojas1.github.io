<?php
declare(strict_types=1);

/**
 * Ssrf — centralized Server-Side Request Forgery guard.
 *
 * Single source of truth for validating outbound URLs before AEGIS makes a
 * server-side request (webhooks, OIDC discovery/token exchange, logo URLs,
 * AIAdvisor, URL-based imports). Replaces the duplicated, IPv4-only
 * gethostbyname() checks that previously lived in Webhook.php and SSO.php.
 *
 * Hardening over the legacy approach:
 *   - Resolves BOTH A (IPv4) and AAAA (IPv6) records and validates every result
 *     (the old gethostbyname() path silently ignored IPv6, allowing bypass).
 *   - Explicitly blocks private, loopback, link-local, cloud-metadata (CGNAT),
 *     unique-local IPv6, IPv4-mapped IPv6, and unspecified ranges in addition
 *     to PHP's FILTER_FLAG_NO_PRIV_RANGE / FILTER_FLAG_NO_RES_RANGE.
 *   - Returns the validated IP so callers can pin the connection with
 *     CURLOPT_RESOLVE, preventing DNS-rebinding (TOCTOU) between check and fetch.
 *
 * Usage:
 *   $r = Ssrf::inspect($url);
 *   if (!$r['ok']) { error_log('SSRF blocked: '.$r['reason']); return false; }
 *   // pin cURL:  CURLOPT_RESOLVE => Ssrf::curlResolve($url)
 */
final class Ssrf
{
    /** Schemes we will ever fetch server-side. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Inspect a URL for SSRF safety.
     *
     * @return array{ok:bool, reason:string, host:?string, ip:?string, port:?int, scheme:?string}
     */
    public static function inspect(string $url, bool $requireHttps = false): array
    {
        $fail = static fn(string $why): array =>
            ['ok' => false, 'reason' => $why, 'host' => null, 'ip' => null, 'port' => null, 'scheme' => null];

        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $fail('malformed url');
        }

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = $parts['host'] ?? '';

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return $fail("scheme not allowed: {$scheme}");
        }
        if ($requireHttps && $scheme !== 'https') {
            return $fail('https required');
        }
        if ($host === '') {
            return $fail('missing host');
        }
        // Reject credentials in URL (user:pass@host) — common SSRF/obfuscation vector.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail('credentials in url not allowed');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        // Strip IPv6 brackets if the host is a literal address.
        $hostLiteral = trim($host, '[]');

        // If the host is already an IP literal, validate it directly.
        if (filter_var($hostLiteral, FILTER_VALIDATE_IP) !== false) {
            if (self::ipIsBlocked($hostLiteral)) {
                return $fail("blocked ip literal: {$hostLiteral}");
            }
            return ['ok' => true, 'reason' => '', 'host' => $host, 'ip' => $hostLiteral, 'port' => $port, 'scheme' => $scheme];
        }

        // Resolve A + AAAA and validate every result.
        $ips = self::resolveAll($host);
        if (!$ips) {
            return $fail("dns resolution failed: {$host}");
        }
        foreach ($ips as $ip) {
            if (self::ipIsBlocked($ip)) {
                return $fail("resolves to blocked ip: {$ip}");
            }
        }

        // Return the first validated IP for connection pinning.
        return ['ok' => true, 'reason' => '', 'host' => $host, 'ip' => $ips[0], 'port' => $port, 'scheme' => $scheme];
    }

    /** Convenience boolean check. */
    public static function isSafeUrl(string $url, bool $requireHttps = false): bool
    {
        return self::inspect($url, $requireHttps)['ok'];
    }

    /**
     * Build a CURLOPT_RESOLVE entry that pins the host:port to the validated IP,
     * preventing DNS rebinding between validation and the actual request.
     *
     * @return string[]|null  e.g. ["api.example.com:443:93.184.216.34"] or null if unsafe
     */
    public static function curlResolve(string $url, bool $requireHttps = false): ?array
    {
        $r = self::inspect($url, $requireHttps);
        if (!$r['ok']) {
            return null;
        }
        $host = trim((string)$r['host'], '[]');
        return ["{$host}:{$r['port']}:{$r['ip']}"];
    }

    /** Resolve a hostname to all IPv4 and IPv6 addresses. */
    private static function resolveAll(string $host): array
    {
        $ips = [];

        // IPv4 (A records). gethostbynamel returns all A records or false.
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        // IPv6 (AAAA records) + any A records dns_get_record exposes.
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $rec) {
                if (!empty($rec['ip']))   $ips[] = $rec['ip'];   // A
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6']; // AAAA
            }
        }

        return array_values(array_unique(array_filter($ips)));
    }

    /**
     * Decide whether an IP address is in a range we refuse to connect to.
     * Covers private, loopback, link-local, metadata, CGNAT, ULA, and mapped ranges.
     */
    private static function ipIsBlocked(string $ip): bool
    {
        // Fast path: PHP's own private/reserved range filters.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // IPv4-mapped / IPv4-compatible IPv6 (e.g. ::ffff:169.254.169.254):
        // extract the embedded IPv4 and re-check it.
        if (str_contains($ip, '.') && str_contains($ip, ':')) {
            $tail = substr($ip, (int)strrpos($ip, ':') + 1);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::ipIsBlocked($tail);
            }
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true; // unparseable → block
        }

        // Explicit IPv4 ranges not always caught by the filter flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            foreach (self::IPV4_BLOCK_CIDRS as [$base, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($base) & $mask)) {
                    return true;
                }
            }
            return false;
        }

        // IPv6 explicit ranges (loopback ::1, unspecified ::, link-local fe80::/10,
        // unique-local fc00::/7). Compare on the packed 16-byte form.
        $first = ord($packed[0]);
        if ($ip === '::1' || $ip === '::') {
            return true;
        }
        if (($first & 0xFE) === 0xFC) {            // fc00::/7 unique-local
            return true;
        }
        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) { // fe80::/10 link-local
            return true;
        }

        return false;
    }

    /**
     * Narrow SSRF guard for operator-configured infrastructure endpoints
     * (SMTP relay host, S3-compatible endpoint). Unlike isSafeUrl(), this does
     * NOT block RFC-1918 private ranges — internal mail relays and self-hosted
     * MinIO legitimately live there. It blocks ONLY the ranges that are never a
     * valid such target yet are the actual SSRF escalation risk: loopback,
     * cloud-metadata / link-local, and the unspecified "this network" block.
     * Returns true when the host is, or resolves to, such an address.
     */
    public static function isDangerousInfraHost(string $host): bool
    {
        $host = trim($host, "[] \t");
        if ($host === '') {
            return true;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolveAll($host);
        if (!$ips) {
            return false; // unresolvable → let the connection attempt fail naturally
        }
        foreach ($ips as $ip) {
            if (self::isMetadataOrLoopback($ip)) {
                return true;
            }
        }
        return false;
    }

    private static function isMetadataOrLoopback(string $ip): bool
    {
        // IPv4-mapped IPv6 (e.g. ::ffff:169.254.169.254) → re-check embedded v4.
        if (str_contains($ip, '.') && str_contains($ip, ':')) {
            $tail = substr($ip, (int)strrpos($ip, ':') + 1);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return self::isMetadataOrLoopback($tail);
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            foreach (self::DANGEROUS_IPV4_CIDRS as [$base, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($base) & $mask)) {
                    return true;
                }
            }
            return false;
        }
        // IPv6: loopback, unspecified, link-local. NOT ULA (fc00::/7) — that is the
        // v6 analogue of a private range and may host a legitimate internal endpoint.
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        if ($ip === '::1' || $ip === '::') {
            return true;
        }
        $first = ord($packed[0]);
        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) { // fe80::/10 link-local
            return true;
        }
        return false;
    }

    /** Ranges that are NEVER a valid operator endpoint: [network, prefix-bits]. */
    private const DANGEROUS_IPV4_CIDRS = [
        ['0.0.0.0',     8],   // "this network" / unspecified
        ['127.0.0.0',   8],   // loopback
        ['169.254.0.0', 16],  // link-local + cloud metadata (169.254.169.254)
    ];

    /** IPv4 CIDR ranges to block: [network, prefix-bits]. */
    private const IPV4_BLOCK_CIDRS = [
        ['0.0.0.0',        8],   // "this network"
        ['10.0.0.0',       8],   // private
        ['100.64.0.0',     10],  // CGNAT
        ['127.0.0.0',      8],   // loopback
        ['169.254.0.0',    16],  // link-local + cloud metadata (169.254.169.254)
        ['172.16.0.0',     12],  // private
        ['192.0.0.0',      24],  // IETF protocol assignments
        ['192.168.0.0',    16],  // private
        ['198.18.0.0',     15],  // benchmarking
        ['224.0.0.0',      4],   // multicast
        ['240.0.0.0',      4],   // reserved
    ];
}
