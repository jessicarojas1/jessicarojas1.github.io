<?php
/**
 * Auth — authentication, RBAC and the hash-chained audit log for the PALADIN
 * platform. Role defaults are inherited from the user's role; explicit grants
 * stored in `user_permissions` override/extend them. Coarse legacy strings
 * (module.read / module.write) map to granular actions via $aliases.
 */
class Auth {
    private static ?array $permCache = null;

    /**
     * Granular module × action defaults per role. The `admin` role bypasses
     * all checks in can(). Modules:
     *   space, page, document, process, workflow, approval, task,
     *   template, report, search
     */
    private static array $roleDefaults = [
        // PALADIN Administrator — manages the whole library short of system settings
        'pal_admin' => [
            'space'    => ['view','create','edit','delete','manage'],
            'page'     => ['view','create','edit','delete','publish','review','comment'],
            'document' => ['view','create','edit','delete','publish','approve','review','checkout','acknowledge'],
            'process'  => ['view','create','edit','delete','publish'],
            'workflow' => ['view','manage'],
            'approval' => ['view','approve'],
            'task'     => ['view','create','edit','complete'],
            'template' => ['view','manage'],
            'report'   => ['view','export'],
            'search'   => ['view'],
        ],
        // Compliance Administrator — governance + approvals + audit, no space deletion
        'compliance_admin' => [
            'space'    => ['view','create','edit','manage'],
            'page'     => ['view','create','edit','publish','review','comment'],
            'document' => ['view','create','edit','publish','approve','review','checkout','acknowledge'],
            'process'  => ['view','create','edit','publish'],
            'workflow' => ['view','manage'],
            'approval' => ['view','approve'],
            'task'     => ['view','create','edit','complete'],
            'template' => ['view','manage'],
            'report'   => ['view','export'],
            'search'   => ['view'],
        ],
        // Space Owner — full authority within spaces they own
        'space_owner' => [
            'space'    => ['view','create','edit','manage'],
            'page'     => ['view','create','edit','delete','publish','review','comment'],
            'document' => ['view','create','edit','delete','publish','review','checkout','acknowledge'],
            'process'  => ['view','create','edit','publish'],
            'workflow' => ['view'],
            'approval' => ['view','approve'],
            'task'     => ['view','create','edit','complete'],
            'template' => ['view'],
            'report'   => ['view','export'],
            'search'   => ['view'],
        ],
        // Contributor — authors pages/documents, submits for approval
        'contributor' => [
            'space'    => ['view'],
            'page'     => ['view','create','edit','comment'],
            'document' => ['view','create','edit','checkout','acknowledge'],
            'process'  => ['view','create','edit'],
            'workflow' => ['view'],
            'approval' => ['view'],
            'task'     => ['view','create','edit','complete'],
            'template' => ['view'],
            'report'   => ['view'],
            'search'   => ['view'],
        ],
        // Reviewer — reviews and comments, cannot approve
        'reviewer' => [
            'space'    => ['view'],
            'page'     => ['view','review','comment'],
            'document' => ['view','review','acknowledge'],
            'process'  => ['view'],
            'workflow' => ['view'],
            'approval' => ['view'],
            'task'     => ['view','complete'],
            'template' => ['view'],
            'report'   => ['view'],
            'search'   => ['view'],
        ],
        // Approver — approval authority in workflows
        'approver' => [
            'space'    => ['view'],
            'page'     => ['view','review','comment'],
            'document' => ['view','review','acknowledge'],
            'process'  => ['view'],
            'workflow' => ['view'],
            'approval' => ['view','approve'],
            'task'     => ['view','complete'],
            'template' => ['view'],
            'report'   => ['view'],
            'search'   => ['view'],
        ],
        // Auditor — read-only across everything + reports/export, evidence traceability
        'auditor' => [
            'space'    => ['view'],
            'page'     => ['view'],
            'document' => ['view'],
            'process'  => ['view'],
            'workflow' => ['view'],
            'approval' => ['view'],
            'task'     => ['view'],
            'template' => ['view'],
            'report'   => ['view','export'],
            'search'   => ['view'],
        ],
        // Viewer / Read-only — consume content, record read receipts
        'viewer' => [
            'space'    => ['view'],
            'page'     => ['view'],
            'document' => ['view','acknowledge'],
            'process'  => ['view'],
            'workflow' => ['view'],
            'approval' => ['view'],
            'task'     => ['view'],
            'template' => ['view'],
            'report'   => ['view'],
            'search'   => ['view'],
        ],
    ];

