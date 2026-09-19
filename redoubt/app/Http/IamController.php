<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\PermissionCatalog;
use Redoubt\Support\Roles;
use Redoubt\Support\Security;
use Throwable;

/**
 * Two-pane IAM console (Annex G). Renders the shell and serves permission-aware
 * JSON endpoints for the user list, a user's permission state, and grant saves.
 * Reads require access.view; saves require access.grant — both program-scoped.
 */
final class IamController
{
    /** GET /app/admin/iam */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $programs = self::adminablePrograms($user);
        if ($programs === []) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden — you have no program where you may view access.";
            return;
        }
        $selected = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$selected])) {
            $selected = (int) array_key_first($programs);
        }
        Audit::log('iam.view', 'console', $selected);

        $NONCE = $nonce;
        $catalog = PermissionCatalog::modules();
        $csrf = Security::csrfToken();
        require dirname(__DIR__) . '/Views/app_iam.php';
    }

    /** GET /app/admin/iam/users?program_id= */
    public static function users(): void
    {
        [$user, $programId] = self::guard('access.view');
        $rows = Db::fetchAll(
            "SELECT u.id, u.display_name, u.email, u.kind, u.is_us_person,
                    string_agg(DISTINCT r.key, ',' ORDER BY r.key) AS roles
               FROM program_membership pm
               JOIN app_user u ON u.id = pm.user_id
               JOIN role r ON r.id = pm.role_id
              WHERE pm.program_id = :pid AND (pm.expires_at IS NULL OR pm.expires_at > NOW())
              GROUP BY u.id, u.display_name, u.email, u.kind, u.is_us_person
              ORDER BY u.display_name",
            ['pid' => $programId]
        );
        self::json(200, ['users' => array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['display_name'],
            'email' => $r['email'],
            'kind' => $r['kind'],
            'is_us_person' => $r['is_us_person'] === null ? null : (bool) $r['is_us_person'],
            'roles' => $r['roles'] !== null ? explode(',', $r['roles']) : [],
        ], $rows)]);
    }

    /** GET /app/admin/iam/user?program_id=&user_id= */
    public static function userPerms(): void
    {
        [$user, $programId] = self::guard('access.view');
        $targetId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $roles = self::rolesOf($programId, $targetId);
        if ($roles === null) {
            self::json(404, ['error' => 'user_not_in_program']);
            return;
        }
        $roleDefaults = array_fill_keys(Roles::permissionsFor($roles), true);
        $wildcard = isset($roleDefaults['*']);
        $explicit = [];
        foreach (Db::fetchAll(
            'SELECT permission_key, effect FROM user_permission_grant WHERE program_id = :p AND user_id = :u',
            ['p' => $programId, 'u' => $targetId]
        ) as $g) {
            $explicit[$g['permission_key']] = $g['effect'];
        }

        $state = [];
        foreach (PermissionCatalog::allKeys() as $key) {
            if (isset($explicit[$key])) {
                $state[$key] = $explicit[$key] === 'deny' ? 'deny' : 'grant';
            } elseif ($wildcard || isset($roleDefaults[$key])) {
                $state[$key] = 'role';
            } else {
                $state[$key] = 'none';
            }
        }
        self::json(200, ['program_id' => $programId, 'user_id' => $targetId, 'roles' => $roles, 'state' => $state]);
    }

    /** POST /app/admin/iam/save  (JSON body: {program_id, user_id, changes:{key:role|grant|deny}}) */
    public static function save(): void
    {
        [$actor, $programId, $body] = self::guardWrite('access.grant');
        $targetId = (int) ($body['user_id'] ?? 0);
        $changes = is_array($body['changes'] ?? null) ? $body['changes'] : [];

        if (self::rolesOf($programId, $targetId) === null) {
            self::json(400, ['error' => 'user_not_in_program']);
            return;
        }
        $valid = array_fill_keys(PermissionCatalog::allKeys(), true);
        $applied = 0;
        try {
            foreach ($changes as $key => $effect) {
                if (!isset($valid[$key]) || !in_array($effect, ['role', 'grant', 'deny'], true)) {
                    continue;
                }
                if ($effect === 'role') {
                    Db::query(
                        'DELETE FROM user_permission_grant WHERE program_id=:p AND user_id=:u AND permission_key=:k',
                        ['p' => $programId, 'u' => $targetId, 'k' => $key]
                    );
                } else {
                    Db::query(
                        'INSERT INTO user_permission_grant (program_id, user_id, permission_key, effect, granted_by)
                         VALUES (:p,:u,:k,:e,:by)
                         ON CONFLICT (program_id, user_id, permission_key)
                         DO UPDATE SET effect = EXCLUDED.effect, granted_by = EXCLUDED.granted_by, granted_at = NOW()',
                        ['p' => $programId, 'u' => $targetId, 'k' => $key, 'e' => $effect, 'by' => $actor['id'] ?? null]
                    );
                }
                Audit::log('iam.grant.' . $effect, $key . '#user' . $targetId, $programId, $actor['id'] ?? null);
                $applied++;
            }
        } catch (Throwable $e) {
            error_log('[IAM] save failed: ' . $e->getMessage());
            self::json(500, ['error' => 'save_failed']);
            return;
        }
        // Rotate CSRF so the client updates its in-memory token (repo IAM standard).
        self::json(200, ['ok' => true, 'applied' => $applied, 'csrf' => Security::rotateCsrf()]);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array{0:array,1:int} [user, programId] — enforces read permission + DB. */
    private static function guard(string $perm): array
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::json(503, ['error' => 'database_not_configured']);
            exit;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
        if (!Authorize::can($user, $perm, ['program_id' => $programId])) {
            Audit::log('authz.deny', $perm, $programId);
            self::json(403, ['error' => 'forbidden']);
            exit;
        }
        return [$user, $programId];
    }

    /** @return array{0:array,1:int,2:array} [user, programId, jsonBody] — write guard + CSRF. */
    private static function guardWrite(string $perm): array
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::json(503, ['error' => 'database_not_configured']);
            exit;
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            self::json(400, ['error' => 'bad_json']);
            exit;
        }
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null);
        if (!Security::validateCsrf(is_string($csrf) ? $csrf : null)) {
            self::json(419, ['error' => 'csrf']);
            exit;
        }
        $programId = (int) ($body['program_id'] ?? 0);
        if (!Authorize::can($user, $perm, ['program_id' => $programId])) {
            Audit::log('authz.deny', $perm, $programId);
            self::json(403, ['error' => 'forbidden']);
            exit;
        }
        return [$user, $programId, $body];
    }

    /** Programs where the signed-in user may view access. @return array<int,string> id=>label */
    private static function adminablePrograms(array $user): array
    {
        $out = [];
        if (!Db::isConfigured()) {
            return $out;
        }
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, 'access.view', ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }
        return $out;
    }

    /** Roles a user holds in a program, or null if not a member. @return string[]|null */
    private static function rolesOf(int $programId, int $userId): ?array
    {
        $rows = Db::fetchAll(
            'SELECT r.key FROM program_membership pm JOIN role r ON r.id = pm.role_id
              WHERE pm.program_id = :p AND pm.user_id = :u',
            ['p' => $programId, 'u' => $userId]
        );
        if ($rows === []) {
            return null;
        }
        return array_map(static fn ($r) => (string) $r['key'], $rows);
    }

    private static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
