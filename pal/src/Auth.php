<?php
/**
 * Auth — authentication, RBAC and the hash-chained audit log for the PAL
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
        // PAL Administrator — manages the whole library short of system settings
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

    /** Friendly label for a role key. */
    public static function roleLabel(?string $role = null): string {
        $role = $role ?? self::role();
        return [
            'admin'             => 'System Administrator',
            'pal_admin'         => 'PAL Administrator',
            'compliance_admin'  => 'Compliance Administrator',
            'space_owner'       => 'Space Owner',
            'contributor'       => 'Contributor',
            'reviewer'          => 'Reviewer',
            'approver'          => 'Approver',
            'auditor'           => 'Auditor',
            'viewer'            => 'Viewer',
        ][$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    public static function roleKeys(): array {
        return ['admin','pal_admin','compliance_admin','space_owner','contributor','reviewer','approver','auditor','viewer'];
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

    /** Default granted module.action strings inherited from a role (read-only view). */
    public static function roleDefaults(string $role): array {
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

        // Flatten role defaults
        $granted = [];
        foreach (self::$roleDefaults[$role] ?? [] as $module => $actions) {
            foreach ($actions as $action) {
                $granted[] = $module . '.' . $action;
            }
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

    public static function login(string $email, string $password): bool {
        $email = strtolower(trim($email));
        $ip = Security::clientIp();

        if (!Security::checkRateLimit('login_' . $ip)) return false;
        if (!Security::checkRateLimit('login_email_' . hash('sha256', $email))) return false;

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
            return false;
        }

        Security::resetRateLimit('login_' . $ip);
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
        return true;
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
