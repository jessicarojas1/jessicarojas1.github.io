<?php

declare(strict_types=1);

namespace App\Storage;

use App\Platform\Http\ApiProblem;

/**
 * Re-encodes an uploaded image so what PAL stores is only pixels (owner decision #29):
 * EXIF/XMP/IPTC metadata (GPS location, device, author) is dropped, and a file merely
 * disguised as an image (a polyglot) cannot survive decoding and re-encoding. JPEG
 * orientation is applied first so photos are not stored sideways. Animated GIFs become their
 * first frame. Dimensions are checked from the header before any pixels are decoded, so a
 * small file that expands to gigabytes ("decompression bomb") is refused cheaply.
 */
final class ImageSanitizer
{
    /** detected MIME => allowed extensions */
    public const ALLOWED = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];
    private const MAX_SIDE = 12_000;
    private const MAX_PIXELS = 40_000_000;

    /**
     * @return string|null a user-facing reason when the type is not an allowed image
     */
    public function rejectionReason(string $detectedMime, string $filename): ?string
    {
        if (!isset(self::ALLOWED[$detectedMime])) {
            return 'This is not an image PAL accepts. Use PNG, JPEG, WebP or GIF (SVG is not allowed).';
        }
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if (!\in_array($extension, self::ALLOWED[$detectedMime], true)) {
            return \sprintf('The image content is %s, which does not match its .%s extension.', self::ALLOWED[$detectedMime][0], '' === $extension ? '(none)' : $extension);
        }

        return null;
    }

    /**
     * Writes a clean copy of the image at $source to a new temporary file and returns its
     * path. The caller deletes it.
     */
    public function sanitize(string $source, string $mime): string
    {
        if (!\function_exists('imagecreatefromstring')) {
            throw new ApiProblem(503, 'image_processing_unavailable', 'Images cannot be processed on this server right now.');
        }
        $info = @getimagesize($source);
        if (false === $info || $info[0] < 1 || $info[1] < 1) {
            throw new ApiProblem(422, 'image_unreadable', 'The image could not be read. Save it again as PNG or JPEG and retry.');
        }
        [$width, $height] = $info;
        $orientation = 'image/jpeg' === $mime ? $this->orientation($source) : 1;
        // Rotating needs a second full-size copy of the pixels in memory.
        $copies = \in_array($orientation, [3, 4, 5, 6, 7, 8], true) ? 2 : 1;
        $maxPixels = min(self::MAX_PIXELS, self::pixelBudget($copies));
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > $maxPixels) {
            throw new ApiProblem(422, 'image_too_large', \sprintf(
                'The image has too many pixels. Resize it to at most 12,000 pixels on a side and %d megapixels.',
                intdiv($maxPixels, 1_000_000),
            ));
        }
        $bytes = file_get_contents($source);
        $image = false === $bytes ? false : @imagecreatefromstring($bytes);
        if (false === $image) {
            throw new ApiProblem(422, 'image_unreadable', 'The image could not be read. Save it again as PNG or JPEG and retry.');
        }

        if (1 !== $orientation) {
            $image = $this->orient($image, $orientation);
        }
        $target = tempnam(sys_get_temp_dir(), 'pal-img-');
        if (false === $target) {
            throw new ApiProblem(503, 'image_processing_unavailable', 'Images cannot be processed on this server right now.');
        }
        $ok = match ($mime) {
            'image/png' => $this->png($image, $target),
            'image/webp' => imagewebp($image, $target, 90),
            'image/gif' => imagegif($image, $target),
            default => imagejpeg($image, $target, 90),
        };
        if (!$ok || 0 === (int) filesize($target)) {
            @unlink($target);
            throw new ApiProblem(422, 'image_unreadable', 'The image could not be processed. Save it again as PNG or JPEG and retry.');
        }

        return $target;
    }

    private function png(\GdImage $image, string $target): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $target, 6);
    }

    /**
     * Pixels that fit in the PHP memory still available, at about 5 bytes per pixel per copy
     * (4 for a truecolor buffer plus encoder overhead), keeping a safety margin. With the
     * bundled GD these buffers count against memory_limit, so exceeding it would be a fatal
     * error (500, no audit record) instead of a clean refusal.
     */
    private static function pixelBudget(int $copies): int
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return self::MAX_PIXELS;
        }
        $available = $limit - memory_get_usage(true) - 48 * 1_048_576;

        return max(0, intdiv(max(0, $available), 5 * $copies));
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '-1' === $value) {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1_073_741_824,
            'm' => $number * 1_048_576,
            'k' => $number * 1_024,
            default => $number,
        };
    }

    private function orientation(string $source): int
    {
        if (!\function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($source);

        return \is_array($exif) && \is_int($exif['Orientation'] ?? null) && $exif['Orientation'] >= 1 && $exif['Orientation'] <= 8 ? $exif['Orientation'] : 1;
    }

    /**
     * Applies EXIF orientation (values 3, 6, 8 and their mirrored forms) to the pixels, since
     * the EXIF block that carried it is about to be removed.
     */
    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if (0 === $angle) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (false === $rotated) {
            return $image;
        }
        return $rotated;
    }
}
