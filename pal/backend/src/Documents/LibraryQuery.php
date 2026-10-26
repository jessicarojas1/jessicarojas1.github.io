<?php

declare(strict_types=1);

namespace App\Documents;

use App\Identity\User\PalUser;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * The library: currently published documents the user may read, with metadata full-text
 * search. Authorization is part of the SQL, so drafts and restricted documents never
 * reach the result set (no filtering after the fact).
 */
final class LibraryQuery
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentAccess $access,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    public function search(PalUser $user, string $query, ?string $typeId, ?string $departmentId, int $page): array
    {
        [$visibility, $params] = $this->access->visibilitySql($user);
        $where = ["p.status = 'RELEASED'", 'p.visible_period @> now()', "d.disposition = 'ACTIVE'", $visibility];
        $query = trim(mb_substr($query, 0, 200));
        if ('' !== $query) {
            $where[] = "(r.search_vector @@ websearch_to_tsquery('english', :q) OR d.document_number ILIKE :like)";
            $params['q'] = $query;
            $params['like'] = '%'.addcslashes($query, '%_\\').'%';
        }
        if (Ids::isUuid($typeId)) {
            $where[] = 'd.document_type_id = :type';
            $params['type'] = $typeId;
        }
        if (Ids::isUuid($departmentId)) {
            $where[] = 'd.org_unit_id = :dept';
            $params['dept'] = $departmentId;
        }
        $from = <<<'SQL'
            FROM publication p
            JOIN revision r ON r.id = p.revision_id
            JOIN document d ON d.id = p.document_id
            JOIN document_type dt ON dt.id = d.document_type_id
            JOIN org_unit ou ON ou.id = d.org_unit_id
            SQL;
        $whereSql = ' WHERE '.implode(' AND ', $where);
        $total = Row::int(['n' => $this->connection->fetchOne('SELECT count(*) '.$from.$whereSql, $params)], 'n');
        $page = max(1, $page);
        $order = '' !== $query
            ? "ORDER BY ts_rank(r.search_vector, websearch_to_tsquery('english', :q)) DESC, d.document_number"
            : 'ORDER BY d.document_number';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.id, d.document_number, d.audience, dt.code AS type_code, dt.name AS type_name, ou.code AS dept_code, ou.name AS dept_name, '
            .'r.title, r.description, r.major, r.minor, p.released_at '.$from.$whereSql.' '.$order
            .' LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page - 1) * self::PAGE_SIZE),
            $params,
        );

        return [
            'items' => array_map(fn (array $r): array => [
                'id' => Row::string($r, 'id'),
                'documentNumber' => Row::string($r, 'document_number'),
                'title' => Row::string($r, 'title'),
                'description' => Row::nullableString($r, 'description'),
                'type' => ['code' => Row::string($r, 'type_code'), 'name' => Row::string($r, 'type_name')],
                'department' => ['code' => Row::string($r, 'dept_code'), 'name' => Row::string($r, 'dept_name')],
                'revision' => Row::int($r, 'major').'.'.Row::int($r, 'minor'),
                'releasedAt' => Row::string($r, 'released_at'),
                'restricted' => 'RESTRICTED' === $r['audience'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    /**
     * Documents the user owns or authors, with their work in progress.
     *
     * @return list<array<string, mixed>>
     */
    public function workspace(PalUser $user): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.document_number, dt.name AS type_name,
                   open_rev.id AS open_revision_id, open_rev.state AS open_state, open_rev.title AS open_title, open_rev.major AS open_major,
                   pub_rev.title AS published_title, pub_rev.major AS published_major, pub_rev.minor AS published_minor,
                   (SELECT r2.major FROM revision r2 WHERE r2.document_id = d.id AND r2.state = 'APPROVED'
                      AND NOT EXISTS (SELECT 1 FROM publication p2 WHERE p2.revision_id = r2.id AND p2.status = 'RELEASED') LIMIT 1) AS awaiting_release_major
            FROM document d
            JOIN document_type dt ON dt.id = d.document_type_id
            LEFT JOIN revision open_rev ON open_rev.document_id = d.id AND open_rev.state IN ('DRAFT', 'IN_WORKFLOW', 'CHANGES_REQUESTED', 'REJECTED')
            LEFT JOIN publication p ON p.document_id = d.id AND p.status = 'RELEASED' AND p.visible_period @> now()
            LEFT JOIN revision pub_rev ON pub_rev.id = p.revision_id
            WHERE d.disposition = 'ACTIVE' AND (d.owner_user_id = :user OR EXISTS (
                SELECT 1 FROM document_grant g WHERE g.document_id = d.id AND g.role = 'AUTHOR' AND g.valid_to IS NULL
                  AND (g.user_id = :user OR g.group_id IN (SELECT gm.group_id FROM group_membership gm WHERE gm.user_id = :user AND gm.valid_to IS NULL))))
            ORDER BY d.updated_at DESC, d.document_number
            LIMIT 200
            SQL, ['user' => $user->id]);

        return array_map(fn (array $r): array => [
            'id' => Row::string($r, 'id'),
            'documentNumber' => Row::string($r, 'document_number'),
            'type' => Row::string($r, 'type_name'),
            'title' => Row::nullableString($r, 'open_title') ?? Row::nullableString($r, 'published_title') ?? '',
            'openRevision' => null === $r['open_revision_id'] ? null : [
                'id' => Row::string($r, 'open_revision_id'),
                'state' => Row::string($r, 'open_state'),
                'proposedLabel' => Row::int($r, 'open_major').'.0',
            ],
            'published' => null === $r['published_major'] ? null : Row::int($r, 'published_major').'.'.Row::int($r, 'published_minor'),
            'awaitingRelease' => null === $r['awaiting_release_major'] ? null : Row::int($r, 'awaiting_release_major').'.0',
        ], $rows);
    }

    /**
     * Open approval tasks assigned to the user.
     *
     * @return list<array<string, mixed>>
     */
    public function myTasks(PalUser $user): array
    {
        return array_map(fn (array $r): array => [
            'taskId' => Row::string($r, 'task_id'),
            'documentId' => Row::string($r, 'document_id'),
            'documentNumber' => Row::string($r, 'document_number'),
            'title' => Row::string($r, 'title'),
            'revisionId' => Row::string($r, 'revision_id'),
            'proposedLabel' => Row::int($r, 'major').'.0',
            'submittedBy' => Row::string($r, 'submitted_by'),
            'submittedAt' => Row::string($r, 'started_at'),
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT t.id AS task_id, d.id AS document_id, d.document_number, r.title, r.id AS revision_id, r.major,
                   u.display_name AS submitted_by, i.started_at
            FROM workflow_task t
            JOIN workflow_instance i ON i.id = t.instance_id
            JOIN revision r ON r.id = i.revision_id
            JOIN document d ON d.id = r.document_id
            JOIN app_user u ON u.id = i.initiated_by
            WHERE t.assignee_user_id = ? AND t.status = 'OPEN' AND i.status = 'ACTIVE'
            ORDER BY i.started_at
            SQL, [$user->id]));
    }

    /**
     * Approved revisions waiting to be released (for publishers).
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingRelease(): array
    {
        return array_map(fn (array $r): array => [
            'documentId' => Row::string($r, 'document_id'),
            'documentNumber' => Row::string($r, 'document_number'),
            'title' => Row::string($r, 'title'),
            'revisionId' => Row::string($r, 'id'),
            'label' => Row::int($r, 'major').'.'.Row::int($r, 'minor'),
            'approvedAt' => Row::string($r, 'approved_at'),
        ], $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT r.id, r.document_id, r.title, r.major, r.minor, r.approved_at, d.document_number
            FROM revision r JOIN document d ON d.id = r.document_id
            WHERE r.state = 'APPROVED' AND d.disposition = 'ACTIVE'
              AND NOT EXISTS (SELECT 1 FROM publication p WHERE p.revision_id = r.id AND p.status = 'RELEASED')
            ORDER BY r.approved_at
            SQL));
    }
}
