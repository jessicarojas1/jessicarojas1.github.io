<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Dashboard;
use Verity\Support\Db;
use Verity\Support\Security;

final class DashboardController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'dashboard.view');

        $kpis = Db::isConfigured() ? Dashboard::kpis() : [];
        $byDepartment = Db::isConfigured() ? Dashboard::byDepartment() : [];
        $byApplication = Db::isConfigured() ? Dashboard::byApplication() : [];
        $connectorHealth = Db::isConfigured() ? Dashboard::connectorHealth() : [];

        $title = 'Dashboard';
        $navActive = 'home';
        $breadcrumbs = ['Dashboard' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_home.php';
    }
}
