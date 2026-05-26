<?php
class Auth {
    private static ?array $permCache = null;

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

        $defaults = [
            'manager' => ['compliance.read', 'compliance.write', 'compliance.edit',
                          'audit.read', 'audit.write', 'audit.edit',
                          'policy.read', 'policy.write', 'policy.edit',
                          'risk.read', 'risk.write', 'risk.edit',
                          'incident.read', 'incident.write', 'incident.edit',
                          'vendor.read', 'vendor.write', 'vendor.edit',
                          'issue.read', 'issue.write', 'issue.edit'],
            'auditor' => ['compliance.read', 'audit.read', 'audit.write', 'audit.edit',
                          'policy.read', 'risk.read',
                          'incident.read', 'incident.write',
                          'vendor.read', 'issue.read', 'issue.write'],
            'analyst' => ['compliance.read', 'audit.read', 'policy.read',
                          'risk.read', 'risk.write', 'risk.edit',
                          'incident.read', 'vendor.read',
                          'issue.read', 'issue.write'],
            'viewer'  => ['compliance.read', 'audit.read', 'policy.read', 'risk.read',
                          'incident.read', 'vendor.read', 'issue.read'],
        ];

        if (in_array($permission, $defaults[$role] ?? [])) return true;

        // Check explicit DB grants (cached per request)
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

        return in_array($permission, self::$permCache);
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!Security::checkRateLimit('login_' . $ip)) return false;

        $user = Database::fetchOne("SELECT * FROM users WHERE email = ? AND is_active = TRUE", [$email]);
        if (!$user || !Security::verifyPassword($password, $user['password_hash'])) return false;

        Security::resetRateLimit('login_' . $ip);
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
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

    public static function log(string $action, ?string $entityType, ?int $entityId, ?array $changes = null): void {
        if (!self::check()) return;
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $changesJson = $changes ? json_encode($changes) : null;

        // Hash chain: SHA-256( prev_hash | userId | action | entityType | entityId | changes | ip | now )
        $prev = Database::fetchOne("SELECT log_hash FROM activity_log ORDER BY id DESC LIMIT 1");
        $prevHash = $prev['log_hash'] ?? 'genesis';
        $payload  = implode('|', [
            $prevHash,
            (string)self::id(),
            $action,
            (string)$entityType,
            (string)$entityId,
            (string)$changesJson,
            $ip,
        ]);
        $logHash = hash('sha256', $payload);

        Database::query(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, changes, ip_address, user_agent, log_hash)
             VALUES (?,?,?,?,?,?,?,?)",
            [self::id(), $action, $entityType, $entityId, $changesJson, $ip, $userAgent, $logHash]
        );
    }

    public static function logSystem(string $action, ?string $entityType = null, ?int $entityId = null): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'system';
        $prev = Database::fetchOne("SELECT log_hash FROM activity_log ORDER BY id DESC LIMIT 1");
        $prevHash = $prev['log_hash'] ?? 'genesis';
        $payload  = implode('|', [$prevHash, 'system', $action, (string)$entityType, (string)$entityId, '', $ip]);
        $logHash  = hash('sha256', $payload);
        Database::query(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, ip_address, log_hash)
             VALUES (NULL,?,?,?,?,?)",
            [$action, $entityType, $entityId, $ip, $logHash]
        );
    }
}
