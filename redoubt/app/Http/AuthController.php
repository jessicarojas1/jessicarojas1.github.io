<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;

/** Thin HTTP layer over the Auth service (Entra GCC High OIDC). */
final class AuthController
{
    public static function login(): void
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
}
