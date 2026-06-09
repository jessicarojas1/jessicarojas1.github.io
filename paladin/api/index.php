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
    $patUser = $apiKey !== '' ? Security::validatePersonalToken($apiKey) : null;
    if ($patUser !== null) {
        $principal = ['type' => 'token', 'user' => $patUser];
    } elseif ($apiKey !== '' && Security::validateApiKey($apiKey)) {
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

// ── Write-capability helpers ────────────────────────────────────────────────
// A token may write only if its scopes include 'write'; a session user must
// hold the relevant create permission; a legacy admin-issued api_key may write.
$canWrite = static function () use ($principal): bool {
    if ($principal['type'] === 'token') {
        $scopes = array_map('trim', explode(',', (string)($principal['user']['scopes'] ?? '')));
        return in_array('write', $scopes, true);
    }
    if ($principal['type'] === 'session') {
        return Auth::can('document.create') || Auth::can('page.create');
    }
    return $principal['type'] === 'api_key'; // privileged server key
};
$actorId = static function () use ($principal): ?int {
    if (in_array($principal['type'], ['session', 'token'], true) && $principal['user']) {
        $u = $principal['user'];
        return isset($u['id']) ? (int)$u['id'] : (isset($u['user_id']) ? (int)$u['user_id'] : null);
    }
    return null;
};
$jsonBody = static function (): array {
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
};

try {
    // ── WRITE: POST /v1/documents ────────────────────────────────────────────
    if ($segments === ['v1', 'documents'] && $method === 'POST') {
        if (!$canWrite()) { $sendError('Forbidden — write scope required', 403); }
        $b = $jsonBody();
        $title = trim((string)($b['title'] ?? ''));
        if ($title === '') { $sendError('title is required', 422); }
        $docType = (string)($b['doc_type'] ?? 'policy');
        $allowedTypes = ['policy','procedure','process','standard','guideline','work_instruction','plan','form','template','record','evidence','training'];
        if (!in_array($docType, $allowedTypes, true)) { $docType = 'policy'; }
        $code = DocNumbering::next($docType);
        $id = Database::insert('documents', [
            'document_code' => $code,
            'title'         => Security::sanitizeInput($title),
            'doc_type'      => $docType,
            'space_id'      => !empty($b['space_id']) ? (int)$b['space_id'] : null,
            'description'   => isset($b['description']) ? Security::sanitizeInput((string)$b['description']) : null,
            'body'          => isset($b['body']) ? Security::sanitizeHtml((string)$b['body']) : null,
            'status'        => 'draft',
            'revision'      => '1.0',
            'owner_id'      => $actorId(),
            'created_by'    => $actorId(),
        ]);
        $doc = Database::fetchOne("SELECT {$DOC_LIST_FIELDS}, description FROM documents WHERE id = ?", [$id]);
        $sendJson($doc, 201);
    }

    // ── WRITE: PATCH/PUT /v1/documents/{id} (metadata only) ──────────────────
    if (($segments[0] ?? '') === 'v1' && ($segments[1] ?? '') === 'documents'
        && isset($segments[2]) && $segments[2] !== '' && in_array($method, ['PATCH', 'PUT'], true)) {
        if (!$canWrite()) { $sendError('Forbidden — write scope required', 403); }
        $id  = (int)$segments[2];
        $doc = Database::fetchOne("SELECT id FROM documents WHERE id = ?", [$id]);
        if (!$doc) { $sendError('Not found', 404); }
        $b = $jsonBody();
        $data = [];
        if (isset($b['title']))           { $data['title']           = Security::sanitizeInput((string)$b['title']); }
        if (isset($b['description']))     { $data['description']     = Security::sanitizeInput((string)$b['description']); }
        if (isset($b['body']))            { $data['body']            = Security::sanitizeHtml((string)$b['body']); }
        if (array_key_exists('review_date', $b))     { $data['review_date']     = $b['review_date'] ?: null; }
        if (array_key_exists('expiration_date', $b)) { $data['expiration_date'] = $b['expiration_date'] ?: null; }
        if (!$data) { $sendError('No updatable fields provided', 422); }
        Database::update('documents', $data, 'id = ?', [$id]);
        $sendJson(Database::fetchOne("SELECT {$DOC_LIST_FIELDS}, description FROM documents WHERE id = ?", [$id]));
    }

    // ── WRITE: POST /v1/pages ────────────────────────────────────────────────
    if ($segments === ['v1', 'pages'] && $method === 'POST') {
        if (!$canWrite()) { $sendError('Forbidden — write scope required', 403); }
        $b = $jsonBody();
        $spaceId = (int)($b['space_id'] ?? 0);
        $title   = trim((string)($b['title'] ?? ''));
        if (!$spaceId || $title === '') { $sendError('space_id and title are required', 422); }
        if (!Database::fetchOne("SELECT 1 FROM spaces WHERE id = ?", [$spaceId])) { $sendError('space not found', 422); }
        $status = in_array(($b['status'] ?? 'draft'), ['draft', 'published'], true) ? $b['status'] : 'draft';
        $body   = Security::sanitizeHtml((string)($b['body'] ?? ''));
        $id = Database::insert('pages', [
            'space_id'     => $spaceId,
            'title'        => Security::sanitizeInput($title),
            'slug'         => substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), 0, 200),
            'body'         => $body,
            'status'       => $status,
            'owner_id'     => $actorId(),
            'created_by'   => $actorId(),
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        Database::insert('page_versions', ['page_id' => $id, 'version' => 1, 'title' => $title, 'body' => $body, 'change_note' => 'Created via API', 'edited_by' => $actorId()]);
        $sendJson(Database::fetchOne("SELECT id, space_id, parent_id, title, slug, status, current_version, updated_at FROM pages WHERE id = ?", [$id]), 201);
    }

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
        if (in_array($principal['type'], ['session', 'token'], true) && $principal['user']) {
            $u = $principal['user'];
            $sendJson([
                'id'    => isset($u['id']) ? (int)$u['id'] : (isset($u['user_id']) ? (int)$u['user_id'] : null),
                'name'  => $u['name'] ?? null,
                'email' => $u['email'] ?? null,
                'role'  => $u['role'] ?? null,
                'auth'  => $principal['type'],
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

    // ── GET /v1/pages and /v1/pages/{id} ─────────────────────────────────────
    if (($segments[0] ?? '') === 'v1' && ($segments[1] ?? '') === 'pages') {
        $requireGet();
        $fields = 'id, space_id, parent_id, title, slug, status, current_version, updated_at';
        if (isset($segments[2]) && $segments[2] !== '') {
            $row = Database::fetchOne("SELECT {$fields} FROM pages WHERE id = ?", [(int)$segments[2]]);
            if (!$row) { $sendError('Not found', 404); }
            $sendJson($row);
        }
        $where = []; $params = [];
        if (isset($_GET['space_id']) && $_GET['space_id'] !== '') { $where[] = 'space_id = ?'; $params[] = (int)$_GET['space_id']; }
        if (isset($_GET['status'])   && $_GET['status']   !== '') { $where[] = 'status = ?';   $params[] = (string)$_GET['status']; }
        $sql = "SELECT {$fields} FROM pages";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY updated_at DESC LIMIT 200';
        $rows = Database::fetchAll($sql, $params);
        $sendJson(['data' => $rows, 'count' => count($rows)]);
    }

    // ── Fallthrough ────────────────────────────────────────────────────────
    $sendError('Not found', 404);

} catch (Throwable $e) {
    error_log('[PALADIN API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $sendError('Server error', 500);
}
