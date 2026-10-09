<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Admin IAM data access — platform users, their roles, and the three-state
 * (role default / explicit grant / explicit deny) permission model shown in
 * the two-pane Admin IAM console.
 */
final class Users
{
    public static function list(): array
    {
        $users = Db::fetchAll(
            'SELECT u.*, p.display_name AS person_name
             FROM app_user u LEFT JOIN person p ON p.id = u.person_id
             ORDER BY u.display_name'
        );
        $roles = Db::fetchAll('SELECT user_id, role_key FROM app_user_role');
        $byUser = [];
        foreach ($roles as $r) {
            $byUser[(int) $r['user_id']][] = (string) $r['role_key'];
        }
        foreach ($users as &$u) {
            $u['roles'] = $byUser[(int) $u['id']] ?? [];
        }
        unset($u);
        return $users;
    }

    public static function get(int $id): ?array
    {
        $u = Db::fetchOne(
            'SELECT u.*, p.display_name AS person_name
             FROM app_user u LEFT JOIN person p ON p.id = u.person_id WHERE u.id = :id',
            ['id' => $id]
        );
        if ($u === null) {
            return null;
        }
        $roles = Db::fetchAll('SELECT role_key FROM app_user_role WHERE user_id = :id', ['id' => $id]);
        $u['roles'] = array_map(static fn ($r) => (string) $r['role_key'], $roles);
        return $u;
    }

    public static function create(string $email, string $displayName, array $roles, ?int $personId, ?int $actorId): int
    {
        $id = Db::insert('app_user', [
            'email' => strtolower(trim($email)),
            'display_name' => $displayName,
            'status' => 'invited',
            'person_id' => $personId,
        ]);
        self::setRoles($id, $roles, $actorId);
        Audit::log('iam.user_create', 'app_user#' . $id, null, ['email' => $email, 'roles' => $roles], null, $actorId);
        return $id;
    }

    public static function setRoles(int $userId, array $roles, ?int $actorId): void
    {
        $valid = array_intersect($roles, Roles::all());
        Db::delete('app_user_role', ['user_id' => $userId]);
        foreach ($valid as $r) {
            Db::query(
                'INSERT INTO app_user_role (user_id, role_key) VALUES (:uid, :role) ON CONFLICT DO NOTHING',
                ['uid' => $userId, 'role' => $r]
            );
        }
        Audit::log('iam.roles_set', 'app_user#' . $userId, null, ['roles' => $valid], null, $actorId);
    }

    public static function setStatus(int $userId, string $status, ?int $actorId): void
    {
        $before = self::get($userId);
        Db::update('app_user', ['status' => $status], ['id' => $userId]);
        Audit::log('iam.status_set', 'app_user#' . $userId, $before, ['status' => $status], null, $actorId);
    }

    /**
     * Per-permission effective state for the IAM editor: 'role' (inherited
     * default), 'grant' (explicit), 'deny' (explicit), or 'none'.
     * @return array<string,string>
     */
    public static function permissionStates(int $userId): array
    {
        $user = self::get($userId);
        if ($user === null) {
            return [];
        }
        $roleDefaults = array_fill_keys(Roles::permissionsFor($user['roles']), true);
        $grants = Db::fetchAll('SELECT permission_key, effect FROM user_permission_grant WHERE user_id = :uid', ['uid' => $userId]);

        $states = [];
        foreach (PermissionCatalog::allKeys() as $key) {
            $states[$key] = isset($roleDefaults[$key]) || isset($roleDefaults['*']) ? 'role' : 'none';
        }
        foreach ($grants as $g) {
            $key = (string) $g['permission_key'];
            if (!isset($states[$key])) {
                continue;
            }
            $states[$key] = $g['effect'] === 'deny' ? 'deny' : 'grant';
        }
        return $states;
    }

    /**
     * Apply a batch of explicit grant/deny/role(=clear) changes.
     * @param array<string,string> $changes permission_key => 'role'|'grant'|'deny'
     */
    public static function saveGrants(int $userId, array $changes, ?int $actorId): int
    {
        $applied = 0;
        $validKeys = array_fill_keys(PermissionCatalog::allKeys(), true);
        foreach ($changes as $key => $state) {
            if (!isset($validKeys[$key])) {
                continue;
            }
            if ($state === 'role') {
                Db::delete('user_permission_grant', ['user_id' => $userId, 'permission_key' => $key]);
            } elseif (in_array($state, ['grant', 'deny'], true)) {
                Db::query(
                    'INSERT INTO user_permission_grant (user_id, permission_key, effect, granted_by_user_id)
                     VALUES (:uid, :key, :effect, :by)
                     ON CONFLICT (user_id, permission_key) DO UPDATE SET effect = EXCLUDED.effect, granted_by_user_id = EXCLUDED.granted_by_user_id',
                    ['uid' => $userId, 'key' => $key, 'effect' => $state, 'by' => $actorId]
                );
            } else {
                continue;
            }
            $applied++;
        }
        Audit::log('iam.grants_saved', 'app_user#' . $userId, null, $changes, null, $actorId);
        return $applied;
    }
}
