<?php
class Auth {
    private static ?array $permCache = null;

    private static array $roleDefaults = [
        'manager' => [
            'risk'       => ['view','create','edit','delete','accept','review','treatment','scenarios','bowtie','export'],
            'compliance' => ['view','create','assess','import','test','gap'],
            'audit'      => ['view','create','edit','findings','close'],
            'policy'     => ['view','create','edit','publish','attest'],
            'incident'   => ['view','create','edit','close','playbook'],
            'vendor'     => ['view','create','edit','assess','contracts','questionnaire'],
            'issue'      => ['view','create','edit'],
            'change'     => ['view','create','edit','approve'],
            'threat'     => ['view','create','edit'],
            'awareness'  => ['view','manage'],
            'asset'      => ['view','create','edit'],
            'kri'        => ['view','manage','record'],
            'bcp'        => ['view','edit','exercise'],
            'ssp'        => ['view','edit'],
            'report'     => ['view'],
            'automation' => ['view','manage'],
            'approval'   => ['view','approve'],
        ],
        'analyst' => [
            'risk'       => ['view','create','edit','review','treatment','scenarios','bowtie'],
            'compliance' => ['view','assess','test','gap'],
            'audit'      => ['view','findings'],
            'policy'     => ['view','attest'],
            'incident'   => ['view','create','edit'],
            'vendor'     => ['view','assess'],
            'issue'      => ['view','create','edit'],
            'change'     => ['view','create'],
            'threat'     => ['view','create'],
            'awareness'  => ['view'],
            'asset'      => ['view','create'],
            'kri'        => ['view','record'],
            'bcp'        => ['view'],
            'ssp'        => ['view'],
            'report'     => ['view'],
            'automation' => ['view'],
            'approval'   => ['view'],
        ],
        'viewer' => [
            'risk'       => ['view'],
            'compliance' => ['view'],
            'audit'      => ['view'],
            'policy'     => ['view','attest'],
            'incident'   => ['view'],
            'vendor'     => ['view'],
            'issue'      => ['view'],
            'change'     => ['view'],
            'threat'     => ['view'],
            'awareness'  => ['view'],
            'asset'      => ['view'],
            'kri'        => ['view'],
            'bcp'        => ['view'],
            'ssp'        => ['view'],
            'report'     => ['view'],
            'automation' => ['view'],
            'approval'   => ['view'],
        ],

        // Auditor — broad read across all modules plus full ownership of audits
        // and findings. (Previously offered in the UI with NO defaults defined,
        // leaving auditor users with zero permissions — this fixes that.)
        'auditor' => [
            'risk'       => ['view'],
            'compliance' => ['view','test','gap'],
            'audit'      => ['view','create','edit','findings','close'],
            'policy'     => ['view'],
            'incident'   => ['view'],
            'vendor'     => ['view','assess'],
            'issue'      => ['view','create','edit'],
            'change'     => ['view'],
            'threat'     => ['view'],
            'awareness'  => ['view'],
            'asset'      => ['view'],
            'kri'        => ['view'],
            'bcp'        => ['view'],
            'ssp'        => ['view'],
            'report'     => ['view'],
            'automation' => ['view'],
            'approval'   => ['view'],
        ],

        // Control Owner — implements and evidences controls; owns policies they attest.
        'control_owner' => [
            'risk'       => ['view','treatment'],
            'compliance' => ['view','assess','test'],
            'audit'      => ['view','findings'],
            'policy'     => ['view','attest'],
            'incident'   => ['view'],
            'vendor'     => ['view'],
            'issue'      => ['view','create','edit'],
            'asset'      => ['view','create','edit'],
            'kri'        => ['view','record'],
            'ssp'        => ['view','edit'],
            'report'     => ['view'],
            'approval'   => ['view'],
        ],

        // Risk Owner — owns the risk lifecycle (incl. acceptance) and KRIs.
        'risk_owner' => [
            'risk'       => ['view','create','edit','accept','review','treatment','scenarios','bowtie','export'],
            'compliance' => ['view'],
            'audit'      => ['view'],
            'policy'     => ['view'],
            'incident'   => ['view','create'],
            'vendor'     => ['view'],
            'issue'      => ['view','create','edit'],
            'asset'      => ['view'],
            'kri'        => ['view','manage','record'],
            'report'     => ['view'],
            'approval'   => ['view','approve'],
        ],

        // Executive — read everything, run reports, and approve.
        'executive' => [
            'risk'       => ['view','export'],
            'compliance' => ['view'],
            'audit'      => ['view'],
            'policy'     => ['view'],
            'incident'   => ['view'],
            'vendor'     => ['view'],
            'issue'      => ['view'],
            'change'     => ['view'],
            'threat'     => ['view'],
            'awareness'  => ['view'],
            'asset'      => ['view'],
            'kri'        => ['view'],
            'bcp'        => ['view'],
            'ssp'        => ['view'],
            'report'     => ['view'],
            'automation' => ['view'],
            'approval'   => ['view','approve'],
        ],
    ];

