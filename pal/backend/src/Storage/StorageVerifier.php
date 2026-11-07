<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Verifies that each referenced blob still exists in the store and matches its SHA-256.
 * Content-addressed storage is self-verifying: re-hashing the bytes must reproduce the
 * identifier, so this detects silent corruption, truncation, or an out-of-band deletion.
 * Backend-agnostic: it uses only the BlobStore interface (filesystem, NAS, future S3).
 */
final class StorageVerifier
{
    public function __construct(
        private readonly BlobStore $blobs,
    ) {
    }

    /**
     * @param iterable<string> $sha256s referenced blob identifiers (e.g. every stored_file row)
     */
    public function verify(iterable $sha256s): StorageVerifyReport
    {
        $checked = 0;
        $missing = [];
        $corrupt = [];

        foreach ($sha256s as $sha256) {
            ++$checked;
            try {
                if (!$this->blobs->exists($sha256)) {
                    $missing[] = $sha256;
                    continue;
                }
                $handle = $this->blobs->openRead($sha256);
                try {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $handle);
                    $actual = hash_final($context);
                } finally {
                    fclose($handle);
                }
                if (!hash_equals($sha256, $actual)) {
                    $corrupt[] = $sha256;
                }
            } catch (\Throwable) {
                // Present but unreadable (permissions, I/O error) counts as corrupt for reporting.
                $corrupt[] = $sha256;
            }
        }

        return new StorageVerifyReport($checked, $missing, $corrupt);
    }
}
