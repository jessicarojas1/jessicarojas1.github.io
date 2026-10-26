<?php

declare(strict_types=1);

namespace App\Identity\Local;

/**
 * Password rules aligned with OWASP ASVS 4 (V2.1) / NIST SP 800-63B: length over
 * composition, long passphrases allowed, no character-class rules, and no passwords that
 * contain the username or are well-known weak choices. (A breached-password corpus check is
 * an open item: see OPEN_ITEMS.md.)
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    private const COMMON = [
        'password1234', 'password12345', 'password123456', '123456789012', 'qwertyuiop12', 'iloveyou1234',
        'administrator', 'welcome12345', 'changeme1234', 'letmein12345', 'passw0rd1234', 'p@ssw0rd1234',
    ];

    /**
     * @return string|null a user-facing reason, or null when acceptable
     */
    public static function rejectionReason(string $password, ?string $username): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return \sprintf('Use at least %d characters. A passphrase of several words works well.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            return \sprintf('Use at most %d characters.', self::MAX_LENGTH);
        }
        $lower = mb_strtolower($password);
        if (null !== $username && '' !== $username && str_contains($lower, mb_strtolower($username))) {
            return 'The password must not contain your username.';
        }
        if (\in_array($lower, self::COMMON, true) || 1 === preg_match('/^(.)\1+$/u', $password)) {
            return 'That password is too easy to guess.';
        }

        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, \defined('PASSWORD_ARGON2ID') ? \PASSWORD_ARGON2ID : \PASSWORD_BCRYPT);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, \defined('PASSWORD_ARGON2ID') ? \PASSWORD_ARGON2ID : \PASSWORD_BCRYPT);
    }

    /**
     * A random temporary password (user must change it at next sign-in).
     */
    public static function temporary(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 20; ++$i) {
            $out .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return implode('-', str_split($out, 5));
    }
}
