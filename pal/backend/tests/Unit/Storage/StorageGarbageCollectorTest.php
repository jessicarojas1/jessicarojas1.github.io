<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\FilesystemBlobStore;
use App\Storage\StorageGarbageCollector;
use PHPUnit\Framework\TestCase;

final class StorageGarbageCollectorTest extends TestCase
{
    private string $dir;
    private FilesystemBlobStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pal-gc-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
        $this->store = new FilesystemBlobStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testKeepsReferencedCollectsOldOrphansSkipsRecentAndStaleTemporaries(): void
    {
        $referenced = $this->put('referenced bytes');
        $oldOrphan = $this->put('old orphan bytes');
        $recentOrphan = $this->put('recent orphan bytes');
        $this->setAge($oldOrphan, 100_000);          // well past the guard
        $this->setAge($recentOrphan, 10);            // inside the guard
        $staleTemp = $this->makeTemporary(100_000);
        $freshTemp = $this->makeTemporary(5);

        // Dry run: reports but changes nothing.
        $dry = (new StorageGarbageCollector($this->store))->collect([$referenced], 3600, false);
        self::assertSame(3, $dry->scanned);
        self::assertSame([$oldOrphan], $dry->orphans);
        self::assertSame(1, $dry->skippedRecent);
        self::assertSame(0, $dry->deleted);
        self::assertSame(1, $dry->temporariesFound);
        self::assertSame(0, $dry->temporariesDeleted);
        self::assertTrue($this->store->exists($oldOrphan), 'dry run must not delete');
        self::assertFileExists($staleTemp);

        // Delete.
        $run = (new StorageGarbageCollector($this->store))->collect([$referenced], 3600, true);
        self::assertSame([$oldOrphan], $run->orphans);
        self::assertSame(1, $run->deleted);
        self::assertSame([], $run->failed);
        self::assertSame(1, $run->temporariesDeleted);

        self::assertFalse($this->store->exists($oldOrphan), 'old orphan removed');
        self::assertTrue($this->store->exists($referenced), 'referenced blob kept');
        self::assertTrue($this->store->exists($recentOrphan), 'recent orphan kept (age guard)');
        self::assertFileDoesNotExist($staleTemp);
        self::assertFileExists($freshTemp, 'fresh temporary kept');
    }

    private function put(string $bytes): string
    {
        $sha256 = hash('sha256', $bytes);
        $source = $this->dir.'/.src-'.bin2hex(random_bytes(4));
        file_put_contents($source, $bytes);
        $this->store->put($source, $sha256);
        @unlink($source);

        return $sha256;
    }

    private function blobPath(string $sha256): string
    {
        return $this->dir.'/sha256/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;
    }

    private function setAge(string $sha256, int $seconds): void
    {
        touch($this->blobPath($sha256), time() - $seconds);
    }

    private function makeTemporary(int $ageSeconds): string
    {
        $subdir = $this->dir.'/sha256/aa/bb';
        if (!is_dir($subdir)) {
            mkdir($subdir, 0750, true);
        }
        $path = $subdir.'/.incoming-'.bin2hex(random_bytes(6));
        file_put_contents($path, 'partial');
        touch($path, time() - $ageSeconds);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0640);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
