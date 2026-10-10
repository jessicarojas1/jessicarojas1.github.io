<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Authentication: session establishment and local email/password sign-in.
 *
 * Entra ID GCC High SSO (section 5 of the build directive) is NOT implemented
 * in this build pass — it is explicitly a Phase 4 integration item, and the
 * directive itself prohibits live external connections in this development
 * environment. `/auth/sso` responds honestly (503 + guidance) rather than
 * faking a sign-in flow. See docs/GCC_HIGH_INTEGRATION.md.
 */
final class Auth
{
    /**
     * Minimum/maximum password length. Length over complexity rules,
     * following NIST SP 800-63B guidance — no forced symbol/digit mixing.
     * The maximum is defense-in-depth against pathologically large inputs
     * being hashed, not a real-world password constraint.
     */
    public const MIN_PASSWORD_LENGTH = 12;
    public const MAX_PASSWORD_LENGTH = 128;

    /** Null when valid; otherwise a user-facing reason the password was rejected. */
    public static function passwordPolicyError(string $password): ?string
    {
        $len = strlen($password);
        if ($len < self::MIN_PASSWORD_LENGTH) {
            return 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if ($len > self::MAX_PASSWORD_LENGTH) {
            return 'Password must be at most ' . self::MAX_PASSWORD_LENGTH . ' characters.';
        }
        return null;
    }

    /** Verify a password against a specific user id (for self-service "current password" checks). */
    public static function verifyPassword(int $userId, string $password): bool
    {
        if (!Db::isConfigured() || $password === '') {
            return false;
        }
        $row = Db::fetchOne('SELECT password_hash FROM app_user WHERE id = :id', ['id' => $userId]);
        return $row !== null && !empty($row['password_hash']) && password_verify($password, (string) $row['password_hash']);
    }

    /**
     * Per-request cache only — never per-session. Re-fetching on every
     * request (rather than trusting a snapshot taken at login) is what makes
     * a permission change, password reset, or disable take effect on the
     * very next request instead of only at the affected user's next login.
     * See CLAUDE.md / OPEN_ITEMS.md for the incident that prompted this.
     */
    private static ?array $requestUser = null;
    private static bool $requestUserLoaded = false;

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        Session::start();
        if (self::$requestUserLoaded) {
            return self::$requestUser;
        }
        self::$requestUserLoaded = true;

        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return self::$requestUser = null;
        }
        if (!Db::isConfigured()) {
            // Dev-without-a-database edge case: nothing to re-validate against.
            return self::$requestUser = null;
        }

