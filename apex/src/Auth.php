<?php
/**
 * APEX - JWT issue/verify + auth middleware.
 *
 * - HS256 JWT signed with $JWT_SECRET (env)
 * - 8h expiry
 * - Accepts the JWT from the Authorization: Bearer header *or* a cookie
 *   named "apex_token". The cookie is set HttpOnly + SameSite=Lax.
 */

declare(strict_types=1);

namespace Apex;

use RuntimeException;

final class Auth
{
    private const COOKIE_NAME    = 'apex_token';
    private const JWT_TTL_SECS   = 8 * 60 * 60;
    private const ROLE_RANK      = ['viewer' => 1, 'member' => 2, 'admin' => 3];

    /** Issue a signed JWT for the user payload. */
    public static function issueJWT(array $user): string
    {
        $now = time();
        $payload = [
            'sub'         => $user['id'],
            'username'    => $user['username'] ?? null,
            'displayName' => $user['display_name'] ?? null,
            'role'        => $user['role']     ?? 'viewer',
            'clearance'   => $user['clearance'] ?? null,
            'org'         => $user['org']      ?? null,
            // jti: unique token id so the token can be revoked server-side
            // (logout / logout-everywhere) via the revoked_tokens denylist.
            'jti'         => bin2hex(random_bytes(16)),
            'iat'         => $now,
            'exp'         => $now + self::JWT_TTL_SECS,
        ];

        return self::encode($payload);
    }

