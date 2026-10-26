<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Content-addressed, write-once file storage. Objects are identified by the SHA-256 of
 * their bytes, never by a user-supplied name or path.
 */
interface BlobStore
{
    /**
     * Stores the file if these bytes are not stored yet. Idempotent.
     */
    public function put(string $sourcePath, string $sha256): void;

    public function exists(string $sha256): bool;

    /**
     * @return resource a readable stream; the caller closes it
     */
    public function openRead(string $sha256);

    public function backendName(): string;
}
