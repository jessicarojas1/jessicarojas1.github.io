<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Hardened session bootstrap. Cookies are HttpOnly, SameSite=Lax, and Secure
 * whenever the request is HTTPS (behind a TLS-terminating proxy that forwards
 * X-Forwarded-Proto). Sessions are only started when actually needed (the public
 * discovery site does not start a session).
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (\PHP_SAPI === 'cli') {
            self::$started = true;   // no sessions under CLI (tests) — avoid header warnings
            return;
        }
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('REDOUBT_SID');
        session_start();
        self::$started = true;
    }

    /** Regenerate the session id (call right after a successful login). */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$started = false;
    }
}
