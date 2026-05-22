<?php
declare(strict_types=1);

// Bootstrap
define('AEGIS_ROOT', __DIR__);
define('AEGIS_START', microtime(true));

// Load environment
foreach (['.env.local', '.env'] as $envFile) {
    if (file_exists(AEGIS_ROOT . '/' . $envFile)) {
        foreach (file(AEGIS_ROOT . '/' . $envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

// Session config
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
if ($_SERVER['REQUEST_SCHEME'] ?? '' === 'https' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// Autoload core classes
spl_autoload_register(function (string $class): void {
    $paths = [
        AEGIS_ROOT . "/src/{$class}.php",
        AEGIS_ROOT . "/controllers/{$class}.php",
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) { require_once $path; return; }
    }
});

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';
require_once AEGIS_ROOT . '/src/Auth.php';
require_once AEGIS_ROOT . '/src/JWT.php';

Security::setSecurityHeaders();

// Parse route
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = '/' . ltrim($uri, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// API handler
if (str_starts_with($uri, '/api/')) {
    require_once AEGIS_ROOT . '/api/index.php';
    exit;
}

// Route table
$routes = [
    'GET'  => [
        '/'                           => ['DashboardController', 'index'],
        '/login'                      => ['AuthController', 'loginForm'],
        '/logout'                     => ['AuthController', 'logout'],
        '/compliance'                 => ['ComplianceController', 'index'],
        '/compliance/import'          => ['ComplianceController', 'importForm'],
        '/audit'                      => ['AuditController', 'index'],
        '/audit/create'               => ['AuditController', 'createForm'],
        '/policy'                     => ['PolicyController', 'index'],
        '/policy/create'              => ['PolicyController', 'createForm'],
        '/risk'                       => ['RiskController', 'index'],
        '/risk/create'                => ['RiskController', 'createForm'],
        '/risk/matrix'                => ['RiskController', 'matrix'],
        '/admin'                      => ['AdminController', 'index'],
        '/admin/users'                => ['AdminController', 'users'],
        '/admin/risk-matrix'          => ['AdminController', 'riskMatrix'],
        '/admin/workflows'            => ['AdminController', 'workflows'],
        '/admin/alerts'               => ['AdminController', 'alerts'],
        '/admin/api-keys'             => ['AdminController', 'apiKeys'],
        '/admin/permissions'          => ['AdminController', 'permissions'],
    ],
    'POST' => [
        '/login'                         => ['AuthController', 'login'],
        '/compliance/import'             => ['ComplianceController', 'import'],
        '/audit/create'                  => ['AuditController', 'create'],
        '/policy/create'                 => ['PolicyController', 'create'],
        '/risk/create'                   => ['RiskController', 'create'],
        '/admin/users/create'            => ['AdminController', 'createUser'],
        '/admin/risk-matrix/update'      => ['AdminController', 'updateRiskMatrix'],
        '/admin/workflows/create'        => ['AdminController', 'createWorkflow'],
        '/admin/api-keys/create'         => ['AdminController', 'createApiKey'],
    ],
];

// Dynamic route patterns
$dynamicRoutes = [
    'GET' => [
        '#^/compliance/(\d+)$#'                     => ['ComplianceController', 'viewPackage'],
        '#^/compliance/(\d+)/objective/(\d+)$#'     => ['ComplianceController', 'viewObjective'],
        '#^/audit/(\d+)$#'                          => ['AuditController', 'view'],
        '#^/audit/(\d+)/edit$#'                     => ['AuditController', 'editForm'],
        '#^/policy/(\d+)$#'                         => ['PolicyController', 'view'],
        '#^/policy/(\d+)/edit$#'                    => ['PolicyController', 'editForm'],
        '#^/risk/(\d+)$#'                           => ['RiskController', 'view'],
        '#^/risk/(\d+)/edit$#'                      => ['RiskController', 'editForm'],
        '#^/admin/users/(\d+)/edit$#'               => ['AdminController', 'editUser'],
        '#^/admin/workflows/(\d+)/edit$#'           => ['AdminController', 'editWorkflow'],
    ],
    'POST' => [
        '#^/compliance/(\d+)/objective/(\d+)/update$#' => ['ComplianceController', 'updateObjective'],
        '#^/audit/(\d+)/update$#'                      => ['AuditController', 'update'],
        '#^/audit/(\d+)/complete$#'                    => ['AuditController', 'complete'],
        '#^/audit/(\d+)/item/(\d+)/update$#'           => ['AuditController', 'updateItem'],
        '#^/policy/(\d+)/update$#'                     => ['PolicyController', 'update'],
        '#^/policy/(\d+)/map$#'                        => ['PolicyController', 'mapObjective'],
        '#^/policy/(\d+)/unmap/(\d+)$#'                => ['PolicyController', 'unmapObjective'],
        '#^/risk/(\d+)/update$#'                       => ['RiskController', 'update'],
        '#^/risk/(\d+)/delete$#'                       => ['RiskController', 'delete'],
        '#^/admin/users/(\d+)/update$#'                => ['AdminController', 'updateUser'],
        '#^/admin/users/(\d+)/delete$#'                => ['AdminController', 'deleteUser'],
        '#^/admin/workflows/(\d+)/toggle$#'            => ['AdminController', 'toggleWorkflow'],
        '#^/admin/api-keys/(\d+)/revoke$#'             => ['AdminController', 'revokeApiKey'],
        '#^/admin/permissions/(\d+)/update$#'          => ['AdminController', 'updatePermissions'],
        '#^/alerts/(\d+)/read$#'                       => ['DashboardController', 'markAlertRead'],
    ],
];

function dispatch(string $controller, string $action, array $params = []): void {
    $file = AEGIS_ROOT . "/controllers/{$controller}.php";
    if (!file_exists($file)) { http_response_code(404); die('Controller not found'); }
    require_once $file;
    $ctrl = new $controller();
    if (!method_exists($ctrl, $action)) { http_response_code(404); die('Action not found'); }
    $ctrl->$action(...$params);
}

// Static routes
if (isset($routes[$method][$uri])) {
    [$controller, $action] = $routes[$method][$uri];
    dispatch($controller, $action);
    exit;
}

// Dynamic routes
foreach ($dynamicRoutes[$method] ?? [] as $pattern => [$controller, $action]) {
    if (preg_match($pattern, $uri, $matches)) {
        array_shift($matches);
        dispatch($controller, $action, $matches);
        exit;
    }
}

// 404
http_response_code(404);
require AEGIS_ROOT . '/views/errors/404.php';
