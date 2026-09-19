<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Security primitives: per-request CSP nonce, output escaping, and CSRF tokens.
 * Mirrors the AEGIS-family conventions so views and controllers are consistent.
 */
final class Security
{
    private static ?string $nonce = null;

    /** Per-request CSP nonce (stable within a request, unique across requests). */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /** HTML-escape untrusted output. Always use for user/content data in views. */
    public static function h(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * JSON encode for embedding in a <script> context, hardened against tag/amp
     * breakouts (repo rule: JSON_HEX_TAG | JSON_HEX_AMP in script contexts).
     */
    public static function jsonForScript(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    // --- CSRF ---------------------------------------------------------------

    public static function csrfToken(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** Hidden form field for POST forms. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::h(self::csrfToken()) . '">';
    }

    /** Validate a submitted CSRF token in constant time. Call on every POST. */
    public static function validateCsrf(?string $submitted): bool
    {
        Session::start();
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($submitted) && $expected !== '' && hash_equals($expected, $submitted);
    }

    /** Rotate the CSRF token (e.g., after a privilege change or AJAX save). */
    public static function rotateCsrf(): string
    {
        Session::start();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
}
