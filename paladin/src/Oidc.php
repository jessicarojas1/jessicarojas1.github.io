<?php
declare(strict_types=1);

/**
 * Oidc — a minimal, dependency-free OpenID Connect Relying Party.
 *
 * Implements the Authorization Code flow with PKCE: discovery (or explicit
 * endpoints), an authorize redirect carrying state+nonce+code_challenge, code
 * exchange at the token endpoint, and full ID-token (JWT) verification against
 * the provider's JWKS — RS256 signature, issuer, audience, expiry and nonce.
 * RSA public keys are reconstructed from JWK n/e into DER/PEM and verified with
 * openssl. Claims map to a local account (JIT provisioning optional).
 */
final class Oidc {

    public static function config(): array {
        $rows = [];
        try {
            foreach (Database::fetchAll("SELECT key, value FROM settings WHERE key LIKE 'oidc_%'") as $r) {
                $rows[$r['key']] = $r['value'];
            }
        } catch (\Throwable) {}
        return [
            'enabled'        => ($rows['oidc_enabled'] ?? '0') === '1',
            'issuer'         => rtrim($rows['oidc_issuer'] ?? '', '/'),
            'client_id'      => $rows['oidc_client_id'] ?? '',
            'client_secret'  => $rows['oidc_client_secret'] ?? '',
            'scopes'         => $rows['oidc_scopes'] ?? 'openid email profile',
            'authorize_url'  => $rows['oidc_authorize_url'] ?? '',
            'token_url'      => $rows['oidc_token_url'] ?? '',
            'jwks_url'       => $rows['oidc_jwks_url'] ?? '',
            'attr_email'     => $rows['oidc_attr_email'] ?? 'email',
            'attr_name'      => $rows['oidc_attr_name'] ?? 'name',
            'auto_provision' => ($rows['oidc_auto_provision'] ?? '0') === '1',
            'default_role'   => $rows['oidc_default_role'] ?? 'viewer',
        ];
    }

    public static function isEnabled(): bool {
        $c = self::config();
        return $c['enabled'] && $c['client_id'] !== '' && ($c['issuer'] !== '' || $c['authorize_url'] !== '');
    }