    /** Backward-compat coarse → granular permission aliases. */
    private static array $aliases = [
        'space.read'     => ['space.view'],
        'space.write'    => ['space.create','space.edit','space.delete','space.manage'],
        'page.read'      => ['page.view'],
        'page.write'     => ['page.create','page.edit','page.delete','page.publish'],
        'document.read'  => ['document.view'],
        'document.write' => ['document.create','document.edit','document.delete','document.publish'],
        'process.read'   => ['process.view'],
        'process.write'  => ['process.create','process.edit','process.delete','process.publish'],
        'workflow.read'  => ['workflow.view'],
        'workflow.write' => ['workflow.manage'],
        'approval.read'  => ['approval.view'],
        'task.read'      => ['task.view'],
        'task.write'     => ['task.create','task.edit','task.complete'],
        'template.read'  => ['template.view'],
        'template.write' => ['template.manage'],
        'report.read'    => ['report.view'],
    ];

    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function check(): bool   { return isset($_SESSION['user']); }
    public static function id(): ?int      { return $_SESSION['user']['id'] ?? null; }
    public static function role(): string  { return $_SESSION['user']['role'] ?? 'viewer'; }

    private static ?array $customRolesCache = null;

    /** Friendly label for a role key (built-in or custom). */
    public static function roleLabel(?string $role = null): string {
        $role = $role ?? self::role();
        $builtin = [
            'admin'             => 'System Administrator',
            'pal_admin'         => 'PALADIN Administrator',
            'compliance_admin'  => 'Compliance Administrator',
            'space_owner'       => 'Space Owner',
            'contributor'       => 'Contributor',
            'reviewer'          => 'Reviewer',
            'approver'          => 'Approver',
            'auditor'           => 'Auditor',
            'viewer'            => 'Viewer',
        ];
        if (isset($builtin[$role])) return $builtin[$role];
        $custom = self::customRoles();
        if (isset($custom[$role])) return $custom[$role]['name'];
        return ucfirst(str_replace('_', ' ', $role));
    }

    public static function roleKeys(): array {
        return ['admin','pal_admin','compliance_admin','space_owner','contributor','reviewer','approver','auditor','viewer'];
    }

    /** Built-in role key? (everything else is a custom role) */
    public static function isBuiltinRole(string $role): bool {
        return in_array($role, self::roleKeys(), true);
    }

