<?php

declare(strict_types=1);

namespace App\Documents;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * Read access to documents, revisions, files and publications.
 */
final class DocumentRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDocument(string $id, bool $forUpdate = false): ?array
    {
        if (!Ids::isUuid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT d.*, dt.code AS type_code, dt.name AS type_name, ou.code AS dept_code, ou.name AS dept_name,
                   owner.display_name AS owner_name
            FROM document d
            JOIN document_type dt ON dt.id = d.document_type_id
            JOIN org_unit ou ON ou.id = d.org_unit_id
            JOIN app_user owner ON owner.id = d.owner_user_id
            WHERE d.id = ?
            SQL.($forUpdate ? ' FOR UPDATE OF d' : ''), [$id]);

        return false === $row ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRevision(string $id, bool $forUpdate = false): ?array
    {
        if (!Ids::isUuid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM revision WHERE id = ?'.($forUpdate ? ' FOR UPDATE' : ''), [$id]);

        return false === $row ? null : $row;
    }

    /**
     * The revision that is publicly visible right now, if any.
     *
     * @return array<string, mixed>|null
     */
    public function visibleRevision(string $documentId): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT r.*, p.released_at, p.id AS publication_id, lower(p.effective_period) AS effective_from
            FROM publication p JOIN revision r ON r.id = p.revision_id
            WHERE p.document_id = ? AND p.status = 'RELEASED' AND p.visible_period @> now()
            SQL, [$documentId]);

        return false === $row ? null : $row;
    }

    /**
     * The single open (not approved, not withdrawn) revision, if any.
     *
     * @return array<string, mixed>|null
     */
    public function openRevision(string $documentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM revision WHERE document_id = ? AND state IN ('DRAFT', 'IN_WORKFLOW', 'CHANGES_REQUESTED', 'REJECTED')",
            [$documentId],
        );

        return false === $row ? null : $row;
    }

    /**
     * Approved revisions not yet released (waiting for a publisher).
     *
     * @return array<string, mixed>|null
     */
    public function approvedUnreleased(string $documentId): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT r.* FROM revision r
            WHERE r.document_id = ? AND r.state = 'APPROVED'
              AND NOT EXISTS (SELECT 1 FROM publication p WHERE p.revision_id = r.id AND p.status = 'RELEASED')
            ORDER BY r.major DESC LIMIT 1
            SQL, [$documentId]);

        return false === $row ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function releaseHistory(string $documentId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT r.id, r.major, r.minor, r.title, r.change_summary, p.released_at,
                   lower(p.effective_period) AS effective_from, upper(p.effective_period) AS effective_to,
                   (p.visible_period @> now()) AS is_current, u.display_name AS released_by_name
            FROM publication p JOIN revision r ON r.id = p.revision_id
            LEFT JOIN app_user u ON u.id = p.released_by
            WHERE p.document_id = ? AND p.status = 'RELEASED'
            ORDER BY lower(p.effective_period) DESC
            SQL, [$documentId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function primaryFile(string $revisionId): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT rf.sha256, rf.original_filename, rf.role, sf.size_bytes, sf.detected_mime
            FROM revision_file rf JOIN stored_file sf ON sf.sha256 = rf.sha256
            WHERE rf.revision_id = ? AND rf.role = 'PRIMARY'
            SQL, [$revisionId]);

        return false === $row ? null : $row;
    }

    /**
     * @return list<array{role: string, sha256: string, filename: string}>
     */
    public function manifestFiles(string $revisionId): array
    {
        return array_map(fn (array $r): array => [
            'role' => Row::string($r, 'role'),
            'sha256' => Row::string($r, 'sha256'),
            'filename' => Row::string($r, 'original_filename'),
        ], $this->connection->fetchAllAssociative('SELECT role, sha256, original_filename FROM revision_file WHERE revision_id = ?', [$revisionId]));
    }

    /**
     * Latest workflow on the revision with its task and decision (single-approver skeleton).
     *
     * @return array<string, mixed>|null
     */
    public function latestWorkflow(string $revisionId): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT i.id AS instance_id, i.status, i.started_at, i.completed_at, i.bound_manifest_hash,
                   t.id AS task_id, t.status AS task_status, t.assignee_user_id, a.display_name AS assignee_name,
                   d.decision, d.comment, d.decided_at, d.manifest_hash_at_decision
            FROM workflow_instance i
            JOIN workflow_task t ON t.instance_id = i.id
            JOIN app_user a ON a.id = t.assignee_user_id
            LEFT JOIN approval_decision d ON d.task_id = t.id
            WHERE i.revision_id = ?
            ORDER BY i.started_at DESC LIMIT 1
            SQL, [$revisionId]);

        return false === $row ? null : $row;
    }

    public function nextMajor(string $documentId): int
    {
        return 1 + Row::int(['m' => $this->connection->fetchOne('SELECT COALESCE(MAX(major), 0) FROM revision WHERE document_id = ? AND label_confirmed', [$documentId])], 'm');
    }

    /**
     * @return list<string> keywords from a Postgres text[] value
     */
    public static function keywords(mixed $pgArray): array
    {
        if (!\is_string($pgArray) || '{}' === $pgArray) {
            return [];
        }
        $inner = substr($pgArray, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        return array_map(fn (array $x): string => null !== ($x[1] ?? null) ? stripcslashes($x[1]) : (string) ($x[2] ?? ''), $m);
    }

    /**
     * @param list<string> $keywords
     */
    public static function toPgArray(array $keywords): string
    {
        return '{'.implode(',', array_map(fn (string $k): string => '"'.addcslashes($k, '"\\').'"', $keywords)).'}';
    }
}