    /**
     * Canonical role → display-label map. Single source of truth for the role
     * dropdowns (admin user form, SSO mapping) and server-side role validation.
     */
    public const ROLES = [
        'admin'         => 'Administrator',
        'manager'       => 'Manager',
        'auditor'       => 'Auditor',
        'control_owner' => 'Control Owner',
        'risk_owner'    => 'Risk Owner',
        'analyst'       => 'Analyst',
        'executive'     => 'Executive',
        'viewer'        => 'Viewer',
    ];

    /** All assignable roles as role => label. */
    public static function roles(): array
    {
        return self::ROLES;
    }

    /** Whether a string is an assignable role. */
    public static function isValidRole(string $role): bool
    {
        return isset(self::ROLES[$role]);
    }

    /**
     * Flattened "module.action" permissions a role grants by default (no DB
     * lookups). Returns ['*'] for admin (all permissions). Pure — unit-testable.
     */
    public static function roleDefaultPermissions(string $role): array
    {
        if ($role === 'admin') {
            return ['*'];
        }
        $granted = [];
        foreach (self::$roleDefaults[$role] ?? [] as $module => $actions) {
            foreach ($actions as $action) {
                $granted[] = $module . '.' . $action;
            }
        }
        return $granted;
    }

    private static array $aliases = [
        'risk.read'        => ['risk.view'],
        'risk.write'       => ['risk.create','risk.edit','risk.delete','risk.accept','risk.review','risk.treatment','risk.scenarios'],
        'risk.edit'        => ['risk.edit','risk.delete'],
        'compliance.read'  => ['compliance.view'],
        'compliance.write' => ['compliance.create','compliance.assess','compliance.import','compliance.test','compliance.gap'],
        'compliance.edit'  => ['compliance.assess','compliance.test'],
        'audit.read'       => ['audit.view'],
        'audit.write'      => ['audit.create','audit.edit','audit.findings','audit.close'],
        'audit.edit'       => ['audit.edit','audit.findings'],
        'policy.read'      => ['policy.view'],
        'policy.write'     => ['policy.create','policy.edit','policy.publish','policy.attest'],
        'policy.edit'      => ['policy.edit','policy.publish'],
        'incident.read'    => ['incident.view'],
        'incident.write'   => ['incident.create','incident.edit','incident.close','incident.playbook'],
        'incident.edit'    => ['incident.edit','incident.close'],
        'vendor.read'      => ['vendor.view'],
        'vendor.write'     => ['vendor.create','vendor.edit','vendor.assess','vendor.contracts','vendor.questionnaire'],
        'vendor.edit'      => ['vendor.edit','vendor.assess'],
        'issue.read'       => ['issue.view'],
        'issue.write'      => ['issue.create','issue.edit'],
        'change.read'      => ['change.view'],
        'change.write'     => ['change.create','change.edit','change.approve'],
        'threat.read'      => ['threat.view'],
        'threat.write'     => ['threat.create','threat.edit'],
        'awareness.read'   => ['awareness.view'],
        'asset.read'       => ['asset.view'],
        'asset.write'      => ['asset.create','asset.edit'],
        'kri.read'         => ['kri.view'],
        'automation.read'  => ['automation.view'],
        'automation.write' => ['automation.manage'],
        'approval.read'    => ['approval.view'],
        'ssp.read'         => ['ssp.view'],
        'bcp.read'         => ['bcp.view'],
        'report.read'      => ['report.view'],
    ];

