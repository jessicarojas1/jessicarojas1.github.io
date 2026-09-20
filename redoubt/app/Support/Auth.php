<?php

declare(strict_types=1);

namespace Redoubt\Support;

use RuntimeException;
use Throwable;

/**
 * Authentication: Entra GCC High OIDC sign-in, session, and mapping of the
 * signed-in identity to the application user (with program/company memberships,
 * explicit grants, and US-person status) used by the Authorize engine.
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

    // --- Local authentication (works with no external IdP) ------------------

    /**
     * Verify local email + password and, on success, establish the session.
     * Returns false on any failure (bad credentials, no password set, inactive).
     */
    public static function attemptLocal(string $email, string $password): bool
    {
        $userId = self::checkLocalCredentials($email, $password);
        if ($userId === null) {
            return false;
        }
        self::establishForUser($userId);
        return true;
    }

    /**
     * Validate local credentials WITHOUT establishing a session (pure check).
     * Returns the app_user id on success, or null. (Session-free so it's testable.)
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
        if ($row === null || empty($row['password_hash']) || !in_array($row['status'], ['active', 'invited'], true)) {
            return null;
        }
        return password_verify($password, (string) $row['password_hash']) ? (int) $row['id'] : null;
    }

    /** Set/replace a local password (argon2id/bcrypt via PHP default). */
    public static function setPassword(int $userId, string $password, ?int $actorId = null): void
    {
        Db::update('app_user', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $userId]);
        Audit::log('auth.password_set', 'user#' . $userId, null, $actorId);
    }

    /** Build the session for a known app_user id (used by local login + setup). */
    public static function establishForUser(int $userId): void
    {
        $u = Db::fetchOne('SELECT id, entra_oid, display_name, email, kind, is_us_person FROM app_user WHERE id = :id', ['id' => $userId]);
        if ($u === null) {
            self::fail('Account not found.');
        }
        Session::regenerate();
        $_SESSION['user'] = [
            'id'           => (int) $u['id'],
            'entra_oid'    => $u['entra_oid'],
            'name'         => $u['display_name'],
            'email'        => $u['email'],
            'kind'         => (string) $u['kind'],
            'is_us_person' => $u['is_us_person'] === null ? null : (bool) $u['is_us_person'],
            'memberships'  => self::loadMemberships($userId),
            'grants'       => self::loadGrants($userId),
            'companies'    => self::loadCompanies($userId),
        ];
        Audit::log('auth.login', (string) ($u['email'] ?? $u['entra_oid'] ?? ''), null, (int) $u['id']);
    }

    /** Step 1: redirect the browser to Entra to authenticate. */
    public static function beginLogin(): void
    {
        if (!Config::authConfigured()) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Sign-in is not configured yet.\n\nSet ENTRA_TENANT_ID / ENTRA_CLIENT_ID / "
               . "ENTRA_CLIENT_SECRET / ENTRA_REDIRECT_URI (GCC High) — see deployments/AZURE.md.";
            exit;
        }
        Session::start();
        $state    = bin2hex(random_bytes(16));
        $nonce    = bin2hex(random_bytes(16));
        $verifier = Oidc::b64url(random_bytes(48));
        $_SESSION['oidc'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 't' => time()];
        self::redirect(Oidc::authorizeUrl($state, $nonce, $verifier));
    }

    /** Step 2: handle the redirect back from Entra. */
    public static function completeLogin(array $query): void
    {
        Session::start();
        $saved = $_SESSION['oidc'] ?? null;
        unset($_SESSION['oidc']);

        if (!$saved || !isset($query['state']) || !hash_equals($saved['state'], (string) $query['state'])) {
            self::fail('Invalid sign-in state.');
        }
        if (isset($query['error'])) {
            self::fail('Sign-in error: ' . (string) ($query['error_description'] ?? $query['error']));
        }
        if (!isset($query['code'])) {
            self::fail('Missing authorization code.');
        }

        try {
            $tokens = Oidc::exchangeCode((string) $query['code'], (string) $saved['verifier']);
            $claims = Oidc::validateIdToken((string) ($tokens['id_token'] ?? ''), (string) $saved['nonce']);
        } catch (Throwable $e) {
            self::fail('Sign-in failed: ' . $e->getMessage());
        }

        $user = self::resolveUser($claims);
        Session::regenerate();
        $_SESSION['user'] = $user;
        Audit::log('auth.login', $user['entra_oid'] ?? null, null, $user['id'] ?? null);
        self::redirect('/app');
    }

    public static function logout(): void
    {
        $u = self::user();
        Audit::log('auth.logout', $u['entra_oid'] ?? null, null, $u['id'] ?? null);
        Session::destroy();
        // Optionally also hit the Entra end-session endpoint; kept local for now.
        self::redirect('/');
    }

    /**
     * Map OIDC claims to an application user. Uses the DB when configured
     * (upsert + membership/grant load); otherwise returns an ephemeral user with
     * no program access (honest: sign-in works, but authorization grants nothing).
     * @param array<string,mixed> $claims @return array<string,mixed>
     */
    private static function resolveUser(array $claims): array
    {
        $oid   = (string) ($claims['oid'] ?? $claims['sub'] ?? '');
        $name  = (string) ($claims['name'] ?? 'Unknown');
        $email = (string) ($claims['preferred_username'] ?? $claims['email'] ?? '');

        if (!Db::isConfigured()) {
            return [
                'id' => null, 'entra_oid' => $oid, 'name' => $name, 'email' => $email,
                'kind' => 'internal', 'is_us_person' => null,
                'memberships' => [], 'grants' => [], 'companies' => [],
            ];
        }

        try {
            $row = Db::fetchOne('SELECT id, kind, is_us_person FROM app_user WHERE entra_oid = :oid', ['oid' => $oid]);
            if ($row === null) {
                $id = Db::insert('app_user', [
                    'entra_oid' => $oid, 'display_name' => $name, 'email' => $email,
                    'kind' => 'internal', 'status' => 'active',
                ]);
                $kind = 'internal';
                $isUsPerson = null;
            } else {
                $id = (int) $row['id'];
                $kind = (string) $row['kind'];
                $isUsPerson = isset($row['is_us_person']) ? (bool) $row['is_us_person'] : null;
                Db::update('app_user', ['display_name' => $name, 'email' => $email], ['id' => $id]);
            }

            return [
                'id' => $id, 'entra_oid' => $oid, 'name' => $name, 'email' => $email,
                'kind' => $kind, 'is_us_person' => $isUsPerson,
                'memberships' => self::loadMemberships($id),
                'grants'      => self::loadGrants($id),
                'companies'   => self::loadCompanies($id),
            ];
        } catch (Throwable $e) {
            error_log('[AUTH] user resolve failed: ' . $e->getMessage());
            self::fail('Could not establish your account. Contact the program administrator.');
        }
    }

    /** @return array<int,array{roles:string[],company_id:?int}> */
    private static function loadMemberships(int $userId): array
    {
        $rows = Db::fetchAll(
            'SELECT pm.program_id, r.key AS role_key, pm.company_id
               FROM program_membership pm JOIN role r ON r.id = pm.role_id
              WHERE pm.user_id = :uid AND (pm.expires_at IS NULL OR pm.expires_at > NOW())',
            ['uid' => $userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['program_id'];
            $out[$pid] ??= ['roles' => [], 'company_id' => $r['company_id'] !== null ? (int) $r['company_id'] : null];
            $out[$pid]['roles'][] = (string) $r['role_key'];
        }
        return $out;
    }

    /** @return array<int,array{grant:string[],deny:string[]}> */
    private static function loadGrants(int $userId): array
    {
        $rows = Db::fetchAll(
            'SELECT program_id, permission_key, effect FROM user_permission_grant WHERE user_id = :uid',
            ['uid' => $userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['program_id'];
            $out[$pid] ??= ['grant' => [], 'deny' => []];
            $bucket = ($r['effect'] === 'deny') ? 'deny' : 'grant';
            $out[$pid][$bucket][] = (string) $r['permission_key'];
        }
        return $out;
    }

    /** @return int[] */
    private static function loadCompanies(int $userId): array
    {
        $rows = Db::fetchAll('SELECT company_id FROM company_membership WHERE user_id = :uid', ['uid' => $userId]);
        return array_map(static fn ($r) => (int) $r['company_id'], $rows);
    }

    private static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    private static function fail(string $message): never
    {
        Audit::log('auth.fail', $message);
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "401 Unauthorized\n\n" . $message;
        exit;
    }
}
