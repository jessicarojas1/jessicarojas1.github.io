<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\User\PalUser;
use PHPUnit\Framework\TestCase;

/**
 * PalUser::isEqualTo decides whether a stored session is still valid after refreshUser reloads
 * the account. It must survive role changes but be revoked when the credential version
 * (password_changed_at) moves, so a password change or admin reset signs out other sessions.
 */
final class PalUserEqualityTest extends TestCase
{
    private function user(string $id, ?string $passwordChangedAt, ?string $username = 'ada'): PalUser
    {
        return new PalUser($id, null, null, 'Ada', null, null, [], $username, false, $passwordChangedAt);
    }

    public function testSameCredentialVersionIsEqual(): void
    {
        $session = $this->user('user-1', '2026-11-07 10:00:00+00');
        $refreshed = $this->user('user-1', '2026-11-07 10:00:00+00');

        self::assertTrue($session->isEqualTo($refreshed));
    }

    public function testMovedCredentialVersionIsNotEqual(): void
    {
        $session = $this->user('user-1', '2026-11-07 10:00:00+00');
        $afterChange = $this->user('user-1', '2026-11-07 11:30:00+00');

        self::assertFalse($session->isEqualTo($afterChange), 'a password change/reset must revoke other sessions');
    }

    public function testDifferentUserIsNotEqual(): void
    {
        self::assertFalse($this->user('user-1', 'v1')->isEqualTo($this->user('user-2', 'v1')));
    }

    public function testSsoUsersWithoutCredentialVersionStayEqual(): void
    {
        $session = $this->user('user-1', null, null);
        $refreshed = $this->user('user-1', null, null);

        self::assertTrue($session->isEqualTo($refreshed));
    }
}
