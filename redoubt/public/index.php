<?php
/**
 * REDOUBT — GMRE Program Portal Framework
 * Front controller.
 *
 *   /              Public Product & Architecture Discovery Package (no auth)
 *   /health        Liveness JSON
 *   /auth/login    Begin Entra GCC High OIDC sign-in
 *   /auth/callback OIDC redirect handler
 *   /auth/logout   Sign out
 *   /app           Authenticated, role-aware application shell (Phase 1 skeleton)
 *   /api/v1/*      Versioned REST API (bearer API key or session; permission-aware)
 *
 * Phase 1 skeleton: framework services (auth, authorization, audit, Graph, API,
 * webhooks) are implemented; portal modules are pending (see OPEN_ITEMS.md).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Redoubt\Support\Security;
use Redoubt\Http\AuthController;
use Redoubt\Http\AppController;
use Redoubt\Http\ApiRouter;
use Redoubt\Http\IamController;
use Redoubt\Http\AnnouncementsController;
use Redoubt\Http\DocumentsController;
use Redoubt\Http\TaskOrdersController;
use Redoubt\Http\JobsController;
use Redoubt\Http\DirectoryController;
use Redoubt\Http\SearchController;
use Redoubt\Http\ContentAdminController;
use Redoubt\Http\SettingsController;

$nonce = Security::nonce();

// --- Security headers -------------------------------------------------------
$csp = implode('; ', [
    "default-src 'self'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "object-src 'none'",
    "img-src 'self' data: https:",
    "font-src 'self' https://fonts.gstatic.com",
    "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
    "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net",
    "connect-src 'self' https://cdn.jsdelivr.net",
]);
header("Content-Security-Policy: {$csp}");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// --- Routing ----------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

// API namespace handles its own auth + JSON responses.
if (str_starts_with($path, '/api/')) {
    ApiRouter::dispatch($method, $path);
    return;
}

switch ($path) {
    case '/health':
    case '/healthz':
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'ok',
            'service' => 'redoubt-program-portal',
            'phase'   => 'phase1-skeleton',
            'time'    => gmdate('c'),
        ], JSON_THROW_ON_ERROR);
        return;

    case '/auth/login':
        AuthController::login();
        return;

    case '/auth/callback':
        AuthController::callback();
        return;

    case '/auth/logout':
        AuthController::logout();
        return;

    case '/app':
        AppController::home($nonce);
        return;

    case '/app/announcements':
        if ($method === 'POST') {
            AnnouncementsController::post();
        } else {
            AnnouncementsController::index($nonce);
        }
        return;

    case '/app/documents':
        if ($method === 'POST') {
            DocumentsController::post();
        } else {
            DocumentsController::index($nonce);
        }
        return;

    case '/app/documents/open':
        DocumentsController::open();
        return;

    case '/app/task-orders':
        if ($method === 'POST') {
            TaskOrdersController::post();
        } else {
            TaskOrdersController::index($nonce);
        }
        return;

    case '/app/jobs':
        if ($method === 'POST') {
            JobsController::post();
        } else {
            JobsController::index($nonce);
        }
        return;

    case '/app/directory':
        if ($method === 'POST') {
            DirectoryController::post();
        } else {
            DirectoryController::index($nonce);
        }
        return;

    case '/app/search':
        SearchController::index($nonce);
        return;

    case '/app/admin/content':
        ContentAdminController::index($nonce);
        return;

    case '/app/admin/settings':
        if ($method === 'POST') {
            SettingsController::post();
        } else {
            SettingsController::index($nonce);
        }
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
        if ($method !== 'POST') {
            http_response_code(405);
            echo '405 Method Not Allowed';
            return;
        }
        IamController::save();
        return;

    case '/':
        $NONCE = $nonce; // exposed to the discovery view
        require dirname(__DIR__) . '/app/Views/discovery.php';
        return;

    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found";
        return;
}
