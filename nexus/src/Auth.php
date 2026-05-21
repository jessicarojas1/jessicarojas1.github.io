<?php
/**
 * NEXUS - JWT issue/verify + auth middleware.
 *
 * - HS256 JWT signed with $JWT_SECRET (env)
 * - 8h expiry
 * - Accepts the JWT from the Authorization: Bearer header *or* a cookie
 *   named "nexus_token". The cookie is set HttpOnly + SameSite=Lax.
 */

declare(strict_types=1);

namespace Nexus;

use RuntimeException;

final class Auth
{
    private const COOKIE_NAME    = 'nexus_token';
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
        $s = getenv('JWT_SECRET') ?: '';
        if ($s === '') {
            // Predictable dev fallback so the app still runs without env set.
            // Render's render.yaml sets a real secret in production.
            $s = 'nexus-dev-secret-please-override';
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
