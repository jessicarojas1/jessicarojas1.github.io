<?php
class Security {
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
        return trim(strip_tags($input));
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
        $blockedAttrPrefixes = ['on']; // onclick, onload, onerror, etc.
        $blockedAttrs = ['href', 'src', 'action', 'formaction', 'data', 'srcdoc'];

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
                // Remove javascript: hrefs and data: srcs
                if (in_array($name, $blockedAttrs, true)) {
                    $val = strtolower(trim($attr->nodeValue));
                    if (str_starts_with($val, 'javascript:') || str_starts_with($val, 'data:text')) {
                        $attrsToRemove[] = $attr->nodeName;
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
        return hash('sha256', 'aegis_settings_v1:' . $secret, true);
    }

    public static function generateApiKey(): array {
        $key = 'aegis_' . bin2hex(random_bytes(32));
        $prefix = substr($key, 0, 12);
        $hash = hash('sha256', $key);
        return ['key' => $key, 'prefix' => $prefix, 'hash' => $hash];
    }

    public static function validateApiKey(string $key): bool {
        $hash = hash('sha256', $key);
        $row = Database::fetchOne(
            "SELECT id FROM api_keys WHERE key_hash = ? AND is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())",
            [$hash]
        );
        if ($row) {
            Database::query("UPDATE api_keys SET last_used = NOW() WHERE key_hash = ?", [$hash]);
            return true;
        }
        return false;
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

        // Nonce-based CSP. 'unsafe-inline' is kept for inline event handlers (onclick etc.)
        // In CSP3 browsers the nonce overrides 'unsafe-inline' for <script> elements,
        // so injected <script> blocks are still blocked. Event handlers need unsafe-inline.
        $n = self::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'nonce-{$n}'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
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
