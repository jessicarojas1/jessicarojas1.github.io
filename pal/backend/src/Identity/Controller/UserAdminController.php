<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\Permission;
use App\Identity\Authorization\RolePermissionMap;
use App\Identity\Local\LocalAccountService;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Users and their global roles. Roles inherited from directory-group mappings are shown
 * separately from explicit grants; only explicit grants are edited here.
 */
#[IsGranted(Permission::ADMIN_USERS)]
final class UserAdminController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly LocalAccountService $accounts,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/api/admin/users', name: 'api_admin_users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = trim(mb_substr((string) $request->query->get('q', ''), 0, 100));
        $params = [];
        $where = '';
        if ('' !== $query) {
            $where = 'WHERE u.display_name ILIKE :q OR u.email ILIKE :q OR u.username ILIKE :q';
            $params['q'] = '%'.addcslashes($query, '%_\\').'%';
        }
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT u.id, u.display_name, u.email, u.username, u.source, u.is_active, u.last_login_at,
                   c.locked_until, c.must_change,
                   COALESCE((SELECT string_agg(ur.role, ',') FROM user_role ur WHERE ur.user_id = u.id), '') AS explicit_roles,
                   COALESCE((SELECT string_agg(DISTINCT m.role, ',') FROM global_role_mapping m
                             JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
                             WHERE gm.user_id = u.id), '') AS group_roles
            FROM app_user u LEFT JOIN local_credential c ON c.user_id = u.id
            {$where}
            ORDER BY u.display_name
            LIMIT 500
            SQL, $params);

        return new JsonResponse([
            'roles' => array_map(fn (GlobalRole $r): array => [
                'value' => $r->value,
                'permissions' => RolePermissionMap::permissionsFor([$r]),
            ], array_values(array_filter(GlobalRole::cases(), fn (GlobalRole $r): bool => $r->isMappable()))),
            'users' => array_map(fn (array $r): array => [
                'id' => Row::string($r, 'id'),
                'displayName' => Row::string($r, 'display_name'),
                'email' => Row::nullableString($r, 'email'),
                'username' => Row::nullableString($r, 'username'),
                'signInMethod' => null === $r['username'] ? 'entra' : 'password',
                'isActive' => Row::bool($r, 'is_active'),
                'lastSignInAt' => Row::nullableString($r, 'last_login_at'),
                'lockedUntil' => Row::nullableString($r, 'locked_until'),
                'mustChangePassword' => true === $r['must_change'],
                'explicitRoles' => array_values(array_filter(explode(',', Row::string($r, 'explicit_roles')))),
                'groupRoles' => array_values(array_filter(explode(',', Row::string($r, 'group_roles')))),
            ], $rows),
        ]);
    }

    #[Route('/api/admin/users', name: 'api_admin_user_create', methods: ['POST'])]
    public function create(#[CurrentUser] PalUser $admin, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $roles = $this->parseRoles($body['roles'] ?? []);
        // The new account's temporary password is shown to this administrator, so they could act as it.
        $this->assertNoEscalation($admin, $roles);
        $created = $this->accounts->create(
            $admin->auditActor(),
            $admin->id,
            \is_string($body['username'] ?? null) ? $body['username'] : '',
            \is_string($body['displayName'] ?? null) ? $body['displayName'] : '',
            \is_string($body['email'] ?? null) ? $body['email'] : null,
            $roles,
        );

        return new JsonResponse(['ok' => true, ...$created], 201);
    }

    #[Route('/api/admin/users/{id}/roles', name: 'api_admin_user_roles', methods: ['PUT'])]
    public function setRoles(#[CurrentUser] PalUser $admin, string $id, Request $request): JsonResponse
    {
        $roles = $this->parseRoles(JsonBody::decode($request)['roles'] ?? []);
        $target = $this->requireUser($id);

        $this->connection->transactional(function () use ($admin, $id, $roles, $target): void {
            $before = Row::strings($this->connection->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ? ORDER BY role FOR UPDATE', [$id]));
            $after = array_map(fn (GlobalRole $r): string => $r->value, $roles);
            sort($after);
            $added = array_values(array_diff($after, $before));
            // Separation of duties: nobody grants themselves document authority.
            if ($id === $admin->id) {
                foreach ($added as $value) {
                    if (GlobalRole::from($value)->hasDocumentAuthority()) {
                        throw new ApiProblem(403, 'self_grant_forbidden', 'Another administrator must grant you a role with document authority.');
                    }
                }
            }
            $this->assertNoEscalation($admin, array_map(GlobalRole::from(...), $added));
            $this->connection->executeStatement('DELETE FROM user_role WHERE user_id = ?', [$id]);
            foreach ($after as $value) {
                $this->connection->executeStatement('INSERT INTO user_role (user_id, role, granted_by) VALUES (?, ?, ?)', [$id, $value, $admin->id]);
            }
            $this->assertAdministratorRemains();
            if ($before !== $after) {
                $this->audit->record(new AuditEntry('USER_ROLES_CHANGED', $admin->auditActor(), 'app_user', $id, [
                    'user' => Row::string($target, 'display_name'), 'from' => $before, 'to' => $after,
                ]));
            }
        });

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/users/{id}/active', name: 'api_admin_user_active', methods: ['PUT'])]
    public function setActive(#[CurrentUser] PalUser $admin, string $id, Request $request): JsonResponse
    {
        $active = JsonBody::decode($request)['isActive'] ?? null;
        if (!\is_bool($active)) {
            throw ApiProblem::validation(['isActive' => 'Must be true or false.']);
        }
        if ($id === $admin->id && !$active) {
            throw new ApiProblem(409, 'cannot_deactivate_self', 'You cannot deactivate your own account.');
        }
        $target = $this->requireUser($id);
        $this->connection->transactional(function () use ($admin, $id, $active, $target): void {
            $this->connection->executeStatement('UPDATE app_user SET is_active = ?, updated_at = now() WHERE id = ?', [$active ? 'true' : 'false', $id]);
            $this->assertAdministratorRemains();
            $this->audit->record(new AuditEntry($active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED', $admin->auditActor(), 'app_user', $id, ['user' => Row::string($target, 'display_name')]));
        });

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/users/{id}/reset-password', name: 'api_admin_user_reset', methods: ['POST'])]
    public function resetPassword(#[CurrentUser] PalUser $admin, string $id): JsonResponse
    {
        $this->requireUser($id);
        // A reset hands this administrator a working password for the account.
        $this->assertNoEscalation($admin, $this->effectiveRoles($id));

        return new JsonResponse(['ok' => true, 'temporaryPassword' => $this->accounts->resetPassword($admin, $id)]);
    }

    /**
     * Separation of duties without a second approver (see D20): a System Administrator alone
     * can never obtain document authority, directly or through an account they control.
     * Conferring it, or taking over an account that has it, requires PAL Administrator.
     *
     * @param list<GlobalRole> $roles
     */
    private function assertNoEscalation(PalUser $admin, array $roles): void
    {
        if ($admin->hasRole(GlobalRole::PalAdministrator)) {
            return;
        }
        foreach ($roles as $role) {
            if ($role->hasDocumentAuthority()) {
                throw new ApiProblem(403, 'role_escalation_forbidden', 'Only a PAL Administrator can give a role with document authority, or reset the password of an account that has one.');
            }
        }
    }

    /**
     * Explicit and group-inherited roles of a user.
     *
     * @return list<GlobalRole>
     */
    private function effectiveRoles(string $userId): array
    {
        $values = Row::strings($this->connection->fetchFirstColumn(<<<'SQL'
            SELECT role FROM user_role WHERE user_id = :id
            UNION
            SELECT m.role FROM global_role_mapping m
            JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
            WHERE gm.user_id = :id
            SQL, ['id' => $userId]));

        return array_values(array_filter(array_map(GlobalRole::tryFrom(...), $values)));
    }

    /** Refuses (and so rolls back) a change that would leave no active System Administrator. */
    private function assertAdministratorRemains(): void
    {
        $remains = $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) > 0 FROM app_user u
            WHERE u.is_active AND (
                EXISTS (SELECT 1 FROM user_role ur WHERE ur.user_id = u.id AND ur.role = :role)
                OR EXISTS (SELECT 1 FROM global_role_mapping m
                           JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
                           WHERE gm.user_id = u.id AND m.role = :role))
            SQL, ['role' => GlobalRole::SystemAdministrator->value]);
        if (true !== $remains) {
            throw new ApiProblem(409, 'last_administrator', 'At least one active System Administrator must remain.');
        }
    }

    /**
     * @return list<GlobalRole>
     */
    private function parseRoles(mixed $input): array
    {
        if (!\is_array($input)) {
            throw ApiProblem::validation(['roles' => 'Roles must be a list.']);
        }
        $roles = [];
        foreach ($input as $value) {
            $role = \is_string($value) ? GlobalRole::tryFrom($value) : null;
            if (null === $role || !$role->isMappable()) {
                throw ApiProblem::validation(['roles' => 'Unknown role.']);
            }
            $roles[$role->value] = $role;
        }

        return array_values($roles);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUser(string $id): array
    {
        $row = 1 === preg_match(self::UUID, $id) ? $this->connection->fetchAssociative('SELECT * FROM app_user WHERE id = ?', [$id]) : false;
        if (false === $row) {
            throw new ApiProblem(404, 'not_found', 'User not found.');
        }

        return $row;
    }
}
