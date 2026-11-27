<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\Permission;
use App\Identity\Authorization\RoleAssignments;
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
 * Role changes held by the two-person rule (decision #31): a different Owner or System
 * Administrator, who is neither the requester nor the person changed, approves or rejects;
 * the requester may cancel. Approval re-checks that the person's roles have not changed since
 * the request, and refuses to make anyone an Owner while their account still has a temporary
 * password the requester saw.
 */
#[IsGranted(Permission::ADMIN_USERS)]
final class RoleRequestController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly RoleAssignments $assignments,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/api/admin/role-requests', name: 'api_admin_role_requests', methods: ['GET'])]
    public function list(#[CurrentUser] PalUser $user): JsonResponse
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT r.id, r.target_user_id, t.display_name AS target_name, r.requested_by, q.display_name AS requester_name,
                   r.roles_from, r.roles_to, r.reason, r.created_at, r.expires_at
            FROM role_change_request r
            JOIN app_user t ON t.id = r.target_user_id
            JOIN app_user q ON q.id = r.requested_by
            WHERE r.status = 'PENDING' AND r.expires_at > now()
            ORDER BY r.created_at
            SQL);
        $sole = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.id, a.occurred_at, a.subject_id, a.actor_snapshot->>'display_name' AS actor_name, a.changes->>'user' AS target_name,
                   COALESCE(a.changes->>'from', '[]') AS roles_from, COALESCE(a.changes->>'to', '[]') AS roles_to
            FROM audit_event a
            WHERE a.event_type = 'ROLE_CHANGE_SOLE_ADMINISTRATOR' AND a.occurred_at > now() - interval '90 days'
            ORDER BY a.occurred_at DESC LIMIT 20
            SQL);
        $mayDecide = RoleAssignments::managesAllRoles($user);

        return new JsonResponse([
            'requests' => array_map(fn (array $r): array => [
                'id' => Row::string($r, 'id'),
                'target' => ['id' => Row::string($r, 'target_user_id'), 'displayName' => Row::string($r, 'target_name')],
                'requestedBy' => ['id' => Row::string($r, 'requested_by'), 'displayName' => Row::string($r, 'requester_name')],
                'from' => self::roles($r['roles_from']),
                'to' => self::roles($r['roles_to']),
                'reason' => Row::nullableString($r, 'reason'),
                'createdAt' => Row::string($r, 'created_at'),
                'expiresAt' => Row::string($r, 'expires_at'),
                'canDecide' => $mayDecide && $user->id !== Row::string($r, 'requested_by') && $user->id !== Row::string($r, 'target_user_id')
                    && !$this->assignments->controlledBy($user->id, Row::string($r, 'requested_by')),
                'canCancel' => $user->id === Row::string($r, 'requested_by'),
            ], $rows),
            // Changes applied without a second approver (sole administrator) in the last 90 days.
            'soleAdministratorChanges' => array_map(fn (array $r): array => [
                'id' => (string) Row::int($r, 'id'),
                'from' => self::jsonRoles(Row::string($r, 'roles_from')),
                'to' => self::jsonRoles(Row::string($r, 'roles_to')),
                'at' => Row::string($r, 'occurred_at'),
                'by' => Row::nullableString($r, 'actor_name') ?? 'unknown',
                'user' => Row::nullableString($r, 'target_name') ?? Row::string($r, 'subject_id'),
            ], $sole),
        ]);
    }

    #[Route('/api/admin/role-requests/{id}/approve', name: 'api_admin_role_request_approve', methods: ['POST'])]
    public function approve(#[CurrentUser] PalUser $user, string $id): JsonResponse
    {
        $this->connection->transactional(function () use ($user, $id): void {
            $request = $this->lockOpen($id);
            $this->assertSecondPerson($user, $request);
            $targetId = Row::string($request, 'target_user_id');
            $target = $this->connection->fetchAssociative('SELECT u.display_name, u.is_active, c.must_change FROM app_user u LEFT JOIN local_credential c ON c.user_id = u.id WHERE u.id = ?', [$targetId]);
            if (false === $target || !Row::bool($target, 'is_active')) {
                throw new ApiProblem(409, 'request_stale', 'This person is no longer active, so the request cannot be approved.');
            }
            $before = $this->assignments->explicitRolesForUpdate($targetId);
            $from = self::roles($request['roles_from']);
            $to = self::roles($request['roles_to']);
            if ($before !== $from) {
                throw new ApiProblem(409, 'request_stale', 'This person\'s roles changed after the request was made. Reject it and ask for a new one.');
            }
            // The requester saw the temporary password of an account they created or reset.
            if (\in_array(GlobalRole::Owner->value, $to, true) && !\in_array(GlobalRole::Owner->value, $from, true) && true === $target['must_change']) {
                throw new ApiProblem(409, 'password_not_set', 'This person must sign in and choose their own password before they can be made an Owner.');
            }
            $requester = Row::string($request, 'requested_by');
            $this->assignments->apply($user, $targetId, Row::string($target, 'display_name'), $before, $to, $user->id);
            $this->connection->executeStatement("UPDATE role_change_request SET status = 'APPROVED', decided_by = ?, decided_at = now() WHERE id = ?", [$user->id, $id]);
            $this->audit->record(new AuditEntry('ROLE_CHANGE_APPROVED', $user->auditActor(), 'role_change_request', $id, [
                'user_id' => $targetId, 'requested_by' => $requester, 'from' => $from, 'to' => $to,
            ]));
        });

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/role-requests/{id}/reject', name: 'api_admin_role_request_reject', methods: ['POST'])]
    public function reject(#[CurrentUser] PalUser $user, string $id, Request $http): JsonResponse
    {
        $body = JsonBody::decode($http);
        $note = \is_string($body['note'] ?? null) ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $body['note'])), 0, 500) : '';
        $this->connection->transactional(function () use ($user, $id, $note): void {
            $request = $this->lockOpen($id);
            $cancel = $user->id === Row::string($request, 'requested_by');
            if (!$cancel) {
                $this->assertSecondPerson($user, $request);
            }
            $status = $cancel ? 'CANCELLED' : 'REJECTED';
            $this->connection->executeStatement(
                'UPDATE role_change_request SET status = ?, decided_by = ?, decided_at = now(), decision_note = ? WHERE id = ?',
                [$status, $user->id, '' === $note ? null : $note, $id],
            );
            $this->audit->record(new AuditEntry($cancel ? 'ROLE_CHANGE_CANCELLED' : 'ROLE_CHANGE_REJECTED', $user->auditActor(), 'role_change_request', $id, [
                'user_id' => Row::string($request, 'target_user_id'),
            ], '' === $note ? null : $note));
        });

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lockOpen(string $id): array
    {
        $request = 1 === preg_match(self::UUID, $id)
            ? $this->connection->fetchAssociative('SELECT *, expires_at <= now() AS expired FROM role_change_request WHERE id = ? FOR UPDATE', [$id])
            : false;
        if (false === $request) {
            throw new ApiProblem(404, 'not_found', 'Request not found.');
        }
        if ('PENDING' !== $request['status'] || true === $request['expired']) {
            throw new ApiProblem(409, 'request_closed', 'This request has already been decided or has expired.');
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function assertSecondPerson(PalUser $user, array $request): void
    {
        if (!RoleAssignments::managesAllRoles($user)) {
            throw new ApiProblem(403, 'forbidden', 'Only an Owner or System Administrator can decide role requests.');
        }
        if ($user->id === Row::string($request, 'requested_by') || $user->id === Row::string($request, 'target_user_id')) {
            throw new ApiProblem(403, 'second_person_required', 'A different Owner or System Administrator must decide this request.');
        }
        // An account the requester created or reset the password of is not a second person.
        if ($this->assignments->controlledBy($user->id, Row::string($request, 'requested_by'))) {
            throw new ApiProblem(403, 'approver_not_independent', 'You cannot decide this request: the person who asked created your account or reset your password.');
        }
    }

    /**
     * @return list<string>
     */
    private static function jsonRoles(string $json): array
    {
        $decoded = json_decode($json, true);
        $values = \is_array($decoded) ? array_values(array_filter($decoded, fn (mixed $v): bool => \is_string($v) && null !== GlobalRole::tryFrom($v))) : [];
        sort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function roles(mixed $pgArray): array
    {
        $raw = \is_string($pgArray) ? trim($pgArray, '{}') : '';
        $values = '' === $raw ? [] : array_values(array_filter(explode(',', $raw), fn (string $v): bool => null !== GlobalRole::tryFrom($v)));
        sort($values);

        return $values;
    }
}
