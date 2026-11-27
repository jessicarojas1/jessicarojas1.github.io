<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Applies explicit role grants and enforces the two-person rule for the most powerful
 * changes (decision #31, amending #30).
 *
 * An Owner or System Administrator manages every role (#30), but these changes need a second
 * Owner or System Administrator, who is neither the requester nor the person changed:
 *  - granting the Owner role (god mode) to anyone,
 *  - removing the Owner role from someone else,
 *  - giving yourself System Administrator or any role with document authority.
 * When no such second person exists (the first administrator bootstrapping an instance), the
 * change is applied at once but recorded as ROLE_CHANGE_SOLE_ADMINISTRATOR and shown on the
 * Users screen, so it is never silent. Every path stays server-side and audited.
 *
 * "Second person" means independent: an account the requester created or reset the password
 * of could be operated by the requester, so it neither approves the requester's changes nor
 * counts as an available approver. And the sole-administrator exception is refused for 30
 * days after the requester deactivated or demoted another administrator, so nobody can clear
 * the field, grant themselves Owner alone, and restore the others.
 */
final class RoleAssignments
{
    public const REQUEST_DAYS = 7;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    public static function managesAllRoles(PalUser $user): bool
    {
        return $user->hasRole(GlobalRole::Owner) || $user->hasRole(GlobalRole::SystemAdministrator);
    }

    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    public static function needsSecondPerson(string $actorId, string $targetId, array $added, array $removed): bool
    {
        if (\in_array(GlobalRole::Owner->value, $added, true)) {
            return true;
        }
        if ($targetId !== $actorId && \in_array(GlobalRole::Owner->value, $removed, true)) {
            return true;
        }
        if ($targetId === $actorId) {
            foreach ($added as $value) {
                $role = GlobalRole::from($value);
                if (GlobalRole::SystemAdministrator === $role || $role->hasDocumentAuthority()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is there an active Owner or System Administrator, other than these people, who could
     * approve a request?
     *
     * @param list<string> $excluded user ids
     */
    public function otherApproverExists(array $excluded, string $requesterId): bool
    {
        return true === $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) > 0 FROM app_user u
            WHERE u.is_active AND u.id <> ALL(:excluded::uuid[]) AND (
                EXISTS (SELECT 1 FROM user_role ur WHERE ur.user_id = u.id AND ur.role IN ('OWNER', 'SYSTEM_ADMINISTRATOR'))
                OR EXISTS (SELECT 1 FROM global_role_mapping m
                           JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
                           WHERE gm.user_id = u.id AND m.role = 'SYSTEM_ADMINISTRATOR'))
              AND NOT EXISTS (SELECT 1 FROM audit_event a
                              WHERE a.actor_user_id = :requester AND a.subject_type = 'app_user' AND a.subject_id = u.id::text
                                AND a.event_type IN ('LOCAL_USER_CREATED', 'USER_PASSWORD_RESET'))
            SQL, ['excluded' => '{'.implode(',', $excluded).'}', 'requester' => $requesterId]);
    }

    /**
     * Could the requester operate this account? True when they created it or reset its password.
     */
    public function controlledBy(string $userId, string $requesterId): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM audit_event
            WHERE actor_user_id = ? AND subject_type = 'app_user' AND subject_id = ?
              AND event_type IN ('LOCAL_USER_CREATED', 'USER_PASSWORD_RESET')
            LIMIT 1
            SQL, [$requesterId, $userId]);
    }

    /**
     * Did this person deactivate or demote another Owner or System Administrator in the last
     * 30 days? Then they may not use the sole-administrator exception.
     */
    public function recentlyRemovedAnAdministrator(string $actorId): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM audit_event a
            WHERE a.actor_user_id = :actor AND a.subject_type = 'app_user' AND a.subject_id <> :actor::text
              AND a.occurred_at > now() - interval '30 days'
              AND (
                (a.event_type = 'USER_DEACTIVATED' AND EXISTS (
                    SELECT 1 FROM user_role ur WHERE ur.user_id::text = a.subject_id AND ur.role IN ('OWNER', 'SYSTEM_ADMINISTRATOR')))
                OR (a.event_type = 'USER_ROLES_CHANGED' AND (
                    (jsonb_exists(a.changes->'from', 'SYSTEM_ADMINISTRATOR') AND NOT jsonb_exists(a.changes->'to', 'SYSTEM_ADMINISTRATOR'))
                    OR (jsonb_exists(a.changes->'from', 'OWNER') AND NOT jsonb_exists(a.changes->'to', 'OWNER'))))
              )
            LIMIT 1
            SQL, ['actor' => $actorId]);
    }

    /**
     * The sole-administrator exception applies only when nobody independent could approve and
     * the requester has not just removed the others.
     */
    public function assertSoleAdministratorAllowed(string $actorId): void
    {
        if ($this->recentlyRemovedAnAdministrator($actorId)) {
            throw new ApiProblem(409, 'second_person_required', 'You recently deactivated or demoted another administrator, so this change needs a second Owner or System Administrator. Reactivate one to approve it.');
        }
    }

    /**
     * Explicit roles of a user, sorted, locking the rows.
     *
     * @return list<string>
     */
    public function explicitRolesForUpdate(string $userId): array
    {
        // Lock the person first: with no role rows, locking user_role alone would lock nothing.
        $this->connection->executeStatement('SELECT 1 FROM app_user WHERE id = ? FOR UPDATE', [$userId]);

        return Row::strings($this->connection->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ? ORDER BY role FOR UPDATE', [$userId]));
    }

    /**
     * Replaces a user's explicit roles. Runs inside the caller's transaction.
     *
     * @param list<string> $before
     * @param list<string> $after  sorted
     */
    public function apply(PalUser $actor, string $userId, string $displayName, array $before, array $after, ?string $approvedBy = null, bool $soleAdministrator = false): void
    {
        $this->connection->executeStatement('DELETE FROM user_role WHERE user_id = ?', [$userId]);
        foreach ($after as $value) {
            $this->connection->executeStatement('INSERT INTO user_role (user_id, role, granted_by) VALUES (?, ?, ?)', [$userId, $value, $approvedBy ?? $actor->id]);
        }
        $this->assertAdministratorRemains();
        if ($before === $after) {
            return;
        }
        $changes = ['user' => $displayName, 'from' => $before, 'to' => $after];
        if (null !== $approvedBy) {
            $changes['approved_by'] = $approvedBy;
        }
        $this->audit->record(new AuditEntry('USER_ROLES_CHANGED', $actor->auditActor(), 'app_user', $userId, $changes));
        if ($soleAdministrator) {
            $this->audit->record(new AuditEntry('ROLE_CHANGE_SOLE_ADMINISTRATOR', $actor->auditActor(), 'app_user', $userId, $changes,
                'Applied without a second approver because no other active Owner or System Administrator exists.'));
        }
    }

    /**
     * Opens a request for a held change. One open request per person; an expired one is
     * closed first.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    public function openRequest(PalUser $actor, string $userId, string $displayName, array $before, array $after, ?string $reason): string
    {
        $this->connection->executeStatement(
            "UPDATE role_change_request SET status = 'CANCELLED', decided_at = now(), decision_note = 'expired' WHERE target_user_id = ? AND status = 'PENDING' AND expires_at <= now()",
            [$userId],
        );
        if (false !== $this->connection->fetchOne("SELECT 1 FROM role_change_request WHERE target_user_id = ? AND status = 'PENDING'", [$userId])) {
            throw new ApiProblem(409, 'request_pending', 'A role change for this person is already waiting for approval. Approve, reject or cancel it first.');
        }
        try {
            $id = Row::scalarString($this->connection->fetchOne(
                'INSERT INTO role_change_request (target_user_id, requested_by, roles_from, roles_to, reason, expires_at) VALUES (?, ?, ?, ?, ?, now() + make_interval(days => ?)) RETURNING id',
                [$userId, $actor->id, self::pgArray($before), self::pgArray($after), $reason, self::REQUEST_DAYS],
            ));
        } catch (UniqueConstraintViolationException) {
            throw new ApiProblem(409, 'request_pending', 'A role change for this person is already waiting for approval. Approve, reject or cancel it first.');
        }
        $this->audit->record(new AuditEntry('ROLE_CHANGE_REQUESTED', $actor->auditActor(), 'role_change_request', $id, [
            'user_id' => $userId, 'user' => $displayName, 'from' => $before, 'to' => $after,
        ], $reason));

        return $id;
    }

    /**
     * Refuses (and so rolls back) a change that would leave no active administrator. An Owner
     * counts: it holds every System Administrator permission. Owner is an explicit grant only,
     * so the directory-group branch considers System Administrator alone.
     */
    public function assertAdministratorRemains(): void
    {
        $remains = $this->connection->fetchOne(<<<'SQL'
            SELECT count(*) > 0 FROM app_user u
            WHERE u.is_active AND (
                EXISTS (SELECT 1 FROM user_role ur WHERE ur.user_id = u.id AND ur.role IN (:sysadmin, :owner))
                OR EXISTS (SELECT 1 FROM global_role_mapping m
                           JOIN group_membership gm ON gm.group_id = m.group_id AND gm.valid_to IS NULL
                           WHERE gm.user_id = u.id AND m.role = :sysadmin))
            SQL, ['sysadmin' => GlobalRole::SystemAdministrator->value, 'owner' => GlobalRole::Owner->value]);
        if (true !== $remains) {
            throw new ApiProblem(409, 'last_administrator', 'At least one active System Administrator or Owner must remain.');
        }
    }

    /**
     * @param list<string> $values validated role values
     */
    public static function pgArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}
