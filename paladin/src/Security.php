<?php
class Security {

    /**
     * Return the real client IP, trusting X-Real-IP set by our nginx proxy.
     * Falls back to REMOTE_ADDR (e.g. in CLI/test contexts).
     */
    public static function clientIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $realIp     = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        // Only trust X-Real-IP when the immediate connection comes from a known proxy
        // (TRUSTED_PROXY_IPS defaults to localhost; set in env to match your nginx IP)
        $trusted = array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXY_IPS'] ?? '127.0.0.1')));
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP) && in_array($remoteAddr, $trusted, true)) {
            return $realIp;
        }
        return $remoteAddr;
    }
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_time']  = time();
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(string $token): bool {
        $cfg = require __DIR__ . '/../config/app.php';
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_time'])) return false;
        if (time() - $_SESSION['csrf_time'] > $cfg['csrf_lifetime']) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_time']);
            return false;
        }
        if (!hash_equals($_SESSION['csrf_token'], $token)) return false;
        // Rotate token after successful validation to prevent replay attacks
        unset($_SESSION['csrf_token'], $_SESSION['csrf_time']);
        return true;
    }

    public static function csrfField(): string {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    public static function h(mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function sanitizeInput(string $input): string {
        return trim(strip_tags(str_replace("\0", '', $input)));
    }

    /**
     * Sanitize rich HTML content (policy bodies, etc.) by stripping dangerous
     * tags and event-handler attributes while preserving safe formatting.
     * Uses DOMDocument to handle malformed HTML safely.
     */
    public static function sanitizeHtml(string $html): string {
        if (trim($html) === '') return '';

        // Dangerous tags to remove entirely (including children). svg/math/
        // foreignObject are blocked: they enable javascript: via xlink:href and
        // <set>/<animate> on href, and arbitrary HTML in foreignObject. Inline
        // SVG is never needed in wiki content (images are <img>).
        $blockedTags = ['script','style','iframe','object','embed','applet',
                        'form','input','button','select','textarea','link','meta','base',
                        'svg','math','foreignobject'];

        // Dangerous attribute prefixes
        $blockedAttrPrefixes = ['on']; // onclick, onload, onerror, etc.
        $blockedAttrs = ['href', 'src', 'action', 'formaction', 'data', 'srcdoc', 'style'];

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath  = new \DOMXPath($dom);
        $remove = [];

        // Queue blocked tag nodes for removal
        foreach ($blockedTags as $tag) {
            foreach ($xpath->query('//' . $tag) as $node) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Strip dangerous attributes from all remaining elements
        foreach ($xpath->query('//*') as $node) {
            if (!($node instanceof \DOMElement)) continue;
            $attrsToRemove = [];
            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->nodeName);
                // Remove event handlers (onclick, onload, …)
                foreach ($blockedAttrPrefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $attrsToRemove[] = $attr->nodeName;
                        continue 2;
                    }
                }
                // Allowlist URI schemes on any URL-bearing attribute — including
                // namespaced *:href (e.g. xlink:href) and SVG-animation targets
                // (set/animate to|values|from|by) used to smuggle javascript:.
                $isUrlAttr = in_array($name, $blockedAttrs, true)
                          || str_ends_with($name, ':href')
                          || in_array($name, ['to', 'values', 'from', 'by'], true);
                if ($isUrlAttr) {
                    $val = trim($attr->nodeValue);
                    // Defense-in-depth: reject any value that resolves to a
                    // javascript: (or vbscript:/data:) scheme regardless of case,
                    // embedded whitespace/control chars, or HTML-entity encoding.
                    // DOMDocument decodes entities into nodeValue already; we also
                    // strip whitespace/control chars before matching the scheme so
                    // "java\tscript:" / "  javascript:" cannot smuggle a payload.
                    $collapsed = strtolower(preg_replace('/[\s\x00-\x20]+/', '', $val) ?? '');
                    if ($collapsed !== '' && preg_match('/^(javascript|vbscript|data):/', $collapsed)) {
                        $attrsToRemove[] = $attr->nodeName;
                    } elseif ($val !== '' && !str_starts_with($val, '/') && !str_starts_with($val, '#') && !str_starts_with($val, '?')) {
                        $scheme = strtolower(explode(':', $val)[0] ?? '');
                        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
                            $attrsToRemove[] = $attr->nodeName;
                        }
                    }
                }
            }
            foreach ($attrsToRemove as $a) {
                $node->removeAttribute($a);
            }
        }

        // Extract the inner HTML of our wrapper div
        $wrapper = $dom->getElementById('') ?? $dom->getElementsByTagName('div')->item(0);
        if (!$wrapper) return strip_tags($html);

        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }
        return $inner;
    }

    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
    }

    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    public static function validatePassword(string $password): array {
        $errors = [];
        $cfg = require __DIR__ . '/../config/app.php';
        $p = $cfg['password'];

        if (strlen($password) < $p['min_length'])
            $errors[] = "Password must be at least {$p['min_length']} characters.";
        if ($p['require_upper'] && !preg_match('/[A-Z]/', $password))
            $errors[] = "Password must contain an uppercase letter.";
        if ($p['require_number'] && !preg_match('/[0-9]/', $password))
            $errors[] = "Password must contain a number.";
        if ($p['require_special'] && !preg_match('/[^a-zA-Z0-9]/', $password))
            $errors[] = "Password must contain a special character.";

        return $errors;
    }

    public static function validatePasswordPolicy(string $password): array {
        $errors = [];
        // Load policy from settings (with defaults if not set)
        static $policy = null;
        if ($policy === null) {
            $defaults = [
                'password_min_length'        => 12,
                'password_require_uppercase' => '1',
                'password_require_numbers'   => '1',
                'password_require_special'   => '1',
            ];
            $policy = $defaults;
            try {
                $rows = Database::fetchAll(
                    "SELECT key, value FROM settings WHERE key IN ('password_min_length','password_require_uppercase','password_require_numbers','password_require_special')"
                );
                foreach ($rows as $r) {
                    $policy[$r['key']] = $r['value'];
                }
            } catch (Throwable) {}
        }
        $minLen = (int)($policy['password_min_length'] ?? 12);
        if (strlen($password) < $minLen) {
            $errors[] = "Password must be at least {$minLen} characters.";
        }
        if (($policy['password_require_uppercase'] ?? '1') === '1' && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (($policy['password_require_numbers'] ?? '1') === '1' && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if (($policy['password_require_special'] ?? '1') === '1' && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }
        return $errors;
    }

    /**
     * Encrypt a sensitive setting value (AES-256-GCM via sodium).
     * Returns 'enc:' + base64(nonce + ciphertext) so we can distinguish
     * encrypted values from legacy plaintext during migration.
     */
    public static function encryptSetting(string $value): string {
        if ($value === '') return '';
        $key   = self::_settingsKey();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ct    = sodium_crypto_aead_aes256gcm_encrypt($value, '', $nonce, $key);
        return 'enc:' . base64_encode($nonce . $ct);
    }

    /**
     * Decrypt a setting encrypted with encryptSetting().
     * If the value is not prefixed with 'enc:' (legacy plaintext), returns it as-is.
     */
    public static function decryptSetting(string $value): string {
        if ($value === '' || !str_starts_with($value, 'enc:')) return $value;
        $key  = self::_settingsKey();
        $blob = base64_decode(substr($value, 4), true);
        if ($blob === false) return '';
        $npub = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;
        if (strlen($blob) <= $npub) return '';
        $nonce = substr($blob, 0, $npub);
        $ct    = substr($blob, $npub);
        $pt    = @sodium_crypto_aead_aes256gcm_decrypt($ct, '', $nonce, $key);
        return $pt === false ? '' : $pt;
    }

    private static function _settingsKey(): string {
        // Derive a 32-byte key from JWT_SECRET using SHA-256 with a domain separator
        $secret = $_ENV['JWT_SECRET'] ?? '';
        return hash('sha256', 'paladin_settings_v1:' . $secret, true);
    }

    public static function generateApiKey(): array {
        $key = 'paladin_' . bin2hex(random_bytes(32));
        $prefix = substr($key, 0, 12);
        $hash = hash_hmac('sha256', $key, $_ENV['JWT_SECRET'] ?? '');
        return ['key' => $key, 'prefix' => $prefix, 'hash' => $hash];
    }

    public static function validateApiKey(string $key): bool {
        // Try HMAC-SHA256 first (new keys), fall back to plain SHA-256 (legacy keys)
        $hmacHash  = hash_hmac('sha256', $key, $_ENV['JWT_SECRET'] ?? '');
        $legacyHash = hash('sha256', $key);
        $row = Database::fetchOne(
            "SELECT id, key_hash FROM api_keys WHERE key_hash IN (?,?) AND is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())",
            [$hmacHash, $legacyHash]
        );
        if ($row) {
            // Silently upgrade legacy SHA-256 keys to HMAC on first use
            if ($row['key_hash'] === $legacyHash && $hmacHash !== $legacyHash) {
                Database::query("UPDATE api_keys SET key_hash = ?, last_used = NOW() WHERE key_hash = ?", [$hmacHash, $legacyHash]);
            } else {
                Database::query("UPDATE api_keys SET last_used = NOW() WHERE key_hash = ?", [$hmacHash]);
            }
            return true;
        }
        return false;
    }

    /** Mint a Personal Access Token (per-user API credential). */
    public static function generatePersonalToken(): array {
        $key = 'paladin_pat_' . bin2hex(random_bytes(32));
        $prefix = substr($key, 0, 16);
        $hash = hash_hmac('sha256', $key, $_ENV['JWT_SECRET'] ?? '');
        return ['key' => $key, 'prefix' => $prefix, 'hash' => $hash];
    }

    /**
     * Validate a Personal Access Token and return its owning user row (with the
     * token's scopes), or null. Stamps last_used on success.
     */
    public static function validatePersonalToken(string $key): ?array {
        if (!str_starts_with($key, 'paladin_pat_')) return null;
        $hash = hash_hmac('sha256', $key, $_ENV['JWT_SECRET'] ?? '');
        $row = Database::fetchOne(
            "SELECT t.id AS token_id, u.id, u.id AS user_id, t.scopes, u.name, u.email, u.role, u.is_active
             FROM personal_access_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.is_active = TRUE
               AND (t.expires_at IS NULL OR t.expires_at > NOW())",
            [$hash]
        );
        if (!$row || empty($row['is_active'])) return null;
        Database::query("UPDATE personal_access_tokens SET last_used = NOW() WHERE id = ?", [$row['token_id']]);
        return $row;
    }

    public static function checkRateLimit(string $identifier): bool {
        $cfg = require __DIR__ . '/../config/app.php';
        $r = $cfg['rate_limit'];

        $row = Database::fetchOne(
            "SELECT attempts, window_start, blocked_until FROM rate_limits WHERE key = ?",
            [$identifier]
        );

        if ($row) {
            if ($row['blocked_until'] && strtotime($row['blocked_until']) > time()) return false;

            $windowStart = strtotime($row['window_start']);
            if (time() - $windowStart > $r['window_seconds']) {
                Database::query("UPDATE rate_limits SET attempts = 1, window_start = NOW(), blocked_until = NULL WHERE key = ?", [$identifier]);
                return true;
            }

            if ($row['attempts'] >= $r['login_attempts']) {
                $blockedUntil = date('Y-m-d H:i:s', time() + $r['lockout_seconds']);
                Database::query("UPDATE rate_limits SET blocked_until = ? WHERE key = ?", [$blockedUntil, $identifier]);
                return false;
            }

            Database::query("UPDATE rate_limits SET attempts = attempts + 1 WHERE key = ?", [$identifier]);
            return true;
        }

        Database::query("INSERT INTO rate_limits (key, attempts, window_start) VALUES (?, 1, NOW()) ON CONFLICT (key) DO UPDATE SET attempts = rate_limits.attempts + 1", [$identifier]);
        return true;
    }

    public static function resetRateLimit(string $identifier): void {
        Database::query("DELETE FROM rate_limits WHERE key = ?", [$identifier]);
    }

    /**
     * SSRF guard for outbound server-side requests (webhooks, URL-based embeds
     * or imports). Requires an http(s) URL and resolves the host, ensuring
     * EVERY resolved address is a public IP — rejecting loopback, link-local
     * (including the cloud metadata endpoint 169.254.169.254), private and
     * reserved ranges for both IPv4 and IPv6. If any resolution is unsafe the
     * whole URL is rejected (defends against attacker hosts that resolve to
     * both a public and a private address).
     *
     * Returns the public IP to pin the connection to (use CURLOPT_RESOLVE to
     * defeat DNS-rebinding TOCTOU), or null when the URL must not be requested.
     */
    public static function safeOutboundIp(string $url): ?string {
        $parts = parse_url(trim($url));
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) { return null; }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) { return null; }

        $host = $parts['host'];
        $ips  = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = @gethostbynamel($host);
            if (is_array($a)) { $ips = array_merge($ips, $a); }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) { if (!empty($rec['ipv6'])) { $ips[] = $rec['ipv6']; } }
            }
        }
        if (!$ips) { return null; }

        $pin = null;
        foreach ($ips as $ip) {
            // NO_PRIV_RANGE + NO_RES_RANGE => only globally-routable public IPs pass.
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
            if ($pin === null) { $pin = $ip; }
        }
        return $pin;
    }

    private static string $_nonce = '';

    /** Per-request CSP nonce — generated once, reused across all script tags */
    public static function nonce(): string {
        if (self::$_nonce === '') {
            self::$_nonce = base64_encode(random_bytes(18));
        }
        return self::$_nonce;
    }

    public static function setSecurityHeaders(): void {
        if (headers_sent()) return;

        // Nonce-based CSP without 'unsafe-inline'. All event handlers migrated to
        // data-* attributes via app.js delegation. Every <script> must carry the nonce.
        $n = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$n}' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net",
            // https: permits an externally-hosted branding logo set via URL
            // (Settings → Branding); data:/blob: cover uploads and inline images.
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), interest-cohort=()');
        if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || !empty($_SERVER['HTTPS'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }
}