    /**
     * Custom roles map: role_key => ['id'=>int,'name'=>string,'description'=>string,
     * 'perms'=>['module.action',...]]. Cached per request; never throws.
     */
    public static function customRoles(): array {
        if (self::$customRolesCache !== null) return self::$customRolesCache;
        $map = [];
        try {
            foreach (Database::fetchAll("SELECT id, role_key, name, description FROM custom_roles ORDER BY name") as $r) {
                $map[$r['role_key']] = ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'perms' => []];
            }
            if ($map) {
                $byId = [];
                foreach ($map as $k => $v) $byId[$v['id']] = $k;
                foreach (Database::fetchAll("SELECT role_id, module, permission FROM custom_role_permissions") as $p) {
                    $k = $byId[(int)$p['role_id']] ?? null;
                    if ($k !== null) $map[$k]['perms'][] = $p['module'] . '.' . $p['permission'];
                }
            }
        } catch (Throwable) { $map = []; }
        return self::$customRolesCache = $map;
    }

    /** Clear the per-request custom-role cache (after create/update/delete). */
    public static function clearRoleCache(): void { self::$customRolesCache = null; }

    /** All assignable roles as role_key => label (built-in + custom), for dropdowns. */
    public static function allRoleOptions(): array {
        $opts = [];
        foreach (self::roleKeys() as $k) $opts[$k] = self::roleLabel($k);
        foreach (self::customRoles() as $k => $v) $opts[$k] = $v['name'] . ' (custom)';
        return $opts;
    }

    /** Is this an assignable role (built-in or an existing custom role)? */
    public static function roleExists(string $role): bool {
        return self::isBuiltinRole($role) || isset(self::customRoles()[$role]);
    }

    /** Full module → actions catalog (drives the IAM permission editor). */
    public static function moduleCatalog(): array {
        return [
            'space'    => ['view','create','edit','delete','manage'],
            'page'     => ['view','create','edit','delete','publish','review','comment'],
            'document' => ['view','create','edit','delete','publish','approve','review','checkout','acknowledge'],
            'process'  => ['view','create','edit','delete','publish'],
            'workflow' => ['view','manage'],
            'approval' => ['view','approve'],
            'task'     => ['view','create','edit','complete'],
            'template' => ['view','manage'],
            'report'   => ['view','export'],
            'search'   => ['view'],
        ];
    }

    /** Friendly metadata (icon + colour) for a module, for the IAM UI. */
    public static function moduleMeta(string $module): array {
        return [
            'space'    => ['bi-collection-fill',       '#2563eb'],
            'page'     => ['bi-file-richtext-fill',     '#6366f1'],
            'document' => ['bi-file-earmark-text-fill', '#0284c7'],
            'process'  => ['bi-diagram-3-fill',         '#059669'],
            'workflow' => ['bi-diagram-2-fill',         '#8b5cf6'],
            'approval' => ['bi-check2-square',          '#d97706'],
            'task'     => ['bi-list-task',              '#f97316'],
            'template' => ['bi-files',                  '#0d9488'],
            'report'   => ['bi-bar-chart-line-fill',    '#db2777'],
            'search'   => ['bi-search',                 '#64748b'],
        ][$module] ?? ['bi-square', '#64748b'];
    }

    /** Default granted module.action strings inherited from a role (built-in or custom). */
    public static function roleDefaults(string $role): array {
        if (!isset(self::$roleDefaults[$role]) && !self::isBuiltinRole($role)) {
            $custom = self::customRoles();
            return $custom[$role]['perms'] ?? [];
        }
        $out = [];
        foreach (self::$roleDefaults[$role] ?? [] as $module => $actions) {
            foreach ($actions as $a) $out[] = $module . '.' . $a;
        }
        return $out;
    }

    public static function can(string $permission): bool {
        $role = self::role();
        if ($role === 'admin') return true;
        if ($permission === 'admin') return false;

        // Flatten role defaults (built-in role) or load the custom role's perms
        $granted = [];
        if (isset(self::$roleDefaults[$role])) {
            foreach (self::$roleDefaults[$role] as $module => $actions) {
                foreach ($actions as $action) {
                    $granted[] = $module . '.' . $action;
                }
            }
        } elseif (!self::isBuiltinRole($role)) {
            $custom = self::customRoles();
            $granted = $custom[$role]['perms'] ?? [];
        }

        // Explicit DB grants (cached per request)
        if (self::$permCache === null) {
            self::$permCache = [];
            if (self::check()) {
                try {
                    $rows = Database::fetchAll(
                        "SELECT module, permission FROM user_permissions WHERE user_id = ?",
                        [self::id()]
                    );
                    foreach ($rows as $r) {
                        self::$permCache[] = $r['module'] . '.' . $r['permission'];
                    }
                } catch (Throwable) {}
            }
        }
        $granted = array_unique(array_merge($granted, self::$permCache));

        if (isset(self::$aliases[$permission])) {
            foreach (self::$aliases[$permission] as $aliased) {
                if (in_array($aliased, $granted, true)) return true;
            }
            return false;
        }
        return in_array($permission, $granted, true);
    }

    public static function requireAuth(): void {
        if (!self::check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: /login');
            exit;
        }

        $cfg = require __DIR__ . '/../config/app.php';
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $cfg['session_lifetime'])) {
            self::logout();
            header('Location: /login?reason=timeout');
            exit;
        }

        // Server-side session revocation / account deactivation
        try {
            $dbUser = Database::fetchOne(
                "SELECT sessions_revoked_at, force_password_change FROM users WHERE id = ? AND is_active = TRUE",
                [self::id()]
            );
            if (!$dbUser) {
                self::logout();
                header('Location: /login?reason=account_disabled');
                exit;
            }
            $loginTime = $_SESSION['user']['login_time'] ?? 0;
            if (!empty($dbUser['sessions_revoked_at']) && strtotime($dbUser['sessions_revoked_at']) > $loginTime) {
                self::logout();
                header('Location: /login?reason=revoked');
                exit;
            }
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (!empty($dbUser['force_password_change']) && !in_array($uri, ['/profile/edit', '/logout', '/login'], true)) {
                $_SESSION['flash_warning'] = 'You must change your password before continuing.';
                header('Location: /profile/edit');
                exit;
            }
        } catch (Throwable) {}

        $_SESSION['last_activity'] = time();
    }

    public static function requirePermission(string $permission): void {
        self::requireAuth();
        if (!self::can($permission)) {
            http_response_code(403);
            require __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }

    public static function requireAdmin(): void {
        self::requireAuth();
        if (self::role() !== 'admin') {
            http_response_code(403);
            require __DIR__ . '/../views/errors/403.php';
            exit;
        }
    }

    /** Returns 'ok' (logged in), 'mfa' (password ok, awaiting 2FA), or 'fail'. */
    public static function login(string $email, string $password): string {
        $email = strtolower(trim($email));
        $ip = Security::clientIp();

        if (!Security::checkRateLimit('login_' . $ip)) return 'fail';
        if (!Security::checkRateLimit('login_email_' . hash('sha256', $email))) return 'fail';

        $user = Database::fetchOne("SELECT * FROM users WHERE email = ? AND is_active = TRUE", [$email]);
        if (!$user || !Security::verifyPassword($password, $user['password_hash'])) {
            try {
                $prev = Database::fetchOne("SELECT log_hash FROM activity_log ORDER BY id DESC LIMIT 1");
                $prevHash = $prev['log_hash'] ?? 'genesis';
                $ts = date('Y-m-d\TH:i:s\Z');
                $payload = implode('|', [$prevHash, 'system', 'login_failed', 'users', '0', $email, $ip, $ts]);
                $logHash = hash('sha256', $payload);
                Database::query(
                    "INSERT INTO activity_log (user_id, action, entity_type, ip_address, user_agent, log_hash)
                     VALUES (NULL, 'login_failed', 'users', ?, ?, ?)",
                    [$ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $logHash]
                );
            } catch (Throwable) {}
            return 'fail';
        }

        Security::resetRateLimit('login_' . $ip);

        // Password verified. If the account has MFA enabled, hold a pending
        // step until a valid TOTP code is supplied (do NOT establish a session).
        if (!empty($user['mfa_enabled']) && !empty($user['mfa_secret'])) {
            session_regenerate_id(true);
            $_SESSION['mfa_user_id'] = (int)$user['id'];
            $_SESSION['mfa_time']    = time();
            return 'mfa';
        }

        self::finalizeLogin($user);
        return 'ok';
    }

    /** Complete a pending MFA login with a TOTP code. */
    public static function completeMfa(string $code): bool {
        $uid = $_SESSION['mfa_user_id'] ?? null;
        $started = $_SESSION['mfa_time'] ?? 0;
        if (!$uid || (time() - $started) > 300) { // 5-minute window to finish 2FA
            unset($_SESSION['mfa_user_id'], $_SESSION['mfa_time']);
            return false;
        }
        $user = Database::fetchOne("SELECT * FROM users WHERE id = ? AND is_active = TRUE", [$uid]);
        if (!$user || empty($user['mfa_secret'])) return false;
        // Accept a valid TOTP code, or a one-time recovery code as a fallback.
        $ok = TOTP::verify($user['mfa_secret'], $code) || self::consumeRecoveryCode((int)$uid, $code);
        if (!$ok) return false;
        unset($_SESSION['mfa_user_id'], $_SESSION['mfa_time']);
        self::finalizeLogin($user);
        return true;
    }

    /** Normalise a recovery code (case/format insensitive). */
    private static function normalizeRecoveryCode(string $code): string {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /**
     * Generate a fresh set of one-time recovery codes for a user, replacing any
     * existing ones. Returns the plaintext codes (shown to the user only once).
     * @return string[]
     */
    public static function generateRecoveryCodes(int $userId, int $count = 10): array {
        try {
            Database::query("DELETE FROM mfa_recovery_codes WHERE user_id = ?", [$userId]);
            $plain = [];
            for ($i = 0; $i < $count; $i++) {
                $raw = bin2hex(random_bytes(5)); // 10 hex chars
                $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
                $plain[] = $code;
                Database::insert('mfa_recovery_codes', [
                    'user_id'   => $userId,
                    'code_hash' => Security::hashPassword(self::normalizeRecoveryCode($code)),
                ]);
            }
            return $plain;
        } catch (\Throwable) { return []; }
    }

    /** Verify and consume (single-use) a recovery code. */
    public static function consumeRecoveryCode(int $userId, string $code): bool {
        $norm = self::normalizeRecoveryCode($code);
        if ($norm === '') return false;
        try {
            $rows = Database::fetchAll(
                "SELECT id, code_hash FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL", [$userId]
            );
        } catch (\Throwable) { return false; }
        foreach ($rows as $r) {
            if (Security::verifyPassword($norm, $r['code_hash'])) {
                Database::query("UPDATE mfa_recovery_codes SET used_at = NOW() WHERE id = ?", [(int)$r['id']]);
                self::log('mfa_recovery_used', 'users', $userId);
                return true;
            }
        }
        return false;
    }

    public static function recoveryCodesRemaining(int $userId): int {
        try {
            return (int)(Database::fetchOne(
                "SELECT COUNT(*) c FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL", [$userId]
            )['c'] ?? 0);
        } catch (\Throwable) { return 0; }
    }

    public static function mfaPending(): bool {
        return !empty($_SESSION['mfa_user_id']);
    }

    private static function finalizeLogin(array $user): void {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'         => $user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'login_time' => time(),
        ];
        $_SESSION['last_activity'] = time();
        Database::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        self::log('login', 'users', (int)$user['id']);
    }

    /** Establish a session for a user already authenticated by an external IdP (SAML SSO). */
    public static function ssoLogin(array $user): void {
        self::finalizeLogin($user);
        self::log('sso_login', 'users', (int)$user['id']);
    }

    public static function logout(): void {
        session_destroy();
        session_start();
    }

    /** Append an immutable, hash-chained audit record for the current user. */
    public static function log(string $action, ?string $entityType, ?int $entityId, ?array $changes = null): void {
        if (!self::check()) return;
        $ip          = Security::clientIp();
        $userAgent   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $changesJson = $changes ? json_encode($changes) : null;

        $prev = Database::fetchOne("SELECT log_hash FROM activity_log ORDER BY id DESC LIMIT 1");
        $prevHash = $prev['log_hash'] ?? 'genesis';
        $payload  = implode('|', [$prevHash, (string)self::id(), $action, (string)$entityType, (string)$entityId, (string)$changesJson, $ip]);
        $logHash  = hash('sha256', $payload);

        Database::query(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, changes, ip_address, user_agent, log_hash)
             VALUES (?,?,?,?,?,?,?,?)",
            [self::id(), $action, $entityType, $entityId, $changesJson, $ip, $userAgent, $logHash]
        );
    }

    public static function logSystem(string $action, ?string $entityType = null, ?int $entityId = null): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'system';
        $ts = date('Y-m-d\TH:i:s\Z');
        $prev = Database::fetchOne("SELECT log_hash FROM activity_log ORDER BY id DESC LIMIT 1");
        $prevHash = $prev['log_hash'] ?? 'genesis';
        $payload  = implode('|', [$prevHash, 'system', $action, (string)$entityType, (string)$entityId, '', $ip, $ts]);
        $logHash  = hash('sha256', $payload);
        Database::query(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, ip_address, log_hash)
             VALUES (NULL,?,?,?,?,?)",
            [$action, $entityType, $entityId, $ip, $logHash]
        );
    }
}
