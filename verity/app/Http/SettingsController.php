<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Security;
use Verity\Support\Settings;

final class SettingsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'settings.manage');

        $branding = Settings::branding();

        $title = 'Platform Settings';
        $navActive = 'settings';
        $breadcrumbs = ['Settings' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        $appScript = '/assets/settings.js';
        require dirname(__DIR__) . '/Views/app_settings.php';
    }

    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'settings.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        Settings::saveBranding([
            'logoUrl' => (string) ($_POST['logoUrl'] ?? ''),
            'displayName' => (string) ($_POST['displayName'] ?? ''),
            'accent' => (string) ($_POST['accent'] ?? ''),
        ], $user['id']);
        header('Location: /app/admin/settings');
    }
}
