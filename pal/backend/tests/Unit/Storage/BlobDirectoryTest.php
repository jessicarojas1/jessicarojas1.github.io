<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\BlobDirectory;
use App\Storage\StorageReadinessProbe;
use PHPUnit\Framework\TestCase;

final class BlobDirectoryTest extends TestCase
{
    public function testRelativePathsResolveAgainstTheProjectNotTheWorkingDirectory(): void
    {
        self::assertSame('/srv/app/var/blobs', BlobDirectory::resolve('var/blobs', '/srv/app'));
        self::assertSame('/srv/app/var/blobs', BlobDirectory::resolve('./var/blobs', '/srv/app/'));
        self::assertSame('/srv/pal/data/blobs', BlobDirectory::resolve('/srv/pal/data/blobs', '/srv/app'));
        self::assertSame('', BlobDirectory::resolve('  ', '/srv/app'));
        self::assertSame('/srv/app/../blobs', BlobDirectory::resolve('../blobs', '/srv/app'));
        self::assertSame('/srv/app/.blobs', BlobDirectory::resolve('.blobs', '/srv/app'));
    }

    public function testTheBuiltInServersWebRootIsNeverUsed(): void
    {
        // PHP's built-in server runs with public/ as its working directory.
        $project = sys_get_temp_dir().'/pal-proj-'.bin2hex(random_bytes(4));
        mkdir($project.'/public', 0o750, true);
        $previous = getcwd();
        chdir($project.'/public');
        try {
            self::assertTrue((new StorageReadinessProbe('var/blobs', $project))->isReady());
            self::assertDirectoryExists($project.'/var/blobs');
            self::assertDirectoryDoesNotExist($project.'/public/var');
        } finally {
            chdir((string) $previous);
            exec('rm -rf '.escapeshellarg($project));
        }
    }
}
