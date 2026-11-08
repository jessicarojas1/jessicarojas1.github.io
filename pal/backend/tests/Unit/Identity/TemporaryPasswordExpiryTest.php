<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Local\LocalAccountService;
use PHPUnit\Framework\TestCase;

final class TemporaryPasswordExpiryTest extends TestCase
{
    private const NOW = '2026-11-08 12:00:00';

    private function expired(bool $mustChange, string $changedAt, int $maxAgeHours): bool
    {
        return LocalAccountService::temporaryPasswordExpired(
            $mustChange,
            new \DateTimeImmutable($changedAt),
            new \DateTimeImmutable(self::NOW),
            $maxAgeHours,
        );
    }

    public function testDisabledWindowNeverExpires(): void
    {
        // 0 is the production default: no temporary password ever expires, however old.
        self::assertFalse($this->expired(true, '2020-01-01 00:00:00', 0));
        self::assertFalse($this->expired(true, '2020-01-01 00:00:00', -1));
    }

    public function testOnlyMustChangePasswordsExpire(): void
    {
        // A permanent password (must_change = false) is never subject to expiry.
        self::assertFalse($this->expired(false, '2020-01-01 00:00:00', 24));
    }

    public function testWithinTheWindowIsNotExpired(): void
    {
        self::assertFalse($this->expired(true, '2026-11-08 00:00:00', 24), '12h old, 24h window');
    }

    public function testPastTheWindowIsExpired(): void
    {
        self::assertTrue($this->expired(true, '2026-11-06 00:00:00', 24), '60h old, 24h window');
    }

    public function testBoundaryIsInclusiveOfTheWindow(): void
    {
        // Exactly at the window is still valid; strictly past it expires.
        self::assertFalse($this->expired(true, '2026-11-07 12:00:00', 24), 'exactly 24h old');
        self::assertTrue($this->expired(true, '2026-11-07 11:59:59', 24), 'one second past 24h');
    }
}