    public static function user(): ?array {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool {
        return isset($_SESSION['user']);
    }

    public static function id(): ?int {
        return $_SESSION['user']['id'] ?? null;
    }

    public static function role(): string {
        return $_SESSION['user']['role'] ?? 'viewer';
    }

    public static function can(string $permission): bool {
        $role = self::role();
        if ($role === 'admin') return true;

        // Build flat $granted from role defaults (pure resolver — see roleDefaultPermissions)
        $granted = self::roleDefaultPermissions($role);

        // Load explicit DB grants (cached per request)
        if (self::$permCache === null) {
            self::$permCache = [];
            if (self::check()) {
                $rows = Database::fetchAll(
                    "SELECT module, permission FROM user_permissions WHERE user_id = ?",
                    [self::id()]
                );
                foreach ($rows as $r) {
                    self::$permCache[] = $r['module'] . '.' . $r['permission'];
                }
            }
        }

        // Merge explicit DB grants into the granted set
        $granted = array_unique(array_merge($granted, self::$permCache));

        // If permission is an alias key, check if ANY aliased permission is in $granted
        if (isset(self::$aliases[$permission])) {
            foreach (self::$aliases[$permission] as $aliased) {
                if (in_array($aliased, $granted)) return true;
            }
            return false;
        }

        return in_array($permission, $granted);
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

        // Server-side session revocation: if an admin deactivated this account
        // after our login, force immediate logout (SOC 2 CC6.5, NIST 800-53 AC-2)
        try {
            $dbUser = Database::fetchOne(
                "SELECT sessions_revoked_at, force_password_change FROM users WHERE id = ? AND is_active = TRUE",
                [self::id()]
            );
            if (!$dbUser) {
                // Account deactivated — force logout
                self::logout();
                header('Location: /login?reason=account_disabled');
                exit;
            }
            $loginTime = $_SESSION['user']['login_time'] ?? 0;
            if (!empty($dbUser['sessions_revoked_at'])
                && strtotime($dbUser['sessions_revoked_at']) > $loginTime) {
                self::logout();
                header('Location: /login?reason=revoked');
                exit;
            }
            // Force password change check (N171-G3 / IA-5)
            if (!empty($dbUser['force_password_change'])
                && !in_array($_SERVER['REQUEST_URI'], ['/profile/edit', '/logout'])) {
                // Allow only profile/edit so they can change it; redirect everything else
                $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                if (!in_array($uri, ['/profile/edit', '/logout', '/login'])) {
                    $_SESSION['flash_warning'] = 'You must change your password before continuing.';
                    header('Location: /profile/edit');
                    exit;
                }
            }
            // Password expiry enforcement (NIST 800-171 3.5.6, CMMC IA.L2-3.5.6)
            try {
                $expiryRow  = Database::fetchOne("SELECT value FROM settings WHERE key = 'password_expiry_days'");
                $expiryDays = (int)($expiryRow['value'] ?? 0);
                if ($expiryDays > 0) {
                    $changedRow = Database::fetchOne("SELECT password_changed_at, created_at FROM users WHERE id = ?", [self::id()]);
                    $changedAt  = $changedRow['password_changed_at'] ?? $changedRow['created_at'] ?? null;
                    if ($changedAt && ((time() - strtotime($changedAt)) / 86400) > $expiryDays) {
                        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                        if (!in_array($uri, ['/profile/edit', '/logout', '/login'])) {
                            $_SESSION['flash_warning'] = 'Your password has expired. Please update it to continue.';
                            header('Location: /profile/edit');
                            exit;
                        }
                    }
                }
            } catch (Throwable) {}
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
        self::requirePermission('admin');
    }

    public static function login(string $email, string $password): bool {
        $email = strtolower(trim($email));
        $ip = Security::clientIp();

        if (!Security::checkRateLimit('login_' . $ip)) return false;
        if (!Security::checkRateLimit('login_email_' . hash('sha256', $email))) return false;

        $user = Database::fetchOne("SELECT * FROM users WHERE email = ? AND is_active = TRUE", [$email]);
        if (!$user || !Security::verifyPassword($password, $user['password_hash'])) {
            // Log the failed attempt for audit trail (NIST 800-53 AU-2, CMMC AC.L1-3.1.1)
            try {
                self::appendAuditLog(null, 'login_failed', 'users', null,
                    json_encode(['email' => $email]), $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
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
        self::log('login', null, null);
        return true;
    }

    public static function logout(): void {
        session_destroy();
        session_start();
    }

    /**
     * Keyed audit-chain hash over the ordered payload parts (element 0 must be the
     * previous row's hash). HMAC-SHA256 with the audit key (see Security::auditKey)
     * — an attacker who can write the database but cannot read the key cannot forge
     * the chain, unlike the previous unkeyed SHA-256. Pure — unit-tested.
     */
    public static function computeLogHash(array $parts, ?string $key = null): string {
        return hash_hmac('sha256', implode('|', $parts), $key ?? Security::auditKey());
    }

    /**
     * Append one row to the tamper-evident audit chain.
     *
     * Serialized with a PostgreSQL session advisory lock so concurrent requests
     * cannot read the same previous hash and fork the chain (which would also
     * cause false-positive verification failures). The lock is best-effort — if it
     * cannot be taken, the row is still written — and is always released.
     *
     * The hashed payload mirrors EXACTLY the columns the verifier reconstructs
     * (user_id, action, entity_type, entity_id, changes, ip) so every row — user,
     * system, and failed-login alike — is verifiable. user_id is '' for system rows.
     */
    private static function appendAuditLog(
        ?int $userId, string $action, ?string $entityType, ?int $entityId,
        ?string $changesJson, string $ip, ?string $userAgent
    ): void {
        $locked = false;
        try {
            try {
                Database::query("SELECT pg_advisory_lock(hashtext('aegis_audit_chain'))");
                $locked = true;
            } catch (Throwable $e) {
                error_log('[AEGIS] audit advisory lock unavailable: ' . $e->getMessage());
            }

            $prev = Database::fetchOne("SELECT log_hash FROM activity_log ORDER BY id DESC LIMIT 1");
            $prevHash = $prev['log_hash'] ?? 'genesis';
            $logHash  = self::computeLogHash([
                $prevHash,
                (string)($userId ?? ''),
                $action,
                (string)$entityType,
                (string)$entityId,
                (string)$changesJson,
                $ip,
            ]);
            Database::query(
                "INSERT INTO activity_log (user_id, action, entity_type, entity_id, changes, ip_address, user_agent, log_hash)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$userId, $action, $entityType, $entityId, $changesJson, $ip, $userAgent, $logHash]
            );
        } finally {
            if ($locked) {
                try { Database::query("SELECT pg_advisory_unlock(hashtext('aegis_audit_chain'))"); } catch (Throwable) {}
            }
        }
    }

    public static function log(string $action, ?string $entityType, ?int $entityId, ?array $changes = null): void {
        if (!self::check()) return;
        $ip          = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $changesJson = $changes ? json_encode($changes) : null;
        self::appendAuditLog(self::id(), $action, $entityType, $entityId, $changesJson, $ip, $userAgent);
    }

    public static function logSystem(string $action, ?string $entityType = null, ?int $entityId = null): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'system';
        self::appendAuditLog(null, $action, $entityType, $entityId, null, $ip, null);
    }
}