    private static function baseUrl(): string {
        $app = rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        if ($app !== '') return $app;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function redirectUri(): string { return self::baseUrl() . '/oidc/callback'; }

    /** Resolve endpoints from explicit config, else the issuer's discovery document. */
    public static function endpoints(): array {
        $c = self::config();
        if ($c['authorize_url'] !== '' && $c['token_url'] !== '' && $c['jwks_url'] !== '') {
            return ['authorization_endpoint' => $c['authorize_url'], 'token_endpoint' => $c['token_url'], 'jwks_uri' => $c['jwks_url']];
        }
        if ($c['issuer'] === '') { throw new \RuntimeException('OIDC issuer not configured'); }
        $doc = self::httpGetJson($c['issuer'] . '/.well-known/openid-configuration');
        return [
            'authorization_endpoint' => $doc['authorization_endpoint'] ?? $c['authorize_url'],
            'token_endpoint'         => $doc['token_endpoint'] ?? $c['token_url'],
            'jwks_uri'               => $doc['jwks_uri'] ?? $c['jwks_url'],
        ];
    }

    public static function authorizeUrl(string $state, string $nonce, string $codeChallenge): string {
        $c = self::config();
        $ep = self::endpoints();
        $params = http_build_query([
            'client_id'             => $c['client_id'],
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => $c['scopes'],
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        $sep = str_contains($ep['authorization_endpoint'], '?') ? '&' : '?';
        return $ep['authorization_endpoint'] . $sep . $params;
    }

    /** Exchange an authorization code for tokens (returns the decoded token response). */
    public static function exchangeCode(string $code, string $codeVerifier): array {
        $c = self::config();
        $ep = self::endpoints();
        $post = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'code_verifier' => $codeVerifier,
        ]);
        $ch = curl_init($ep['token_endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) { throw new \RuntimeException('Token endpoint error: ' . $err); }
        $data = json_decode((string)$body, true);
        if (!is_array($data) || $status >= 400) {
            throw new \RuntimeException('Token exchange failed (' . $status . '): ' . ($data['error'] ?? substr((string)$body, 0, 120)));
        }
        return $data;
    }

    public static function jwks(): array {
        $ep = self::endpoints();
        return self::httpGetJson($ep['jwks_uri']);
    }

    /**
     * Verify an ID token (JWT) and return its claims.
     * @throws RuntimeException on any validation failure.
     */
    public static function verifyIdToken(string $jwt, array $jwks, string $issuer, string $clientId, ?string $nonce): array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) { throw new \RuntimeException('Malformed ID token'); }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64urlDecode($h64), true);
        $claims = json_decode(self::b64urlDecode($p64), true);
        if (!is_array($header) || !is_array($claims)) { throw new \RuntimeException('Bad ID token JSON'); }
        $alg = $header['alg'] ?? '';
        if ($alg !== 'RS256') { throw new \RuntimeException('Unsupported ID token alg: ' . $alg); }

        $pem = self::pemForKid($jwks, $header['kid'] ?? null);
        $signed = $h64 . '.' . $p64;
        $sig = self::b64urlDecode($s64);
        if (openssl_verify($signed, $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('ID token signature invalid');
        }

        $now = time(); $skew = 120;
        if (($claims['iss'] ?? '') !== $issuer && rtrim((string)($claims['iss'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new \RuntimeException('ID token issuer mismatch');
        }
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud) ? in_array($clientId, $aud, true) : ($aud === $clientId);
        if (!$audOk) { throw new \RuntimeException('ID token audience mismatch'); }
        if (isset($claims['exp']) && $now - $skew >= (int)$claims['exp']) { throw new \RuntimeException('ID token expired'); }
        if (isset($claims['nbf']) && $now + $skew < (int)$claims['nbf']) { throw new \RuntimeException('ID token not yet valid'); }
        if ($nonce !== null && ($claims['nonce'] ?? null) !== $nonce) { throw new \RuntimeException('ID token nonce mismatch'); }

        return $claims;
    }

    private static function pemForKid(array $jwks, ?string $kid): string {
        $keys = $jwks['keys'] ?? [];
        $chosen = null;
        foreach ($keys as $k) {
            if (($k['kty'] ?? '') !== 'RSA') { continue; }
            if ($kid === null || ($k['kid'] ?? null) === $kid) { $chosen = $k; break; }
            if ($chosen === null) { $chosen = $k; }
        }
        if ($chosen === null) { throw new \RuntimeException('No matching JWKS key'); }
        return self::jwkToPem((string)$chosen['n'], (string)$chosen['e']);
    }

    /** Reconstruct an RSA public key PEM (SPKI) from JWK base64url modulus/exponent. */
    public static function jwkToPem(string $n64, string $e64): string {
        $modulus  = self::b64urlDecode($n64);
        $exponent = self::b64urlDecode($e64);
        $rsaPubKey = self::derSeq(self::derInt($modulus) . self::derInt($exponent));
        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algId = self::derSeq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
        $spki = self::derSeq($algId . self::derBitString($rsaPubKey));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $n): string {
        if ($n < 0x80) { return chr($n); }
        $b = '';
        while ($n > 0) { $b = chr($n & 0xff) . $b; $n >>= 8; }
        return chr(0x80 | strlen($b)) . $b;
    }
    private static function derInt(string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') { $bytes = "\x00"; }
        if (ord($bytes[0]) & 0x80) { $bytes = "\x00" . $bytes; } // keep it positive
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }
    private static function derSeq(string $c): string { return "\x30" . self::derLen(strlen($c)) . $c; }
    private static function derBitString(string $c): string { return "\x03" . self::derLen(strlen($c) + 1) . "\x00" . $c; }

    public static function b64urlDecode(string $s): string {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) { $s .= str_repeat('=', 4 - $pad); }
        return (string)base64_decode($s, true);
    }
    public static function b64urlEncode(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function httpGetJson(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) { throw new \RuntimeException('HTTP error for ' . $url . ': ' . $err); }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) { throw new \RuntimeException('Invalid JSON from ' . $url); }
        return $data;
    }
}