    /** Decode & verify; returns the payload or null. */
    public static function verifyJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expected = self::sign("$headerB64.$payloadB64");
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::b64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        // Server-side revocation: a token whose jti is on the denylist is
        // rejected even though its signature is valid and it has not expired.
        if (!empty($payload['jti']) && self::isRevoked((string)$payload['jti'])) {
            return null;
        }
        return $payload;
    }

    /**
     * Read the JWT from request, verify, and return the payload.
     * On failure: respond 401 and exit.
     */
    public static function requireAuth(): array
    {
        $token = self::readToken();
        if ($token === null) {
            Response::unauthorized();
        }
        $payload = self::verifyJWT($token);
        if ($payload === null) {
            Response::unauthorized('Invalid or expired token');
        }
        return $payload;
    }

    /** Optional auth - returns null on no/invalid token, does NOT exit. */
    public static function optionalAuth(): ?array
    {
        $token = self::readToken();
        if ($token === null) {
            return null;
        }
        return self::verifyJWT($token);
    }

    /**
     * Enforce role hierarchy. Pass 'member' to require member or admin.
     */
    public static function requireRole(string $minRole): array
    {
        $user = self::requireAuth();
        $userRank = self::ROLE_RANK[$user['role'] ?? 'viewer'] ?? 0;
        $minRank  = self::ROLE_RANK[$minRole] ?? 99;
        if ($userRank < $minRank) {
            Response::forbidden("Requires role: $minRole");
        }
        return $user;
    }

    /** Set the auth cookie on the response. */
    public static function setCookie(string $token): void
    {
        $secure = (getenv('APP_ENV') ?: 'development') === 'production';
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::JWT_TTL_SECS,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // ── Server-side token revocation (denylist keyed on jti) ─────────────

    /** Revoke a token by its jti so it is rejected before its natural exp. */
    public static function revokeJti(string $jti, int $exp, ?string $userId = null): void
    {
        if ($jti === '') {
            return;
        }
        try {
            Database::execute(
                'INSERT INTO revoked_tokens (jti, user_id, expires_at)
                      VALUES (:jti, :uid, to_timestamp(:exp))
                 ON CONFLICT (jti) DO NOTHING',
                [':jti' => $jti, ':uid' => $userId, ':exp' => $exp]
            );
            // Opportunistic cleanup so the denylist stays small.
            Database::execute('DELETE FROM revoked_tokens WHERE expires_at < NOW()');
        } catch (\Throwable $e) {
            error_log('[apex-auth] revokeJti failed: ' . $e->getMessage());
        }
    }

    private static function isRevoked(string $jti): bool
    {
        try {
            $row = Database::fetchOne(
                'SELECT 1 AS x FROM revoked_tokens WHERE jti = :jti AND expires_at > NOW()',
                [':jti' => $jti]
            );
            return $row !== null;
        } catch (\Throwable $e) {
            // Fail open on denylist lookup errors (e.g. table missing on a
            // partial deploy) rather than locking every user out; log loudly.
            error_log('[apex-auth] isRevoked lookup failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Auth-event audit sink + login throttling ─────────────────────────

    /**
     * Persist an auth event (login_success | login_failed | logout |
     * pin_change | locked_out) for audit and brute-force throttling.
     * `identity` is the submitted userId/username (may be unknown); `userId`
     * is the resolved account id when known.
     */
    public static function recordAuthEvent(string $event, ?string $identity, ?string $userId = null): void
    {
        try {
            Database::execute(
                'INSERT INTO auth_events (id, identity, user_id, event, ip, user_agent)
                      VALUES (:id, :ident, :uid, :ev, :ip, :ua)',
                [
                    ':id'    => Database::newId('ae'),
                    ':ident' => $identity !== null ? substr($identity, 0, 255) : null,
                    ':uid'   => $userId,
                    ':ev'    => $event,
                    ':ip'    => self::clientIp(),
                    ':ua'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[apex-auth] recordAuthEvent failed: ' . $e->getMessage());
        }
    }

    /**
     * True when the identity or client IP has exceeded the failed-login
     * threshold inside the rolling window. Tunable via
     * APEX_LOGIN_MAX_ATTEMPTS (default 5) and APEX_LOGIN_WINDOW_MIN (default 15).
     */
    public static function loginBlocked(string $identity, ?string $ip = null): bool
    {
        $max    = (int)(getenv('APEX_LOGIN_MAX_ATTEMPTS') ?: 5);
        $window = (int)(getenv('APEX_LOGIN_WINDOW_MIN') ?: 15);
        if ($max <= 0) {
            return false; // throttling disabled
        }
        $ip = $ip ?? self::clientIp();
        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS n FROM auth_events
                  WHERE event = 'login_failed'
                    AND created_at > NOW() - (:mins || ' minutes')::interval
                    AND (identity = :ident OR ip = :ip)",
                [':mins' => (string)$window, ':ident' => substr($identity, 0, 255), ':ip' => $ip]
            );
            return $row !== null && (int)$row['n'] >= $max;
        } catch (\Throwable $e) {
            error_log('[apex-auth] loginBlocked check failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Best-effort client IP for audit/throttle (trusts the front proxy XFF). */
    public static function clientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '') {
                return substr($first, 0, 64);
            }
        }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    /** Read JWT from Authorization header or cookie. */
    private static function readToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        // Normalize header casing
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower($k)] = $v;
        }
        $auth = $normalized['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            return $_COOKIE[self::COOKIE_NAME];
        }
        return null;
    }

    private static function secret(): string
    {
        $s  = getenv('JWT_SECRET') ?: '';
        $env = getenv('APP_ENV') ?: 'development';

        // Fail closed in production: never sign/verify tokens with a missing or
        // weak (guessable) secret. A predictable signing key lets anyone forge
        // an admin JWT, so refuse to start rather than silently fall back.
        if ($env === 'production') {
            if (strlen($s) < 32) {
                throw new RuntimeException(
                    'JWT_SECRET is missing or too short (need >= 32 chars) in production.'
                );
            }
            return $s;
        }

        // Non-production only: allow a predictable fallback so local dev runs
        // without configuring an env var. NEVER used when APP_ENV=production.
        if ($s === '') {
            $s = 'apex-dev-secret-please-override';
        }
        return $s;
    }

    private static function encode(array $payload): string
    {
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h = self::b64UrlEncode(json_encode($header,  JSON_UNESCAPED_SLASHES));
        $p = self::b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::sign("$h.$p");
        return "$h.$p.$sig";
    }

    private static function sign(string $data): string
    {
        $raw = hash_hmac('sha256', $data, self::secret(), true);
        return self::b64UrlEncode($raw);
    }

    private static function b64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $s): string
    {
        $pad = 4 - (strlen($s) % 4);
        if ($pad !== 4) {
            $s .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
