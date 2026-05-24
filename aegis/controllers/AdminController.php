<?php
class AdminController {
    public function index(): void {
        Auth::requireAdmin();

        $userCount   = Database::fetchOne("SELECT COUNT(*) as c FROM users")['c'];
        $apiKeyCount = Database::fetchOne("SELECT COUNT(*) as c FROM api_keys WHERE is_active = TRUE")['c'];
        $activityLog = Database::fetchAll(
            "SELECT al.*, u.name as user_name FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC LIMIT 20"
        );
        $settings = Database::fetchAll("SELECT * FROM settings ORDER BY key");
        $settingsMap = array_column($settings, 'value', 'key');

        require AEGIS_ROOT . '/views/admin/index.php';
    }

    public function users(): void {
        Auth::requireAdmin();
        $users = Database::fetchAll("SELECT * FROM users ORDER BY created_at DESC");
        require AEGIS_ROOT . '/views/admin/users.php';
    }

    public function createUser(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $name       = Security::sanitizeInput($_POST['name'] ?? '');
        $email      = strtolower(Security::sanitizeInput($_POST['email'] ?? ''));
        $password   = $_POST['password'] ?? '';
        $role       = in_array($_POST['role'] ?? '', ['admin','manager','auditor','analyst','viewer']) ? $_POST['role'] : 'viewer';
        $dept       = Security::sanitizeInput($_POST['department'] ?? '');
        $title      = Security::sanitizeInput($_POST['job_title'] ?? '');

        $errors = Security::validatePassword($password);
        if (!$name || !$email) $errors[] = 'Name and email are required.';
        if (Database::fetchOne("SELECT id FROM users WHERE email = ?", [$email])) $errors[] = 'Email already in use.';

        if ($errors) {
            $_SESSION['user_errors'] = $errors;
            header('Location: /admin/users'); return;
        }

        $userId = Database::insert('users', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => Security::hashPassword($password),
            'role'          => $role,
            'department'    => $dept,
            'job_title'     => $title,
        ]);

