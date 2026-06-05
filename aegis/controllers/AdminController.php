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

        $errors = Security::validatePasswordPolicy($password);
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

        // Send email verification
        try {
            require_once AEGIS_ROOT . '/src/Mailer.php';
            $token  = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 86400);
            Database::query(
                "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)",
                [$userId, hash('sha256', $token), $expiry]
            );
            $appUrl  = rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/');
            $verifyUrl = $appUrl . '/verify-email/' . $token;
            $html = '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto">
                <div style="background:#6366f1;padding:20px 28px;border-radius:8px 8px 0 0">
                  <h2 style="color:#fff;margin:0">Welcome to AEGIS GRC</h2>
                </div>
                <div style="background:#fff;padding:24px 28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px">
                  <p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
                  <p>Your AEGIS GRC account has been created. Please verify your email address to activate it.</p>
                  <p style="text-align:center;margin:28px 0">
                    <a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '"
                       style="display:inline-block;padding:12px 28px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">
                      Verify Email Address
                    </a>
                  </p>
                  <p style="color:#6b7280;font-size:13px">This link expires in 24 hours. Your temporary password was provided by your administrator.</p>
                </div>
              </div>';
            Mailer::sendFromSettings($email, $name, 'Verify your AEGIS GRC account', $html);
        } catch (Throwable $e) {
            // Non-fatal — user can still log in, just unverified
        }

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
            $errors = Security::validatePasswordPolicy($pwd);
            if (!$errors) {
                Database::query("UPDATE users SET password_hash=? WHERE id=?", [Security::hashPassword($pwd), $id]);
            }
        }

        // Revoke active sessions and API keys if account is being deactivated
        if (!$isActive) {
            Database::query("UPDATE users SET sessions_revoked_at = NOW() WHERE id = ?", [$id]);
            Database::query("UPDATE api_keys SET is_active = FALSE WHERE user_id = ?", [$id]);
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

        // Soft-delete: deactivate and revoke all active sessions + API keys
        Database::query("UPDATE users SET is_active = FALSE, sessions_revoked_at = NOW() WHERE id = ?", [$id]);
        Database::query("UPDATE api_keys SET is_active = FALSE WHERE user_id = ?", [$id]);
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
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $id        = (int)($_POST['matrix_id'] ?? 1);
        $name      = Security::sanitizeInput($_POST['name'] ?? 'Default 5x5');
        $desc      = Security::sanitizeInput($_POST['description'] ?? '');
        $rowLabels = array_map('trim', explode(',', $_POST['row_labels'] ?? ''));
        $colLabels = array_map('trim', explode(',', $_POST['col_labels'] ?? ''));
        $rows      = count($rowLabels);
        $cols      = count($colLabels);

        // Parse per-cell data from the submitted JSON blob
        $cellsJson = $_POST['cells_json'] ?? '{}';
        $cells     = json_decode($cellsJson, true);
        if (!is_array($cells)) { $cells = []; }

        // Sanitize each cell
        $cleanCells = [];
        foreach ($cells as $key => $cell) {
            if (!preg_match('/^\d+_\d+$/', $key)) continue;
            $cleanCells[$key] = [
                'title' => Security::sanitizeInput($cell['title'] ?? ''),
                'desc'  => Security::sanitizeInput($cell['desc']  ?? ''),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $cell['color'] ?? '') ? $cell['color'] : '#22c55e',
            ];
        }

        $thresholds = [
            'low'      => (int)($_POST['thresh_low']    ?? 4),
            'medium'   => (int)($_POST['thresh_medium'] ?? 9),
            'high'     => (int)($_POST['thresh_high']   ?? 14),
            'critical' => $rows * $cols,
        ];
        $colors = [
            'low'      => '#22c55e',
            'medium'   => '#f59e0b',
            'high'     => '#f97316',
            'critical' => '#ef4444',
        ];

        Database::query(
            "UPDATE risk_matrix_config SET name=?, description=?, rows=?, cols=?, row_labels=?, col_labels=?, thresholds=?, colors=?, cells=?, updated_at=NOW() WHERE id=?",
            [$name, $desc, $rows, $cols, json_encode($rowLabels), json_encode($colLabels), json_encode($thresholds), json_encode($colors), json_encode($cleanCells), $id]
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

    public function logs(): void {
        Auth::requireAdmin();

        $userId     = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        $action     = Security::sanitizeInput($_GET['action'] ?? '');
        $entityType = Security::sanitizeInput($_GET['entity_type'] ?? '');
        $ipAddr     = Security::sanitizeInput($_GET['ip'] ?? '');
        $dateFrom   = Security::sanitizeInput($_GET['from'] ?? '');
        $dateTo     = Security::sanitizeInput($_GET['to'] ?? '');
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $perPage    = 50;
        $offset     = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];
        if ($userId)     { $where[] = 'al.user_id = ?';         $params[] = $userId; }
        if ($action)     { $where[] = 'al.action ILIKE ?';       $params[] = '%' . $action . '%'; }
        if ($entityType) { $where[] = 'al.entity_type = ?';      $params[] = $entityType; }
        if ($ipAddr)     { $where[] = 'al.ip_address ILIKE ?';   $params[] = '%' . $ipAddr . '%'; }
        if ($dateFrom)   { $where[] = 'al.created_at >= ?';      $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)     { $where[] = 'al.created_at <= ?';      $params[] = $dateTo . ' 23:59:59'; }
        $whereStr = implode(' AND ', $where);

        $totalRows  = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM activity_log al WHERE {$whereStr}", $params)['c'] ?? 0);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        // Append LIMIT/OFFSET as parameterized values to avoid any interpolation
        $logsParams   = array_merge($params, [(int)$perPage, (int)$offset]);
        $logs = Database::fetchAll(
            "SELECT al.*, u.name as user_name, u.email as user_email, u.role as user_role
             FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE {$whereStr}
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?",
            $logsParams
        );

        $users       = Database::fetchAll("SELECT id, name FROM users ORDER BY name");
        $actionTypes = Database::fetchAll("SELECT DISTINCT action FROM activity_log WHERE action IS NOT NULL ORDER BY action");
        $entityTypes = Database::fetchAll("SELECT DISTINCT entity_type FROM activity_log WHERE entity_type IS NOT NULL ORDER BY entity_type");

        $stats = [
            'total'        => Database::fetchOne("SELECT COUNT(*) as c FROM activity_log")['c'] ?? 0,
            'today'        => Database::fetchOne("SELECT COUNT(*) as c FROM activity_log WHERE created_at >= CURRENT_DATE")['c'] ?? 0,
            'week_users'   => Database::fetchOne("SELECT COUNT(DISTINCT user_id) as c FROM activity_log WHERE created_at >= NOW() - INTERVAL '7 days'")['c'] ?? 0,
            'top_action'   => Database::fetchOne("SELECT action, COUNT(*) as c FROM activity_log GROUP BY action ORDER BY c DESC LIMIT 1")['action'] ?? '—',
        ];

        $topUsers = Database::fetchAll(
            "SELECT u.name, COUNT(al.id) as c
             FROM activity_log al LEFT JOIN users u ON al.user_id = u.id
             WHERE al.created_at >= NOW() - INTERVAL '30 days'
             GROUP BY u.name ORDER BY c DESC LIMIT 5"
        );

        $actionBreakdown = Database::fetchAll(
            "SELECT action, COUNT(*) as c FROM activity_log GROUP BY action ORDER BY c DESC LIMIT 10"
        );

        require AEGIS_ROOT . '/views/admin/logs.php';
    }

    public function exportLogs(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $logs = Database::fetchAll(
            "SELECT al.id, COALESCE(u.name,'System') as user_name, u.email as user_email, u.role as user_role,
                    al.action, al.entity_type, al.entity_id, al.changes, al.ip_address, al.created_at
             FROM activity_log al LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC"
        );

        $sanitize = static function(mixed $v): string {
            $s = (string)($v ?? '');
            return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
        };

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="aegis_activity_log_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','User','Email','Role','Action','Entity Type','Entity ID','Changes','IP Address','Timestamp']);
        foreach ($logs as $r) {
            fputcsv($out, array_map($sanitize, [
                $r['id'], $r['user_name'], $r['user_email'] ?? '', $r['user_role'] ?? '',
                $r['action'], $r['entity_type'] ?? '', $r['entity_id'] ?? '',
                $r['changes'] ?? '', $r['ip_address'] ?? '', $r['created_at'],
            ]));
        }
        fclose($out);
        exit;
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
        // Password only updated if provided — encrypt at rest (NIST 800-53 SC-28)
        $pass = $_POST['smtp_pass'] ?? '';
        if ($pass !== '') {
            Database::query("INSERT INTO settings (key, value, type, description) VALUES ('smtp_pass',?,'string','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
                [Security::encryptSetting($pass)]);
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

    public function uploadLogo(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $file = $_FILES['logo_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'No file uploaded or upload error.';
            header('Location: /admin/settings'); return;
        }

        // Max 2 MB
        if ($file['size'] > 2 * 1024 * 1024) {
            $_SESSION['flash_error'] = 'Logo file must be under 2 MB.';
            header('Location: /admin/settings'); return;
        }

        // Validate MIME type via finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
        if (!in_array($mime, $allowedMimes, true)) {
            $_SESSION['flash_error'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP, SVG.';
            header('Location: /admin/settings'); return;
        }

        $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
        $origName = Security::sanitizeInput($file['name']);

        Database::query(
            "INSERT INTO settings (key, value) VALUES ('company_logo_data', ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
            [$dataUri]
        );
        Database::query(
            "INSERT INTO settings (key, value) VALUES ('company_logo_name', ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
            [$origName]
        );

        Auth::log('upload_logo', 'settings', 0);
        $_SESSION['flash_success'] = 'Company logo uploaded successfully.';
        header('Location: /admin/settings');
    }

    public function removeLogo(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        Database::query(
            "INSERT INTO settings (key, value) VALUES ('company_logo_data', '') ON CONFLICT (key) DO UPDATE SET value = ''",
            []
        );
        Database::query(
            "INSERT INTO settings (key, value) VALUES ('company_logo_name', '') ON CONFLICT (key) DO UPDATE SET value = ''",
            []
        );

        Auth::log('remove_logo', 'settings', 0);
        $_SESSION['flash_success'] = 'Company logo removed.';
        header('Location: /admin/settings');
    }

    public function storage(): void {
        Auth::requireAdmin();
        $keys = ['storage_driver','s3_bucket','s3_region','s3_access_key','s3_endpoint','s3_public_url'];
        $rows = Database::fetchAll(
            "SELECT key, value FROM settings WHERE key = ANY(?::text[])",
            ['{' . implode(',', $keys) . '}']
        );
        $storageSettings = array_column($rows, 'value', 'key');
        // Never expose the secret key in the UI
        $pageTitle    = 'Storage Settings';
        $activeModule = 'admin_storage';
        $breadcrumbs  = [['Admin', '/admin'], ['Storage', null]];
        require AEGIS_ROOT . '/views/admin/storage.php';
    }

    public function saveStorage(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $driver = in_array($_POST['storage_driver'] ?? '', ['local','s3']) ? $_POST['storage_driver'] : 'local';
        $fields = [
            'storage_driver' => $driver,
            's3_bucket'      => Security::sanitizeInput($_POST['s3_bucket'] ?? ''),
            's3_region'      => Security::sanitizeInput($_POST['s3_region'] ?? 'us-east-1'),
            's3_access_key'  => Security::sanitizeInput($_POST['s3_access_key'] ?? ''),
            's3_endpoint'    => Security::sanitizeInput($_POST['s3_endpoint'] ?? ''),
            's3_public_url'  => Security::sanitizeInput($_POST['s3_public_url'] ?? ''),
        ];
        // Only update secret key if a new one is supplied — encrypt at rest (NIST 800-53 SC-28)
        if (!empty($_POST['s3_secret_key'])) {
            $fields['s3_secret_key'] = Security::encryptSetting(Security::sanitizeInput($_POST['s3_secret_key']));
        }
        foreach ($fields as $key => $val) {
            Database::query(
                "INSERT INTO settings (key, value, type, description) VALUES (?,?,'string','') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
                [$key, $val]
            );
        }
        // Bust the Storage class cache
        if (class_exists('Storage')) { Storage::clearCache(); }
        Auth::log('update_storage_settings', 'settings', 0, ['driver' => $driver]);
        $_SESSION['flash_success'] = 'Storage settings saved.';
        header('Location: /admin/storage');
    }

    public function testStorage(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); return;
        }
        header('Content-Type: application/json');
        try {
            // Write a small test object then delete it
            $tmpFile = tempnam(sys_get_temp_dir(), 'aegis_storage_test_');
            file_put_contents($tmpFile, 'AEGIS storage test ' . date('c'));
            $key = Storage::put('uploads/.tests', $tmpFile, 'storage_test.txt');
            unlink($tmpFile);
            Storage::delete($key);
            echo json_encode(['ok' => true, 'message' => 'Storage test passed.']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ─── Data Retention ───────────────────────────────────────────────────────

    public function retention(): void {
        Auth::requireAdmin();
        $policies     = Database::fetchAll("SELECT * FROM data_retention_policies ORDER BY entity_type");
        $pageTitle    = 'Data Retention';
        $activeModule = 'admin_retention';
        $breadcrumbs  = [['Admin', '/admin'], ['Data Retention', null]];
        require AEGIS_ROOT . '/views/admin/retention.php';
    }

    public function saveRetention(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $rows = $_POST['policies'] ?? [];
        if (!is_array($rows)) {
            $_SESSION['flash_error'] = 'Invalid input.';
            header('Location: /admin/retention'); return;
        }
        foreach ($rows as $row) {
            $id      = (int)($row['id'] ?? 0);
            $days    = (int)($row['retention_days'] ?? 0);
            $action  = $row['action'] ?? '';
            $enabled = !empty($row['is_enabled']);

            if ($days < 1 || $days > 3650) continue;
            if (!in_array($action, ['delete', 'archive'], true)) continue;

            Database::query(
                "UPDATE data_retention_policies SET retention_days=?, action=?, is_enabled=?, updated_at=NOW() WHERE id=?",
                [$days, $action, $enabled, $id]
            );
        }
        Auth::log('update_retention_policies', 'data_retention_policies', null);
        $_SESSION['flash_success'] = 'Data retention policies saved.';
        header('Location: /admin/retention');
    }

    public function runRetention(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $policies = Database::fetchAll(
            "SELECT * FROM data_retention_policies WHERE is_enabled = TRUE"
        );

        $results = [];
        foreach ($policies as $policy) {
            $days       = (int)$policy['retention_days'];
            $entityType = $policy['entity_type'];
            $deleted    = 0;

            try {
                switch ($entityType) {
                    case 'activity_log':
                        $res     = Database::query(
                            "DELETE FROM activity_log WHERE created_at < NOW() - (? * INTERVAL '1 day')",
                            [$days]
                        );
                        break;
                    case 'notification_log':
                        $res     = Database::query(
                            "DELETE FROM notification_log WHERE sent_at < NOW() - (? * INTERVAL '1 day')",
                            [$days]
                        );
                        break;
                    case 'webhook_deliveries':
                        $res     = Database::query(
                            "DELETE FROM webhook_deliveries WHERE created_at < NOW() - (? * INTERVAL '1 day') AND status IN ('delivered','failed')",
                            [$days]
                        );
                        break;
                    case 'alerts':
                        $res     = Database::query(
                            "DELETE FROM alerts WHERE created_at < NOW() - (? * INTERVAL '1 day') AND is_read = TRUE",
                            [$days]
                        );
                        break;
                    default:
                        $res = null;
                }

                // Get affected row count if driver supports it
                if ($res && method_exists($res, 'rowCount')) {
                    $deleted = $res->rowCount();
                }

                Database::query(
                    "UPDATE data_retention_policies SET last_run_at = NOW() WHERE id = ?",
                    [(int)$policy['id']]
                );

                $results[] = ['entity_type' => $entityType, 'deleted' => $deleted];
            } catch (Throwable $e) {
                $results[] = ['entity_type' => $entityType, 'deleted' => 0, 'error' => $e->getMessage()];
            }
        }

        Auth::log('data_retention_run', 'data_retention_policies', null, ['policies_run' => count($results)]);

        if (($_POST['format'] ?? '') === 'json') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'results' => $results]);
            return;
        }

        $_SESSION['flash_success'] = 'Data retention enforcement completed.';
        header('Location: /admin/retention');
    }

    // ─── Active Session Management ────────────────────────────────────────────

    public function sessions(): void {
        Auth::requireAdmin();
        $activeSessions = Database::fetchAll(
            "SELECT s.*, u.name as user_name, u.email, u.role
             FROM active_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.last_seen_at > NOW() - INTERVAL '2 hours'
             ORDER BY s.last_seen_at DESC"
        );
        $totalCount   = count($activeSessions);
        $uniqueUsers  = count(array_unique(array_column($activeSessions, 'user_id')));

        $pageTitle    = 'Active Sessions';
        $activeModule = 'admin_sessions';
        $breadcrumbs  = [['Admin', '/admin'], ['Active Sessions', null]];
        require AEGIS_ROOT . '/views/admin/sessions.php';
    }

    public function killSession(string $sessionId): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
            return;
        }
        if ($sessionId === session_id()) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Cannot terminate your own session']);
            return;
        }
        Database::query("DELETE FROM active_sessions WHERE id = ?", [$sessionId]);
        Auth::log('kill_session', 'active_sessions', null, ['session_id' => substr($sessionId, 0, 8) . '…']);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    // ─── Security Policy ──────────────────────────────────────────────────────

    public function securityPolicy(): void {
        Auth::requireAdmin();

        $keys = [
            'password_min_length'       => 12,
            'password_require_uppercase' => '1',
            'password_require_numbers'   => '1',
            'password_require_special'   => '1',
            'password_expiry_days'       => '0',
            'mfa_enforcement'            => 'optional',
            'session_timeout_minutes'    => '480',
            'max_failed_logins'          => '5',
            'account_lockout_minutes'    => '30',
            'allowed_ip_ranges'          => '',
        ];

        $policy = [];
        foreach ($keys as $key => $default) {
            $row = Database::fetchOne("SELECT value FROM settings WHERE key = ?", [$key]);
            $policy[$key] = $row['value'] ?? $default;
        }

        $pageTitle    = 'Security Policy';
        $activeModule = 'admin_security';
        $breadcrumbs  = [['Admin', '/admin'], ['Security Policy', null]];

        ob_start();
        require AEGIS_ROOT . '/views/admin/security_policy.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveSecurityPolicy(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $updates = [];

        // password_min_length: integer 8-128
        $minLen = (int)($_POST['password_min_length'] ?? 12);
        $updates['password_min_length'] = (string)max(8, min(128, $minLen));

        // checkboxes: cast to '1'/'0'
        $updates['password_require_uppercase'] = isset($_POST['password_require_uppercase']) ? '1' : '0';
        $updates['password_require_numbers']   = isset($_POST['password_require_numbers'])   ? '1' : '0';
        $updates['password_require_special']   = isset($_POST['password_require_special'])   ? '1' : '0';

        // password_expiry_days: integer 0-365
        $expiryDays = (int)($_POST['password_expiry_days'] ?? 0);
        $updates['password_expiry_days'] = (string)max(0, min(365, $expiryDays));

        // mfa_enforcement: enum
        $mfaOpts = ['optional', 'admin_required', 'manager_required', 'all_required'];
        $mfa = $_POST['mfa_enforcement'] ?? 'optional';
        $updates['mfa_enforcement'] = in_array($mfa, $mfaOpts, true) ? $mfa : 'optional';

        // session_timeout_minutes: integer 15-10080
        $sessionTimeout = (int)($_POST['session_timeout_minutes'] ?? 480);
        $updates['session_timeout_minutes'] = (string)max(15, min(10080, $sessionTimeout));

        // max_failed_logins: integer 3-20
        $maxFailed = (int)($_POST['max_failed_logins'] ?? 5);
        $updates['max_failed_logins'] = (string)max(3, min(20, $maxFailed));

        // account_lockout_minutes: integer 5-1440
        $lockout = (int)($_POST['account_lockout_minutes'] ?? 30);
        $updates['account_lockout_minutes'] = (string)max(5, min(1440, $lockout));

        // allowed_ip_ranges: strip dangerous chars, allow empty
        $ipRanges = preg_replace('/[^0-9a-fA-F.:\/\n\r ,\-]/', '', $_POST['allowed_ip_ranges'] ?? '');
        $updates['allowed_ip_ranges'] = trim($ipRanges ?? '');

        foreach ($updates as $k => $v) {
            Database::query(
                "INSERT INTO settings (key, value) VALUES (?,?) ON CONFLICT (key) DO UPDATE SET value=?, updated_at=NOW()",
                [$k, $v, $v]
            );
        }

        Auth::log('update_security_policy', 'settings', null);
        $_SESSION['flash_success'] = 'Security policy saved successfully.';
        header('Location: /admin/security-policy');
    }

    // ─── Custom Fields ────────────────────────────────────────────────────────

    public function customFields(): void {
        Auth::requireAdmin();

        $rows = Database::fetchAll(
            "SELECT * FROM custom_field_definitions ORDER BY entity_type, sort_order, id"
        );

        $fields = [];
        foreach ($rows as $row) {
            $fields[$row['entity_type']][] = $row;
        }

        $pageTitle    = 'Custom Fields';
        $activeModule = 'admin_custom_fields';
        $breadcrumbs  = [['Admin', '/admin'], ['Custom Fields', null]];
        ob_start();
        require AEGIS_ROOT . '/views/admin/custom_fields.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveCustomField(): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $allowedEntities = ['risk','policy','audit','incident','vendor','control','asset'];
        $allowedTypes    = ['text','textarea','number','date','select','checkbox','url'];

        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $fieldKey   = strtolower(Security::sanitizeInput($_POST['field_key'] ?? ''));
        $fieldKey   = preg_replace('/\s+/', '_', $fieldKey);
        $label      = Security::sanitizeInput($_POST['label'] ?? '');
        $fieldType  = Security::sanitizeInput($_POST['field_type'] ?? '');
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);
        $required   = isset($_POST['required']) ? true : false;
        $options    = null;

        if (!in_array($entityType, $allowedEntities, true)) {
            $_SESSION['flash_error'] = 'Invalid entity type.';
            header('Location: /admin/custom-fields'); return;
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $fieldKey)) {
            $_SESSION['flash_error'] = 'Field key must start with a letter, use only lowercase letters, numbers, and underscores (2–50 chars).';
            header('Location: /admin/custom-fields'); return;
        }
        if (!$label) {
            $_SESSION['flash_error'] = 'Label is required.';
            header('Location: /admin/custom-fields'); return;
        }
        if (!in_array($fieldType, $allowedTypes, true)) {
            $_SESSION['flash_error'] = 'Invalid field type.';
            header('Location: /admin/custom-fields'); return;
        }

        if ($fieldType === 'select') {
            $rawOptions = Security::sanitizeInput($_POST['options'] ?? '');
            $optArr = array_filter(array_map('trim', explode("\n", $rawOptions)));
            $options = !empty($optArr) ? json_encode(array_values($optArr)) : null;
        }

        $existing = Database::fetchOne(
            "SELECT id FROM custom_field_definitions WHERE entity_type = ? AND field_key = ?",
            [$entityType, $fieldKey]
        );
        if ($existing) {
            $_SESSION['flash_error'] = "A field with key '{$fieldKey}' already exists for entity type '{$entityType}'.";
            header('Location: /admin/custom-fields'); return;
        }

        $fieldId = Database::insert('custom_field_definitions', [
            'entity_type' => $entityType,
            'field_key'   => $fieldKey,
            'label'       => $label,
            'field_type'  => $fieldType,
            'options'     => $options,
            'is_required' => $required,
            'sort_order'  => $sortOrder,
        ]);

        Auth::log('create_custom_field', 'custom_field_definitions', $fieldId, [
            'entity_type' => $entityType,
            'field_key'   => $fieldKey,
            'field_type'  => $fieldType,
        ]);
        $_SESSION['flash_success'] = "Custom field '{$label}' created.";
        header('Location: /admin/custom-fields');
    }

    public function deleteCustomField(string $id): void {
        Auth::requireAdmin();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        $field = Database::fetchOne("SELECT * FROM custom_field_definitions WHERE id = ?", [$id]);
        if (!$field) {
            $_SESSION['flash_error'] = 'Custom field not found.';
            header('Location: /admin/custom-fields'); return;
        }

        $valueCount = (int)(Database::fetchOne(
            "SELECT COUNT(*) as c FROM custom_field_values WHERE field_id = ?", [$id]
        )['c'] ?? 0);

        if ($valueCount > 0 && empty($_GET['force']) && empty($_POST['force'])) {
            $_SESSION['flash_error'] = "This field has {$valueCount} stored value(s). Append ?force=1 to the URL or submit with force=1 to delete anyway.";
            header('Location: /admin/custom-fields'); return;
        }

        Database::query("DELETE FROM custom_field_definitions WHERE id = ?", [$id]);
        Auth::log('delete_custom_field', 'custom_field_definitions', $id, [
            'entity_type' => $field['entity_type'],
            'field_key'   => $field['field_key'],
            'had_values'  => $valueCount,
        ]);
        $_SESSION['flash_success'] = "Custom field '{$field['label']}' deleted.";
        header('Location: /admin/custom-fields');
    }

    public function riskAppetite(): void {
        Auth::requireAdmin();
        $rows = Database::fetchAll("SELECT * FROM risk_appetite ORDER BY category");
        $pageTitle    = 'Risk Appetite';
        $activeModule = 'admin_risk_appetite';
        $breadcrumbs  = [['Administration', '/admin'], ['Risk Appetite', null]];
        ob_start();
        require AEGIS_ROOT . '/views/admin/risk_appetite.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveRiskAppetite(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $ids             = (array)($_POST['id'] ?? []);
        $categories      = (array)($_POST['category'] ?? []);
        $appetites       = (array)($_POST['appetite'] ?? []);
        $statements      = (array)($_POST['statement'] ?? []);
        $maxScores       = (array)($_POST['max_score'] ?? []);
        $amberThresholds = (array)($_POST['amber_threshold'] ?? []);
        $redThresholds   = (array)($_POST['red_threshold'] ?? []);
        $valid           = ['zero','low','moderate','high'];
        foreach ($ids as $i => $id) {
            $id             = (int)$id;
            $category       = trim(Security::sanitizeInput($categories[$i] ?? ''));
            $appetite       = in_array($appetites[$i] ?? '', $valid, true) ? $appetites[$i] : 'low';
            $statement      = trim(Security::sanitizeInput($statements[$i] ?? ''));
            $maxScore       = ($maxScores[$i] ?? '') !== '' ? (int)$maxScores[$i] : null;
            $amberThreshold = ($amberThresholds[$i] ?? '') !== '' ? (int)$amberThresholds[$i] : null;
            $redThreshold   = ($redThresholds[$i] ?? '') !== '' ? (int)$redThresholds[$i] : null;
            if (!$id || !$category || !$statement) continue;
            Database::query(
                "UPDATE risk_appetite SET category=?, appetite=?, statement=?, max_score=?,
                  amber_threshold=?, red_threshold=?, updated_by=?, updated_at=NOW() WHERE id=?",
                [$category, $appetite, $statement, $maxScore, $amberThreshold, $redThreshold, Auth::id(), $id]
            );
        }
        // Handle new rows
        $newCats   = (array)($_POST['new_category'] ?? []);
        $newApps   = (array)($_POST['new_appetite'] ?? []);
        $newStmts  = (array)($_POST['new_statement'] ?? []);
        $newMax    = (array)($_POST['new_max_score'] ?? []);
        $newAmber  = (array)($_POST['new_amber_threshold'] ?? []);
        $newRed    = (array)($_POST['new_red_threshold'] ?? []);
        foreach ($newCats as $i => $cat) {
            $cat   = trim(Security::sanitizeInput($cat));
            $app   = in_array($newApps[$i] ?? '', $valid, true) ? $newApps[$i] : 'low';
            $stmt  = trim(Security::sanitizeInput($newStmts[$i] ?? ''));
            $max   = ($newMax[$i] ?? '') !== '' ? (int)$newMax[$i] : null;
            $amber = ($newAmber[$i] ?? '') !== '' ? (int)$newAmber[$i] : null;
            $red   = ($newRed[$i] ?? '') !== '' ? (int)$newRed[$i] : null;
            if (!$cat || !$stmt) continue;
            Database::insert('risk_appetite', ['category'=>$cat,'appetite'=>$app,'statement'=>$stmt,'max_score'=>$max,'amber_threshold'=>$amber,'red_threshold'=>$red,'updated_by'=>Auth::id()]);
        }
        Auth::log('risk_appetite_updated', 'risk_appetite', 0, []);
        $_SESSION['flash_success'] = 'Risk appetite saved.';
        header('Location: /admin/risk-appetite');
    }

    // ─── Incident SLA Policy ──────────────────────────────────────────────────

    public function slaPolicy(): void {
        Auth::requireAdmin();
        $policies = Database::fetchAll(
            "SELECT * FROM incident_sla_policies ORDER BY CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END"
        );
        $pageTitle    = 'Incident SLA Policy';
        $activeModule = 'admin_sla';
        $breadcrumbs  = [['Administration', '/admin'], ['SLA Policy', null]];
        ob_start();
        require AEGIS_ROOT . '/views/admin/sla_policy.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveSlaPolicy(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $ids  = (array)($_POST['id'] ?? []);
        $acks = (array)($_POST['acknowledge_hours'] ?? []);
        $ress = (array)($_POST['resolve_hours'] ?? []);
        $escs = (array)($_POST['escalate_hours'] ?? []);
        foreach ($ids as $i => $id) {
            $id  = (int)$id;
            $ack = max(1, (int)($acks[$i] ?? 4));
            $res = max(1, (int)($ress[$i] ?? 72));
            $esc = ($escs[$i] ?? '') !== '' ? max(1, (int)$escs[$i]) : null;
            Database::query(
                "UPDATE incident_sla_policies SET acknowledge_hours=?, resolve_hours=?, escalate_hours=? WHERE id=?",
                [$ack, $res, $esc, $id]
            );
        }
        Auth::log('sla_policy_updated', 'incident_sla_policies', 0, []);
        $_SESSION['flash_success'] = 'SLA policy saved.';
        header('Location: /admin/sla-policy');
    }

    // ─── Email Templates ──────────────────────────────────────────────────────

    public function emailTemplates(): void {
        Auth::requireAdmin();
        $templates    = Database::fetchAll("SELECT * FROM email_templates ORDER BY type");
        $pageTitle    = 'Email Templates';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Email Templates', null]];
        require AEGIS_ROOT . '/views/admin/email_templates.php';
    }

    public function emailTemplateForm(string $id): void {
        Auth::requireAdmin();
        $template = Database::fetchOne("SELECT * FROM email_templates WHERE id = ?", [(int)$id]);
        if (!$template) { http_response_code(404); return; }
        $template['variables'] = json_decode($template['variables'] ?? '[]', true) ?: [];
        $pageTitle    = 'Edit Template: ' . $template['name'];
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Email Templates', '/admin/email-templates'], ['Edit', null]];
        require AEGIS_ROOT . '/views/admin/email_template_form.php';
    }

    public function updateEmailTemplate(string $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $id = (int)$id;
        $template = Database::fetchOne("SELECT id FROM email_templates WHERE id = ?", [$id]);
        if (!$template) { http_response_code(404); return; }

        Database::query(
            "UPDATE email_templates SET name=?, subject=?, body_html=?, body_text=?, is_active=?, updated_by=?, updated_at=NOW() WHERE id=?",
            [
                Security::sanitizeInput($_POST['name'] ?? ''),
                Security::sanitizeInput($_POST['subject'] ?? ''),
                Security::sanitizeHtml($_POST['body_html'] ?? ''),
                Security::sanitizeInput($_POST['body_text'] ?? ''),
                isset($_POST['is_active']) ? true : false,
                Auth::id(),
                $id,
            ]
        );
        Auth::log('update_email_template', 'email_templates', $id);
        $_SESSION['flash_success'] = 'Template updated.';
        header('Location: /admin/email-templates/' . $id . '/edit');
    }

    public function previewEmailTemplate(string $id): void {
        Auth::requireAdmin();
        $template = Database::fetchOne("SELECT body_html, variables FROM email_templates WHERE id = ?", [(int)$id]);
        if (!$template) { http_response_code(404); return; }
        $vars = json_decode($template['variables'] ?? '[]', true) ?: [];
        $html = $template['body_html'];
        foreach ($vars as $v) {
            $html = str_replace('{{' . $v . '}}', '<span style="background:#fef08a;padding:0 3px;border-radius:3px">[' . htmlspecialchars($v, ENT_QUOTES) . ']</span>', $html);
        }
        // Serve inside a sandboxed iframe wrapper to prevent script execution in parent context
        $escaped = htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; img-src \'self\' data:; sandbox');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . Security::sanitizeHtml($html) . '</body></html>';
    }

    // ─── Scheduled Reports ────────────────────────────────────────────────────

    public function scheduledReports(): void {
        Auth::requireAdmin();
        $schedules    = Database::fetchAll("SELECT * FROM report_schedules ORDER BY name");
        $pageTitle    = 'Scheduled Reports';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Scheduled Reports', null]];
        require AEGIS_ROOT . '/views/admin/scheduled_reports.php';
    }

    public function scheduledReportForm(string $id = ''): void {
        Auth::requireAdmin();
        $schedule = $id ? Database::fetchOne("SELECT * FROM report_schedules WHERE id = ?", [(int)$id]) : null;
        if ($id && !$schedule) { http_response_code(404); return; }
        if ($schedule) {
            $schedule['recipients_arr'] = implode("\n", json_decode($schedule['recipients'] ?? '[]', true) ?: []);
        }
        $pageTitle    = $schedule ? 'Edit Report Schedule' : 'New Report Schedule';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Scheduled Reports', '/admin/scheduled-reports'], [$schedule ? 'Edit' : 'New', null]];
        require AEGIS_ROOT . '/views/admin/scheduled_report_form.php';
    }

    public function createScheduledReport(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $recipients = array_filter(array_map('trim', explode("\n", $_POST['recipients'] ?? '')));
        $newId = Database::insert('report_schedules', [
            'name'          => Security::sanitizeInput($_POST['name'] ?? ''),
            'report_type'   => Security::sanitizeInput($_POST['report_type'] ?? 'risk_register'),
            'frequency'     => Security::sanitizeInput($_POST['frequency'] ?? 'weekly'),
            'day_of_week'   => !empty($_POST['day_of_week']) ? (int)$_POST['day_of_week'] : 1,
            'day_of_month'  => !empty($_POST['day_of_month']) ? (int)$_POST['day_of_month'] : 1,
            'send_time'     => Security::sanitizeInput($_POST['send_time'] ?? '08:00'),
            'recipients'    => json_encode(array_values($recipients)),
            'format'        => in_array($_POST['format'] ?? '', ['html','csv','both']) ? $_POST['format'] : 'html',
            'is_active'     => isset($_POST['is_active']),
            'created_by'    => Auth::id(),
        ]);
        Auth::log('create_report_schedule', 'report_schedules', $newId);
        $_SESSION['flash_success'] = 'Report schedule created.';
        header('Location: /admin/scheduled-reports');
    }

    public function updateScheduledReport(string $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $id = (int)$id;
        $recipients = array_filter(array_map('trim', explode("\n", $_POST['recipients'] ?? '')));
        Database::query(
            "UPDATE report_schedules SET name=?, report_type=?, frequency=?, day_of_week=?, day_of_month=?,
             send_time=?, recipients=?, format=?, is_active=?, updated_at=NOW() WHERE id=?",
            [
                Security::sanitizeInput($_POST['name'] ?? ''),
                Security::sanitizeInput($_POST['report_type'] ?? 'risk_register'),
                Security::sanitizeInput($_POST['frequency'] ?? 'weekly'),
                !empty($_POST['day_of_week']) ? (int)$_POST['day_of_week'] : 1,
                !empty($_POST['day_of_month']) ? (int)$_POST['day_of_month'] : 1,
                Security::sanitizeInput($_POST['send_time'] ?? '08:00'),
                json_encode(array_values($recipients)),
                in_array($_POST['format'] ?? '', ['html','csv','both']) ? $_POST['format'] : 'html',
                isset($_POST['is_active']),
                $id,
            ]
        );
        Auth::log('update_report_schedule', 'report_schedules', $id);
        $_SESSION['flash_success'] = 'Report schedule updated.';
        header('Location: /admin/scheduled-reports');
    }

    public function deleteScheduledReport(string $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        Database::query("DELETE FROM report_schedules WHERE id = ?", [(int)$id]);
        Auth::log('delete_report_schedule', 'report_schedules', (int)$id);
        $_SESSION['flash_success'] = 'Report schedule deleted.';
        header('Location: /admin/scheduled-reports');
    }

    // ─── Email Delivery Log ───────────────────────────────────────────────────

    public function emailDelivery(): void {
        Auth::requireAdmin();
        $type       = Security::sanitizeInput($_GET['type'] ?? '');
        $recipient  = Security::sanitizeInput($_GET['recipient'] ?? '');
        $from       = Security::sanitizeInput($_GET['from'] ?? '');
        $to         = Security::sanitizeInput($_GET['to'] ?? '');
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $perPage    = 50;
        $offset     = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];
        if ($type)      { $where[] = 'notification_type = ?'; $params[] = $type; }
        if ($recipient) { $where[] = 'recipient_email ILIKE ?'; $params[] = "%{$recipient}%"; }
        if ($from)      { $where[] = 'sent_at >= ?'; $params[] = $from . ' 00:00:00'; }
        if ($to)        { $where[] = 'sent_at <= ?'; $params[] = $to . ' 23:59:59'; }
        $whereSQL = implode(' AND ', $where);

        $params[] = $perPage;
        $params[] = $offset;
        $logs  = Database::fetchAll(
            "SELECT * FROM notification_log WHERE {$whereSQL} ORDER BY sent_at DESC LIMIT ? OFFSET ?",
            $params
        );
        array_pop($params); array_pop($params); // remove limit/offset for the count query
        $total = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM notification_log WHERE {$whereSQL}", $params)['c'] ?? 0);
        $pages = (int)ceil($total / $perPage);

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) AS total_all,
               COUNT(*) FILTER (WHERE sent_at >= CURRENT_DATE) AS today,
               COUNT(*) FILTER (WHERE sent_at >= date_trunc('week', CURRENT_DATE)) AS this_week,
               COUNT(*) FILTER (WHERE sent_at >= date_trunc('month', CURRENT_DATE)) AS this_month
             FROM notification_log"
        );

        $types = Database::fetchAll("SELECT DISTINCT notification_type FROM notification_log ORDER BY notification_type");

        $pageTitle    = 'Email Delivery Log';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Email Delivery Log', null]];
        require AEGIS_ROOT . '/views/admin/email_delivery.php';
    }

    // ─── Alert Config ─────────────────────────────────────────────────────────

    public function alertConfigForm(string $id = ''): void {
        Auth::requireAdmin();
        $config = $id ? Database::fetchOne("SELECT * FROM alert_configs WHERE id = ?", [(int)$id]) : null;
        if ($id && !$config) { http_response_code(404); return; }
        if ($config) {
            $config['recipients'] = json_decode($config['recipients'] ?? '[]', true) ?: [];
            $config['channels']   = json_decode($config['channels']   ?? '["in_app"]', true) ?: ['in_app'];
            $config['trigger_config'] = json_decode($config['trigger_config'] ?? '{}', true) ?: [];
        }
        $pageTitle    = $config ? 'Edit Alert Config' : 'New Alert Config';
        $activeModule = 'admin';
        $breadcrumbs  = [['Admin', '/admin'], ['Alerts', '/admin/alerts'], [$config ? 'Edit' : 'New', null]];
        require AEGIS_ROOT . '/views/admin/alert_config_form.php';
    }

    public function saveAlertConfig(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $id         = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $name       = Security::sanitizeInput($_POST['name'] ?? '');
        $type       = Security::sanitizeInput($_POST['type'] ?? '');
        $recipients = array_filter(array_map('trim', explode("\n", $_POST['recipients'] ?? '')));
        $channels   = array_intersect((array)($_POST['channels'] ?? ['in_app']), ['in_app','email','webhook']);
        $isActive   = isset($_POST['is_active']);
        $triggerRaw = $_POST['trigger_config'] ?? '{}';
        $triggerCfg = json_decode($triggerRaw, true) ?: [];

        if ($id) {
            Database::query(
                "UPDATE alert_configs SET name=?, type=?, trigger_config=?::jsonb, recipients=?::jsonb, channels=?::jsonb, is_active=?, updated_at=NOW() WHERE id=?",
                [$name, $type, json_encode($triggerCfg), json_encode(array_values($recipients)), json_encode(array_values($channels)), $isActive, $id]
            );
            Auth::log('update_alert_config', 'alert_configs', $id);
        } else {
            $id = Database::insert('alert_configs', [
                'name'           => $name,
                'type'           => $type,
                'trigger_config' => json_encode($triggerCfg),
                'recipients'     => json_encode(array_values($recipients)),
                'channels'       => json_encode(array_values($channels)),
                'is_active'      => $isActive,
            ]);
            Auth::log('create_alert_config', 'alert_configs', $id);
        }
        $_SESSION['flash_success'] = 'Alert configuration saved.';
        header('Location: /admin/alerts');
    }

    public function deleteAlertConfig(string $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        Database::query("DELETE FROM alert_configs WHERE id = ?", [(int)$id]);
        Auth::log('delete_alert_config', 'alert_configs', (int)$id);
        $_SESSION['flash_success'] = 'Alert configuration deleted.';
        header('Location: /admin/alerts');
    }

    // ─── Module Visibility ────────────────────────────────────────────────────
    public function moduleVisibility(): void {
        Auth::requireAdmin();
        $rows = Database::fetchAll("SELECT key, value FROM settings WHERE key LIKE 'module_hide_%'");
        $hidden = array_column($rows, 'value', 'key');
        $pageTitle    = 'Module Visibility';
        $activeModule = 'admin_module_visibility';
        $breadcrumbs  = [['Admin', '/admin'], ['Module Visibility', null]];
        require AEGIS_ROOT . '/views/admin/module_visibility.php';
    }

    public function saveModuleVisibility(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $modules = [
            'compliance','control_testing','compliance_gap','import','bulk_import',
            'audit','policy','incident','playbooks','issue','change','bcp','incident_sla','questionnaire',
            'risk','risk_matrix','risk_roadmap','risk_exceptions','threats','treatment_plans','kris','vendor','vendor_contracts','assets',
            'metrics','documents','report','report_board','export','calendar',
            'search','docs',
        ];
        foreach ($modules as $mod) {
            // Checkbox is checked = module is VISIBLE → hide = '0'
            // Checkbox is unchecked = module is HIDDEN → hide = '1'
            $hide = isset($_POST['hide'][$mod]) ? '0' : '1';
            Database::query(
                "INSERT INTO settings (key, value, type, description) VALUES (?,?,'boolean','Module visibility')
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
                ['module_hide_' . $mod, $hide]
            );
        }
        Auth::log('update_module_visibility', 'settings', 0);
        $_SESSION['flash_success'] = 'Module visibility saved.';
        header('Location: /admin/module-visibility');
    }
}
