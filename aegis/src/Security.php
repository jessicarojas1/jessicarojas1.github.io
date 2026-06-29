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

        // Dangerous tags to remove entirely (including children)
        $blockedTags = ['script','style','iframe','object','embed','applet',
                        'form','input','button','select','textarea','link','meta','base'];

        // Dangerous attribute prefixes
        // 'on*' = event handlers; 'data-*' = app.js delegation hooks (data-click,
        // data-submit, data-show-modal, …). Neither belongs in user-submitted
        // rich text, and allowing data-* would let stored content trigger UI
        // actions when an admin/approver views it.
        $blockedAttrPrefixes = ['on', 'data-'];
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
                // Allowlist URI schemes — reject anything other than http, https, mailto, relative
                if (in_array($name, $blockedAttrs, true)) {
                    $val = trim($attr->nodeValue);
                    if ($val !== '' && !str_starts_with($val, '/') && !str_starts_with($val, '#') && !str_starts_with($val, '?')) {
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
        $blob = base64_decode(substr($value, 4), true);
        if ($blob === false) return '';
        $npub = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;
        if (strlen($blob) <= $npub) return '';
        $nonce = substr($blob, 0, $npub);
        $ct    = substr($blob, $npub);
        // Try the primary key first; fall back to the legacy JWT_SECRET-derived key
        // so values written before APP_ENCRYPTION_KEY was configured still decrypt.
        $pt = @sodium_crypto_aead_aes256gcm_decrypt($ct, '', $nonce, self::_settingsKey());
        if ($pt === false) {
            $pt = @sodium_crypto_aead_aes256gcm_decrypt($ct, '', $nonce, self::_legacySettingsKey());
        }
        return $pt === false ? '' : $pt;
    }

    /**
     * Primary settings-encryption key. Uses a dedicated APP_ENCRYPTION_KEY when set
     * (key separation per NIST SC-12 — so rotating JWT_SECRET doesn't make stored
     * secrets undecryptable), otherwise derives from JWT_SECRET for backward
     * compatibility with existing deployments.
     */
    private static function _settingsKey(): string {
        $dedicated = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        if ($dedicated !== '') {
            return hash('sha256', 'aegis_settings_v2:' . $dedicated, true);
        }
        return self::_legacySettingsKey();
    }

    /** Legacy JWT_SECRET-derived key — decrypt-only fallback for old ciphertexts. */
    private static function _legacySettingsKey(): string {
        return hash('sha256', 'aegis_settings_v1:' . ($_ENV['JWT_SECRET'] ?? ''), true);
    }

    /**
     * Dedicated audit-log HMAC key. Set AUDIT_HMAC_KEY — ideally in a secret store
     * the database role cannot read — for true integrity separation. Falls back to a
     * JWT_SECRET-derived key so that, even unconfigured, the audit chain is keyed
     * (HMAC) rather than a forgeable unkeyed hash. Returns raw 32-byte key material.
     */
    public static function auditKey(): string {
        $dedicated = $_ENV['AUDIT_HMAC_KEY'] ?? '';
        if ($dedicated !== '') {
            return hash('sha256', 'aegis_audit_v2:' . $dedicated, true);
        }
        return hash('sha256', 'aegis_audit_v1:' . ($_ENV['JWT_SECRET'] ?? ''), true);
    }

    public static function generateApiKey(): array {
        $key = 'aegis_' . bin2hex(random_bytes(32));
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

    public static function checkRateLimit(string $identifier): bool {
        $cfg = require __DIR__ . '/../config/app.php';
        $r = $cfg['rate_limit'];

        // Shared in-memory fast path (TD-7): when Redis is configured, use it as
        // the authoritative, cross-instance counter instead of the hot rate_limits
        // row. Mirrors the DB semantics below — allow `login_attempts` tries per
        // `window_seconds`, then lock out for `lockout_seconds`. Cache returns null
        // for any non-Redis backend (APCu is per-node, so not safe for shared rate
        // limiting), in which case we fall through to the authoritative DB store.
        $rlKey    = 'aegis:rl:' . $identifier;
        $blockKey = 'aegis:rl:block:' . $identifier;
        if (Cache::counterFlagExists($blockKey)) {
            return false; // currently locked out
        }
        $count = Cache::incrementCounter($rlKey, (int) $r['window_seconds']);
        if ($count !== null) {
            if ($count > (int) $r['login_attempts']) {
                Cache::setCounterFlag($blockKey, (int) $r['lockout_seconds']);
                return false;
            }
            return true;
        }
        // No shared in-memory backend → authoritative DB store (original path).

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
        // Clear both stores so a successful login lifts the limit regardless of
        // which backend recorded the attempts.
        Cache::deleteRaw('aegis:rl:' . $identifier, 'aegis:rl:block:' . $identifier);
        Database::query("DELETE FROM rate_limits WHERE key = ?", [$identifier]);
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
        // Fonts are self-hosted (no Google Fonts); icons, Chart.js, Bootstrap CSS
        // and all app JS/CSS are vendored locally under /public/vendor, so the CSP
        // needs NO external origin at all — every fetch directive is locked to
        // 'self'. This makes the app fully self-contained (air-gapped / IL5+ safe)
        // with no CDN/supply-chain exposure. 'unsafe-inline' remains on style-src
        // only (inline style attributes); scripts are strictly nonce-gated.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$n}'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            // https: permits an externally-hosted branding logo set via URL
            // (Settings → Branding); data:/blob: cover uploads and inline images.
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
        header('Content-Security-Policy: ' . $csp);
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        // X-XSS-Protection is deprecated; "1; mode=block" can itself introduce
        // vulnerabilities. Modern guidance (OWASP/CISA) is to disable it and rely
        // on the CSP above. See OWASP Secure Headers Project.
        header('X-XSS-Protection: 0');
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
