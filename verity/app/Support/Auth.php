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
    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        Session::start();
        return $_SESSION['user'] ?? null;
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
     * Verify local email + password and, on success, establish the session.
     * Returns false on any failure (bad credentials, no password set, inactive).
     */
    public static function attemptLocal(string $email, string $password): bool
    {
        $userId = self::checkLocalCredentials($email, $password);
        if ($userId === null) {
            Audit::denied('auth.login_failed', $email);
            return false;
        }
        self::establishForUser($userId);
        return true;
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
        $u = Db::fetchOne(
            'SELECT id, email, display_name, status, person_id FROM app_user WHERE id = :id',
            ['id' => $userId]
        );
        if ($u === null) {
            self::fail('Account not found.');
        }
        Session::regenerate();
        $_SESSION['user'] = [
            'id' => (int) $u['id'],
            'email' => (string) $u['email'],
            'name' => (string) $u['display_name'],
            'status' => (string) $u['status'],
            'person_id' => $u['person_id'] !== null ? (int) $u['person_id'] : null,
            'roles' => self::loadRoles($userId),
            'grants' => self::loadGrants($userId),
        ];
        Audit::log('auth.login', 'app_user#' . $userId);
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
