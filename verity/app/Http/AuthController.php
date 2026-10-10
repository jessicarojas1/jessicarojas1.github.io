<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Config;
use Verity\Support\Db;
use Verity\Support\Security;

final class AuthController
{
    public static function loginForm(string $nonce, ?string $error = null): void
    {
        if (Auth::check()) {
            header('Location: /app');
            return;
        }
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        $dbConfigured = Db::isConfigured();
        require dirname(__DIR__) . '/Views/auth_login.php';
    }

    public static function login(): void
    {
        $nonce = Security::nonce();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::loginForm($nonce, 'Your session expired. Please try again.');
            return;
        }
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        // Checked before attempting the login so the message is accurate;
        // Auth::attemptLocal() enforces the same throttle independently
        // regardless of this check, so there's exactly one place this can
        // actually be bypassed (nowhere).
        if (Auth::isLoginThrottled($email)) {
            self::loginForm($nonce, 'Too many failed sign-in attempts. Please try again in a few minutes.');
            return;
        }
        if (!Auth::attemptLocal($email, $password)) {
            self::loginForm($nonce, 'Incorrect email or password.');
            return;
        }
        header('Location: /app');
    }

    public static function logout(): void
    {
        Auth::logout();
    }

    /**
     * Entra ID GCC High SSO is a Phase 4 integration — not implemented in
     * this build pass (see docs/GCC_HIGH_INTEGRATION.md). Responds honestly
     * rather than faking a sign-in flow.
     */
    public static function ssoUnavailable(): void
    {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        $configured = Config::entraConfigured() ? 'An Entra app registration is configured, but the sign-in flow itself is not yet implemented.' : 'No Entra app registration is configured yet.';
        echo "503 Service Unavailable\n\nMicrosoft Entra ID GCC High single sign-on is not yet implemented.\n"
            . "$configured\nUse local email/password sign-in at /auth/login in the meantime.\n"
            . "See docs/GCC_HIGH_INTEGRATION.md for status.";
    }
}
