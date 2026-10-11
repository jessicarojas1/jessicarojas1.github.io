<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * TOTP (RFC 6238, built on HOTP from RFC 4226). Hand-rolled because this
 * app takes zero Composer dependencies, but — unlike the JWT/OIDC
 * verification flagged elsewhere in this codebase as needing a vetted
 * library before production use — TOTP is a small, fully-specified,
 * easily-tested algorithm: HMAC-SHA1 over a counter, truncated to N
 * decimal digits. `Totp::generateCode()`'s core HOTP computation is
 * verified in `tests/unit_test.php` directly against RFC 4226 Appendix D's
 * own published test vectors (secret "12345678901234567890", counters
 * 0–9), not just written and trusted — see that test group for the exact
 * expected values.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD_SECONDS = 30;
    public const SECRET_BYTES = 20; // 160 bits — RFC 4226's own recommended HMAC-SHA1 key size
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ]);
        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * Verify a 6-digit code against the current time step, allowing
     * $windowSteps of drift each direction (default 1 = ±30s, so a code
     * is accepted for up to ~90s around when it was generated — enough
     * for real clock drift and the time it takes a person to type it in,
     * without opening a wide replay/brute-force window).
     */
    public static function verify(string $secret, string $code, int $windowSteps = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $timeStep = intdiv(time(), self::PERIOD_SECONDS);
        for ($i = -$windowSteps; $i <= $windowSteps; $i++) {
            if (hash_equals(self::generateCode($secret, $timeStep + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** The current 6-digit code for a secret — exists for tests; real verification always goes through verify(). */
    public static function currentCode(string $secret): string
    {
        return self::generateCode($secret, intdiv(time(), self::PERIOD_SECONDS));
    }

    /** Pure HOTP (RFC 4226) over a raw (non-base32) key — exposed for direct RFC-vector testing. */
    public static function hotp(string $rawKey, int $counter): string
    {
        $counterBytes = pack('N2', 0, $counter); // 8-byte big-endian counter (pack('N2', hi32, lo32))
        $hash = hash_hmac('sha1', $counterBytes, $rawKey, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function generateCode(string $base32Secret, int $counter): string
    {
        return self::hotp(self::base32Decode($base32Secret), $counter);
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                break; // trailing partial byte is padding, not data
            }
            $out .= chr((int) bindec($byte));
        }
        return $out;
    }
}
