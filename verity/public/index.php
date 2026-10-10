<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verity\Support\Auth;
use Verity\Support\Security;
use Verity\Http\AccessRequestsController;
use Verity\Http\AccountsController;
use Verity\Http\ApiRouter;
use Verity\Http\ApplicationsController;
use Verity\Http\AuditController;
use Verity\Http\AuthController;
use Verity\Http\CampaignsController;
use Verity\Http\DashboardController;
use Verity\Http\DynamicFieldsController;
use Verity\Http\IamController;
use Verity\Http\IdentitiesController;
use Verity\Http\MatrixController;
use Verity\Http\ProfileController;
use Verity\Http\RemediationController;
use Verity\Http\SettingsController;
use Verity\Http\SetupController;

$nonce = Security::nonce();
$csp = implode('; ', [
    "default-src 'self'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "object-src 'none'",
    "img-src 'self' data: https:",
    "font-src 'self' https://fonts.gstatic.com",
    "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
    "script-src 'self' 'nonce-{$nonce}'",
    "connect-src 'self'",
]);
header("Content-Security-Policy: {$csp}");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'app' => 'verity', 'db' => \Verity\Support\Db::isConfigured()]);
    return;
}

if (str_starts_with($path, '/api/')) {
    ApiRouter::dispatch($method, $path);
    return;
}

if ($path === '/setup') {
    SetupController::run();
    return;
}

\Verity\Support\Session::start();

switch ($path) {
    case '/':
        header('Location: ' . (Auth::check() ? '/app' : '/auth/login'));
        return;

    case '/auth/login':
        if ($method === 'POST') { AuthController::login(); } else { AuthController::loginForm($nonce); }
        return;
    case '/auth/logout':
        AuthController::logout();
        return;
    case '/auth/sso':
        AuthController::ssoUnavailable();
        return;

    case '/app':
        DashboardController::index($nonce);
        return;

    case '/app/profile':
        ProfileController::index($nonce);
        return;
    case '/app/profile/password':
        if ($method === 'POST') { ProfileController::changePassword(); return; }
        http_response_code(405); return;

    case '/app/identities':
        IdentitiesController::index($nonce);
        return;
    case '/app/identities/view':
        IdentitiesController::view($nonce);
        return;

    case '/app/accounts/unmatched':
        AccountsController::unmatched($nonce);
        return;
    case '/app/accounts/view':
        AccountsController::view($nonce);
        return;
    case '/app/accounts/link':
        if ($method === 'POST') { AccountsController::link(); return; }
        http_response_code(405); return;
    case '/app/accounts/unlink':
        if ($method === 'POST') { AccountsController::unlink(); return; }
        http_response_code(405); return;
    case '/app/accounts/link-candidates':
        AccountsController::linkCandidates();
        return;

    case '/app/applications':
        if ($method === 'POST') { ApplicationsController::save(); return; }
        ApplicationsController::index($nonce);
        return;
    case '/app/applications/view':
        ApplicationsController::view($nonce);
        return;
    case '/app/applications/connector':
        if ($method === 'POST') { ApplicationsController::saveConnector(); return; }
        http_response_code(405); return;
    case '/app/applications/connector/sync-csv':
        if ($method === 'POST') { ApplicationsController::syncCsv(); return; }
        http_response_code(405); return;

    case '/app/campaigns':
        if ($method === 'POST') { CampaignsController::create(); return; }
        CampaignsController::index($nonce);
        return;
    case '/app/campaigns/view':
        CampaignsController::view($nonce);
        return;
    case '/app/campaigns/complete':
        if ($method === 'POST') { CampaignsController::complete(); return; }
        http_response_code(405); return;
    case '/app/campaigns/cancel':
        if ($method === 'POST') { CampaignsController::cancel(); return; }
        http_response_code(405); return;
    case '/app/campaigns/my-reviews':
        CampaignsController::myReviews($nonce);
        return;
    case '/app/campaigns/decide':
        if ($method === 'POST') { CampaignsController::decide(); return; }
        http_response_code(405); return;

    case '/app/access-requests':
        AccessRequestsController::index($nonce);
        return;
    case '/app/access-requests/accounts':
        AccessRequestsController::accountsForApplication();
        return;
    case '/app/access-requests/entitlements':
        AccessRequestsController::entitlementsForAccount();
        return;
    case '/app/access-requests/create':
        if ($method === 'POST') { AccessRequestsController::create(); return; }
        http_response_code(405); return;
    case '/app/access-requests/approve':
        if ($method === 'POST') { AccessRequestsController::approve(); return; }
        http_response_code(405); return;
    case '/app/access-requests/deny':
        if ($method === 'POST') { AccessRequestsController::deny(); return; }
        http_response_code(405); return;
    case '/app/access-requests/cancel':
        if ($method === 'POST') { AccessRequestsController::cancel(); return; }
        http_response_code(405); return;

    case '/app/remediation':
        RemediationController::index($nonce);
        return;
    case '/app/remediation/create':
        if ($method === 'POST') { RemediationController::create(); return; }
        http_response_code(405); return;
    case '/app/remediation/resolve':
        if ($method === 'POST') { RemediationController::resolve(); return; }
        http_response_code(405); return;
    case '/app/remediation/dismiss':
        if ($method === 'POST') { RemediationController::dismiss(); return; }
        http_response_code(405); return;

    case '/app/matrix':
        MatrixController::index($nonce);
        return;
    case '/app/matrix/export':
        MatrixController::export();
        return;
    case '/app/matrix/saved-views':
        if ($method === 'POST') { MatrixController::saveView(); return; }
        http_response_code(405); return;
    case '/app/matrix/saved-views/delete':
        if ($method === 'POST') { MatrixController::deleteView(); return; }
        http_response_code(405); return;

    case '/app/admin/dynamic-fields':
        if ($method === 'POST') { DynamicFieldsController::save(); return; }
        DynamicFieldsController::index($nonce);
        return;

    case '/app/admin/iam':
        IamController::index($nonce);
        return;
    case '/app/admin/iam/users':
        IamController::users();
        return;
    case '/app/admin/iam/user':
        IamController::userPerms();
        return;
    case '/app/admin/iam/save':
        if ($method === 'POST') { IamController::save(); return; }
        http_response_code(405); return;
    case '/app/admin/iam/create-user':
        if ($method === 'POST') { IamController::createUser(); return; }
        http_response_code(405); return;
    case '/app/admin/iam/update-details':
        if ($method === 'POST') { IamController::updateUserDetails(); return; }
        http_response_code(405); return;
    case '/app/admin/iam/reset-password':
        if ($method === 'POST') { IamController::resetPassword(); return; }
        http_response_code(405); return;
    case '/app/admin/iam/set-status':
        if ($method === 'POST') { IamController::setStatus(); return; }
        http_response_code(405); return;

    case '/app/admin/settings':
        if ($method === 'POST') { SettingsController::post(); return; }
        SettingsController::index($nonce);
        return;

    case '/app/admin/audit':
        AuditController::index($nonce);
        return;

    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found";
        return;
}
