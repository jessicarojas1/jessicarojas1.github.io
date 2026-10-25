<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Security\ReturnToValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnToValidatorTest extends TestCase
{
    #[DataProvider('safe')]
    public function testSafePathsAreKept(string $path): void
    {
        self::assertSame($path, ReturnToValidator::sanitize($path));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function safe(): iterable
    {
        yield ['/'];
        yield ['/library'];
        yield ['/documents/123?tab=history#rev-2'];
    }

    #[DataProvider('unsafe')]
    public function testUnsafeValuesFallBackToHome(mixed $value): void
    {
        self::assertSame('/', ReturnToValidator::sanitize($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsafe(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/path'];
        yield 'backslash trick' => ['/\\evil.example'];
        yield 'absolute url' => ['https://evil.example'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative without slash' => ['library'];
        yield 'header injection' => ["/ok\r\nLocation: https://evil.example"];
        yield 'auth loop' => ['/auth/login'];
        yield 'empty' => [''];
        yield 'not a string' => [['/']];
        yield 'too long' => ['/'.str_repeat('a', 600)];
    }
}
