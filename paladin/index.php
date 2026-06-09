<?php
declare(strict_types=1);

define('PALADIN_ROOT', __DIR__);
define('PALADIN_START', microtime(true));

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('[PALADIN] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>PALADIN — Configuration Error</title>"
       . "<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#f1f5f9;display:flex;"
       . "align-items:center;justify-content:center;min-height:100vh;margin:0}"
       . ".box{background:#1e293b;border:1px solid #ef4444;border-radius:12px;padding:40px;max-width:560px}"
       . "h1{color:#ef4444;margin:0 0 12px}p{color:#94a3b8;margin:0 0 8px}code{color:#fbbf24}</style></head>"
       . "<body><div class='box'><h1>&#9888; Configuration Error</h1><p>{$msg}</p>"
       . "<p style='margin-top:16px'>Ensure required environment variables are set: "
       . "<code>JWT_SECRET</code>, <code>DATABASE_URL</code>, <code>APP_URL</code></p></div></body></html>";
    exit(1);
});

// ── Environment ───────────────────────────────────────────────────────────
foreach (['.env.local', '.env'] as $envFile) {
    if (file_exists(PALADIN_ROOT . '/' . $envFile)) {
        foreach (file(PALADIN_ROOT . '/' . $envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}
foreach ((getenv() ?: []) as $k => $v) {
    if (!isset($_ENV[$k])) $_ENV[$k] = $v;
}

if (empty($_ENV['JWT_SECRET']) || strlen($_ENV['JWT_SECRET']) < 32) {
    throw new RuntimeException('JWT_SECRET must be set and at least 32 characters.');
}

// ── Session ───────────────────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$_isHttps = ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || !empty($_SERVER['HTTPS']);
if ($_isHttps) {
    ini_set('session.cookie_secure', '1');
    session_name('__Host-PALADIN');
}
session_start();

header_remove('X-Powered-By');
@ini_set('expose_php', '0');

// ── Autoload ──────────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    foreach ([PALADIN_ROOT . "/src/{$class}.php", PALADIN_ROOT . "/controllers/{$class}.php"] as $path) {
        if (file_exists($path)) { require_once $path; return; }
    }
});

require_once PALADIN_ROOT . '/config/database.php';
require_once PALADIN_ROOT . '/src/Database.php';
require_once PALADIN_ROOT . '/src/Security.php';
require_once PALADIN_ROOT . '/src/Branding.php';
require_once PALADIN_ROOT . '/src/Auth.php';

