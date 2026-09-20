<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Config;
use Redoubt\Support\Db;
use Redoubt\Support\Security;

/**
 * Authentication endpoints. The portal works standalone with built-in local
 * email/password accounts; Microsoft Entra SSO is offered additionally when it
 * is configured. No external API is required to sign in.
 */
final class AuthController
{
    /** GET /auth/login — local login form (+ Microsoft button when Entra is set). */
    public static function login(string $nonce): void
    {
        if (Auth::check()) {
            header('Location: /app');
            return;
        }
        // First run: if the DB has no users yet, send the operator to setup.
        if (Db::isConfigured() && self::userCount() === 0) {
            header('Location: /setup');
            return;
        }
        $entraConfigured = Config::authConfigured();
        $dbConfigured = Db::isConfigured();
        $error = isset($_GET['error']) ? (string) $_GET['error'] : '';
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_login.php';
    }

    /** POST /auth/local — verify email + password. */
    public static function local(): void
    {
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            header('Location: /auth/login?error=' . rawurlencode('Session expired, try again.'));
            return;
        }
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (Auth::attemptLocal($email, $password)) {
            header('Location: /app');
            return;
        }
        header('Location: /auth/login?error=' . rawurlencode('Invalid email or password.'));
    }

    /** GET /auth/entra — begin Microsoft Entra OIDC (only when configured). */
    public static function entra(): void
    {
        Auth::beginLogin();
    }

    public static function callback(): void
    {
        Auth::completeLogin($_GET);
    }

    public static function logout(): void
    {
        Auth::logout();
    }

    private static function userCount(): int
    {
        try {
            return (int) (Db::fetchOne('SELECT count(*) c FROM app_user')['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0; // table not created yet -> treat as first run
        }
    }
}
