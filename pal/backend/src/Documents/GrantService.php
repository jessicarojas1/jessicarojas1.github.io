<?php

declare(strict_types=1);

namespace App\Documents;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Directory\DirectoryException;
use App\Identity\Directory\DirectorySynchronizer;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use App\Platform\Http\ApiProblem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Document access grants (AUTHOR, APPROVER, READER) to users or directory groups. Revoking
 * closes the grant (valid_to); history is kept so past access stays answerable.
 */
final class GrantService
{
    public const ROLES = ['AUTHOR', 'APPROVER', 'READER'];

    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentRepository $documents,
        private readonly DocumentAccess $access,
        private readonly DirectorySynchronizer $directory,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $documentId): array
    {
        return array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'role' => Row::string($r, 'role'),
            'principal' => null !== $r['user_id']
                ? ['type' => 'user', 'id' => Row::string($r, 'user_id'), 'displayName' => Row::string($r, 'user_name')]
                : ['type' => 'group', 'id' => Row::string($r, 'group_id'), 'displayName' => Row::string($r, 'group_name')],
            'grantedAt' => Row::string($r, 'valid_from'),
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT g.id, g.role, g.user_id, g.group_id, g.valid_from, u.display_name AS user_name, dg.display_name AS group_name
            FROM document_grant g
            LEFT JOIN app_user u ON u.id = g.user_id
            LEFT JOIN directory_group dg ON dg.id = g.group_id
            WHERE g.document_id = ? AND g.valid_to IS NULL
            ORDER BY g.role, COALESCE(u.display_name, dg.display_name)
            SQL, [$documentId]));
    }

    /**
     * @param array<string, mixed> $input {role, userId} or {role, groupObjectId}
     */
    public function add(PalUser $user, string $documentId, array $input): string
    {
        $role = \is_string($input['role'] ?? null) ? $input['role'] : '';
        if (!\in_array($role, self::ROLES, true)) {
            throw ApiProblem::validation(['role' => 'Choose author, approver or reader.']);
        }
        $userId = $input['userId'] ?? null;
        $groupObjectId = \is_string($input['groupObjectId'] ?? null) ? strtolower(trim($input['groupObjectId'])) : null;
        if (!Ids::isUuid($userId) && !Ids::isUuid($groupObjectId)) {
            throw ApiProblem::validation(['principal' => 'Choose a person or enter a group object ID.']);
        }
        $actor = $user->auditActor();
        $groupId = null;
        $groupName = null;
        if (!Ids::isUuid($userId) && \is_string($groupObjectId)) {
            try {
                $group = $this->directory->trackGroup($groupObjectId, $actor);
            } catch (DirectoryException) {
                throw new ApiProblem(502, 'directory_lookup_failed', 'The group could not be found in the directory.');
            }
            $groupId = $group['id'];
            $groupName = $group['display_name'];
        }

        return $this->connection->transactional(function () use ($user, $documentId, $role, $userId, $groupId, $groupName, $groupObjectId, $actor): string {
            $document = $this->requireManageable($user, $documentId);
            if (Ids::isUuid($userId)) {
                if (false === $this->connection->fetchOne('SELECT 1 FROM app_user WHERE id = ? AND is_active', [$userId])) {
                    throw ApiProblem::validation(['principal' => 'That person was not found.']);
                }
                if ($userId === Row::string($document, 'owner_user_id') && 'APPROVER' === $role) {
                    throw ApiProblem::validation(['principal' => 'The owner cannot also be an approver of their own document.']);
                }
            }
            try {
                $grantId = Row::scalarString($this->connection->fetchOne(
                    'INSERT INTO document_grant (document_id, user_id, group_id, role, granted_by) VALUES (?, ?, ?, ?, ?) RETURNING id',
                    [$documentId, Ids::isUuid($userId) ? $userId : null, $groupId, $role, $user->id],
                ));
            } catch (UniqueConstraintViolationException) {
                throw new ApiProblem(409, 'grant_exists', 'That access is already granted.');
            }
            $this->audit->record(new AuditEntry('DOCUMENT_ACCESS_GRANTED', $actor, 'document', $documentId, [
                'grant_id' => $grantId, 'role' => $role,
                'user_id' => Ids::isUuid($userId) ? $userId : null,
                'group_object_id' => null === $groupId ? null : $groupObjectId,
                'group_display_name' => $groupName,
            ]));

            return $grantId;
        });
    }

    public function revoke(PalUser $user, string $documentId, string $grantId): void
    {
        $this->connection->transactional(function () use ($user, $documentId, $grantId): void {
            $this->requireManageable($user, $documentId);
            $grant = Ids::isUuid($grantId) ? $this->connection->fetchAssociative('SELECT * FROM document_grant WHERE id = ? AND document_id = ? AND valid_to IS NULL', [$grantId, $documentId]) : false;
            if (false === $grant) {
                throw new ApiProblem(404, 'not_found', 'Grant not found.');
            }
            $this->connection->executeStatement('UPDATE document_grant SET valid_to = GREATEST(now(), valid_from + interval \'1 microsecond\') WHERE id = ?', [$grantId]);
            $this->audit->record(new AuditEntry('DOCUMENT_ACCESS_REVOKED', $user->auditActor(), 'document', $documentId, [
                'grant_id' => $grantId, 'role' => Row::string($grant, 'role'),
                'user_id' => Row::nullableString($grant, 'user_id'), 'group_id' => Row::nullableString($grant, 'group_id'),
            ]));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function requireManageable(PalUser $user, string $documentId): array
    {
        $document = $this->documents->findDocument($documentId, true);
        if (null === $document || !$this->access->canSeeDocument($user, $document, null !== $this->documents->visibleRevision($documentId))) {
            throw new ApiProblem(404, 'not_found', 'Document not found.');
        }
        if (!$this->access->canManageAccess($user, $document)) {
            throw new ApiProblem(403, 'forbidden', 'Only the owner or a PAL administrator can change access.');
        }

        return $document;
    }
}
