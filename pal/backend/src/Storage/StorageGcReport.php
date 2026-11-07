<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Result of a garbage-collection pass over the blob store: how many blobs were scanned, which
 * on-disk orphans (no `stored_file` row, older than the age guard) were found, how many were
 * deleted or failed to delete, how many were skipped for being too recent, and the count of
 * abandoned `.incoming-*` temporaries handled.
 */
final class StorageGcReport
{
    /**
     * @param list<string> $orphans collectible orphan identifiers (unreferenced, older than the age guard)
     * @param list<string> $failed  orphans that could not be deleted (e.g. the directory denies delete)
     */
    public function __construct(
        public readonly int $scanned,
        public readonly array $orphans,
        public readonly int $skippedRecent,
        public readonly int $deleted,
        public readonly array $failed,
        public readonly int $temporariesFound,
        public readonly int $temporariesDeleted,
    ) {
    }

    public function foundAnything(): bool
    {
        return [] !== $this->orphans || $this->temporariesFound > 0;
    }
}
