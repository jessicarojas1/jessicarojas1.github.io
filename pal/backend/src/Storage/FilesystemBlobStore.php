<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Filesystem/NAS backend (the selected on-prem primary, ADR-0006). Files land under
 * sha256/ab/cd/<hash> via write-to-temp + atomic rename, and are made read-only. Existing
 * objects are never overwritten. Real immutability also needs the directory to deny the
 * application user delete rights (operator responsibility, see SECURITY.md).
 */
final class FilesystemBlobStore implements BlobStore
{
    public function __construct(
        #[Autowire(env: 'PAL_BLOB_DIR')]
        private readonly string $root,
    ) {
    }

    public function put(string $sourcePath, string $sha256): void
    {
        $target = $this->path($sha256);
        if (is_file($target)) {
            return;
        }
        $directory = \dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new StorageException('Could not create the blob directory.');
        }
        $temporary = $directory.'/.incoming-'.bin2hex(random_bytes(8));
        if (!@copy($sourcePath, $temporary)) {
            throw new StorageException('Could not write the blob.');
        }
        if (!hash_equals($sha256, (string) hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new StorageException('Blob content changed while being stored.');
        }
        @chmod($temporary, 0440);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            if (!is_file($target)) {
                throw new StorageException('Could not commit the blob.');
            }
        }
    }

    public function exists(string $sha256): bool
    {
        return is_file($this->path($sha256));
    }

    public function openRead(string $sha256)
    {
        $handle = is_file($this->path($sha256)) ? @fopen($this->path($sha256), 'rb') : false;
        if (false === $handle) {
            throw new StorageException('Blob not found.');
        }

        return $handle;
    }

    public function backendName(): string
    {
        return 'filesystem';
    }

    private function path(string $sha256): string
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new StorageException('Invalid blob identifier.');
        }
        if ('' === trim($this->root)) {
            throw new StorageException('PAL_BLOB_DIR is not configured.');
        }

        return rtrim($this->root, '/').'/sha256/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;
    }
}
