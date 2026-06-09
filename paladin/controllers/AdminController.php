<?php
declare(strict_types=1);

/**
 * AdminController — the Administration module for the PALADIN.
 * Every method requires the system `admin` role. POST actions validate CSRF
 * (savePermissions is AJAX and validates a JSON-body token instead).
 */
class AdminController {

    // ── Overview ───────────────────────────────────────────────────────────
    public function index(): void {
        Auth::requireAdmin();
        $stats = [
            'users'        => (int)(Database::fetchOne("SELECT COUNT(*) c FROM users")['c'] ?? 0),
            'active_users' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM users WHERE is_active=TRUE")['c'] ?? 0),
            'documents'    => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents")['c'] ?? 0),
            'spaces'       => (int)(Database::fetchOne("SELECT COUNT(*) c FROM spaces WHERE is_archived=FALSE")['c'] ?? 0),
            'workflows'    => (int)(Database::fetchOne("SELECT COUNT(*) c FROM workflow_templates WHERE is_active=TRUE")['c'] ?? 0),
            'audit_events' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM activity_log")['c'] ?? 0),
            'sessions'     => (int)(Database::fetchOne("SELECT COUNT(*) c FROM active_sessions WHERE last_seen_at > NOW() - INTERVAL '30 minutes'")['c'] ?? 0),
        ];
        require PALADIN_ROOT . '/views/admin/index.php';
    }

    // ── Users ──────────────────────────────────────────────────────────────
    public function users(): void {
        Auth::requireAdmin();
        $users = Database::fetchAll(
            "SELECT id, name, email, role, department, title, is_active, last_login
             FROM users ORDER BY name"
        );
        require PALADIN_ROOT . '/views/admin/users.php';
    }

    public function createUserForm(): void {
        Auth::requireAdmin();
        require PALADIN_ROOT . '/views/admin/user_form.php';
    }

