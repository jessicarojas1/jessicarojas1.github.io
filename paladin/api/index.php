<?php
/**
 * PALADIN — REST API v1 (self-contained JSON handler).
 *
 * Delegated to from the front controller (index.php) for any '/api/...' path.
 * Core classes (Database, Security, Auth, Branding) are ALREADY loaded and the
 * session is ALREADY started by the time this file runs — do NOT re-require them
 * or call session_start() again. PALADIN_ROOT is defined.
 *
 * Read-only JSON endpoints. Authentication via API key (Bearer / X-API-Key) OR
 * an active session. All SQL is parameterized; no internal storage paths or
 * secrets are ever exposed.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-API-Version: v1');

// ── JSON response helpers ──────────────────────────────────────────────────
$sendJson = static function (mixed $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$sendError = static function (string $message, int $status) use ($sendJson): never {
    $sendJson(['error' => $message], $status);
};

// ── Read request headers robustly ──────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hk => $hv) {
        if (strcasecmp($hk, 'Authorization') === 0) { $authHeader = $hv; break; }
    }
}
$apiKey = '';
if (stripos($authHeader, 'Bearer ') === 0) {
    $apiKey = trim(substr($authHeader, 7));
}
if ($apiKey === '' && !empty($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim((string)$_SERVER['HTTP_X_API_KEY']);
}

// ── Authentication ─────────────────────────────────────────────────────────
$principal = null; // ['type' => 'session'|'api_key', 'user' => ?array]
try {
    if ($apiKey !== '' && Security::validateApiKey($apiKey)) {
        $principal = ['type' => 'api_key', 'user' => null];
    } elseif (Auth::check()) {
        $principal = ['type' => 'session', 'user' => Auth::user()];
    }
} catch (Throwable $e) {
    error_log('[PALADIN API] auth error: ' . $e->getMessage());
    $sendError('Server error', 500);
}

if ($principal === null) {
    $sendError('Unauthorized', 401);
}

// ── Rate limiting ──────────────────────────────────────────────────────────
try {
    if (!Security::checkRateLimit('api_' . Security::clientIp())) {
        $sendError('Rate limit exceeded', 429);
    }
} catch (Throwable $e) {
    error_log('[PALADIN API] rate limit error: ' . $e->getMessage());
    $sendError('Server error', 500);
}

// ── Routing ────────────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Strip everything up to and including the first '/api/'.
$pos  = strpos($uriPath, '/api/');
$path = $pos !== false ? substr($uriPath, $pos + 5) : '';
$path = trim($path, '/'); // tolerate leading/trailing slashes
$segments = $path === '' ? [] : explode('/', $path);

// Only GET is supported for the data endpoints below.
$requireGet = static function () use ($method, $sendError): void {
    if ($method !== 'GET') {
        $sendError('Method not allowed', 405);
    }
};

// Safe document field projection (no storage paths, hashes, or secrets).
$DOC_LIST_FIELDS =
    'id, document_code, title, doc_type, status, classification, revision, ' .
    'space_id, owner_id, review_date, expiration_date, updated_at';

try {
    // ── /v1/health ─────────────────────────────────────────────────────────
    if ($segments === ['v1', 'health']) {
        $requireGet();
        $sendJson([
            'status'  => 'ok',
            'service' => 'paladin',
            'version' => '1.0.0',
            'time'    => date('c'),
        ]);
    }

    // ── /v1/me ─────────────────────────────────────────────────────────────
    if ($segments === ['v1', 'me']) {
        $requireGet();
        if ($principal['type'] === 'session' && $principal['user']) {
            $u = $principal['user'];
            $sendJson([
                'id'    => isset($u['id']) ? (int)$u['id'] : null,
                'name'  => $u['name'] ?? null,
                'email' => $u['email'] ?? null,
                'role'  => $u['role'] ?? null,
            ]);
        }
        $sendJson(['auth' => 'api_key']);
    }

    // ── /v1/documents and /v1/documents/{id} ───────────────────────────────
    if (($segments[0] ?? '') === 'v1' && ($segments[1] ?? '') === 'documents') {
        $requireGet();

        // Single document
        if (isset($segments[2]) && $segments[2] !== '') {
            $id  = (int)$segments[2];
            $doc = Database::fetchOne(
                "SELECT {$DOC_LIST_FIELDS}, description FROM documents WHERE id = ?",
                [$id]
            );
            if (!$doc) {
                $sendError('Not found', 404);
            }
            $sendJson($doc);
        }

        // List
        $where  = [];
        $params = [];
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = (string)$_GET['status'];
        }
        if (isset($_GET['type']) && $_GET['type'] !== '') {
            $where[] = 'doc_type = ?';
            $params[] = (string)$_GET['type'];
        }
        if (isset($_GET['q']) && $_GET['q'] !== '') {
            $where[] = '(title ILIKE ? OR document_code ILIKE ?)';
            $like = '%' . $_GET['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        if ($limit < 1) { $limit = 50; }
        if ($limit > 200) { $limit = 200; }

        $sql = "SELECT {$DOC_LIST_FIELDS} FROM documents";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT ' . $limit;
        $rows = Database::fetchAll($sql, $params);
        $sendJson(['data' => $rows, 'count' => count($rows)]);
    }

    // ── /v1/spaces and /v1/spaces/{id} ─────────────────────────────────────
    if (($segments[0] ?? '') === 'v1' && ($segments[1] ?? '') === 'spaces') {
        $requireGet();
        $fields = 'id, space_key, name, type, owner_id, is_private, is_archived';

        if (isset($segments[2]) && $segments[2] !== '') {
            $id  = (int)$segments[2];
            $row = Database::fetchOne(
                "SELECT {$fields} FROM spaces WHERE id = ?",
                [$id]
            );
            if (!$row) {
                $sendError('Not found', 404);
            }
            $sendJson($row);
        }

        $rows = Database::fetchAll(
            "SELECT {$fields} FROM spaces WHERE is_archived = FALSE ORDER BY name ASC"
        );
        $sendJson(['data' => $rows, 'count' => count($rows)]);
    }

    // ── /v1/processes ──────────────────────────────────────────────────────
    if ($segments === ['v1', 'processes']) {
        $requireGet();
        $rows = Database::fetchAll(
            "SELECT id, process_code, name, status, version, owner_id, space_id, updated_at
             FROM processes ORDER BY updated_at DESC LIMIT 200"
        );
        $sendJson(['data' => $rows, 'count' => count($rows)]);
    }

    // ── /v1/tasks ──────────────────────────────────────────────────────────
    if ($segments === ['v1', 'tasks']) {
        $requireGet();
        $where  = [];
        $params = [];
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = (string)$_GET['status'];
        }
        if (isset($_GET['assigned_to']) && $_GET['assigned_to'] !== '') {
            $where[] = 'assigned_to = ?';
            $params[] = (int)$_GET['assigned_to'];
        }
        $sql = "SELECT id, title, type, status, priority, assigned_to, due_date FROM tasks";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        $rows = Database::fetchAll($sql, $params);
        $sendJson(['data' => $rows, 'count' => count($rows)]);
    }

    // ── /v1/approvals ──────────────────────────────────────────────────────
    if ($segments === ['v1', 'approvals']) {
        $requireGet();
        $rows = Database::fetchAll(
            "SELECT id, title, entity_type, entity_id, status, current_step, requested_by, due_at
             FROM approval_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 200"
        );
        $sendJson(['data' => $rows, 'count' => count($rows)]);
    }

    // ── Fallthrough ────────────────────────────────────────────────────────
    $sendError('Not found', 404);

} catch (Throwable $e) {
    error_log('[PALADIN API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $sendError('Server error', 500);
}
