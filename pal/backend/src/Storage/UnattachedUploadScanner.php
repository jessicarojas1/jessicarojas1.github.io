<?php

declare(strict_types=1);

namespace App\Storage;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

/**
 * Finds never-attached uploads: `stored_file` rows produced by a clean upload (`upload.stored_sha256`)
 * that no `revision_file` references. Read-only — it reports what could be reclaimed but changes
 * nothing. The query reads the Documents-owned `revision_file` table directly (by name, not a code
 * dependency), as the GC command reads `stored_file`: a blob is "attached" iff some revision links it as a file or shows it as an image
 * (`revision_image`), or some template version shows it (`template_version_image`).
 */
final class UnattachedUploadScanner
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function scan(int $olderThanSeconds = 0, int $sampleSize = 20): UnattachedUploadReport
    {
        $where = <<<'SQL'
            WHERE EXISTS (SELECT 1 FROM upload u WHERE u.stored_sha256 = sf.sha256)
              AND NOT EXISTS (SELECT 1 FROM revision_file rf WHERE rf.sha256 = sf.sha256)
              AND NOT EXISTS (SELECT 1 FROM revision_image ri WHERE ri.sha256 = sf.sha256)
              AND NOT EXISTS (SELECT 1 FROM template_version_image ti WHERE ti.sha256 = sf.sha256)
            SQL;
        $params = [];
        if ($olderThanSeconds > 0) {
            $where .= "\n              AND sf.created_at <= now() - make_interval(secs => ?)";
            $params[] = $olderThanSeconds;
        }

        $totals = $this->connection->fetchAssociative(
            "SELECT count(*) AS file_count, COALESCE(sum(sf.size_bytes), 0) AS total_bytes FROM stored_file sf\n".$where,
            $params,
        );
        $fileCount = false === $totals ? 0 : Row::int($totals, 'file_count');
        $totalBytes = false === $totals ? 0 : Row::int($totals, 'total_bytes');

        $sample = [];
        if ($fileCount > 0 && $sampleSize > 0) {
            $sql = "SELECT sf.sha256, sf.size_bytes, sf.created_at,\n"
                ."       (SELECT count(*) FROM upload u WHERE u.stored_sha256 = sf.sha256) AS upload_count,\n"
                ."       (SELECT min(u.created_at) FROM upload u WHERE u.stored_sha256 = sf.sha256) AS first_uploaded_at\n"
                .'FROM stored_file sf'."\n".$where."\n".'ORDER BY sf.size_bytes DESC, sf.created_at ASC LIMIT '.$sampleSize;
            foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
                $sample[] = [
                    'sha256' => Row::string($row, 'sha256'),
                    'sizeBytes' => Row::int($row, 'size_bytes'),
                    'firstUploadedAt' => Row::string($row, 'first_uploaded_at'),
                    'uploadCount' => Row::int($row, 'upload_count'),
                ];
            }
        }

        return new UnattachedUploadReport($fileCount, $totalBytes, $sample);
    }
}
