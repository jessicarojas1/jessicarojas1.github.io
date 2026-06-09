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
        $this->maybeAutoExpire();
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
        if (!Auth::roleExists($role)) $role = 'viewer';

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
        if (!Auth::roleExists($role)) $role = 'viewer';

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
        $customCss = (Database::fetchOne("SELECT value FROM settings WHERE key='custom_css'")['value'] ?? '');
        $sidebarFooter = (Database::fetchOne("SELECT value FROM settings WHERE key='sidebar_footer'")['value'] ?? '');
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

        // Look & feel extras
        if (isset($_POST['custom_css']))     $this->setSetting('custom_css', (string)$_POST['custom_css']);
        if (isset($_POST['sidebar_footer'])) $this->setSetting('sidebar_footer', Security::sanitizeInput((string)$_POST['sidebar_footer']));

        Branding::clearCache();
        Auth::log('update_branding', 'settings', null);
        $_SESSION['flash_success'] = 'Branding updated.';
        header('Location: /admin/branding');
    }

    // ── Shortcut links ───────────────────────────────────────────────────────
    public function shortcuts(): void {
        Auth::requireAdmin();
        $links = Database::fetchAll("SELECT * FROM shortcut_links ORDER BY sort_order, id");
        require PALADIN_ROOT . '/views/admin/shortcuts.php';
    }

    public function createShortcut(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $label = Security::sanitizeInput($_POST['label'] ?? '');
        $url   = trim((string)($_POST['url'] ?? ''));
        if ($label === '' || $url === '') { $_SESSION['flash_error'] = 'Label and URL are required.'; header('Location: /admin/shortcuts'); return; }
        if (!preg_match('#^https?://#i', $url) && !str_starts_with($url, '/')) {
            $_SESSION['flash_error'] = 'URL must start with http(s):// or / (a site path).'; header('Location: /admin/shortcuts'); return;
        }
        $max = Database::fetchOne("SELECT COALESCE(MAX(sort_order),0) m FROM shortcut_links");
        Database::insert('shortcut_links', [
            'label' => $label, 'url' => substr($url, 0, 500),
            'icon' => Security::sanitizeInput($_POST['icon'] ?? '') ?: 'bi-link-45deg',
            'sort_order' => (int)$max['m'] + 1,
        ]);
        Auth::log('create_shortcut', 'shortcut_links', null);
        $_SESSION['flash_success'] = 'Shortcut added.';
        header('Location: /admin/shortcuts');
    }

    public function deleteShortcut(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM shortcut_links WHERE id = ?", [$id]);
        Auth::log('delete_shortcut', 'shortcut_links', $id);
        $_SESSION['flash_success'] = 'Shortcut removed.';
        header('Location: /admin/shortcuts');
    }

    // ── Settings ─────────────────────────────────────────────────────────────
    public function system(): void {
        Auth::requireAdmin();
        $env = [
            'PALADIN version'   => (Database::fetchOne("SELECT value FROM settings WHERE key='version'")['value'] ?? '—'),
            'PHP version'       => PHP_VERSION,
            'Server'            => $_SERVER['SERVER_SOFTWARE'] ?? 'cli',
            'App environment'   => $_ENV['APP_ENV'] ?? 'production',
            'Storage driver'    => (Database::fetchOne("SELECT value FROM settings WHERE key='storage_driver'")['value'] ?? 'local'),
        ];
        $db = Database::fetchOne("SELECT version() AS v");
        $env['Database'] = $db['v'] ?? '—';
        $exts = ['pdo_pgsql', 'gd', 'sodium', 'mbstring', 'openssl', 'zip'];
        $extStatus = [];
        foreach ($exts as $e) { $extStatus[$e] = extension_loaded($e); }
        $counts = [];
        foreach (['users','spaces','pages','documents','blog_posts','processes','tasks','workflow_templates','activity_log','attachments'] as $t) {
            try { $counts[$t] = (int)(Database::fetchOne("SELECT COUNT(*) c FROM {$t}")['c'] ?? 0); } catch (Throwable) { $counts[$t] = 0; }
        }
        $appliedMigrations = [];
        foreach (glob(PALADIN_ROOT . '/database/migrations/*.sql') ?: [] as $m) { $appliedMigrations[] = basename($m); }
        sort($appliedMigrations);
        require PALADIN_ROOT . '/views/admin/system.php';
    }

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
        $bools = ['password_require_uppercase', 'password_require_numbers', 'password_require_special', 'email_notifications', 'require_esignature', 'auto_archive_on_expiry'];
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

    // ── Document numbering ───────────────────────────────────────────────────
    public function numbering(): void {
        Auth::requireAdmin();
        $config = DocNumbering::config();
        require PALADIN_ROOT . '/views/admin/numbering.php';
    }

    public function saveNumbering(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $prefixes = [];
        foreach (array_keys(DocNumbering::DEFAULT_PREFIXES) as $type) {
            $prefixes[$type] = (string)($_POST['prefix'][$type] ?? '');
        }
        DocNumbering::save(
            (string)($_POST['separator'] ?? '-'),
            (int)($_POST['pad'] ?? 4),
            $prefixes
        );
        Auth::log('update_doc_numbering', 'settings', null);
        $_SESSION['flash_success'] = 'Document numbering scheme saved.';
        header('Location: /admin/numbering');
    }

    // ── Expiry sweep (auto-archive expired controlled documents) ─────────────
    public function runExpiry(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $n = Retention::sweepExpired();
        $this->setSetting('last_expiry_sweep', date('Y-m-d H:i:s'));
        Auth::log('run_expiry_sweep', 'documents', null, ['archived' => $n]);
        $_SESSION['flash_success'] = "Expiry sweep complete — {$n} expired document(s) archived.";
        header('Location: /admin/retention');
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

    public function updateTag(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT 1 FROM tags WHERE id = ?", [$id])) { http_response_code(404); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Tag name is required.'; header('Location: /admin/tags'); return; }
        $color = Branding::sanitizeColor((string)($_POST['color'] ?? '')) ?: '#64748b';
        // Name unique across other tags
        if (Database::fetchOne("SELECT 1 FROM tags WHERE name = ? AND id <> ?", [$name, $id])) {
            $_SESSION['flash_error'] = 'Another tag already uses that name.'; header('Location: /admin/tags'); return;
        }
        // tags has no updated_at column — use a plain parameterized UPDATE
        Database::query("UPDATE tags SET name = ?, color = ? WHERE id = ?", [$name, $color, $id]);
        Auth::log('update_tag', 'tags', $id);
        $_SESSION['flash_success'] = 'Tag updated.';
        header('Location: /admin/tags');
    }

    public function deleteTag(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT 1 FROM tags WHERE id = ?", [$id])) { http_response_code(404); return; }
        // Remove the tag and any of its entity associations
        Database::query("DELETE FROM entity_tags WHERE tag_id = ?", [$id]);
        Database::query("DELETE FROM tags WHERE id = ?", [$id]);
        Auth::log('delete_tag', 'tags', $id);
        $_SESSION['flash_success'] = 'Tag deleted.';
        header('Location: /admin/tags');
    }

    // ── Custom Roles ─────────────────────────────────────────────────────────
    public function roles(): void {
        Auth::requireAdmin();
        $custom = Database::fetchAll(
            "SELECT cr.*, u.name AS creator,
                    (SELECT COUNT(*) FROM custom_role_permissions p WHERE p.role_id = cr.id) AS perm_count,
                    (SELECT COUNT(*) FROM users us WHERE us.role = cr.role_key) AS user_count
             FROM custom_roles cr LEFT JOIN users u ON u.id = cr.created_by ORDER BY cr.name"
        );
        require PALADIN_ROOT . '/views/admin/roles.php';
    }

    public function roleForm(int $id = 0): void {
        Auth::requireAdmin();
        $role = null; $granted = [];
        if ($id) {
            $role = Database::fetchOne("SELECT * FROM custom_roles WHERE id = ?", [$id]);
            if (!$role) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
            foreach (Database::fetchAll("SELECT module, permission FROM custom_role_permissions WHERE role_id = ?", [$id]) as $p) {
                $granted[] = $p['module'] . '.' . $p['permission'];
            }
        }
        $catalog = Auth::moduleCatalog();
        require PALADIN_ROOT . '/views/admin/role_form.php';
    }

    public function createRole(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Role name is required.'; header('Location: /admin/roles/create'); return; }
        $key = $this->roleKeyFromName($name);
        if (Auth::isBuiltinRole($key) || Database::fetchOne("SELECT 1 FROM custom_roles WHERE role_key = ?", [$key])) {
            $_SESSION['flash_error'] = 'A role with a similar name already exists — choose a different name.';
            header('Location: /admin/roles/create'); return;
        }
        $roleId = Database::insert('custom_roles', [
            'role_key' => $key, 'name' => $name,
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'created_by' => Auth::id(),
        ]);
        $this->saveRolePermissions($roleId);
        Auth::clearRoleCache();
        Auth::log('create_role', 'custom_roles', $roleId, ['key' => $key]);
        $_SESSION['flash_success'] = "Role '{$name}' created.";
        header('Location: /admin/roles');
    }

    public function updateRole(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $role = Database::fetchOne("SELECT * FROM custom_roles WHERE id = ?", [$id]);
        if (!$role) { http_response_code(404); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Role name is required.'; header('Location: /admin/roles/' . $id . '/edit'); return; }
        // role_key is stable (users reference it) — only name/description/permissions change
        Database::update('custom_roles', [
            'name' => $name, 'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
        ], 'id = ?', [$id]);
        Database::query("DELETE FROM custom_role_permissions WHERE role_id = ?", [$id]);
        $this->saveRolePermissions($id);
        Auth::clearRoleCache();
        Auth::log('update_role', 'custom_roles', $id);
        $_SESSION['flash_success'] = 'Role updated.';
        header('Location: /admin/roles');
    }

    public function deleteRole(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $role = Database::fetchOne("SELECT role_key, name FROM custom_roles WHERE id = ?", [$id]);
        if (!$role) { http_response_code(404); return; }
        $assigned = (int)(Database::fetchOne("SELECT COUNT(*) c FROM users WHERE role = ?", [$role['role_key']])['c'] ?? 0);
        if ($assigned > 0) {
            $_SESSION['flash_error'] = "Cannot delete '{$role['name']}' — {$assigned} user(s) still have this role. Reassign them first.";
            header('Location: /admin/roles'); return;
        }
        Database::query("DELETE FROM custom_roles WHERE id = ?", [$id]); // perms cascade
        Auth::clearRoleCache();
        Auth::log('delete_role', 'custom_roles', $id);
        $_SESSION['flash_success'] = 'Role deleted.';
        header('Location: /admin/roles');
    }

    /** Derive a stable, unique-ish role_key from a display name. */
    private function roleKeyFromName(string $name): string {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
        $slug = trim($slug, '_');
        $slug = substr($slug, 0, 32);
        return 'cr_' . ($slug !== '' ? $slug : bin2hex(random_bytes(4)));
    }

    /** Persist the checked module.action permissions for a role, validated against the catalog. */
    private function saveRolePermissions(int $roleId): void {
        $catalog = Auth::moduleCatalog();
        $perms = $_POST['perms'] ?? [];
        if (!is_array($perms)) return;
        $seen = [];
        foreach ($perms as $p) {
            if (!is_string($p) || !str_contains($p, '.')) continue;
            [$module, $action] = explode('.', $p, 2);
            if (!isset($catalog[$module]) || !in_array($action, $catalog[$module], true)) continue;
            if (isset($seen[$p])) continue;
            $seen[$p] = true;
            Database::insert('custom_role_permissions', ['role_id' => $roleId, 'module' => $module, 'permission' => $action]);
        }
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

    // ── Webhooks ─────────────────────────────────────────────────────────────
    public function webhooks(): void {
        Auth::requireAdmin();
        $hooks = Database::fetchAll("SELECT * FROM webhooks ORDER BY created_at DESC");
        $events = Webhook::EVENTS;
        require PALADIN_ROOT . '/views/admin/webhooks.php';
    }

    public function createWebhook(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        $url  = trim((string)($_POST['url'] ?? ''));
        if ($name === '' || !preg_match('#^https?://#i', $url)) {
            $_SESSION['flash_error'] = 'A name and a valid http(s) URL are required.';
            header('Location: /admin/webhooks'); return;
        }
        // Normalise the event selection: '*' (all) or a comma-separated allowlist.
        $selected = (array)($_POST['events'] ?? []);
        if (in_array('*', $selected, true) || $selected === []) {
            $events = '*';
        } else {
            $valid  = array_keys(Webhook::EVENTS);
            $events = implode(',', array_values(array_filter($selected, fn($e) => in_array($e, $valid, true))));
            if ($events === '') $events = '*';
        }
        $secret = Security::sanitizeInput($_POST['secret'] ?? '');
        $id = Database::insert('webhooks', [
            'name'       => $name,
            'url'        => $url,
            'secret'     => $secret !== '' ? $secret : null,
            'events'     => $events,
            'is_active'  => 't',
            'created_by' => Auth::id(),
        ]);
        Auth::log('create_webhook', 'webhooks', $id);
        $_SESSION['flash_success'] = 'Webhook created.';
        header('Location: /admin/webhooks');
    }

    public function toggleWebhook(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("UPDATE webhooks SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?", [$id]);
        Auth::log('toggle_webhook', 'webhooks', $id);
        header('Location: /admin/webhooks');
    }

    public function testWebhook(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $hook = Database::fetchOne("SELECT * FROM webhooks WHERE id = ?", [$id]);
        if (!$hook) { http_response_code(404); return; }
        $body = json_encode([
            'event'     => 'ping',
            'timestamp' => date('c'),
            'data'      => ['message' => 'Test delivery from PALADIN'],
        ], JSON_UNESCAPED_SLASHES);
        $status = Webhook::deliver($hook, 'ping', (string)$body);
        Auth::log('test_webhook', 'webhooks', $id);
        $_SESSION[($status >= 200 && $status < 300) ? 'flash_success' : 'flash_error'] =
            $status > 0 ? "Test delivered — endpoint returned HTTP {$status}." : 'Test failed — endpoint unreachable.';
        header('Location: /admin/webhooks');
    }

    public function deleteWebhook(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM webhooks WHERE id = ?", [$id]);
        Auth::log('delete_webhook', 'webhooks', $id);
        $_SESSION['flash_success'] = 'Webhook deleted.';
        header('Location: /admin/webhooks');
    }

    // ── Retention rules ──────────────────────────────────────────────────────
    public function retention(): void {
        Auth::requireAdmin();
        $rules = Database::fetchAll(
            "SELECT r.*, s.name AS space_name FROM retention_rules r
             LEFT JOIN spaces s ON s.id = r.space_id ORDER BY r.created_at DESC"
        );
        // Live preview count per rule (read-only).
        foreach ($rules as &$r) { $r['preview'] = Retention::preview($r); }
        unset($r);
        $spaces = Database::fetchAll("SELECT id, name FROM spaces WHERE is_archived = FALSE ORDER BY name");
        $expiredCount  = Retention::expiredCount();
        $autoExpire    = ((Database::fetchOne("SELECT value FROM settings WHERE key='auto_archive_on_expiry'")['value'] ?? '0') === '1');
        $lastSweep     = Database::fetchOne("SELECT value FROM settings WHERE key='last_expiry_sweep'")['value'] ?? '';
        require PALADIN_ROOT . '/views/admin/retention.php';
    }

    public function createRetention(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        $age  = max(1, (int)($_POST['age_days'] ?? 365));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Give the rule a name.';
            header('Location: /admin/retention'); return;
        }
        $type    = ($_POST['content_type'] ?? 'document') === 'page' ? 'page' : 'document';
        $action  = ($_POST['action'] ?? 'archive') === 'notify' ? 'notify' : 'archive';
        $spaceId = !empty($_POST['space_id']) ? (int)$_POST['space_id'] : null;
        $docType = $type === 'document' ? (Security::sanitizeInput($_POST['doc_type'] ?? '') ?: null) : null;
        $id = Database::insert('retention_rules', [
            'name'         => $name,
            'content_type' => $type,
            'space_id'     => $spaceId,
            'doc_type'     => $docType,
            'age_days'     => $age,
            'action'       => $action,
            'is_active'    => 't',
            'created_by'   => Auth::id(),
        ]);
        Auth::log('create_retention_rule', 'retention_rules', $id);
        $_SESSION['flash_success'] = 'Retention rule created.';
        header('Location: /admin/retention');
    }

    public function toggleRetention(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("UPDATE retention_rules SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?", [$id]);
        Auth::log('toggle_retention_rule', 'retention_rules', $id);
        header('Location: /admin/retention');
    }

    public function runRetention(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $rule = Database::fetchOne("SELECT * FROM retention_rules WHERE id = ?", [$id]);
        if (!$rule) { http_response_code(404); return; }
        $affected = Retention::apply($rule);
        Database::update('retention_rules', ['last_run_at' => date('Y-m-d H:i:s'), 'last_affected' => $affected], 'id = ?', [$id]);
        Auth::log('run_retention_rule', 'retention_rules', $id);
        $verb = $rule['action'] === 'notify' ? 'notified' : 'archived';
        $_SESSION['flash_success'] = "Retention rule ran — {$affected} item(s) {$verb}.";
        header('Location: /admin/retention');
    }

    public function deleteRetention(int $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM retention_rules WHERE id = ?", [$id]);
        Auth::log('delete_retention_rule', 'retention_rules', $id);
        $_SESSION['flash_success'] = 'Retention rule deleted.';
        header('Location: /admin/retention');
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    /**
     * Opportunistic expiry sweep: when auto_archive_on_expiry is enabled, run
     * sweepExpired() at most once per day (no cron required). Best-effort.
     */
    private function maybeAutoExpire(): void {
        try {
            $on = (Database::fetchOne("SELECT value FROM settings WHERE key='auto_archive_on_expiry'")['value'] ?? '0') === '1';
            if (!$on) return;
            $last = Database::fetchOne("SELECT value FROM settings WHERE key='last_expiry_sweep'")['value'] ?? '';
            if ($last !== '' && strtotime($last) > strtotime('-1 day')) return;
            $n = Retention::sweepExpired();
            $this->setSetting('last_expiry_sweep', date('Y-m-d H:i:s'));
            if ($n > 0) Auth::log('auto_expiry_sweep', 'documents', null, ['archived' => $n]);
        } catch (\Throwable) { /* never block the dashboard */ }
    }

    /** Upsert a single settings row. */
    private function setSetting(string $key, string $value): void {
        Database::query(
            "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, NOW())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
            [$key, $value]
        );
    }
}
