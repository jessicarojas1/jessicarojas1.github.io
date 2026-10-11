<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Hardened session bootstrap. Cookies are HttpOnly, SameSite=Lax, and Secure
 * whenever the request is HTTPS (behind a TLS-terminating proxy that forwards
 * X-Forwarded-Proto).
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
        session_name('VERITY_SID');
        session_start();
        self::$started = true;
    }

    /**
     * Regenerate the session id (call right after a successful login, or at
     * any other authentication-boundary crossing — e.g. Auth's MFA-pending
     * state). Guarded the same way destroy() is: under CLI SAPI, start()
     * never calls the real session_start(), so session_status() stays
     * PHP_SESSION_NONE — calling session_regenerate_id() in that state
     * emits a PHP warning for no benefit. Found the same way destroy()'s
     * guard was: a CLI-reachable test path (Auth's MFA challenge) started
     * calling this. Keep the guard if this method is ever touched again.
     */
    public static function regenerate(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        // Under CLI (tests, and any future CLI-driven call path) start()
        // never calls the real session_start(), so session_status() stays
        // PHP_SESSION_NONE — calling session_destroy()/setcookie() in that
        // state emits a PHP warning for no benefit. Only tear down a session
        // that was genuinely started.
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
        self::$started = false;
    }
}
