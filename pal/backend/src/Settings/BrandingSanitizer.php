<?php

declare(strict_types=1);

namespace App\Settings;

use App\Platform\Http\ApiProblem;

/**
 * Validates branding input. Logos may only be http(s) URLs or base64 raster data URLs
 * (PNG, JPEG, GIF, WebP). SVG is excluded because it is a scriptable document format.
 * Remote logos must be https.
 */
final class BrandingSanitizer
{
    public const MAX_LOGO_DATA_URL_LENGTH = 400_000;
    private const MAX_LOGO_URL_LENGTH = 2048;
    private const MAX_DISPLAY_NAME_LENGTH = 80;

    /**
     * @param array<mixed> $input null or "" for a field resets it to the default
     */
    public static function sanitize(array $input): Branding
    {
        $errors = [];

        $displayName = self::optionalString($input['displayName'] ?? null);
        if (null !== $displayName) {
            $displayName = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $displayName));
            if ('' === $displayName) {
                $displayName = null;
            } elseif (mb_strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
                $errors['displayName'] = 'Use at most 80 characters.';
            }
        }

        $logoUrl = self::optionalString($input['logoUrl'] ?? null);
        if (null !== $logoUrl && !self::isAllowedLogo($logoUrl)) {
            $errors['logoUrl'] = 'Use an https:// image URL, or upload a PNG, JPEG, GIF or WebP image under 300 KB.';
        }

        $accentColor = self::optionalString($input['accentColor'] ?? null);
        if (null !== $accentColor) {
            if (1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor)) {
                $errors['accentColor'] = 'Use a hex colour such as #2f5bd3.';
            }
            $accentColor = strtolower($accentColor);
        }

        foreach (['displayName', 'logoUrl', 'accentColor'] as $field) {
            if (isset($input[$field]) && !\is_string($input[$field])) {
                $errors[$field] = 'Must be a string or null.';
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validation($errors);
        }

        return new Branding($displayName, $logoUrl, $accentColor);
    }

    private static function optionalString(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private static function isAllowedLogo(string $url): bool
    {
        if (str_starts_with($url, 'data:')) {
            if (\strlen($url) > self::MAX_LOGO_DATA_URL_LENGTH) {
                return false;
            }
            if (1 !== preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/]+={0,2})$#', $url, $m)) {
                return false;
            }

            return false !== base64_decode($m[2], true);
        }
        if (\strlen($url) > self::MAX_LOGO_URL_LENGTH || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        // https only: the SPA's CSP (img-src 'self' data: https:) would block http images anyway.
        return 'https' === $scheme && '' !== (string) parse_url($url, \PHP_URL_HOST) && null === parse_url($url, \PHP_URL_USER);
    }
}
