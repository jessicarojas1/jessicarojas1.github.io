<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Reclaims disk space held by UNREFERENCED blobs: files on disk that have no `stored_file`
 * row (orphans left when a put() succeeded but its database insert then failed), plus
 * abandoned `.incoming-*` temporaries. It never touches a blob PAL recorded in `stored_file`
 * — including never-attached uploads — so immutability and approved content are preserved.
 * An age guard avoids racing an upload that has written its blob but not yet inserted the row.
 */
final class StorageGarbageCollector
{
    public function __construct(
        private readonly FilesystemBlobStore $store,
    ) {
    }

    /**
     * @param iterable<string> $referenced every `stored_file` SHA-256 (the set that must be kept)
     */
    public function collect(iterable $referenced, int $minAgeSeconds, bool $delete): StorageGcReport
    {
        // Materialise the referenced set BEFORE touching the filesystem. If the caller's query
        // throws mid-stream, we abort here with nothing deleted, so a transient database error
        // can never cause a referenced blob to be removed.
        $keep = [];
        foreach ($referenced as $sha256) {
            $keep[$sha256] = true;
        }

        $scanned = 0;
        $skippedRecent = 0;
        $deleted = 0;
        $orphans = [];
        $failed = [];

        foreach ($this->store->listStoredHashes() as $sha256) {
            ++$scanned;
            if (isset($keep[$sha256])) {
                continue;
            }
            $age = $this->store->ageSeconds($sha256);
            if (null === $age || $age < $minAgeSeconds) {
                ++$skippedRecent;
                continue;
            }
            $orphans[] = $sha256;
            if ($delete) {
                if ($this->store->deleteOrphan($sha256)) {
                    ++$deleted;
                } else {
                    $failed[] = $sha256;
                }
            }
        }

        $temporaries = $this->store->pruneStaleTemporaries($minAgeSeconds, $delete);

        return new StorageGcReport($scanned, $orphans, $skippedRecent, $deleted, $failed, $temporaries['found'], $temporaries['deleted']);
    }
}
