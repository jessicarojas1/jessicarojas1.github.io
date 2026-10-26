<?php

declare(strict_types=1);

namespace App\Documents;

use App\Identity\Authorization\Permission;
use App\Identity\Authorization\RolePermissionMap;
use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * The single place that decides what a user may see and do with a document.
 *
 * Callers answer "not visible" with HTTP 404 (never 403), so the existence of drafts and
 * restricted documents is not revealed. The same rules are expressed in SQL in
 * {@see self::visibilitySql()} for lists and search, and the two must stay in step
 * (both are covered by DocumentAccessTest).
 */
final class DocumentAccess
{
    /** @var array<string, list<string>> */
    private array $relationCache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function can(PalUser $user, string $permission): bool
    {
        return \in_array($permission, RolePermissionMap::permissionsFor($user->globalRoles), true);
    }

    /**
     * Roles the user holds on the document: OWNER, plus any open AUTHOR/APPROVER/READER grant
     * held directly or through a current directory-group membership.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public function relations(PalUser $user, array $document): array
    {
        $documentId = Row::string($document, 'id');
        $key = $user->id.'|'.$documentId;
        if (!isset($this->relationCache[$key])) {
            $roles = Row::strings($this->connection->fetchFirstColumn(<<<'SQL'
                SELECT DISTINCT g.role FROM document_grant g
                WHERE g.document_id = :doc AND g.valid_to IS NULL
                  AND (g.user_id = :user OR g.group_id IN (
                      SELECT gm.group_id FROM group_membership gm WHERE gm.user_id = :user AND gm.valid_to IS NULL))
                SQL, ['doc' => $documentId, 'user' => $user->id]));
            if (Row::string($document, 'owner_user_id') === $user->id) {
                $roles[] = 'OWNER';
            }
            $this->relationCache[$key] = $roles;
        }

        return $this->relationCache[$key];
    }

    /**
     * @param array<string, mixed> $document
     */
    public function isOwnerOrAuthor(PalUser $user, array $document): bool
    {
        return [] !== array_intersect(['OWNER', 'AUTHOR'], $this->relations($user, $document));
    }

    /**
     * May the user read the document's currently published content?
     *
     * @param array<string, mixed> $document
     */
    public function canReadPublished(PalUser $user, array $document): bool
    {
        return 'ALL_EMPLOYEES' === $document['audience']
            || self::can($user, Permission::DOCUMENT_READ_ALL)
            || [] !== $this->relations($user, $document);
    }

    /**
     * May the user see work in progress (drafts, submitted, returned, approved-unreleased)
     * and superseded revisions? Owners, authors, the assigned approver and governance.
     *
     * @param array<string, mixed> $document
     */
    public function canReadWorkInProgress(PalUser $user, array $document, ?string $revisionId = null): bool
    {
        if (self::can($user, Permission::DOCUMENT_READ_ALL) || $this->isOwnerOrAuthor($user, $document)) {
            return true;
        }
        // Publishers review what they release: approved revisions not yet released.
        if (null !== $revisionId && self::can($user, Permission::PUBLICATION_RELEASE) && $this->isAwaitingRelease($revisionId)) {
            return true;
        }

        return null !== $revisionId && false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM workflow_task t JOIN workflow_instance i ON i.id = t.instance_id
            WHERE i.revision_id = ? AND t.assignee_user_id = ?
            SQL, [$revisionId, $user->id]);
    }

    /**
     * May the user know this document exists at all?
     *
     * @param array<string, mixed> $document
     */
    public function canSeeDocument(PalUser $user, array $document, bool $hasVisiblePublication): bool
    {
        if ($hasVisiblePublication && $this->canReadPublished($user, $document)) {
            return true;
        }
        if (self::can($user, Permission::DOCUMENT_READ_ALL) || [] !== $this->relations($user, $document)) {
            return true;
        }
        if (self::can($user, Permission::PUBLICATION_RELEASE) && false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM revision r WHERE r.document_id = ? AND r.state = 'APPROVED'
              AND NOT EXISTS (SELECT 1 FROM publication p WHERE p.revision_id = r.id AND p.status = 'RELEASED')
            SQL, [Row::string($document, 'id')])) {
            return true;
        }

        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM workflow_task t JOIN workflow_instance i ON i.id = t.instance_id JOIN revision r ON r.id = i.revision_id
            WHERE r.document_id = ? AND t.assignee_user_id = ?
            SQL, [Row::string($document, 'id'), $user->id]);
    }

    private function isAwaitingRelease(string $revisionId): bool
    {
        return false !== $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM revision r WHERE r.id = ? AND r.state = 'APPROVED'
              AND NOT EXISTS (SELECT 1 FROM publication p WHERE p.revision_id = r.id AND p.status = 'RELEASED')
            SQL, [$revisionId]);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function canManageAccess(PalUser $user, array $document): bool
    {
        return \in_array('OWNER', $this->relations($user, $document), true) || self::can($user, Permission::DOCUMENT_MANAGE_ACCESS);
    }

    /**
     * SQL predicate (alias d = document) restricting published content to what the user may
     * read. Mirrors canReadPublished().
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function visibilitySql(PalUser $user): array
    {
        if (self::can($user, Permission::DOCUMENT_READ_ALL)) {
            return ['TRUE', []];
        }

        return [<<<'SQL'
            (d.audience = 'ALL_EMPLOYEES' OR d.owner_user_id = :vis_user OR EXISTS (
                SELECT 1 FROM document_grant g WHERE g.document_id = d.id AND g.valid_to IS NULL
                  AND (g.user_id = :vis_user OR g.group_id IN (
                      SELECT gm.group_id FROM group_membership gm WHERE gm.user_id = :vis_user AND gm.valid_to IS NULL))))
            SQL, ['vis_user' => $user->id]];
    }
}
