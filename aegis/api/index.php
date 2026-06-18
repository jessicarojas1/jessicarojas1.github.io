<?php
header('Content-Type: application/json');
header_remove('X-Powered-By');
$_allowedOrigin = rtrim($_ENV['APP_URL'] ?? '', '/');
$_requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($_allowedOrigin && $_requestOrigin === $_allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $_requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function apiResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => $code < 400, 'data' => $data, 'meta' => ['timestamp' => date('c'), 'version' => 'v1']]);
    exit;
}

function apiError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error'   => $message,
        'meta'    => ['timestamp' => date('c'), 'request_id' => defined('AEGIS_REQUEST_ID') ? AEGIS_REQUEST_ID : null],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

/**
 * Paginated + sorted list response.
 *
 * Reads ?page, ?per_page, and ?sort from the query string. Sorting is restricted
 * to a per-endpoint allowlist of columns (prefix "-" = DESC) so ORDER BY never
 * contains untrusted input. $sql must NOT already contain ORDER BY / LIMIT.
 *
 * @param string   $sql       SELECT without ORDER BY/LIMIT
 * @param array    $params    bound parameters for $sql
 * @param string[] $sortable  allowlisted sort columns (as written in $sql)
 * @param string   $default   default ORDER BY clause (e.g. "cp.name ASC")
 */
function apiList(string $sql, array $params = [], array $sortable = [], string $default = ''): never {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $orderBy = $default;
    $sort    = (string)($_GET['sort'] ?? '');
    if ($sort !== '') {
        $dir = 'ASC';
        if ($sort[0] === '-') { $dir = 'DESC'; $sort = substr($sort, 1); }
        if (in_array($sort, $sortable, true)) {
            $orderBy = "{$sort} {$dir}";
        }
    }

    $total = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM ({$sql}) AS _sub", $params)['c'] ?? 0);

    $finalSql = $sql;
    if ($orderBy !== '') { $finalSql .= " ORDER BY {$orderBy}"; }
    $finalSql .= " LIMIT {$perPage} OFFSET {$offset}";
    $rows = Database::fetchAll($finalSql, $params);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'meta'    => [
            'timestamp'  => date('c'),
            'version'    => 'v1',
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
            ],
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// Auth via API key or JWT Bearer
function authenticateApi(): array {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $bearerToken = '';
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) $bearerToken = substr($auth, 7);

    if ($apiKey) {
        // Try HMAC-SHA256 (new keys) and plain SHA-256 (legacy keys)
        $hmacHash   = hash_hmac('sha256', $apiKey, $_ENV['JWT_SECRET'] ?? '');
        $legacyHash = hash('sha256', $apiKey);
        $key = Database::fetchOne(
            "SELECT ak.*, u.id as uid, u.role FROM api_keys ak JOIN users u ON u.id = ak.user_id
             WHERE ak.key_hash IN (?,?) AND ak.is_active = TRUE AND (ak.expires_at IS NULL OR ak.expires_at > NOW())",
            [$hmacHash, $legacyHash]
        );
        if (!$key) apiError('Invalid or expired API key', 401);
        // Silently upgrade legacy SHA-256 keys to HMAC on first use
        if ($key['key_hash'] === $legacyHash && $hmacHash !== $legacyHash) {
            Database::query("UPDATE api_keys SET key_hash=?, last_used=NOW() WHERE key_hash=?", [$hmacHash, $legacyHash]);
        } else {
            Database::query("UPDATE api_keys SET last_used=NOW() WHERE key_hash=?", [$hmacHash]);
        }
        return ['id' => $key['uid'], 'role' => $key['role'], 'permissions' => json_decode($key['permissions'] ?? '["read"]', true)];
    }

    if ($bearerToken) {
        $payload = JWT::verify($bearerToken);
        if (!$payload) apiError('Invalid or expired token', 401);
        $user = Database::fetchOne("SELECT id, role FROM users WHERE id = ? AND is_active = TRUE", [$payload['sub']]);
        if (!$user) apiError('User not found', 401);
        return ['id' => $user['id'], 'role' => $user['role'], 'permissions' => ['read', 'write']];
    }

    apiError('Authentication required', 401);
}

