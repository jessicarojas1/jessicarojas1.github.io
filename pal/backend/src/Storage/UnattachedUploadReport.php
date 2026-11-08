<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Read-only summary of never-attached uploads: blobs PAL stored for a clean upload that were
 * never referenced by any document revision. They hold a `stored_file` row (and a `.sha256`
 * blob on disk), so `pal:storage:gc` deliberately leaves them alone; this report only quantifies
 * the space they occupy. Reclaiming them is a separate, policy-gated step (retention window +
 * removing the `upload`/`stored_file` rows) and is not performed here.
 */
final class UnattachedUploadReport
{
    /**
     * @param list<array{sha256: string, sizeBytes: int, firstUploadedAt: string, uploadCount: int}> $sample
     */
    public function __construct(
        public readonly int $fileCount,
        public readonly int $totalBytes,
        public readonly array $sample,
    ) {
    }
}
