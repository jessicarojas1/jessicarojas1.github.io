<?php
/**
 * Branding — centralized access to the app's display name, logo and accent
 * colour (the "Settings → Branding" standard).
 *
 * Values are persisted server-side in the `settings` table under the keys:
 *   - org_name           : organization / product display name (string)
 *   - company_logo_data   : logo source — a data: URI (file upload) OR an
 *                           http(s):// image URL (paste a link)
 *   - company_logo_name   : original filename / label for the logo (string)
 *   - brand_accent        : primary accent colour as a #RRGGBB hex string
 *
 * Every value has a sensible built-in default so unset branding never breaks
 * the UI, and a bad/broken logo source degrades gracefully to the built-in
 * shield mark. All getters return *sanitized* values safe to embed in markup.
 */
final class Branding
{
    /** Built-in defaults (the app's own mark/name/accent). */
    public const DEFAULT_NAME   = 'PALADIN';
    public const DEFAULT_ACCENT = '#2563eb';

    /** Per-request cache so repeated lookups hit the DB only once. */
    private static ?array $cache = null;

    /**
     * Load the raw branding row map from the settings table (cached).
     * Never throws — on any DB error it returns an empty map and callers
     * fall back to defaults.
     */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $map = [];
        try {
            $rows = Database::fetchAll(
                "SELECT key, value FROM settings
                 WHERE key IN ('org_name','company_logo_data','company_logo_name','brand_accent')"
            );
            $map = array_column($rows, 'value', 'key');
        } catch (\Throwable) {
            $map = [];
        }
        return self::$cache = $map;
    }

    /** Clear the per-request cache (call after a branding save). */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** Organization / product display name (falls back to the app name). */
    public static function name(): string
    {
        $v = trim((string)(self::load()['org_name'] ?? ''));
        return $v !== '' ? $v : self::DEFAULT_NAME;
    }

    /**
     * Sanitized logo source, or '' when none is set / the source is invalid.
     * Only `data:image/...` and `http(s)://...` sources are allowed; anything
     * else (e.g. `javascript:`) is rejected and treated as unset.
     */
    public static function logo(): string
    {
        return self::sanitizeLogo((string)(self::load()['company_logo_data'] ?? ''));
    }

    /** Whether a usable logo source is configured. */
    public static function hasLogo(): bool
    {
        return self::logo() !== '';
    }

    /** Original logo filename / label (may be empty). */
    public static function logoName(): string
    {
        return trim((string)(self::load()['company_logo_name'] ?? ''));
    }

    /** Primary accent colour as a validated #RRGGBB hex (falls back to default). */
    public static function accent(): string
    {
        return self::sanitizeColor((string)(self::load()['brand_accent'] ?? ''))
            ?: self::DEFAULT_ACCENT;
    }

    /**
     * Validate/normalise a logo source. Returns the trimmed source if it is an
     * allowed `data:image/...` URI or an `http(s)://` URL, otherwise ''.
     */
    public static function sanitizeLogo(string $src): string
    {
        $src = trim($src);
        if ($src === '') {
            return '';
        }
        // data:image/<type>;base64,.... (also allows charset/params before the comma)
        if (preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml)[;,]#i', $src) === 1) {
            return $src;
        }
        // http(s) URL — defer actual reachability to the browser (graceful onerror fallback)
        if (preg_match('#^https?://[^\s"\'<>]+$#i', $src) === 1) {
            return $src;
        }
        return '';
    }

    /**
     * Validate a colour string. Returns a normalised lower-case #RRGGBB hex,
     * or '' when the input is not a valid 3- or 6-digit hex colour.
     */
    public static function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $color, $m) === 1) {
            return '#' . strtolower($m[1]);
        }
        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $color, $m) === 1) {
            $h = strtolower($m[1]);
            return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return '';
    }

    /**
     * Darken a #RRGGBB hex by a factor (0..1) — used to derive --primary-dark
     * for hover states from the chosen accent. Returns #RRGGBB.
     */
    public static function darken(string $hex, float $factor = 0.82): string
    {
        $hex = self::sanitizeColor($hex) ?: self::DEFAULT_ACCENT;
        $factor = max(0.0, min(1.0, $factor));
        $r = (int)round(hexdec(substr($hex, 1, 2)) * $factor);
        $g = (int)round(hexdec(substr($hex, 3, 2)) * $factor);
        $b = (int)round(hexdec(substr($hex, 5, 2)) * $factor);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Lighten a #RRGGBB hex toward white by a factor (0..1) — used to derive
     * --primary-light. Returns #RRGGBB.
     */
    public static function lighten(string $hex, float $factor = 0.35): string
    {
        $hex = self::sanitizeColor($hex) ?: self::DEFAULT_ACCENT;
        $factor = max(0.0, min(1.0, $factor));
        $mix = fn(int $c) => (int)round($c + (255 - $c) * $factor);
        $r = $mix(hexdec(substr($hex, 1, 2)));
        $g = $mix(hexdec(substr($hex, 3, 2)));
        $b = $mix(hexdec(substr($hex, 5, 2)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Inline <style> block (with CSP nonce) that overrides the primary accent
     * CSS custom properties when a non-default accent is configured.
     * Returns '' when the accent equals the built-in default (nothing to do).
     */
    public static function accentStyleTag(): string
    {
        $accent = self::accent();
        if (strcasecmp($accent, self::DEFAULT_ACCENT) === 0) {
            return '';
        }
        $dark  = self::darken($accent);
        $light = self::lighten($accent);
        $nonce = Security::nonce();
        return "<style nonce=\"{$nonce}\">:root{--primary:{$accent};--primary-dark:{$dark};--primary-light:{$light};}"
             . "[data-theme=\"dark\"]{--primary:{$accent};--primary-dark:{$dark};--primary-light:{$light};}</style>";
    }
}