// Rate limit API
$ip = Security::clientIp();
$key = "api_{$ip}";
$row = Database::fetchOne("SELECT attempts, window_start FROM rate_limits WHERE key = ?", [$key]);
if ($row) {
    if (time() - strtotime($row['window_start']) < 60 && $row['attempts'] >= 60) {
        apiError('Rate limit exceeded. Max 60 requests/minute.', 429);
    }
    if (time() - strtotime($row['window_start']) >= 60) {
        Database::query("UPDATE rate_limits SET attempts=1, window_start=NOW() WHERE key=?", [$key]);
    } else {
        Database::query("UPDATE rate_limits SET attempts=attempts+1 WHERE key=?", [$key]);
    }
} else {
    Database::query("INSERT INTO rate_limits (key,attempts,window_start) VALUES (?,1,NOW()) ON CONFLICT (key) DO UPDATE SET attempts=rate_limits.attempts+1", [$key]);
}

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = preg_replace('#^/api/v1#', '', $uri);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// Public health probe (no auth) — liveness + database readiness.
// Reachable at /api/v1/health (stripped to /health) and /api/health.
if ($method === 'GET' && ($uri === '/health' || $uri === '/api/health')) {
    $dbOk = false;
    try { $dbOk = (Database::fetchOne('SELECT 1 AS ok')['ok'] ?? null) == 1; } catch (Throwable) {}
    apiResponse(['status' => $dbOk ? 'ok' : 'degraded', 'database' => $dbOk ? 'ok' : 'fail'], $dbOk ? 200 : 503);
}

// Scanner / SIEM ingestion — handled by dedicated file
if ($method === 'POST' && preg_match('#^/ingest/(tenable|qualys|wiz|generic)$#', $uri)) {
    require_once AEGIS_ROOT . '/api/ingest.php';
    exit;
}

