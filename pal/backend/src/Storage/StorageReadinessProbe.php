<?php

declare(strict_types=1);

namespace App\Storage;

use App\Platform\Health\ReadinessProbe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Readiness fails when the blob directory is missing or not writable by the app user, so a
 * misconfigured volume (wrong owner, read-only mount) is caught at deploy time rather than
 * on the first upload. Checks permissions only; it never writes or deletes probe files.
 */
final class StorageReadinessProbe implements ReadinessProbe
{
    public function __construct(
        #[Autowire(env: 'PAL_BLOB_DIR')]
        private readonly string $root,
    ) {
    }

    public function name(): string
    {
        return 'storage';
    }

    public function isReady(): bool
    {
        $root = rtrim($this->root, '/');
        if ('' === trim($root)) {
            return false;
        }
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            return false;
        }

        return is_writable($root);
    }
}
