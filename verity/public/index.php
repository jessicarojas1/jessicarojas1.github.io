<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verity\Support\Auth;
use Verity\Support\Security;
use Verity\Http\AccountsController;
use Verity\Http\ApiRouter;
use Verity\Http\ApplicationsController;
use Verity\Http\AuditController;
use Verity\Http\AuthController;
use Verity\Http\DashboardController;
use Verity\Http\DynamicFieldsController;
use Verity\Http\IamController;
use Verity\Http\IdentitiesController;
use Verity\Http\MatrixController;
use Verity\Http\SettingsController;

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
