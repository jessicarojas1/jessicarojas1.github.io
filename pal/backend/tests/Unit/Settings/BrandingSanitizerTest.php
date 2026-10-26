<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Platform\Http\ApiProblem;
use App\Settings\Branding;
use App\Settings\BrandingSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrandingSanitizerTest extends TestCase
{
    public function testValidInputIsNormalised(): void
    {
        $b = BrandingSanitizer::sanitize(['displayName' => '  Acme PAL ', 'logoUrl' => 'https://cdn.example.test/logo.png', 'accentColor' => '#AABBCC']);

        self::assertSame('Acme PAL', $b->displayName);
        self::assertSame('#aabbcc', $b->accentColor);
    }

    public function testEmptyValuesResetToDefaults(): void
    {
        $effective = BrandingSanitizer::sanitize(['displayName' => '', 'logoUrl' => null, 'accentColor' => ' '])->effective();

        self::assertSame(Branding::DEFAULT_DISPLAY_NAME, $effective['displayName']);
        self::assertSame(Branding::DEFAULT_ACCENT_COLOR, $effective['accentColor']);
        self::assertNull($effective['logoUrl']);
    }

    public function testRasterDataUrlsAreAccepted(): void
    {
        $png = 'data:image/png;base64,'.base64_encode('fake-png-bytes');

        self::assertSame($png, BrandingSanitizer::sanitize(['logoUrl' => $png])->logoUrl);
    }

    #[DataProvider('badLogos')]
    public function testUnsafeLogosAreRejected(string $logo): void
    {
        $this->expectException(ApiProblem::class);
        BrandingSanitizer::sanitize(['logoUrl' => $logo]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLogos(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'svg data url' => ['data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>')];
        yield 'html data url' => ['data:text/html;base64,PGgxPg=='];
        yield 'credentials in url' => ['https://user:pass@example.test/logo.png'];
        yield 'ftp' => ['ftp://example.test/logo.png'];
        yield 'plain http' => ['http://cdn.example.test/logo.png'];
        yield 'oversized data url' => ['data:image/png;base64,'.str_repeat('A', 400_000)];
    }

    #[DataProvider('badColours')]
    public function testInvalidColoursAreRejected(string $colour): void
    {
        $this->expectException(ApiProblem::class);
        BrandingSanitizer::sanitize(['accentColor' => $colour]);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function badColours(): iterable
    {
        yield ['red'];
        yield ['#abc'];
        yield ['#12345g'];
        yield ['#123456; background:url(x)'];
    }

    public function testOverlongNameIsRejected(): void
    {
        $this->expectException(ApiProblem::class);
        BrandingSanitizer::sanitize(['displayName' => str_repeat('x', 81)]);
    }
}
