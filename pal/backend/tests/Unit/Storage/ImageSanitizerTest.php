<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Platform\Http\ApiProblem;
use App\Storage\ImageSanitizer;
use PHPUnit\Framework\TestCase;

final class ImageSanitizerTest extends TestCase
{
    /**
     * A tiny real JPEG whose frame header claims the given size (getimagesize reads only the
     * header), optionally with EXIF orientation 6.
     */
    private static function jpeg(int $width, int $height, bool $rotate): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        $sof = strpos($bytes, "\xFF\xC0");
        self::assertNotFalse($sof);
        $bytes = substr_replace($bytes, pack('nn', $height, $width), $sof + 5, 4);
        if ($rotate) {
            $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVV', 0x0112, 3, 1, 6).pack('V', 0);
            $payload = "Exif\x00\x00".$tiff;
            $bytes = substr($bytes, 0, 2)."\xFF\xE1".pack('n', \strlen($payload) + 2).$payload.substr($bytes, 2);
        }

        return $bytes;
    }

    private static function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pal-sanitize-test-');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function testRotatedImagesGetAMemoryAwarePixelLimitCheckedBeforeDecoding(): void
    {
        $previous = (string) ini_get('memory_limit');
        ini_set('memory_limit', '256M');
        $path = self::file(self::jpeg(5_000, 5_000, true));
        try {
            (new ImageSanitizer())->sanitize($path, 'image/jpeg');
            self::fail('A 25 MP image that must be rotated cannot fit in 256 MB and must be refused.');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->getStatusCode());
            self::assertSame('image_too_large', $problem->errorCode);
        } finally {
            @unlink($path);
            ini_set('memory_limit', $previous);
        }
    }

    public function testTheTypeMustMatchTheExtensionAndSvgIsNeverAnImage(): void
    {
        $sanitizer = new ImageSanitizer();
        self::assertNull($sanitizer->rejectionReason('image/png', 'diagram.png'));
        self::assertNotNull($sanitizer->rejectionReason('image/png', 'diagram.jpg'));
        self::assertNotNull($sanitizer->rejectionReason('image/svg+xml', 'diagram.svg'));
    }
}
