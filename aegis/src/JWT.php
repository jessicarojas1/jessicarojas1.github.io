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

        // Validate algorithm before verifying signature (defence against alg:none attacks)
        $headerData = json_decode(self::base64urlDecode($header), true);
        if (($headerData['alg'] ?? '') !== 'HS256') return null;

        $expected = self::base64url(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
        if (!hash_equals($expected, $signature)) return null;

        $data = json_decode(self::base64urlDecode($payload), true);
        if (!$data) return null;
        if (!isset($data['exp'])) return null;
        if ($data['exp'] < time()) return null;
        // Reject tokens with iat in the future (60s clock-skew tolerance)
        if (isset($data['iat']) && $data['iat'] > time() + 60) return null;

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

    /**
     * Verify an RS256-signed JWT (e.g., OIDC ID token) against a JWKS endpoint.
     * Returns the decoded payload array on success, null on failure.
     */
    public static function verifyRS256(string $token, string $jwksUri, string $audience, string $issuer, string $nonce = ''): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header  = json_decode(self::base64urlDecode($headerB64), true);
        $payload = json_decode(self::base64urlDecode($payloadB64), true);

        if (!$header || !$payload) return null;
        if (($header['alg'] ?? '') !== 'RS256') return null;

        // Validate standard claims
        if (($payload['aud'] ?? '') !== $audience && !in_array($audience, (array)($payload['aud'] ?? []))) return null;
        if (($payload['iss'] ?? '') !== $issuer) return null;
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;
        if ($nonce && ($payload['nonce'] ?? '') !== $nonce) return null;

        // Fetch JWKS and find matching key
        $jwks = self::fetchJwks($jwksUri);
        if (!$jwks) return null;

        $kid = $header['kid'] ?? null;
        $key = null;
        foreach ($jwks['keys'] ?? [] as $k) {
            if ($kid === null || ($k['kid'] ?? '') === $kid) {
                if (($k['kty'] ?? '') === 'RSA' && ($k['use'] ?? 'sig') === 'sig') {
                    $key = $k;
                    break;
                }
            }
        }
        if (!$key) return null;

        // Build PEM from JWK n and e components
        $pem = self::jwkToPem($key);
        if (!$pem) return null;

        $signature = self::base64urlDecode($sigB64);
        $data      = "{$headerB64}.{$payloadB64}";

        $pubKey = openssl_pkey_get_public($pem);
        if (!$pubKey) return null;

        $result = openssl_verify($data, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        return $result === 1 ? $payload : null;
    }

    private static function fetchJwks(string $url): ?array {
        // SSRF prevention: only allow HTTPS to public hosts
        if (!preg_match('#^https://#i', $url)) return null;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        // Resolve once and validate — then pin the connection to that IP via CURLOPT_RESOLVE
        // to prevent DNS rebinding (TOCTOU between check and fetch)
        $resolved = gethostbyname($host);
        if (filter_var($resolved, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE        => ["{$host}:443:{$resolved}"],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!$body) return null;
        return json_decode($body, true) ?: null;
    }

    private static function jwkToPem(array $jwk): ?string {
        if (empty($jwk['n']) || empty($jwk['e'])) return null;

        $n = self::base64urlDecode($jwk['n']);
        $e = self::base64urlDecode($jwk['e']);

        // ASN.1 encode the RSA public key
        $modLen  = strlen($n);
        $expLen  = strlen($e);

        // Prepend 0x00 if high bit set (unsigned)
        if (ord($n[0]) > 0x7f) $n = "\x00" . $n;
        if (ord($e[0]) > 0x7f) $e = "\x00" . $e;

        $seq = self::asn1Sequence(
            self::asn1Sequence(
                "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" .  // OID rsaEncryption
                "\x05\x00"                                           // NULL
            ) .
            self::asn1BitString(
                self::asn1Sequence(
                    self::asn1Integer($n) .
                    self::asn1Integer($e)
                )
            )
        );

        return "-----BEGIN PUBLIC KEY-----\n" .
               chunk_split(base64_encode($seq), 64, "\n") .
               "-----END PUBLIC KEY-----\n";
    }

    private static function asn1Length(int $len): string {
        if ($len < 128) return chr($len);
        $bytes = '';
        $tmp = $len;
        while ($tmp > 0) { $bytes = chr($tmp & 0xff) . $bytes; $tmp >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function asn1Sequence(string $data): string {
        return "\x30" . self::asn1Length(strlen($data)) . $data;
    }

    private static function asn1Integer(string $data): string {
        return "\x02" . self::asn1Length(strlen($data)) . $data;
    }

    private static function asn1BitString(string $data): string {
        return "\x03" . self::asn1Length(strlen($data) + 1) . "\x00" . $data;
    }
}
