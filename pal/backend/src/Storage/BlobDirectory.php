<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Where blobs live. A relative PAL_BLOB_DIR (e.g. the development default `var/blobs`) is
 * resolved against the backend project directory, never the process's working directory:
 * PHP's built-in server runs with the web root (`public/`) as its working directory, so a
 * cwd-relative path would put stored documents and images inside the web root, where they
 * could be fetched without any access check. Production sets an absolute path.
 */
final class BlobDirectory
{
    public static function resolve(string $configured, string $projectDir): string
    {
        $configured = trim($configured);
        if ('' === $configured || str_starts_with($configured, '/')) {
            return $configured;
        }

        // Drop only leading "./" segments; "../" is kept (it is a real relative path).
        return rtrim($projectDir, '/').'/'.(string) preg_replace('#^(?:\./)+#', '', $configured);
    }
}
