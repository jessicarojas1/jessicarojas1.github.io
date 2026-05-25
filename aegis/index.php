<?php
declare(strict_types=1);

// Bootstrap
define('AEGIS_ROOT', __DIR__);
define('AEGIS_START', microtime(true));

// Suppress PHP error display to users — errors go to the log only
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

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
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// Suppress version disclosure headers
header_remove('X-Powered-By');
@ini_set('expose_php', '0');

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
require_once AEGIS_ROOT . '/src/SSO.php';

Security::setSecurityHeaders();

// Parse route
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = '/' . ltrim($uri, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Health check — unauthenticated, no session required (load balancer / uptime monitoring)
if ($uri === '/health' && $method === 'GET') {
    header('Content-Type: application/json');
    $dbOk = false;
    $dbLatencyMs = 0;
    try {
        $t0 = microtime(true);
        Database::fetchOne("SELECT 1 AS ok");
        $dbLatencyMs = round((microtime(true) - $t0) * 1000, 2);
        $dbOk = true;
    } catch (Throwable) {}
    $diskFree = disk_free_space(AEGIS_ROOT);
    $diskTotal = disk_total_space(AEGIS_ROOT);
    $overall = $dbOk ? 'healthy' : 'degraded';
    http_response_code($dbOk ? 200 : 503);
    echo json_encode([
        'status'    => $overall,
        'version'   => '3.0',
        'timestamp' => date('c'),
        'uptime_ms' => round((microtime(true) - AEGIS_START) * 1000, 2),
        'checks'    => [
            'database' => ['status' => $dbOk ? 'ok' : 'error', 'latency_ms' => $dbLatencyMs],
            'disk'     => [
                'status'     => ($diskFree !== false && $diskFree > 100 * 1024 * 1024) ? 'ok' : 'low',
                'free_mb'    => $diskFree !== false ? round($diskFree / 1048576) : null,
                'total_mb'   => $diskTotal !== false ? round($diskTotal / 1048576) : null,
            ],
            'php'      => ['version' => PHP_VERSION],
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

// API docs (Swagger UI — no auth required)
if ($uri === '/api/docs' && $method === 'GET') {
    require_once AEGIS_ROOT . '/api/docs.php';
    exit;
}

// API handler
if (str_starts_with($uri, '/api/')) {
    require_once AEGIS_ROOT . '/api/index.php';
    exit;
}

// Route table
$routes = [
    'GET'  => [
        '/'                           => ['DashboardController', 'index'],
        '/profile/notifications'      => ['ProfileController', 'notifications'],
        '/login'                      => ['AuthController', 'loginForm'],
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
        '/admin/logs'                 => ['AdminController', 'logs'],
        '/export'                     => ['ExportController', 'index'],
        '/metrics'                    => ['MetricsController', 'index'],
        '/docs'                       => ['DocsController', 'index'],
        '/incident'                   => ['IncidentController', 'index'],
        '/incident/create'            => ['IncidentController', 'createForm'],
        '/vendor'                     => ['VendorController', 'index'],
        '/vendor/create'              => ['VendorController', 'createForm'],
        '/issue'                      => ['IssueController', 'index'],
        '/issue/create'               => ['IssueController', 'createForm'],
        '/report'                     => ['ReportController', 'index'],
        '/report/compliance'          => ['ReportController', 'compliance'],
        '/report/executive'           => ['ReportController', 'executive'],
        '/report/risk'                => ['ReportController', 'risk'],
        '/admin/email'                => ['AdminController', 'email'],
        '/admin/settings'             => ['AdminController', 'settings'],
        '/mfa/verify'                 => ['AuthController', 'mfaVerifyForm'],
        '/mfa/setup'                  => ['AuthController', 'mfaSetupForm'],
        '/evidence/list'              => ['EvidenceController', 'listForEntity'],
        '/sso/login'                  => ['SSOController', 'login'],
        '/sso/callback'               => ['SSOController', 'callback'],
        '/admin/settings/sso'         => ['SSOController', 'settingsForm'],
        '/approvals'                  => ['ApprovalController', 'pending'],
        '/admin/approval-templates'   => ['ApprovalController', 'templates'],
        '/import'                     => ['ImportController', 'index'],
        '/metrics'                    => ['MetricsController', 'index'],
        '/documents'                  => ['DocumentController', 'index'],
        '/documents/create'           => ['DocumentController', 'createForm'],
        '/report/board'               => ['ReportController', 'board'],
        '/admin/storage'              => ['AdminController', 'storage'],
        '/risk/roadmap'               => ['RiskController', 'roadmap'],
        '/admin/webhooks'             => ['WebhookController', 'index'],
        '/admin/webhooks/create'      => ['WebhookController', 'createForm'],
        '/questionnaire'              => ['QuestionnaireController', 'index'],
        '/questionnaire/create'       => ['QuestionnaireController', 'createForm'],
        '/change'                     => ['ChangeController', 'index'],
        '/change/create'              => ['ChangeController', 'createForm'],
        '/bcp'                        => ['BCPController', 'index'],
        '/bcp/create'                 => ['BCPController', 'createForm'],
        '/assets'                     => ['AssetController', 'index'],
        '/assets/create'              => ['AssetController', 'createForm'],
    ],
    'POST' => [
        '/profile/notifications'         => ['ProfileController', 'notifications'],
        '/login'                         => ['AuthController', 'login'],
        '/logout'                        => ['AuthController', 'logout'],
        '/mfa/verify'                    => ['AuthController', 'mfaVerify'],
        '/mfa/setup/verify'              => ['AuthController', 'mfaSetupVerify'],
        '/mfa/disable'                   => ['AuthController', 'mfaDisable'],
        '/compliance/import'             => ['ComplianceController', 'import'],
        '/audit/create'                  => ['AuditController', 'create'],
        '/policy/create'                 => ['PolicyController', 'create'],
        '/risk/create'                   => ['RiskController', 'create'],
        '/admin/users/create'            => ['AdminController', 'createUser'],
        '/admin/risk-matrix/update'      => ['AdminController', 'updateRiskMatrix'],
        '/admin/workflows/create'        => ['AdminController', 'createWorkflow'],
        '/admin/api-keys/create'         => ['AdminController', 'createApiKey'],
        '/admin/email/save'              => ['AdminController', 'saveEmail'],
        '/admin/email/test'              => ['AdminController', 'testEmail'],
        '/admin/settings/save'           => ['AdminController', 'saveSettings'],
        '/admin/logs/export'             => ['AdminController', 'exportLogs'],
        '/admin/settings/sso/save'       => ['SSOController', 'saveSettings'],
        '/import/upload'                 => ['ImportController', 'upload'],
        '/metrics/schedule/save'         => ['MetricsController', 'saveSchedule'],
        '/documents/create'              => ['DocumentController', 'create'],
        '/export/download'               => ['ExportController', 'download'],
        '/export/download-all'           => ['ExportController', 'downloadAll'],
        '/incident/create'               => ['IncidentController', 'create'],
        '/vendor/create'                 => ['VendorController', 'create'],
        '/issue/create'                  => ['IssueController', 'create'],
        '/evidence/upload'               => ['EvidenceController', 'upload'],
        '/admin/webhooks/create'         => ['WebhookController', 'create'],
        '/questionnaire/create'          => ['QuestionnaireController', 'create'],
        '/change/create'                 => ['ChangeController', 'create'],
        '/bcp/create'                    => ['BCPController', 'create'],
        '/assets/create'                 => ['AssetController', 'create'],
        '/admin/storage/save'            => ['AdminController', 'saveStorage'],
        '/admin/storage/test'            => ['AdminController', 'testStorage'],
    ],
];

// Dynamic route patterns
$dynamicRoutes = [
    'GET' => [
        '#^/compliance/(\d+)/ai-suggestions$#'      => ['ComplianceController', 'aiSuggestions'],
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
        '#^/incident/(\d+)$#'                       => ['IncidentController', 'view'],
        '#^/vendor/(\d+)$#'                         => ['VendorController', 'view'],
        '#^/issue/(\d+)$#'                          => ['IssueController', 'view'],
        '#^/evidence/(\d+)/download$#'              => ['EvidenceController', 'download'],
        '#^/admin/webhooks/(\d+)/edit$#'            => ['WebhookController', 'editForm'],
        '#^/admin/webhooks/(\d+)/deliveries$#'      => ['WebhookController', 'deliveries'],
        '#^/questionnaire/(\d+)$#'                  => ['QuestionnaireController', 'view'],
        '#^/questionnaire/assignment/(\d+)/respond$#' => ['QuestionnaireController', 'respond'],
        '#^/change/(\d+)$#'                         => ['ChangeController', 'view'],
        '#^/bcp/(\d+)$#'                            => ['BCPController', 'view'],
        '#^/assets/(\d+)$#'                         => ['AssetController', 'view'],
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
        '#^/incident/(\d+)/update$#'                   => ['IncidentController', 'update'],
        '#^/incident/(\d+)/add-update$#'               => ['IncidentController', 'addUpdate'],
        '#^/incident/(\d+)/close$#'                    => ['IncidentController', 'close'],
        '#^/vendor/(\d+)/update$#'                     => ['VendorController', 'update'],
        '#^/vendor/(\d+)/assessment$#'                 => ['VendorController', 'addAssessment'],
        '#^/vendor/(\d+)/assessment/(\d+)/update$#'    => ['VendorController', 'updateAssessment'],
        '#^/issue/(\d+)/update$#'                      => ['IssueController', 'update'],
        '#^/issue/(\d+)/add-update$#'                  => ['IssueController', 'addUpdate'],
        '#^/evidence/(\d+)/delete$#'                   => ['EvidenceController', 'delete'],
        '#^/approvals/(\d+)/review$#'                  => ['ApprovalController', 'review'],
        '#^/approvals/(\d+)/decide$#'                  => ['ApprovalController', 'decide'],
        '#^/metrics/schedule/(\d+)/delete$#'           => ['MetricsController', 'deleteSchedule'],
        '#^/documents/(\d+)$#'                                  => ['DocumentController', 'view'],
        '#^/documents/(\d+)/update$#'                           => ['DocumentController', 'update'],
        '#^/documents/(\d+)/upload-version$#'                   => ['DocumentController', 'uploadVersion'],
        '#^/admin/webhooks/(\d+)/update$#'                      => ['WebhookController', 'update'],
        '#^/admin/webhooks/(\d+)/toggle$#'                      => ['WebhookController', 'toggleActive'],
        '#^/admin/webhooks/(\d+)/delete$#'                      => ['WebhookController', 'delete'],
        '#^/questionnaire/(\d+)/assign$#'                       => ['QuestionnaireController', 'assign'],
        '#^/questionnaire/assignment/(\d+)/submit$#'            => ['QuestionnaireController', 'submitResponse'],
        '#^/change/(\d+)/update$#'                              => ['ChangeController', 'update'],
        '#^/change/(\d+)/add-update$#'                          => ['ChangeController', 'addUpdate'],
        '#^/bcp/(\d+)/update$#'                                 => ['BCPController', 'update'],
        '#^/bcp/(\d+)/add-exercise$#'                           => ['BCPController', 'addExercise'],
        '#^/assets/(\d+)/update$#'                              => ['AssetController', 'update'],
        '#^/assets/(\d+)/link-risk$#'                           => ['AssetController', 'linkRisk'],
        '#^/assets/(\d+)/unlink-risk/(\d+)$#'                   => ['AssetController', 'unlinkRisk'],
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
