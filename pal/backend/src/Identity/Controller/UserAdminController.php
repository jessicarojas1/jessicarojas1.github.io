<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\Permission;
use App\Identity\Authorization\RoleAssignments;
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
        private readonly RoleAssignments $assignments,
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
            ], array_values(array_filter(GlobalRole::cases(), fn (GlobalRole $r): bool => $r->isAssignable()))),
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
        // An Owner or System Administrator may create another Owner.
        $this->assertOwnerGrant($admin, $roles);
        // Two-person rule (#31): the Owner role of a new account waits for a second approver.
        $holdOwner = \in_array(GlobalRole::Owner, $roles, true) && $this->assignments->otherApproverExists([$admin->id], $admin->id);
        $requested = $roles;
        if ($holdOwner) {
            $roles = array_values(array_filter($roles, fn (GlobalRole $r): bool => GlobalRole::Owner !== $r));
        }
        // The new account's temporary password is shown to this administrator, so they could act as it.
        $this->assertNoEscalation($admin, $roles);
        $displayName = \is_string($body['displayName'] ?? null) ? $body['displayName'] : '';
        $soleOwner = !$holdOwner && \in_array(GlobalRole::Owner, $roles, true);
        if ($soleOwner) {
            $this->assignments->assertSoleAdministratorAllowed($admin->id);
        }
        // Account, request and audit events commit together or not at all.
        [$created, $extra] = $this->connection->transactional(function () use ($admin, $body, $displayName, $roles, $requested, $holdOwner, $soleOwner): array {
            $created = $this->accounts->create(
                $admin->auditActor(),
                $admin->id,
                \is_string($body['username'] ?? null) ? $body['username'] : '',
                $displayName,
                \is_string($body['email'] ?? null) ? $body['email'] : null,
                $roles,
            );
            $extra = [];
            if ($holdOwner) {
                $extra['pendingRoleRequest'] = $this->assignments->openRequest(
                    $admin, $created['id'], $displayName, self::roleValues($roles), self::roleValues($requested), 'Owner role for a new account',
                );
            } elseif ($soleOwner) {
                $this->audit->record(new AuditEntry('ROLE_CHANGE_SOLE_ADMINISTRATOR', $admin->auditActor(), 'app_user', $created['id'], ['user' => $displayName, 'from' => [], 'to' => self::roleValues($roles)],
                    'Owner granted to a new account without a second approver because no other active Owner or System Administrator exists.'));
                $extra['soleAdministrator'] = true;
            }

            return [$created, $extra];
        });

        return new JsonResponse(['ok' => true, ...$created, ...$extra], 201);
    }

    #[Route('/api/admin/users/{id}/roles', name: 'api_admin_user_roles', methods: ['PUT'])]
    public function setRoles(#[CurrentUser] PalUser $admin, string $id, Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $roles = $this->parseRoles($body['roles'] ?? []);
        $reason = \is_string($body['reason'] ?? null) ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $body['reason'])), 0, 500) : null;
        $target = $this->requireUser($id);

        $result = $this->connection->transactional(function () use ($admin, $id, $roles, $target, $reason): array {
            $before = $this->assignments->explicitRolesForUpdate($id);
            $after = array_map(fn (GlobalRole $r): string => $r->value, $roles);
            sort($after);
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            $managesAll = RoleAssignments::managesAllRoles($admin);
            // An Owner or System Administrator may grant or revoke the Owner role; any lesser
            // administrator (e.g. PAL Administrator) can neither create an Owner nor strip one.
            if ((\in_array(GlobalRole::Owner->value, $added, true) || \in_array(GlobalRole::Owner->value, $removed, true)) && !$managesAll) {
                throw new ApiProblem(403, 'owner_grant_forbidden', 'Only an Owner or System Administrator can grant or revoke the Owner role.');
            }
            // Separation of duties: a PAL Administrator cannot grant themselves document authority.
            if ($id === $admin->id && !$managesAll) {
                foreach ($added as $value) {
                    if (GlobalRole::from($value)->hasDocumentAuthority()) {
                        throw new ApiProblem(403, 'self_grant_forbidden', 'Another administrator must grant you a role with document authority.');
                    }
                }
            }
            $this->assertNoEscalation($admin, array_map(GlobalRole::from(...), $added));
            $displayName = Row::string($target, 'display_name');

            // Two-person rule (#31): the most powerful changes wait for a second Owner or
            // System Administrator, unless nobody else could approve them.
            $sole = false;
            if ($managesAll && RoleAssignments::needsSecondPerson($admin->id, $id, $added, $removed)) {
                if ($this->assignments->otherApproverExists([$admin->id, $id], $admin->id)) {
                    return ['ok' => true, 'pending' => true, 'requestId' => $this->assignments->openRequest($admin, $id, $displayName, $before, $after, $reason)];
                }
                $this->assignments->assertSoleAdministratorAllowed($admin->id);
                $sole = true;
            }
            $this->assignments->apply($admin, $id, $displayName, $before, $after, null, $sole);

            return $sole ? ['ok' => true, 'soleAdministrator' => true] : ['ok' => true];
        });

        return new JsonResponse($result, true === ($result['pending'] ?? false) ? 202 : 200);
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
            $this->assignments->assertAdministratorRemains();
            $this->audit->record(new AuditEntry($active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED', $admin->auditActor(), 'app_user', $id, ['user' => Row::string($target, 'display_name')]));
        });

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/users/{id}/reset-password', name: 'api_admin_user_reset', methods: ['POST'])]
    public function resetPassword(#[CurrentUser] PalUser $admin, string $id): JsonResponse
    {
        $this->requireUser($id);
        // A reset hands this administrator a working password for the account.
        $targetRoles = $this->effectiveRoles($id);
        $this->assertNoEscalation($admin, $targetRoles);
        // Taking over an Owner account would be god mode by one person (decision #31): only
        // another Owner (who already holds every permission) may reset an Owner's password.
        if ($id !== $admin->id && \in_array(GlobalRole::Owner, $targetRoles, true) && !$admin->hasRole(GlobalRole::Owner)) {
            throw new ApiProblem(403, 'owner_reset_forbidden', 'Only another Owner can reset an Owner\'s password.');
        }

        return new JsonResponse(['ok' => true, 'temporaryPassword' => $this->accounts->resetPassword($admin, $id)]);
    }

    /**
     * A PAL Administrator cannot confer document authority, or take over an account that has
     * it, unless they are also an Owner or System Administrator. An Owner and a System
     * Administrator are the instance's role managers (decision #30) and are exempt.
     *
     * @param list<GlobalRole> $roles
     */
    private function assertNoEscalation(PalUser $admin, array $roles): void
    {
        if (RoleAssignments::managesAllRoles($admin) || $admin->hasRole(GlobalRole::PalAdministrator)) {
            return;
        }
        foreach ($roles as $role) {
            if ($role->hasDocumentAuthority()) {
                throw new ApiProblem(403, 'role_escalation_forbidden', 'Only a PAL Administrator can give a role with document authority, or reset the password of an account that has one.');
            }
        }
    }

    /**
     * God mode is conferred only by god mode: a request that grants the Owner role is refused
     * unless the acting administrator is themselves an Owner. This runs before the general
     * escalation check, which an Owner (and a PAL Administrator) would otherwise satisfy.
     *
     * @param list<GlobalRole> $roles
     */
    private function assertOwnerGrant(PalUser $admin, array $roles): void
    {
        if (\in_array(GlobalRole::Owner, $roles, true) && !RoleAssignments::managesAllRoles($admin)) {
            throw new ApiProblem(403, 'owner_grant_forbidden', 'Only an Owner or System Administrator can grant or revoke the Owner role.');
        }
    }

    /**
     * @param list<GlobalRole> $roles
     *
     * @return list<string> sorted role values
     */
    private static function roleValues(array $roles): array
    {
        $values = array_map(fn (GlobalRole $r): string => $r->value, $roles);
        sort($values);

        return $values;
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
            if (null === $role || !$role->isAssignable()) {
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