// Public endpoint: token issue
if ($method === 'POST' && $uri === '/auth/token') {
    // Rate-limit the token endpoint by IP (same mechanism as web login)
    $loginRateLimitKey = 'login_' . ($ip);
    if (!Security::checkRateLimit($loginRateLimitKey)) {
        apiError('Too many authentication attempts. Please try again later.', 429);
    }
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $user = Database::fetchOne("SELECT * FROM users WHERE email = ? AND is_active = TRUE", [$email]);
    if (!$user || !Security::verifyPassword($password, $user['password_hash'])) {
        // Don't reset rate limit on failure — only on success
        apiError('Invalid credentials', 401);
    }
    // Require TOTP verification if user has MFA enabled (prevent MFA bypass via API)
    if (!empty($user['mfa_enabled']) && !empty($user['mfa_secret'])) {
        $totpCode = preg_replace('/\s/', '', $input['totp_code'] ?? '');
        require_once AEGIS_ROOT . '/src/TOTP.php';
        if (!$totpCode || !TOTP::verify($user['mfa_secret'], $totpCode)) {
            apiError('MFA code required for accounts with two-factor authentication enabled. Include totp_code in the request body.', 401);
        }
    }
    Security::resetRateLimit($loginRateLimitKey);
    // 1-hour JWT lifetime (not 24h) — clients must re-authenticate regularly
    $token = JWT::issue($user['id'], $user['role'], 3600);
    apiResponse(['token' => $token, 'expires_in' => 3600, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']]]);
}

$authUser = authenticateApi();
$canWrite = in_array('write', $authUser['permissions'] ?? []) || $authUser['role'] === 'admin';

// --- Routes ---
match (true) {
    // Compliance packages
    $method === 'GET' && $uri === '/compliance/packages'
        => apiList("SELECT cp.*, s.name as standard_name, s.code FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.is_active = TRUE",
                   [], ['cp.name', 'cp.created_at'], 'cp.name ASC'),

    $method === 'GET' && preg_match('#^/compliance/packages/(\d+)$#', $uri, $m)
        => apiResponse(Database::fetchOne("SELECT cp.*, s.name as standard_name FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.id = ?", [(int)$m[1]]) ?? apiError('Not found', 404)),

    $method === 'GET' && preg_match('#^/compliance/packages/(\d+)/objectives$#', $uri, $m)
        => apiResponse(Database::fetchAll("SELECT co.*, ci.status as implementation_status FROM compliance_objectives co LEFT JOIN control_implementations ci ON ci.objective_id = co.id WHERE co.package_id = ? ORDER BY co.sort_order", [(int)$m[1]])),

    // Standards
    $method === 'GET' && $uri === '/standards'
        => apiList("SELECT * FROM standards WHERE is_active = TRUE", [], ['name', 'created_at'], 'name ASC'),

    // Risks
    $method === 'GET' && $uri === '/risks'
        => apiList("SELECT r.*, rc.name as category FROM risks r LEFT JOIN risk_categories rc ON rc.id = r.category_id",
                   [], ['r.inherent_score', 'r.created_at', 'r.title', 'r.status'], 'r.inherent_score DESC'),

    $method === 'GET' && preg_match('#^/risks/(\d+)$#', $uri, $m)
        => apiResponse(Database::fetchOne("SELECT * FROM risks WHERE id = ?", [(int)$m[1]]) ?? apiError('Not found', 404)),

    $method === 'POST' && $uri === '/risks' && $canWrite
        => (function() use ($input, $authUser) {
            $id = Database::insert('risks', [
                'title'      => substr(strip_tags($input['title'] ?? ''), 0, 500),
                'description'=> substr(strip_tags($input['description'] ?? ''), 0, 5000),
                'likelihood' => max(1, min(5, (int)($input['likelihood'] ?? 3))),
                'impact'     => max(1, min(5, (int)($input['impact'] ?? 3))),
                'status'     => 'open',
                'owner_id'   => (int)($input['owner_id'] ?? $authUser['id']),
                'created_by' => $authUser['id'],
                'risk_id'    => 'RSK-API-' . time(),
            ]);
            apiResponse(Database::fetchOne("SELECT * FROM risks WHERE id=?", [$id]), 201);
        })(),

    // Policies
    $method === 'GET' && $uri === '/policies'
        => apiList("SELECT p.*, u.name as owner FROM policies p LEFT JOIN users u ON u.id = p.owner_id",
                   [], ['p.updated_at', 'p.title', 'p.status'], 'p.updated_at DESC'),

    // Audits
    $method === 'GET' && $uri === '/audits'
        => apiList("SELECT a.*, cp.name as package FROM audits a LEFT JOIN compliance_packages cp ON cp.id = a.package_id",
                   [], ['a.scheduled_date', 'a.status'], 'a.scheduled_date DESC'),

    // Controls status update
    $method === 'PUT' && preg_match('#^/compliance/objectives/(\d+)/status$#', $uri, $m) && $canWrite
        => (function() use ($input, $m, $authUser) {
            $objId  = (int)$m[1];
            $status = in_array($input['status'] ?? '', ['not_started','compliant','partial','non_compliant','not_applicable']) ? $input['status'] : 'not_started';
            $exists = Database::fetchOne("SELECT id FROM control_implementations WHERE objective_id=?", [$objId]);
            if ($exists) {
                Database::query("UPDATE control_implementations SET status=?, updated_at=NOW() WHERE objective_id=?", [$status, $objId]);
            } else {
                Database::insert('control_implementations', ['objective_id'=>$objId,'status'=>$status]);
            }
            apiResponse(['objective_id' => $objId, 'status' => $status]);
        })(),

    // Dashboard stats
    $method === 'GET' && $uri === '/dashboard/stats'
        => apiResponse([
            'compliance_packages' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM compliance_packages WHERE is_active=TRUE")['c'],
            'compliant_controls'  => (int)Database::fetchOne("SELECT COUNT(*) as c FROM control_implementations WHERE status='compliant'")['c'],
            'open_risks'          => (int)Database::fetchOne("SELECT COUNT(*) as c FROM risks WHERE status='open'")['c'],
            'published_policies'  => (int)Database::fetchOne("SELECT COUNT(*) as c FROM policies WHERE status='published'")['c'],
        ]),

    // Users (admin only)
    $method === 'GET' && $uri === '/users' && $authUser['role'] === 'admin'
        => apiList("SELECT id, name, email, role, department, is_active, created_at FROM users",
                   [], ['name', 'email', 'role', 'created_at'], 'name ASC'),

    default => apiError("Endpoint not found", 404),
};
