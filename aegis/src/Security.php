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

        // Allow inline scripts (app uses inline handlers throughout); CDN for chart.js + icons
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net",
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
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || !empty($_SERVER['HTTPS'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }
}
