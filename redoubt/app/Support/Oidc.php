<?php

declare(strict_types=1);

namespace Redoubt\Support;

use RuntimeException;

/**
 * OpenID Connect client for Microsoft Entra ID (GCC High), authorization-code
 * flow with PKCE. Uses the GCC High endpoints (login.microsoftonline.us).
 *
 * SECURITY NOTE (Phase 1 hardening): this hand-rolled verifier performs issuer /
 * audience / expiry / nonce checks and RS256 signature validation against the
 * tenant JWKS. Before go-live it MUST be security-reviewed; consider replacing
 * the token-verification path with a vetted JOSE library. See OPEN_ITEMS.md.
 */
final class Oidc
{
    private static function base(): string
    {
        $tenant = Config::tenantId();
        if ($tenant === null) {
            throw new RuntimeException('ENTRA_TENANT_ID not configured.');
        }
        return Config::authorityHost() . '/' . $tenant;
    }

    /** Build the authorize URL. Caller stores $state/$nonce/$verifier in session. */
    public static function authorizeUrl(string $state, string $nonce, string $verifier): string
    {
        $challenge = self::b64url(hash('sha256', $verifier, true));
        $params = [
            'client_id'             => (string) Config::clientId(),
            'response_type'         => 'code',
            'redirect_uri'          => (string) Config::redirectUri(),
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email offline_access User.Read',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];
        return self::base() . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    /** Exchange an authorization code for tokens. @return array<string,mixed> */
    public static function exchangeCode(string $code, string $verifier): array
    {
        $resp = self::httpPost(self::base() . '/oauth2/v2.0/token', [
            'client_id'     => (string) Config::clientId(),
            'client_secret' => (string) Config::clientSecret(),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => (string) Config::redirectUri(),
            'code_verifier' => $verifier,
            'scope'         => 'openid profile email offline_access User.Read',
        ]);
        if (isset($resp['error'])) {
            throw new RuntimeException('Token exchange failed: ' . ($resp['error_description'] ?? $resp['error']));
        }
        return $resp;
    }

    /**
     * Validate an ID token and return its claims.
     * Verifies signature (RS256 via JWKS), issuer, audience, expiry, and nonce.
     * @return array<string,mixed>
     */
    public static function validateIdToken(string $idToken, string $expectedNonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed ID token.');
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64urlDecode($h64), true, 512, JSON_THROW_ON_ERROR);
        $claims = json_decode(self::b64urlDecode($p64), true, 512, JSON_THROW_ON_ERROR);
        $sig = self::b64urlDecode($s64);

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('Unexpected token alg.');
        }
        $pem = self::jwkToPem(self::findKey($header['kid'] ?? ''));
        $ok = openssl_verify("$h64.$p64", $sig, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('ID token signature invalid.');
        }

        $expectedIss = self::base() . '/v2.0';
        if (($claims['iss'] ?? '') !== $expectedIss) {
            throw new RuntimeException('Unexpected token issuer.');
        }
        if (($claims['aud'] ?? '') !== Config::clientId()) {
            throw new RuntimeException('Unexpected token audience.');
        }
        if ((int) ($claims['exp'] ?? 0) <= time()) {
            throw new RuntimeException('ID token expired.');
        }
        if (!isset($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new RuntimeException('ID token nonce mismatch.');
        }
        return $claims;
    }

    // --- JWKS ---------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function findKey(string $kid): array
    {
        $jwks = self::httpGetJson(self::base() . '/discovery/v2.0/keys');
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
        throw new RuntimeException('Signing key not found in JWKS.');
    }

    /** Convert an RSA JWK (n,e) to a PEM public key. */
    private static function jwkToPem(array $jwk): string
    {
        $n = self::b64urlDecode($jwk['n'] ?? '');
        $e = self::b64urlDecode($jwk['e'] ?? '');
        // DER-encode a minimal RSAPublicKey SubjectPublicKeyInfo.
        $modulus = self::derUint($n);
        $exponent = self::derUint($e);
        $rsaPub = self::derSeq($modulus . $exponent);
        $algId = self::derSeq(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00" // rsaEncryption OID + NULL
        );
        $bitString = "\x03" . self::derLen(strlen($rsaPub) + 1) . "\x00" . $rsaPub;
        $spki = self::derSeq($algId . $bitString);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    private static function derUint(string $bytes): string
    {
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes; // keep positive
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    private static function derSeq(string $contents): string
    {
        return "\x30" . self::derLen(strlen($contents)) . $contents;
    }

    private static function derLen(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $out = '';
        while ($len > 0) {
            $out = chr($len & 0xff) . $out;
            $len >>= 8;
        }
        return chr(0x80 | strlen($out)) . $out;
    }

    // --- HTTP helpers -------------------------------------------------------

    private static function httpPost(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('OIDC HTTP error: ' . $err);
        }
        curl_close($ch);
        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('OIDC JWKS error: ' . $err);
        }
        curl_close($ch);
        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }

    // --- base64url ----------------------------------------------------------

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        if ($out === false) {
            throw new RuntimeException('Invalid base64url.');
        }
        return $out;
    }
}
