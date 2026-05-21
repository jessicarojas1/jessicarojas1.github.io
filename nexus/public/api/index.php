<?php
/**
 * NEXUS - API entry point.
 *
 * All /api/* requests are funneled here by .htaccess.
 * We resolve the path, wire up the Router, and let each
 * handler file register its endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Response.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Router.php';

use Nexus\Response;
use Nexus\Router;

// ── CORS / preflight ──────────────────────────────────────────────────
header('Vary: Origin');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Resolve the request path ──────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Strip any leading script path (when running under PHP built-in server etc.)
$path = '/' . ltrim($uri, '/');

// ── Build the router ──────────────────────────────────────────────────
$router = new Router();

require __DIR__ . '/auth.php';
require __DIR__ . '/users.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/tickets.php';
require __DIR__ . '/comments.php';
require __DIR__ . '/labels.php';
require __DIR__ . '/sprints.php';
require __DIR__ . '/history.php';
require __DIR__ . '/notifications.php';

// Health check
$router->get('/api/health', function () {
    Response::ok(['ok' => true, 'service' => 'nexus-api', 'time' => date('c')]);
});

// ── Dispatch with a global exception guard ───────────────────────────
try {
    $router->dispatch($method, $path);
} catch (\Throwable $e) {
    // Log to stderr (visible in Render logs / Apache error log).
    error_log('[nexus-api] ' . $e::class . ': ' . $e->getMessage());
    if ((getenv('APP_ENV') ?: 'development') !== 'production') {
        Response::json([
            'error' => $e->getMessage(),
            'code'  => 'SERVER_ERROR',
            'trace' => explode("\n", $e->getTraceAsString()),
        ], 500);
    }
    Response::serverError('An unexpected error occurred');
}