        Auth::log('create_user', 'users', $userId);
        header('Location: /admin/users?created=1');
    }

    public function updateUser(string $id): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id       = (int)$id;
        $name     = Security::sanitizeInput($_POST['name'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['admin','manager','auditor','analyst','viewer']) ? $_POST['role'] : 'viewer';
        $dept     = Security::sanitizeInput($_POST['department'] ?? '');
        $title    = Security::sanitizeInput($_POST['job_title'] ?? '');
        $isActive = isset($_POST['is_active']) ? true : false;

        Database::query(
            "UPDATE users SET name=?, role=?, department=?, job_title=?, is_active=?, updated_at=NOW() WHERE id=?",
            [$name, $role, $dept, $title, $isActive, $id]
        );

        if (!empty($_POST['new_password'])) {
            $pwd = $_POST['new_password'];
            $errors = Security::validatePassword($pwd);
            if (!$errors) {
                Database::query("UPDATE users SET password_hash=? WHERE id=?", [Security::hashPassword($pwd), $id]);
            }
        }

        Auth::log('update_user', 'users', $id);
        header('Location: /admin/users?updated=1');
    }

    public function deleteUser(string $id): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        if ($id === Auth::id()) {
            $_SESSION['user_errors'] = ['Cannot delete your own account.'];
            header('Location: /admin/users'); return;
        }

        Database::query("UPDATE users SET is_active = FALSE WHERE id = ?", [$id]);
        Auth::log('delete_user', 'users', $id);
        header('Location: /admin/users?deleted=1');
    }

    public function editUser(string $id): void {
        Auth::requireAdmin();
        $user = Database::fetchOne("SELECT * FROM users WHERE id = ?", [(int)$id]);
        if (!$user) { http_response_code(404); return; }
        require AEGIS_ROOT . '/views/admin/users.php';
    }

    public function riskMatrix(): void {
        Auth::requireAdmin();
        $matrix = Database::fetchOne("SELECT * FROM risk_matrix_config WHERE is_active = TRUE ORDER BY id LIMIT 1");
        require AEGIS_ROOT . '/views/admin/risk_matrix.php';
    }

    public function updateRiskMatrix(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)($_POST['matrix_id'] ?? 1);
        $rowLabels = array_map('trim', explode(',', $_POST['row_labels'] ?? ''));
        $colLabels = array_map('trim', explode(',', $_POST['col_labels'] ?? ''));
        $rows = count($rowLabels);
        $cols = count($colLabels);

        $thresholds = [
            'low'      => (int)($_POST['thresh_low'] ?? 4),
            'medium'   => (int)($_POST['thresh_medium'] ?? 9),
            'high'     => (int)($_POST['thresh_high'] ?? 14),
            'critical' => $rows * $cols,
        ];

        $colors = [
            'low'      => Security::sanitizeInput($_POST['color_low'] ?? '#22c55e'),
            'medium'   => Security::sanitizeInput($_POST['color_medium'] ?? '#f59e0b'),
            'high'     => Security::sanitizeInput($_POST['color_high'] ?? '#f97316'),
            'critical' => Security::sanitizeInput($_POST['color_critical'] ?? '#ef4444'),
        ];

        Database::query(
            "UPDATE risk_matrix_config SET rows=?, cols=?, row_labels=?, col_labels=?, thresholds=?, colors=?, updated_at=NOW() WHERE id=?",
            [$rows, $cols, json_encode($rowLabels), json_encode($colLabels), json_encode($thresholds), json_encode($colors), $id]
        );

        Auth::log('update_risk_matrix', 'risk_matrix_config', $id);
        header('Location: /admin/risk-matrix?saved=1');
    }

    public function workflows(): void {
        Auth::requireAdmin();
        $workflows = Database::fetchAll("SELECT w.*, u.name as created_by_name FROM workflows w LEFT JOIN users u ON u.id = w.created_by ORDER BY w.created_at DESC");
        require AEGIS_ROOT . '/views/admin/workflows.php';
    }

    public function createWorkflow(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $name    = Security::sanitizeInput($_POST['name'] ?? '');
        $desc    = Security::sanitizeInput($_POST['description'] ?? '');
        $trigger = Security::sanitizeInput($_POST['trigger_type'] ?? '');
        $triggerConfig = json_decode($_POST['trigger_config'] ?? '{}', true) ?? [];
        $actions = json_decode($_POST['actions'] ?? '[]', true) ?? [];

        if (!$name || !$trigger) {
            $_SESSION['workflow_error'] = 'Name and trigger are required.';
            header('Location: /admin/workflows'); return;
        }

        $wfId = Database::insert('workflows', [
            'name'           => $name,
            'description'    => $desc,
            'trigger_type'   => $trigger,
            'trigger_config' => json_encode($triggerConfig),
            'actions'        => json_encode($actions),
            'created_by'     => Auth::id(),
        ]);

        Auth::log('create_workflow', 'workflows', $wfId);
        header('Location: /admin/workflows?created=1');
    }

    public function toggleWorkflow(string $id): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        Database::query("UPDATE workflows SET is_active = NOT is_active, updated_at=NOW() WHERE id=?", [$id]);
        header('Location: /admin/workflows');
    }

    public function editWorkflow(string $id): void {
        Auth::requireAdmin();
        $workflow = Database::fetchOne("SELECT * FROM workflows WHERE id=?", [(int)$id]);
        if (!$workflow) { http_response_code(404); return; }
        require AEGIS_ROOT . '/views/admin/workflows.php';
    }

    public function alerts(): void {
        Auth::requireAdmin();
        $configs  = Database::fetchAll("SELECT * FROM alert_configs ORDER BY created_at DESC");
        $recent   = Database::fetchAll(
            "SELECT a.*, u.name as user_name FROM alerts a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 50"
        );
        require AEGIS_ROOT . '/views/admin/alerts.php';
    }

    public function apiKeys(): void {
        Auth::requireAdmin();
        $keys = Database::fetchAll(
            "SELECT ak.*, u.name as user_name FROM api_keys ak LEFT JOIN users u ON u.id = ak.user_id ORDER BY ak.created_at DESC"
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/admin/api_keys.php';
    }

    public function createApiKey(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $userId  = (int)($_POST['user_id'] ?? Auth::id());
        $name    = Security::sanitizeInput($_POST['name'] ?? '');
        $perms   = $_POST['permissions'] ?? ['read'];
        $expires = !empty($_POST['expires_at']) ? Security::sanitizeInput($_POST['expires_at']) : null;

        $keyData = Security::generateApiKey();

        Database::insert('api_keys', [
            'user_id'     => $userId,
            'name'        => $name,
            'key_prefix'  => $keyData['prefix'],
            'key_hash'    => $keyData['hash'],
            'permissions' => json_encode(is_array($perms) ? $perms : ['read']),
            'expires_at'  => $expires,
        ]);

        $_SESSION['new_api_key'] = $keyData['key'];
        Auth::log('create_api_key', 'api_keys', null, ['name' => $name]);
        header('Location: /admin/api-keys?created=1');
    }

    public function revokeApiKey(string $id): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        Database::query("UPDATE api_keys SET is_active = FALSE WHERE id = ?", [(int)$id]);
        Auth::log('revoke_api_key', 'api_keys', (int)$id);
        header('Location: /admin/api-keys?revoked=1');
    }

    public function permissions(): void {
        Auth::requireAdmin();

        $modules = ['compliance', 'audit', 'policy', 'risk'];
        $permTypes = ['read', 'write', 'edit'];

        $users = Database::fetchAll(
            "SELECT id, name, email, role, department FROM users WHERE role != 'admin' AND is_active = TRUE ORDER BY name"
        );

        // Load all existing grants indexed by user_id
        $grants = [];
        $rows = Database::fetchAll("SELECT user_id, module, permission FROM user_permissions");
        foreach ($rows as $r) {
            $grants[$r['user_id']][$r['module']][$r['permission']] = true;
        }

        // Role default permissions for display
        $roleDefaults = [
            'manager' => ['compliance' => ['read','write','edit'], 'audit' => ['read','write','edit'], 'policy' => ['read','write','edit'], 'risk' => ['read','write','edit']],
            'auditor' => ['compliance' => ['read'], 'audit' => ['read','write','edit'], 'policy' => ['read'], 'risk' => ['read']],
            'analyst' => ['compliance' => ['read'], 'audit' => ['read'], 'policy' => ['read'], 'risk' => ['read','write','edit']],
            'viewer'  => ['compliance' => ['read'], 'audit' => ['read'], 'policy' => ['read'], 'risk' => ['read']],
        ];

        require AEGIS_ROOT . '/views/admin/permissions.php';
    }

    public function updatePermissions(string $userId): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $userId = (int)$userId;
        $user = Database::fetchOne("SELECT id, role FROM users WHERE id = ? AND role != 'admin'", [$userId]);
        if (!$user) {
            header('Location: /admin/permissions?error=invalid'); return;
        }

        // Delete existing explicit grants for this user
        Database::query("DELETE FROM user_permissions WHERE user_id = ?", [$userId]);

        // Insert checked permissions
        $granted = $_POST['permissions'] ?? [];
        if (is_array($granted)) {
            foreach ($granted as $perm) {
                $parts = explode('.', $perm, 2);
                if (count($parts) === 2) {
                    $module = Security::sanitizeInput($parts[0]);
                    $permType = Security::sanitizeInput($parts[1]);
                    $allowed = ['compliance','audit','policy','risk'];
                    $allowedPerms = ['read','write','edit'];
                    if (in_array($module, $allowed) && in_array($permType, $allowedPerms)) {
                        Database::query(
                            "INSERT INTO user_permissions (user_id, module, permission, granted_by) VALUES (?,?,?,?)
                             ON CONFLICT (user_id, module, permission) DO NOTHING",
                            [$userId, $module, $permType, Auth::id()]
                        );
                    }
                }
            }
        }

        Auth::log('update_permissions', 'users', $userId);
        header('Location: /admin/permissions?saved=' . $userId);
    }

    // ─── Email settings ───────────────────────────────────────────────────────
    public function email(): void {
        Auth::requireAdmin();
        $rows     = Database::fetchAll("SELECT key, value FROM settings WHERE key LIKE 'smtp_%' OR key = 'email_notifications'");
        $settings = array_column($rows, 'value', 'key');
        $pageTitle    = 'Email Settings';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Email Settings', null]];
        require AEGIS_ROOT . '/views/admin/email.php';
    }

    public function saveEmail(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $fields = ['smtp_host','smtp_port','smtp_user','smtp_from','smtp_from_name'];
        foreach ($fields as $key) {
            $val = Security::sanitizeInput($_POST[$key] ?? '');
            Database::query("INSERT INTO settings (key, value, type, description) VALUES (?,?,'string','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [$key, $val]);
        }
        // Password only updated if provided
        $pass = $_POST['smtp_pass'] ?? '';
        if ($pass !== '') {
            Database::query("INSERT INTO settings (key, value, type, description) VALUES ('smtp_pass',?,'string','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [$pass]);
        }
        $tls   = isset($_POST['smtp_tls'])            ? '1' : '0';
        $notif = isset($_POST['email_notifications']) ? '1' : '0';
        Database::query("INSERT INTO settings (key, value, type, description) VALUES ('smtp_tls',?,'boolean','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",   [$tls]);
        Database::query("INSERT INTO settings (key, value, type, description) VALUES ('email_notifications',?,'boolean','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [$notif]);

        Auth::log('update_email_settings', 'settings', 0);
        $_SESSION['flash_success'] = 'Email settings saved.';
        header('Location: /admin/email');
    }

    public function testEmail(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $to = Security::sanitizeInput($_POST['test_email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid email address.';
            header('Location: /admin/email'); return;
        }
        require_once AEGIS_ROOT . '/src/Mailer.php';
        $ok = Mailer::sendFromSettings($to, $to, 'AEGIS GRC — Test Email',
            '<h2>Test Email</h2><p>Your AEGIS GRC email configuration is working correctly.</p>');
        if ($ok) {
            $_SESSION['flash_success'] = "Test email sent to {$to}.";
        } else {
            $_SESSION['flash_error'] = 'Failed to send test email. Check SMTP settings and server logs.';
        }
        header('Location: /admin/email');
    }

    // ─── System settings ──────────────────────────────────────────────────────
    public function settings(): void {
        Auth::requireAdmin();
        $rows     = Database::fetchAll("SELECT key, value, type, description FROM settings ORDER BY key");
        $settings = array_column($rows, null, 'key');
        $pageTitle    = 'System Settings';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['System Settings', null]];
        require AEGIS_ROOT . '/views/admin/settings.php';
    }

    public function saveSettings(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $allowed = ['org_name','date_format','timezone','session_timeout'];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $val = Security::sanitizeInput($_POST[$key]);
                Database::query("INSERT INTO settings (key, value, type, description) VALUES (?,?,'string','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [$key, $val]);
            }
        }
        Auth::log('update_settings', 'settings', 0);
        $_SESSION['flash_success'] = 'Settings saved.';
        header('Location: /admin/settings');
    }
}