        $fresh = self::loadCurrentUser((int) $userId);
        if ($fresh === null || $fresh['status'] !== 'active') {
            // Account was disabled or deleted since this session began —
            // terminate it now rather than letting a stale cookie keep working.
            Audit::log('auth.session_terminated', 'app_user#' . $userId, null, ['reason' => $fresh === null ? 'deleted' : $fresh['status']], null, (int) $userId);
            Session::destroy();
            return self::$requestUser = null;
        }
        return self::$requestUser = $fresh;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            self::redirect('/auth/login');
        }
    }

    /**
     * Rate-limit window/thresholds for failed login attempts, enforced
     * against the existing append-only `audit_event` log (every failed
     * attempt is already recorded there — no separate table needed). Two
     * independent limits: per-account (stops a targeted brute force against
     * one email) and per-IP (stops a credential-stuffing spray across many
     * emails from one source). Both are soft, time-boxed lockouts — they
     * expire on their own once the window passes, not a permanent lock —
     * which is a deliberate tradeoff: a hard per-account lockout with no
     * expiry would let an attacker lock a legitimate user out indefinitely
     * just by repeatedly guessing their password wrong.
     */
    public const LOGIN_ATTEMPT_WINDOW_MINUTES = 15;
    public const MAX_FAILED_ATTEMPTS_PER_EMAIL = 10;
    public const MAX_FAILED_ATTEMPTS_PER_IP = 30;

    /**
     * Verify local email + password and, on success, establish the session.
     * Returns false on any failure (bad credentials, no password set,
     * inactive, or currently rate-limited).
     */
    public static function attemptLocal(string $email, string $password): bool
    {
        $normalizedEmail = strtolower(trim($email));
        if (self::isLoginRateLimited($normalizedEmail)) {
            Audit::denied('auth.login_throttled', $normalizedEmail);
            return false;
        }
        $userId = self::checkLocalCredentials($email, $password);
        if ($userId === null) {
            Audit::denied('auth.login_failed', $normalizedEmail);
            return false;
        }
        self::establishForUser($userId);
        return true;
    }

    /** Public check so the controller can show an accurate message — enforcement itself happens inside attemptLocal() regardless. */
    public static function isLoginThrottled(string $email): bool
    {
        return self::isLoginRateLimited(strtolower(trim($email)));
    }

    private static function isLoginRateLimited(string $normalizedEmail): bool
    {
        if (!Db::isConfigured()) {
            return false;
        }
        // The threshold is computed by Postgres itself (NOW() - INTERVAL),
        // never passed as a PHP-formatted timestamp string: PHP has no way
        // to know what session TimeZone setting Postgres will interpret a
        // naive (no-offset) string against, and a mismatch there silently
        // shifts the window — found and fixed during this build (gmdate()'s
        // UTC string was being reinterpreted in the server's local zone,
        // pushing "since" hours into the future and making the count always
        // read 0). Let Postgres do all the time arithmetic in one place.
        $windowMinutes = self::LOGIN_ATTEMPT_WINDOW_MINUTES;

        $emailFailures = (int) Db::fetchValue(
            "SELECT COUNT(*) FROM audit_event
             WHERE action = 'auth.login_failed' AND target = :target
               AND created_at > NOW() - make_interval(mins => :window_minutes)",
            ['target' => $normalizedEmail, 'window_minutes' => $windowMinutes]
        );
        if ($emailFailures >= self::MAX_FAILED_ATTEMPTS_PER_EMAIL) {
            return true;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip !== null) {
            $ipFailures = (int) Db::fetchValue(
                "SELECT COUNT(*) FROM audit_event
                 WHERE action = 'auth.login_failed' AND ip = :ip
                   AND created_at > NOW() - make_interval(mins => :window_minutes)",
                ['ip' => $ip, 'window_minutes' => $windowMinutes]
            );
            if ($ipFailures >= self::MAX_FAILED_ATTEMPTS_PER_IP) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate local credentials WITHOUT establishing a session (pure check,
     * unit-testable without touching $_SESSION).
     */
    public static function checkLocalCredentials(string $email, string $password): ?int
    {
        if (!Db::isConfigured() || $email === '' || $password === '') {
            return null;
        }
        $row = Db::fetchOne(
            'SELECT id, password_hash, status FROM app_user WHERE lower(email) = :e',
            ['e' => strtolower(trim($email))]
        );
        if ($row === null || empty($row['password_hash']) || $row['status'] !== 'active') {
            return null;
        }
        return password_verify($password, (string) $row['password_hash']) ? (int) $row['id'] : null;
    }

    /** Set/replace a local password (argon2id/bcrypt via PHP's default). */
    public static function setPassword(int $userId, string $password, ?int $actorId = null): void
    {
        Db::update('app_user', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $userId]);
        Audit::log('auth.password_set', 'app_user#' . $userId, null, null, null, $actorId);
    }

    /** Build the session for a known app_user id (used by local login). */
    public static function establishForUser(int $userId): void
    {
        $u = self::loadCurrentUser($userId);
        if ($u === null) {
            self::fail('Account not found.');
        }
        Session::regenerate();
        // Only the id is persisted in the session — Auth::user() re-fetches
        // everything else (roles, grants, status, name, email) fresh on
        // every request. See the doc comment on Auth::user().
        $_SESSION['user_id'] = $u['id'];
        self::$requestUser = $u;
        self::$requestUserLoaded = true;
        Audit::log('auth.login', 'app_user#' . $userId);
    }

    /** @return array<string,mixed>|null */
    private static function loadCurrentUser(int $userId): ?array
    {
        $u = Db::fetchOne(
            'SELECT id, email, display_name, status, person_id FROM app_user WHERE id = :id',
            ['id' => $userId]
        );
        if ($u === null) {
            return null;
        }
        return [
            'id' => (int) $u['id'],
            'email' => (string) $u['email'],
            'name' => (string) $u['display_name'],
            'status' => (string) $u['status'],
            'person_id' => $u['person_id'] !== null ? (int) $u['person_id'] : null,
            'roles' => self::loadRoles($userId),
            'grants' => self::loadGrants($userId),
        ];
    }

    public static function logout(): void
    {
        $u = self::user();
        Audit::log('auth.logout', $u !== null ? 'app_user#' . $u['id'] : null);
        Session::destroy();
        self::redirect('/');
    }

    /** @return string[] */
    private static function loadRoles(int $userId): array
    {
        $rows = Db::fetchAll('SELECT role_key FROM app_user_role WHERE user_id = :uid', ['uid' => $userId]);
        return array_map(static fn ($r) => (string) $r['role_key'], $rows);
    }

    /** @return array{grant: string[], deny: string[]} */
    private static function loadGrants(int $userId): array
    {
        $rows = Db::fetchAll(
            'SELECT permission_key, effect FROM user_permission_grant WHERE user_id = :uid',
            ['uid' => $userId]
        );
        $out = ['grant' => [], 'deny' => []];
        foreach ($rows as $r) {
            $bucket = ($r['effect'] === 'deny') ? 'deny' : 'grant';
            $out[$bucket][] = (string) $r['permission_key'];
        }
        return $out;
    }

    private static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    private static function fail(string $message): never
    {
        Audit::denied('auth.fail', $message);
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "401 Unauthorized\n\n" . $message;
        exit;
    }
}
