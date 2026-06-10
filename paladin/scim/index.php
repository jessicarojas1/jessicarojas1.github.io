<?php
/**
 * PALADIN — SCIM 2.0 user provisioning (RFC 7643/7644 subset).
 *
 * Delegated to from the front controller for any '/scim/...' path. Core classes
 * (Database, Security, Auth) are already loaded and the session is already
 * started. Lets an IdP (Okta, Entra ID, OneLogin…) provision, update and
 * deprovision PALADIN accounts.
 *
 * Auth: Bearer <token> matching the configured SCIM token. Maps a SCIM User to
 * the users table (userName/emails → email, displayName/name → name, active →
 * is_active). DELETE deactivates (records are retained for audit integrity).
 */

declare(strict_types=1);

header('Content-Type: application/scim+json');

$out = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};
$err = static function (string $detail, int $status) use ($out): never {
    $out(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'detail' => $detail, 'status' => (string)$status], $status);
};

// ── Config + bearer auth ────────────────────────────────────────────────────
$enabled = false; $token = '';
try {
    foreach (Database::fetchAll("SELECT key, value FROM settings WHERE key IN ('scim_enabled','scim_token')") as $r) {
        if ($r['key'] === 'scim_enabled') { $enabled = $r['value'] === '1'; }
        if ($r['key'] === 'scim_token')   { $token = $r['value'] !== '' ? Security::decryptSetting($r['value']) : ''; }
    }
} catch (Throwable) {}

if (!$enabled || $token === '') { $err('SCIM is not enabled', 404); }

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $hk => $hv) { if (strcasecmp($hk, 'Authorization') === 0) { $authHeader = $hv; break; } }
}
$bearer = stripos($authHeader, 'Bearer ') === 0 ? trim(substr($authHeader, 7)) : '';
if ($bearer === '' || !hash_equals($token, $bearer)) { $err('Unauthorized', 401); }

// ── Routing ─────────────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$pos = strpos($uriPath, '/scim/');
$path = $pos !== false ? trim(substr($uriPath, $pos + 6), '/') : '';
$seg = $path === '' ? [] : explode('/', $path);

$base = (function (): string {
    $app = rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
    if ($app !== '') return $app;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
})();

$defaultRole = 'viewer';
try {
    $r = Database::fetchOne("SELECT value FROM settings WHERE key='scim_default_role'");
    if ($r && in_array($r['value'], ['viewer','contributor','approver','admin'], true)) { $defaultRole = $r['value']; }
} catch (Throwable) {}

$body = static function (): array {
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
};

$toScim = static function (array $u) use ($base): array {
    return [
        'schemas'     => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'id'          => (string)$u['id'],
        'userName'    => $u['email'],
        'name'        => ['formatted' => $u['name']],
        'displayName' => $u['name'],
        'emails'      => [['value' => $u['email'], 'primary' => true]],
        'active'      => (bool)(($u['is_active'] === true) || ($u['is_active'] === 't') || ($u['is_active'] === '1') || ($u['is_active'] === 1)),
        'meta'        => [
            'resourceType' => 'User',
            'created'      => isset($u['created_at']) ? date('c', strtotime((string)$u['created_at'])) : null,
            'lastModified' => isset($u['updated_at']) ? date('c', strtotime((string)$u['updated_at'])) : null,
            'location'     => $base . '/scim/v2/Users/' . $u['id'],
        ],
    ];
};

// Extract email + name from a SCIM User payload.
$readUser = static function (array $b): array {
    $email = strtolower(trim((string)($b['userName'] ?? '')));
    if ($email === '' && !empty($b['emails'][0]['value'])) { $email = strtolower(trim((string)$b['emails'][0]['value'])); }
    $name = trim((string)($b['displayName'] ?? ''));
    if ($name === '' && isset($b['name'])) {
        $name = trim((string)($b['name']['formatted'] ?? trim((($b['name']['givenName'] ?? '') . ' ' . ($b['name']['familyName'] ?? '')))));
    }
    $active = array_key_exists('active', $b) ? filter_var($b['active'], FILTER_VALIDATE_BOOLEAN) : true;
    return ['email' => $email, 'name' => $name, 'active' => $active];
};