Security::setSecurityHeaders();

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = '/' . ltrim($uri, '/');
$uri    = $uri !== '/' ? rtrim($uri, '/') : $uri;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── Health (unauthenticated, for load balancers / probes) ─────────────────
if ($uri === '/health' && $method === 'GET') {
    header('Content-Type: application/json');
    $dbOk = false;
    try { Database::fetchOne("SELECT 1 AS ok"); $dbOk = true; } catch (Throwable) {}
    $diskFree = @disk_free_space(PALADIN_ROOT);
    http_response_code($dbOk ? 200 : 503);
    echo json_encode([
        'status'    => $dbOk ? 'healthy' : 'degraded',
        'service'   => 'paladin',
        'timestamp' => date('c'),
        'checks'    => [
            'database' => $dbOk ? 'ok' : 'error',
            'disk'     => ($diskFree !== false && $diskFree > 100 * 1024 * 1024) ? 'ok' : 'low',
        ],
    ]);
    exit;
}
// Liveness / readiness aliases
if (($uri === '/healthz' || $uri === '/readyz') && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

// ── API ───────────────────────────────────────────────────────────────────
if ($uri === '/api/docs' && $method === 'GET') {
    require_once PALADIN_ROOT . '/api/docs.php';
    exit;
}
if (str_starts_with($uri, '/api/')) {
    require_once PALADIN_ROOT . '/api/index.php';
    exit;
}

// ── Route table ───────────────────────────────────────────────────────────
$routes = [
    'GET' => [
        '/'                          => ['DashboardController', 'index'],
        '/login'                     => ['AuthController', 'loginForm'],
        '/dashboard/admin'           => ['DashboardController', 'admin'],

        '/spaces'                    => ['SpaceController', 'index'],
        '/spaces/create'             => ['SpaceController', 'createForm'],

        '/pages/create'              => ['PageController', 'createForm'],
        '/pages/templates'           => ['PageController', 'templateGallery'],
        '/pages/import'              => ['PageController', 'importForm'],

        '/documents'                 => ['DocumentController', 'index'],
        '/documents/create'          => ['DocumentController', 'createForm'],

        '/processes'                 => ['ProcessController', 'index'],
        '/processes/create'          => ['ProcessController', 'createForm'],

        '/workflows'                 => ['WorkflowController', 'index'],
        '/workflows/create'          => ['WorkflowController', 'createForm'],

        '/approvals'                 => ['ApprovalController', 'index'],
        '/approvals/start'           => ['ApprovalController', 'startForm'],

        '/tasks'                     => ['TaskController', 'index'],
        '/tasks/create'              => ['TaskController', 'createForm'],

        '/blog'                      => ['BlogController', 'index'],
        '/blog/rss'                  => ['BlogController', 'rss'],
        '/blog/create'               => ['BlogController', 'createForm'],
        '/templates'                 => ['TemplateController', 'index'],
        '/templates/create'          => ['TemplateController', 'createForm'],

        '/search'                    => ['SearchController', 'index'],
        '/labels'                    => ['LabelController', 'index'],
        '/reports'                   => ['ReportController', 'index'],
        '/reports/expiring'          => ['ReportController', 'expiring'],
        '/reports/approval-backlog'  => ['ReportController', 'approvalBacklog'],
        '/reports/acknowledgements'  => ['ReportController', 'acknowledgements'],

        '/campaigns'                 => ['CampaignController', 'index'],
        '/campaigns/create'          => ['CampaignController', 'createForm'],

        '/profile/edit'              => ['ProfileController', 'editForm'],
        '/profile/notifications'     => ['ProfileController', 'notifications'],
        '/profile/favorites'         => ['ProfileController', 'favorites'],
        '/profile/tokens'            => ['ProfileController', 'tokens'],
        '/mfa/setup'                 => ['ProfileController', 'mfaSetupForm'],
        '/mfa/verify'                => ['AuthController', 'mfaVerifyForm'],

        '/admin'                     => ['AdminController', 'index'],
        '/admin/users'               => ['AdminController', 'users'],
        '/admin/users/create'        => ['AdminController', 'createUserForm'],
        '/admin/permissions'         => ['AdminController', 'permissions'],
        '/admin/roles'               => ['AdminController', 'roles'],
        '/admin/roles/create'        => ['AdminController', 'roleForm'],
        '/admin/branding'            => ['AdminController', 'branding'],
        '/admin/settings'            => ['AdminController', 'settings'],
        '/admin/tags'                => ['AdminController', 'tags'],
        '/admin/api-keys'            => ['AdminController', 'apiKeys'],
        '/admin/logs'                => ['AdminController', 'logs'],
        '/admin/sessions'            => ['AdminController', 'sessions'],
        '/admin/system'              => ['AdminController', 'system'],
        '/admin/shortcuts'           => ['AdminController', 'shortcuts'],
        '/admin/webhooks'            => ['AdminController', 'webhooks'],
        '/admin/retention'           => ['AdminController', 'retention'],
        '/admin/numbering'           => ['AdminController', 'numbering'],
        '/docs'                      => ['DocsController', 'index'],
    ],
    'POST' => [
        '/login'                     => ['AuthController', 'login'],
        '/logout'                    => ['AuthController', 'logout'],

        '/spaces/create'             => ['SpaceController', 'create'],
        '/pages/create'              => ['PageController', 'create'],
        '/pages/import'              => ['PageController', 'import'],

        '/documents/create'          => ['DocumentController', 'create'],

        '/processes/create'          => ['ProcessController', 'create'],

        '/workflows/create'          => ['WorkflowController', 'create'],
        '/approvals/start'           => ['ApprovalController', 'start'],

        '/tasks/create'              => ['TaskController', 'create'],

        '/blog/create'               => ['BlogController', 'create'],
        '/templates/create'          => ['TemplateController', 'create'],

        '/campaigns'                 => ['CampaignController', 'create'],

        '/profile/edit'              => ['ProfileController', 'update'],
        '/profile/tokens'            => ['ProfileController', 'createToken'],
        '/mfa/setup'                 => ['ProfileController', 'mfaEnable'],
        '/mfa/disable'               => ['ProfileController', 'mfaDisable'],
        '/mfa/verify'                => ['AuthController', 'mfaVerify'],

        '/admin/users/create'        => ['AdminController', 'createUser'],
        '/admin/permissions/save'    => ['AdminController', 'savePermissions'],
        '/admin/roles/create'        => ['AdminController', 'createRole'],
        '/admin/branding'            => ['AdminController', 'saveBranding'],
        '/admin/settings'            => ['AdminController', 'saveSettings'],
        '/admin/tags'                => ['AdminController', 'createTag'],
        '/admin/shortcuts'           => ['AdminController', 'createShortcut'],
        '/admin/api-keys'            => ['AdminController', 'createApiKey'],
        '/admin/webhooks'            => ['AdminController', 'createWebhook'],
        '/admin/retention'           => ['AdminController', 'createRetention'],
        '/admin/numbering'           => ['AdminController', 'saveNumbering'],
        '/admin/expiry-sweep'        => ['AdminController', 'runExpiry'],

        '/alerts/read-all'           => ['ProfileController', 'markAllRead'],
        '/searches/save'             => ['SearchController', 'save'],
        '/media/upload'              => ['MediaController', 'upload'],
        '/reactions/toggle'          => ['ReactionController', 'toggle'],
        '/share'                     => ['ShareController', 'send'],
        '/workflow/apply'            => ['WorkflowRunController', 'apply'],
        '/workflow/transition'       => ['WorkflowRunController', 'transition'],
        '/workflow/remove'           => ['WorkflowRunController', 'remove'],
    ],
];

// ── Dynamic routes (regex → controller) ───────────────────────────────────
$dynamicRoutes = [
    'GET' => [
        '#^/spaces/(\d+)$#'                  => ['SpaceController', 'view'],
        '#^/spaces/(\d+)/export$#'           => ['SpaceController', 'export'],
        '#^/spaces/(\d+)/trash$#'            => ['PageController', 'trash'],
        '#^/spaces/(\d+)/edit$#'             => ['SpaceController', 'editForm'],
        '#^/spaces/(\d+)/pages/create$#'     => ['PageController', 'createForm'],
        '#^/pages/(\d+)$#'                   => ['PageController', 'view'],
        '#^/attachments/(\d+)/download$#'    => ['AttachmentController', 'download'],
        '#^/media/(\d+)$#'                   => ['MediaController', 'serve'],
        '#^/pages/(\d+)/edit$#'              => ['PageController', 'editForm'],
        '#^/pages/(\d+)/history$#'           => ['PageController', 'history'],
        '#^/pages/(\d+)/diff$#'              => ['PageController', 'diff'],
        '#^/pages/(\d+)/print$#'             => ['PageController', 'printView'],
        '#^/documents/(\d+)$#'               => ['DocumentController', 'view'],
        '#^/documents/(\d+)/edit$#'          => ['DocumentController', 'editForm'],
        '#^/documents/(\d+)/download$#'      => ['DocumentController', 'download'],
        '#^/processes/(\d+)$#'               => ['ProcessController', 'view'],
        '#^/processes/(\d+)/edit$#'          => ['ProcessController', 'editForm'],
        '#^/workflows/(\d+)$#'               => ['WorkflowController', 'view'],
        '#^/workflows/(\d+)/edit$#'          => ['WorkflowController', 'editForm'],
        '#^/approvals/(\d+)$#'               => ['ApprovalController', 'view'],
        '#^/tasks/(\d+)$#'                   => ['TaskController', 'view'],
        '#^/blog/(\d+)$#'                    => ['BlogController', 'view'],
        '#^/labels/(\d+)$#'                  => ['LabelController', 'view'],
        '#^/blog/(\d+)/edit$#'               => ['BlogController', 'editForm'],
        '#^/spaces/(\d+)/blog$#'             => ['BlogController', 'space'],
        '#^/templates/(\d+)$#'               => ['TemplateController', 'view'],
        '#^/campaigns/(\d+)$#'               => ['CampaignController', 'view'],
        '#^/admin/users/(\d+)/permissions$#' => ['AdminController', 'permissions'],
        '#^/admin/roles/(\d+)/edit$#'        => ['AdminController', 'roleForm'],
    ],
    'POST' => [
        '#^/spaces/(\d+)/edit$#'             => ['SpaceController', 'update'],
        '#^/spaces/(\d+)/delete$#'           => ['SpaceController', 'delete'],
        '#^/spaces/(\d+)/watch$#'            => ['SpaceController', 'toggleWatch'],
        '#^/spaces/(\d+)/favorite$#'         => ['SpaceController', 'toggleFavorite'],
        '#^/pages/(\d+)/watch$#'             => ['PageController', 'toggleWatch'],
        '#^/pages/(\d+)/favorite$#'          => ['PageController', 'toggleFavorite'],
        '#^/pages/(\d+)/edit$#'              => ['PageController', 'update'],
        '#^/pages/(\d+)/delete$#'            => ['PageController', 'delete'],
        '#^/pages/(\d+)/restore-trash$#'     => ['PageController', 'untrash'],
        '#^/pages/(\d+)/purge$#'             => ['PageController', 'purge'],
        '#^/pages/(\d+)/publish$#'           => ['PageController', 'publish'],
        '#^/pages/(\d+)/comment$#'           => ['PageController', 'comment'],
        '#^/pages/(\d+)/inline-comment$#'    => ['PageController', 'addInlineComment'],
        '#^/inline-comments/(\d+)/resolve$#' => ['PageController', 'resolveInlineComment'],
        '#^/inline-comments/(\d+)/delete$#'  => ['PageController', 'deleteInlineComment'],
        '#^/pages/(\d+)/restore/(\d+)$#'     => ['PageController', 'restore'],
        '#^/pages/(\d+)/labels$#'           => ['PageController', 'addLabel'],
        '#^/pages/(\d+)/labels/(\d+)/delete$#' => ['PageController', 'removeLabel'],
        '#^/pages/(\d+)/attachments$#'      => ['PageController', 'uploadAttachment'],
        '#^/pages/(\d+)/restrictions$#'     => ['PageController', 'addRestriction'],
        '#^/pages/(\d+)/restrictions/(\d+)/delete$#' => ['PageController', 'removeRestriction'],
        '#^/pages/(\d+)/move$#'             => ['PageController', 'move'],
        '#^/pages/(\d+)/reorder$#'          => ['PageController', 'reorder'],
        '#^/attachments/(\d+)/delete$#'     => ['AttachmentController', 'delete'],
        '#^/comments/(\d+)/resolve$#'       => ['CommentController', 'resolve'],
        '#^/comments/(\d+)/reopen$#'        => ['CommentController', 'reopen'],
        '#^/comments/(\d+)/delete$#'        => ['CommentController', 'delete'],
        '#^/documents/(\d+)/edit$#'          => ['DocumentController', 'update'],
        '#^/documents/(\d+)/delete$#'        => ['DocumentController', 'delete'],
        '#^/documents/(\d+)/transition$#'    => ['DocumentController', 'transition'],
        '#^/documents/(\d+)/checkout$#'      => ['DocumentController', 'checkout'],
        '#^/documents/(\d+)/checkin$#'       => ['DocumentController', 'checkin'],
        '#^/documents/(\d+)/acknowledge$#'   => ['DocumentController', 'acknowledge'],
        '#^/documents/(\d+)/revise$#'        => ['DocumentController', 'revise'],
        '#^/documents/(\d+)/comment$#'       => ['DocumentController', 'comment'],
        '#^/processes/(\d+)/edit$#'          => ['ProcessController', 'update'],
        '#^/processes/(\d+)/delete$#'        => ['ProcessController', 'delete'],
        '#^/workflows/(\d+)/delete$#'        => ['WorkflowController', 'delete'],
        '#^/workflows/(\d+)/reactivate$#'    => ['WorkflowController', 'reactivate'],
        '#^/workflows/(\d+)/edit$#'          => ['WorkflowController', 'update'],
        '#^/workflows/(\d+)/steps$#'         => ['WorkflowController', 'addStep'],
        '#^/workflows/(\d+)/steps/(\d+)/update$#' => ['WorkflowController', 'updateStep'],
        '#^/workflows/(\d+)/steps/(\d+)/delete$#' => ['WorkflowController', 'deleteStep'],
        '#^/workflows/(\d+)/layout$#'             => ['WorkflowController', 'saveLayout'],
        '#^/workflows/(\d+)/states$#'             => ['WorkflowController', 'addState'],
        '#^/workflows/(\d+)/states/(\d+)/update$#' => ['WorkflowController', 'updateState'],
        '#^/workflows/(\d+)/states/(\d+)/delete$#' => ['WorkflowController', 'deleteState'],
        '#^/workflows/(\d+)/transitions$#'        => ['WorkflowController', 'addTransition'],
        '#^/workflows/(\d+)/transitions/(\d+)/delete$#' => ['WorkflowController', 'deleteTransition'],
        '#^/workflows/(\d+)/spaces$#'             => ['WorkflowController', 'assignSpace'],
        '#^/workflows/(\d+)/spaces/(\d+)/unassign$#' => ['WorkflowController', 'unassignSpace'],
        '#^/admin/tags/(\d+)/update$#'       => ['AdminController', 'updateTag'],
        '#^/admin/tags/(\d+)/delete$#'       => ['AdminController', 'deleteTag'],
        '#^/approvals/(\d+)/decide$#'        => ['ApprovalController', 'decide'],
        '#^/approvals/(\d+)/cancel$#'        => ['ApprovalController', 'cancel'],
        '#^/tasks/(\d+)/edit$#'              => ['TaskController', 'update'],
        '#^/tasks/(\d+)/complete$#'          => ['TaskController', 'complete'],
        '#^/blog/(\d+)/edit$#'               => ['BlogController', 'update'],
        '#^/blog/(\d+)/delete$#'             => ['BlogController', 'delete'],
        '#^/blog/(\d+)/comment$#'            => ['BlogController', 'comment'],
        '#^/templates/(\d+)/delete$#'        => ['TemplateController', 'delete'],
        '#^/admin/users/(\d+)/update$#'      => ['AdminController', 'updateUser'],
        '#^/admin/users/(\d+)/toggle$#'      => ['AdminController', 'toggleUser'],
        '#^/admin/sessions/(\d+)/revoke$#'   => ['AdminController', 'revokeSessions'],
        '#^/admin/shortcuts/(\d+)/delete$#'  => ['AdminController', 'deleteShortcut'],
        '#^/admin/webhooks/(\d+)/toggle$#'   => ['AdminController', 'toggleWebhook'],
        '#^/admin/webhooks/(\d+)/test$#'     => ['AdminController', 'testWebhook'],
        '#^/admin/webhooks/(\d+)/delete$#'   => ['AdminController', 'deleteWebhook'],
        '#^/admin/retention/(\d+)/toggle$#'  => ['AdminController', 'toggleRetention'],
        '#^/admin/retention/(\d+)/run$#'     => ['AdminController', 'runRetention'],
        '#^/admin/retention/(\d+)/delete$#'  => ['AdminController', 'deleteRetention'],
        '#^/profile/tokens/(\d+)/revoke$#'   => ['ProfileController', 'revokeToken'],
        '#^/campaigns/(\d+)/notify$#'        => ['CampaignController', 'notifyOutstanding'],
        '#^/campaigns/(\d+)/close$#'         => ['CampaignController', 'close'],
        '#^/campaigns/(\d+)/delete$#'        => ['CampaignController', 'delete'],
        '#^/admin/roles/(\d+)/edit$#'        => ['AdminController', 'updateRole'],
        '#^/admin/roles/(\d+)/delete$#'      => ['AdminController', 'deleteRole'],
    ],
];

function dispatch(string $controller, string $action, array $params = []): void {
    $file = PALADIN_ROOT . "/controllers/{$controller}.php";
    if (!file_exists($file)) { http_response_code(404); die('Controller not found'); }
    require_once $file;
    $ctrl = new $controller();
    if (!method_exists($ctrl, $action)) { http_response_code(404); die('Action not found'); }
    if ($params) {
        $refParams = (new ReflectionMethod($ctrl, $action))->getParameters();
        foreach ($params as $i => &$p) {
            $type = ($refParams[$i] ?? null)?->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                settype($p, $type->getName());
            }
        }
        unset($p);
    }
    $ctrl->$action(...$params);
}

// Track active session (for admin session management)
if (!empty($_SESSION['user']['id'])) {
    try {
        Database::query(
            "INSERT INTO active_sessions (id, user_id, ip_address, user_agent, last_seen_at)
             VALUES (?,?,?,?,NOW())
             ON CONFLICT (id) DO UPDATE SET last_seen_at=NOW(), ip_address=EXCLUDED.ip_address",
            [session_id(), (int)$_SESSION['user']['id'], Security::clientIp(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]
        );
    } catch (Throwable) {}
}

if (isset($routes[$method][$uri])) {
    [$controller, $action] = $routes[$method][$uri];
    dispatch($controller, $action);
    exit;
}
foreach ($dynamicRoutes[$method] ?? [] as $pattern => [$controller, $action]) {
    if (preg_match($pattern, $uri, $matches)) {
        array_shift($matches);
        dispatch($controller, $action, $matches);
        exit;
    }
}

http_response_code(404);
require PALADIN_ROOT . '/views/errors/404.php';