    public function createUser(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name       = Security::sanitizeInput($_POST['name'] ?? '');
        $email      = strtolower(Security::sanitizeInput($_POST['email'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');
        $role       = Security::sanitizeInput($_POST['role'] ?? 'viewer');
        $department = Security::sanitizeInput($_POST['department'] ?? '');
        $title      = Security::sanitizeInput($_POST['title'] ?? '');
        $forcePw    = !empty($_POST['force_password_change']);

        if ($name === '' || $email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Name, email and password are required.';
            header('Location: /admin/users/create'); return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Enter a valid email address.';
            header('Location: /admin/users/create'); return;
        }
        if (Database::fetchOne("SELECT 1 FROM users WHERE email = ?", [$email])) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            header('Location: /admin/users/create'); return;
        }
        if (!in_array($role, Auth::roleKeys(), true)) $role = 'viewer';

        $pwErrors = Security::validatePasswordPolicy($password);
        if ($pwErrors) {
            $_SESSION['flash_error'] = implode(' ', $pwErrors);
            header('Location: /admin/users/create'); return;
        }

        $id = Database::insert('users', [
            'name'                  => $name,
            'email'                 => $email,
            'password_hash'         => Security::hashPassword($password),
            'role'                  => $role,
            'department'            => $department ?: null,
            'title'                 => $title ?: null,
            'is_active'             => 't',
            'force_password_change' => $forcePw ? 't' : 'f',
            'password_changed_at'   => date('Y-m-d H:i:s'),
        ]);
        Auth::log('create_user', 'users', $id);
        $_SESSION['flash_success'] = 'User created.';
        header('Location: /admin/users');
    }

    public function updateUser(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $user = Database::fetchOne("SELECT id, email FROM users WHERE id = ?", [$id]);
        if (!$user) { http_response_code(404); return; }

        $name       = Security::sanitizeInput($_POST['name'] ?? '');
        $email      = strtolower(Security::sanitizeInput($_POST['email'] ?? ''));
        $role       = Security::sanitizeInput($_POST['role'] ?? 'viewer');
        $department = Security::sanitizeInput($_POST['department'] ?? '');
        $title      = Security::sanitizeInput($_POST['title'] ?? '');

        if ($name === '' || $email === '') {
            $_SESSION['flash_error'] = 'Name and email are required.';
            header('Location: /admin/users'); return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Enter a valid email address.';
            header('Location: /admin/users'); return;
        }
        if ($email !== strtolower((string)$user['email'])
            && Database::fetchOne("SELECT 1 FROM users WHERE email = ? AND id <> ?", [$email, $id])) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            header('Location: /admin/users'); return;
        }
        if (!in_array($role, Auth::roleKeys(), true)) $role = 'viewer';

        Database::update('users', [
            'name'       => $name,
            'email'      => $email,
            'role'       => $role,
            'department' => $department ?: null,
            'title'      => $title ?: null,
        ], 'id=?', [$id]);
        Auth::log('update_user', 'users', $id);
        $_SESSION['flash_success'] = 'User updated.';
        header('Location: /admin/users');
    }

    public function toggleUser(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if ($id === Auth::id()) {
            $_SESSION['flash_error'] = 'You cannot deactivate your own account.';
            header('Location: /admin/users'); return;
        }
        $user = Database::fetchOne("SELECT id, is_active FROM users WHERE id = ?", [$id]);
        if (!$user) { http_response_code(404); return; }

        $active = !($user['is_active'] === true || $user['is_active'] === 't' || $user['is_active'] === '1' || $user['is_active'] === 1);
        $data = ['is_active' => $active ? 't' : 'f'];
        if (!$active) $data['sessions_revoked_at'] = date('Y-m-d H:i:s');
        Database::update('users', $data, 'id=?', [$id]);
        Auth::log($active ? 'activate_user' : 'deactivate_user', 'users', $id);
        $_SESSION['flash_success'] = $active ? 'User activated.' : 'User deactivated.';
        header('Location: /admin/users');
    }

    // ── Permissions (two-pane IAM editor) ───────────────────────────────────
    public function permissions(int $userId = 0): void {
        Auth::requireAdmin();
        $users = Database::fetchAll("SELECT id, name, email, role, department FROM users ORDER BY name");
        if (!$users) { require PALADIN_ROOT . '/views/admin/permissions.php'; return; }

        $selectedId = $userId ?: (int)$users[0]['id'];
        $selected = null;
        foreach ($users as $u) { if ((int)$u['id'] === $selectedId) { $selected = $u; break; } }
        if (!$selected) { $selected = $users[0]; $selectedId = (int)$selected['id']; }

        $rows = Database::fetchAll("SELECT module, permission FROM user_permissions WHERE user_id = ?", [$selectedId]);
        $explicit = [];
        foreach ($rows as $r) $explicit[] = $r['module'] . '.' . $r['permission'];

        $catalog  = Auth::moduleCatalog();
        $defaults = Auth::roleDefaults((string)$selected['role']);

        require PALADIN_ROOT . '/views/admin/permissions.php';
    }

    public function savePermissions(): void {
        Auth::requireAdmin();
        header('Content-Type: application/json');

        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) $payload = $_POST;

        $token = (string)($payload['csrf_token'] ?? '');
        if (!Security::validateCsrf($token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'csrf']);
            exit;
        }

        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0 || !Database::fetchOne("SELECT 1 FROM users WHERE id = ?", [$userId])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'user']);
            exit;
        }

        $perms   = is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];
        $catalog = Auth::moduleCatalog();
        $valid   = [];
        foreach ($perms as $p) {
            $p = (string)$p;
            $parts = explode('.', $p, 2);
            if (count($parts) !== 2) continue;
            [$module, $action] = $parts;
            if (isset($catalog[$module]) && in_array($action, $catalog[$module], true)) {
                $valid[$module . '.' . $action] = [$module, $action];
            }
        }

        Database::query("DELETE FROM user_permissions WHERE user_id = ?", [$userId]);
        foreach ($valid as [$module, $action]) {
            Database::insert('user_permissions', [
                'user_id'    => $userId,
                'module'     => $module,
                'permission' => $action,
                'granted_by' => Auth::id(),
            ]);
        }
        Auth::log('update_permissions', 'users', $userId);

        echo json_encode(['ok' => true, 'csrf' => Security::generateCsrfToken()]);
        exit;
    }

    // ── Branding ─────────────────────────────────────────────────────────────
    public function branding(): void {
        Auth::requireAdmin();
        require PALADIN_ROOT . '/views/admin/branding.php';
    }

    public function saveBranding(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $orgName = Security::sanitizeInput($_POST['org_name'] ?? '');
        $this->setSetting('org_name', $orgName);

        $accent = Branding::sanitizeColor((string)($_POST['brand_accent'] ?? ''));
        if ($accent !== '') $this->setSetting('brand_accent', $accent);

        $removeLogo = !empty($_POST['remove_logo']);
        if ($removeLogo) {
            $this->setSetting('company_logo_data', '');
            $this->setSetting('company_logo_name', '');
        } elseif (!empty($_FILES['logo_file']['name']) && (int)($_FILES['logo_file']['error'] ?? 0) === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['logo_file']['tmp_name'];
            $info = @getimagesize($tmp);
            $mime = $info['mime'] ?? '';
            if ($info && is_string($mime) && str_starts_with($mime, 'image/')) {
                $contents = (string)file_get_contents($tmp);
                $dataUri  = 'data:' . $mime . ';base64,' . base64_encode($contents);
                $this->setSetting('company_logo_data', $dataUri);
                $this->setSetting('company_logo_name', Security::sanitizeInput((string)$_FILES['logo_file']['name']));
            } else {
                $_SESSION['flash_error'] = 'Uploaded logo is not a valid image.';
                header('Location: /admin/branding'); return;
            }
        } else {
            $logoUrl = Branding::sanitizeLogo((string)($_POST['logo_url'] ?? ''));
            if ($logoUrl !== '') {
                $this->setSetting('company_logo_data', $logoUrl);
                $this->setSetting('company_logo_name', '');
            }
        }

        Branding::clearCache();
        Auth::log('update_branding', 'settings', null);
        $_SESSION['flash_success'] = 'Branding updated.';
        header('Location: /admin/branding');
    }

    // ── Settings ─────────────────────────────────────────────────────────────
    public function settings(): void {
        Auth::requireAdmin();
        $rows = Database::fetchAll("SELECT key, value FROM settings");
        $settings = [];
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
        require PALADIN_ROOT . '/views/admin/settings.php';
    }

    public function saveSettings(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $plain = [
            'date_format', 'timezone',
            'upload_max_size_mb', 'upload_allowed_types',
            'password_min_length',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_from', 'smtp_from_name',
            'storage_driver', 's3_bucket', 's3_region', 's3_access_key', 's3_endpoint', 's3_public_url',
        ];
        foreach ($plain as $key) {
            $this->setSetting($key, Security::sanitizeInput($_POST[$key] ?? ''));
        }

        // Boolean checkboxes stored as '0'/'1'
        $bools = ['password_require_uppercase', 'password_require_numbers', 'password_require_special', 'email_notifications'];
        foreach ($bools as $key) {
            $this->setSetting($key, !empty($_POST[$key]) ? '1' : '0');
        }

        // Secrets: only update when a non-empty value is submitted; encrypt at rest.
        foreach (['smtp_pass', 's3_secret_key'] as $key) {
            $val = (string)($_POST[$key] ?? '');
            if ($val !== '') {
                $this->setSetting($key, Security::encryptSetting($val));
            }
        }

        Auth::log('update_settings', 'settings', null);
        $_SESSION['flash_success'] = 'Settings saved.';
        header('Location: /admin/settings');
    }

    // ── Tags ─────────────────────────────────────────────────────────────────
    public function tags(): void {
        Auth::requireAdmin();
        $tags = Database::fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM entity_tags et WHERE et.tag_id = t.id) AS cnt
             FROM tags t ORDER BY t.name"
        );
        require PALADIN_ROOT . '/views/admin/tags.php';
    }

    public function createTag(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['flash_error'] = 'Tag name is required.';
            header('Location: /admin/tags'); return;
        }
        $color = Branding::sanitizeColor((string)($_POST['color'] ?? '')) ?: '#64748b';
        if (Database::fetchOne("SELECT 1 FROM tags WHERE name = ?", [$name])) {
            $_SESSION['flash_error'] = 'A tag with that name already exists.';
            header('Location: /admin/tags'); return;
        }
        $id = Database::insert('tags', ['name' => $name, 'color' => $color]);
        Auth::log('create_tag', 'tags', $id);
        $_SESSION['flash_success'] = 'Tag created.';
        header('Location: /admin/tags');
    }

    // ── API Keys ─────────────────────────────────────────────────────────────
    public function apiKeys(): void {
        Auth::requireAdmin();
        $keys = Database::fetchAll(
            "SELECT id, name, key_prefix, last_used, expires_at, is_active, created_at
             FROM api_keys ORDER BY created_at DESC"
        );
        require PALADIN_ROOT . '/views/admin/api_keys.php';
    }

    public function createApiKey(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['flash_error'] = 'API key name is required.';
            header('Location: /admin/api-keys'); return;
        }
        $expires = Security::sanitizeInput($_POST['expires_at'] ?? '');
        $k = Security::generateApiKey();
        $id = Database::insert('api_keys', [
            'name'       => $name,
            'key_prefix' => $k['prefix'],
            'key_hash'   => $k['hash'],
            'is_active'  => 't',
            'expires_at' => $expires !== '' ? $expires : null,
            'created_by' => Auth::id(),
        ]);
        Auth::log('create_api_key', 'api_keys', $id);
        $_SESSION['flash_success'] = 'API key created — copy it now, it will not be shown again: ' . $k['key'];
        header('Location: /admin/api-keys');
    }

    // ── Activity Logs ────────────────────────────────────────────────────────
    public function logs(): void {
        Auth::requireAdmin();
        $action = Security::sanitizeInput($_GET['action'] ?? '');
        $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        $q      = Security::sanitizeInput($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;

        $where = ['1=1']; $params = [];
        if ($action !== '') { $where[] = 'al.action = ?'; $params[] = $action; }
        if ($userId > 0)    { $where[] = 'al.user_id = ?'; $params[] = $userId; }
        if ($q !== '')      { $where[] = '(al.action ILIKE ? OR al.entity_type ILIKE ?)'; array_push($params, "%$q%", "%$q%"); }
        $whereSql = implode(' AND ', $where);

        $total = (int)(Database::fetchOne("SELECT COUNT(*) c FROM activity_log al WHERE {$whereSql}", $params)['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $logs = Database::fetchAll(
            "SELECT al.*, u.name AS user_name FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE {$whereSql} ORDER BY al.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        $actions = Database::fetchAll("SELECT DISTINCT action FROM activity_log ORDER BY action");
        $users   = Database::fetchAll("SELECT id, name FROM users ORDER BY name");

        require PALADIN_ROOT . '/views/admin/logs.php';
    }

    // ── Sessions ─────────────────────────────────────────────────────────────
    public function sessions(): void {
        Auth::requireAdmin();
        $sessions = Database::fetchAll(
            "SELECT s.*, u.name AS user_name, u.email AS user_email
             FROM active_sessions s LEFT JOIN users u ON u.id = s.user_id
             ORDER BY s.last_seen_at DESC"
        );
        require PALADIN_ROOT . '/views/admin/sessions.php';
    }

    public function revokeSessions(int $userId): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('users', ['sessions_revoked_at' => date('Y-m-d H:i:s')], 'id=?', [$userId]);
        Database::query("DELETE FROM active_sessions WHERE user_id = ?", [$userId]);
        Auth::log('revoke_sessions', 'users', $userId);
        $_SESSION['flash_success'] = 'Sessions revoked.';
        header('Location: /admin/sessions');
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    /** Upsert a single settings row. */
    private function setSetting(string $key, string $value): void {
        Database::query(
            "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, NOW())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
            [$key, $value]
        );
    }
}