try {
    // GET /scim/v2/ServiceProviderConfig
    if ($seg === ['v2', 'ServiceProviderConfig'] && $method === 'GET') {
        $out([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch'   => ['supported' => true],
            'bulk'    => ['supported' => false],
            'filter'  => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort'    => ['supported' => false],
            'etag'    => ['supported' => false],
            'authenticationSchemes' => [['name' => 'OAuth Bearer Token', 'type' => 'oauthbearertoken', 'primary' => true]],
        ]);
    }

    // /scim/v2/Users
    if (($seg[0] ?? '') === 'v2' && ($seg[1] ?? '') === 'Users') {
        $id = isset($seg[2]) && $seg[2] !== '' ? (int)$seg[2] : 0;

        // LIST / FILTER
        if ($id === 0 && $method === 'GET') {
            $where = '1=1'; $params = [];
            if (!empty($_GET['filter']) && preg_match('/userName\s+eq\s+"([^"]+)"/i', (string)$_GET['filter'], $m)) {
                $where = 'LOWER(email) = ?'; $params[] = strtolower($m[1]);
            }
            $start = max(1, (int)($_GET['startIndex'] ?? 1));
            $count = min(200, max(0, (int)($_GET['count'] ?? 100)));
            $total = (int)(Database::fetchOne("SELECT COUNT(*) c FROM users WHERE {$where}", $params)['c'] ?? 0);
            $rows = $count > 0 ? Database::fetchAll(
                "SELECT * FROM users WHERE {$where} ORDER BY id LIMIT {$count} OFFSET " . ($start - 1), $params
            ) : [];
            $out([
                'schemas'      => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
                'totalResults' => $total,
                'startIndex'   => $start,
                'itemsPerPage' => count($rows),
                'Resources'    => array_map($toScim, $rows),
            ]);
        }

        // CREATE
        if ($id === 0 && $method === 'POST') {
            $u = $readUser($body());
            if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) { $err('userName must be a valid email', 400); }
            $existing = Database::fetchOne("SELECT * FROM users WHERE LOWER(email) = ?", [$u['email']]);
            if ($existing) {
                // SCIM uniqueness conflict.
                $err('User already exists', 409);
            }
            $newId = Database::insert('users', [
                'name'          => $u['name'] !== '' ? $u['name'] : explode('@', $u['email'])[0],
                'email'         => $u['email'],
                'password_hash' => Security::hashPassword(bin2hex(random_bytes(24))),
                'role'          => $defaultRole,
                'is_active'     => $u['active'] ? 1 : 0,
            ]);
            Auth::log('scim_create_user', 'users', $newId, ['email' => $u['email']]);
            $out($toScim(Database::fetchOne("SELECT * FROM users WHERE id = ?", [$newId])), 201);
        }

        // GET ONE
        if ($id > 0 && $method === 'GET') {
            $u = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
            if (!$u) { $err('Not found', 404); }
            $out($toScim($u));
        }

        // REPLACE
        if ($id > 0 && $method === 'PUT') {
            $cur = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
            if (!$cur) { $err('Not found', 404); }
            $u = $readUser($body());
            $data = ['is_active' => $u['active'] ? 1 : 0];
            if ($u['name'] !== '')  { $data['name'] = $u['name']; }
            if ($u['email'] !== '' && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) { $data['email'] = $u['email']; }
            Database::update('users', $data, 'id = ?', [$id]);
            Auth::log('scim_update_user', 'users', $id);
            $out($toScim(Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id])));
        }

        // PATCH (Operations — commonly toggles active)
        if ($id > 0 && $method === 'PATCH') {
            $cur = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
            if (!$cur) { $err('Not found', 404); }
            $ops = $body()['Operations'] ?? $body()['operations'] ?? [];
            $data = [];
            foreach ($ops as $op) {
                $verb = strtolower((string)($op['op'] ?? ''));
                if (!in_array($verb, ['replace', 'add'], true)) { continue; }
                $pathAttr = $op['path'] ?? null;
                $val = $op['value'] ?? null;
                if ($pathAttr === 'active') { $data['is_active'] = filter_var($val, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
                elseif ($pathAttr === 'displayName' && is_string($val)) { $data['name'] = trim($val); }
                elseif ($pathAttr === null && is_array($val)) {
                    if (array_key_exists('active', $val)) { $data['is_active'] = filter_var($val['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
                    if (!empty($val['displayName'])) { $data['name'] = trim((string)$val['displayName']); }
                }
            }
            if ($data) {
                Database::update('users', $data, 'id = ?', [$id]);
                Auth::log('scim_patch_user', 'users', $id, $data);
            }
            $out($toScim(Database::fetchOne("SELECT * FROM users WHERE id = ?", [$id])));
        }

        // DELETE → deactivate (retain the row for audit integrity)
        if ($id > 0 && $method === 'DELETE') {
            $cur = Database::fetchOne("SELECT id FROM users WHERE id = ?", [$id]);
            if (!$cur) { $err('Not found', 404); }
            Database::update('users', ['is_active' => 0], 'id = ?', [$id]);
            Auth::log('scim_deprovision_user', 'users', $id);
            http_response_code(204); exit;
        }

        $err('Method not allowed', 405);
    }

    $err('Not found', 404);
} catch (Throwable $e) {
    error_log('[PALADIN SCIM] ' . $e->getMessage());
    $err('Server error', 500);
}
