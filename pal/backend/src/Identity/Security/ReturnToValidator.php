<?php

declare(strict_types=1);

namespace App\Identity\Security;

/**
 * Accepts only same-origin relative paths as post-sign-in destinations, closing the
 * open-redirect class (//evil.example, /\evil.example, schemes, control characters).
 */
final class ReturnToValidator
{
    public static function sanitize(mixed $returnTo): string
    {
        if (!\is_string($returnTo) || '' === $returnTo || \strlen($returnTo) > 512) {
            return '/';
        }
        if (1 !== preg_match('#^/(?![/\\\\])[A-Za-z0-9\-._~/?=&%\#+:@,]*$#', $returnTo)) {
            return '/';
        }
        if (str_starts_with($returnTo, '/auth/')) {
            return '/';
        }

        return $returnTo;
    }
}
