<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Local\LocalAccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared local-username rule used by account creation and pal:users:bootstrap-admin.
 * A username is not an email address: '@' and uppercase are rejected.
 */
final class UsernameRuleTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testAcceptedUsernamesHaveNoRejectionReason(string $username): void
    {
        self::assertNull(LocalAccountService::usernameRejectionReason($username));
    }

    #[DataProvider('rejected')]
    public function testRejectedUsernamesExplainWhy(string $username): void
    {
        self::assertNotNull(LocalAccountService::usernameRejectionReason($username));
    }

    /** @return iterable<array{string}> */
    public static function accepted(): iterable
    {
        yield ['jrojas'];
        yield ['jessica.rojas'];
        yield ['admin'];
        yield ['j_r-0'];
        yield ['JRojas'];                 // normalised to lowercase before the check
        yield ['  jrojas  '];             // trimmed before the check
        yield [str_repeat('a', 64)];      // max length
    }

    /** @return iterable<array{string}> */
    public static function rejected(): iterable
    {
        yield 'email address' => ['cuevasjessica40@yahoo.com'];
        yield 'too short' => ['ab'];
        yield 'leading dot' => ['.jrojas'];
        yield 'space inside' => ['j rojas'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
    }
}
