<?php
declare(strict_types=1);

class TOTP {

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string {
        $bytes  = random_bytes(20); // 160 bits — satisfies NIST SP 800-63B minimum
        $secret = '';
        $buf    = 0;
        $bits   = 0;
        foreach (str_split($bytes) as $byte) {
            $buf  = ($buf << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits  -= 5;
                $secret .= self::BASE32[($buf >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $secret .= self::BASE32[($buf << (5 - $bits)) & 0x1F];
        }
        return $secret;
    }

    public static function getCode(string $secret, int $offset = 0): string {
        $counter = (int)floor(time() / 30) + $offset;
        return self::hotp(self::base32Decode($secret), $counter);
    }

    public static function verify(string $secret, string $userCode): bool {
        $userCode = preg_replace('/\s/', '', $userCode);
        if (!preg_match('/^\d{6}$/', $userCode)) return false;
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::getCode($secret, $offset), $userCode)) return true;
        }
        return false;
    }

    public static function getUri(string $secret, string $email, string $issuer = 'AEGIS GRC'): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer)
        );
    }

    private static function hotp(string $key, int $counter): string {
        $msg  = pack('J', $counter); // 8-byte big-endian
        $hash = hash_hmac('sha1', $msg, $key, true);
        $off  = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$off])     & 0x7F) << 24) |
            ((ord($hash[$off + 1]) & 0xFF) << 16) |
            ((ord($hash[$off + 2]) & 0xFF) <<  8) |
            ( ord($hash[$off + 3]) & 0xFF)
        ) % 1_000_000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string {
        $secret = strtoupper(preg_replace('/\s/', '', $secret));
        $buf    = 0;
        $bits   = 0;
        $output = '';
        foreach (str_split($secret) as $char) {
            $val = strpos(self::BASE32, $char);
            if ($val === false) continue;
            $buf   = ($buf << 5) | $val;
            $bits += 5;
            if ($bits >= 8) {
                $bits  -= 8;
                $output .= chr(($buf >> $bits) & 0xFF);
            }
        }
        return $output;
    }
}
