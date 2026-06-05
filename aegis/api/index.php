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
    echo json_encode(['success' => false, 'error' => $message, 'meta' => ['timestamp' => date('c')]]);
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
        => apiResponse(Database::fetchAll("SELECT cp.*, s.name as standard_name, s.code FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.is_active = TRUE ORDER BY cp.name")),

    $method === 'GET' && preg_match('#^/compliance/packages/(\d+)$#', $uri, $m)
        => apiResponse(Database::fetchOne("SELECT cp.*, s.name as standard_name FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.id = ?", [(int)$m[1]]) ?? apiError('Not found', 404)),

    $method === 'GET' && preg_match('#^/compliance/packages/(\d+)/objectives$#', $uri, $m)
        => apiResponse(Database::fetchAll("SELECT co.*, ci.status as implementation_status FROM compliance_objectives co LEFT JOIN control_implementations ci ON ci.objective_id = co.id WHERE co.package_id = ? ORDER BY co.sort_order", [(int)$m[1]])),

    // Standards
    $method === 'GET' && $uri === '/standards'
        => apiResponse(Database::fetchAll("SELECT * FROM standards WHERE is_active = TRUE ORDER BY name")),

    // Risks
    $method === 'GET' && $uri === '/risks'
        => apiResponse(Database::fetchAll("SELECT r.*, rc.name as category FROM risks r LEFT JOIN risk_categories rc ON rc.id = r.category_id ORDER BY r.inherent_score DESC")),

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
        => apiResponse(Database::fetchAll("SELECT p.*, u.name as owner FROM policies p LEFT JOIN users u ON u.id = p.owner_id ORDER BY p.updated_at DESC")),

    // Audits
    $method === 'GET' && $uri === '/audits'
        => apiResponse(Database::fetchAll("SELECT a.*, cp.name as package FROM audits a LEFT JOIN compliance_packages cp ON cp.id = a.package_id ORDER BY a.scheduled_date DESC")),

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
        => apiResponse(Database::fetchAll("SELECT id, name, email, role, department, is_active, created_at FROM users ORDER BY name")),

    default => apiError("Endpoint not found", 404),
};
