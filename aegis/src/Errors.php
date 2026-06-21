<?php
declare(strict_types=1);

/**
 * Errors — consistent HTTP error responses for the front-controller.
 *
 * Centralizes the "set status code, render the matching standalone error view,
 * stop" pattern that controllers previously open-coded with inline
 * http_response_code()/require calls. Distinguishes web (HTML view) from API
 * (JSON) requests so a single helper works for both surfaces.
 *
 * Error views never leak internal detail — see views/errors/500.php.
 */
final class Errors
{
    private const VIEWS = [
        400 => 'errors/400',
        401 => 'errors/401',
        403 => 'errors/403',
        404 => 'errors/404',
        419 => 'errors/419',
        429 => 'errors/429',
        500 => 'errors/500',
    ];

    /**
     * Emit an error response and terminate the request.
     * For JSON/API requests a structured body is returned instead of HTML.
     */
    public static function abort(int $code, ?string $message = null): never
    {
        if (!headers_sent()) {
            http_response_code($code);
        }

        if (self::wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'error'   => $message ?? self::defaultMessage($code),
                'meta'    => [
                    'status'     => $code,
                    'request_id' => defined('AEGIS_REQUEST_ID') ? AEGIS_REQUEST_ID : null,
                    'timestamp'  => date('c'),
                ],
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }

        $view = self::VIEWS[$code] ?? self::VIEWS[500];
        $path = AEGIS_ROOT . '/views/' . $view . '.php';
        if (is_file($path)) {
            require $path;
        } else {
            echo '<h1>' . $code . '</h1>';
        }
        exit;
    }

    /** Whether the current request expects a JSON response. */
    private static function wantsJson(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_starts_with($uri, '/api/')) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strcasecmp($xrw, 'XMLHttpRequest') === 0;
    }

    private static function defaultMessage(int $code): string
    {
        return match ($code) {
            400 => 'Bad request',
            401 => 'Authentication required',
            403 => 'Access denied',
            404 => 'Not found',
            419 => 'Session or CSRF token expired',
            429 => 'Too many requests',
            default => 'Internal server error',
        };
    }
}
