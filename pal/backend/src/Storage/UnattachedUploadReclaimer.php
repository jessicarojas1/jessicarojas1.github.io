<?php

declare(strict_types=1);

namespace App\Storage;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * Reclaims never-attached uploads older than a retention window: a clean upload whose blob no
 * revision references. It deletes only the DATABASE rows — the `upload` records and the
 * `stored_file` row (the `upload.stored_sha256` FK and the `ck_upload_clean_stored` check leave
 * no other option) — inside one transaction per blob, audited (`UPLOAD_RECLAIMED`).
 *
 * It never deletes the blob file. That is left to {@see StorageGarbageCollector} (`pal:storage:gc`),
 * which frees disk race-safely: it materialises the referenced `stored_file` set first and skips
 * any blob a concurrent re-upload has re-registered. Deleting the blob here instead would risk a
 * dangling reference if identical content were re-uploaded and attached during reclamation.
 */
final class UnattachedUploadReclaimer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * SHA-256 of every never-attached stored file older than the window.
     *
     * @return iterable<string>
     */
    public function candidates(int $olderThanSeconds): iterable
    {
        $sql = <<<'SQL'
            SELECT sf.sha256 FROM stored_file sf
            WHERE EXISTS (SELECT 1 FROM upload u WHERE u.stored_sha256 = sf.sha256)
              AND NOT EXISTS (SELECT 1 FROM revision_file rf WHERE rf.sha256 = sf.sha256)
              AND NOT EXISTS (SELECT 1 FROM revision_image ri WHERE ri.sha256 = sf.sha256)
              AND NOT EXISTS (SELECT 1 FROM template_version_image ti WHERE ti.sha256 = sf.sha256)
              AND sf.created_at <= now() - make_interval(secs => ?)
            ORDER BY sf.size_bytes DESC
            SQL;
        foreach ($this->connection->iterateAssociative($sql, [$olderThanSeconds]) as $row) {
            yield Row::string($row, 'sha256');
        }
    }

    /**
     * Reclaim one candidate's database rows, re-checking eligibility under a row lock so a blob
     * attached (or made newer, or already removed) since it was listed is never reclaimed.
     * Returns the freed size and the number of upload rows removed, or null when it was skipped.
     *
     * @return array{sizeBytes: int, uploads: int}|null
     */
    public function reclaim(string $sha256, int $olderThanSeconds, AuditActor $actor): ?array
    {
        return $this->connection->transactional(function () use ($sha256, $olderThanSeconds, $actor): ?array {
            // Lock the stored_file row; this serialises with a concurrent upload's
            // INSERT ... ON CONFLICT for the same content.
            $row = $this->connection->fetchAssociative(
                'SELECT size_bytes FROM stored_file WHERE sha256 = ? AND created_at <= now() - make_interval(secs => ?) FOR UPDATE',
                [$sha256, $olderThanSeconds],
            );
            if (false === $row) {
                return null; // already gone, or no longer older than the window
            }
            // Never reclaim a blob a revision now references (attached since it was listed).
            // fetchOne returns false when there is no such row.
            if (false !== $this->connection->fetchOne(
                'SELECT 1 FROM revision_file WHERE sha256 = :s UNION ALL SELECT 1 FROM revision_image WHERE sha256 = :s UNION ALL SELECT 1 FROM template_version_image WHERE sha256 = :s LIMIT 1',
                ['s' => $sha256],
            )) {
                return null;
            }
            $countRow = $this->connection->fetchAssociative('SELECT count(*) AS n FROM upload WHERE stored_sha256 = ?', [$sha256]);
            $uploads = false === $countRow ? 0 : Row::int($countRow, 'n');
            $this->connection->executeStatement('DELETE FROM upload WHERE stored_sha256 = ?', [$sha256]);
            $this->connection->executeStatement('DELETE FROM stored_file WHERE sha256 = ?', [$sha256]);
            $size = Row::int($row, 'size_bytes');
            $this->audit->record(new AuditEntry('UPLOAD_RECLAIMED', $actor, 'stored_file', $sha256, [
                'size_bytes' => $size,
                'upload_rows_removed' => $uploads,
                'retention_seconds' => $olderThanSeconds,
            ]));

            return ['sizeBytes' => $size, 'uploads' => $uploads];
        });
    }
}
