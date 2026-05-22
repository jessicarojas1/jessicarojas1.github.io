<?php
class JWT {
    public static function encode(array $payload, string $secret): string {
        $header  = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64url(json_encode($payload));
        $sig     = self::base64url(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
        return "{$header}.{$payload}.{$sig}";
    }

    public static function decode(string $token, string $secret): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;
        $expected = self::base64url(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        if (!hash_equals($expected, $signature)) return null;

        $data = json_decode(self::base64urlDecode($payload), true);
        if (!$data) return null;
        if (!isset($data['exp'])) return null;
        if ($data['exp'] < time()) return null;

        return $data;
    }

    public static function issue(int $userId, string $role, int $ttl = 3600): string {
        $cfg = require __DIR__ . '/../config/app.php';
        return self::encode([
            'sub'  => $userId,
            'role' => $role,
            'iat'  => time(),
            'exp'  => time() + $ttl,
        ], $cfg['jwt_secret']);
    }

    public static function verify(string $token): ?array {
        $cfg = require __DIR__ . '/../config/app.php';
        return self::decode($token, $cfg['jwt_secret']);
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
