<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\FilesystemBlobStore;
use App\Storage\StorageVerifier;
use PHPUnit\Framework\TestCase;

final class StorageVerifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pal-verify-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testCleanWhenEveryReferencedBlobIsPresentAndIntact(): void
    {
        $store = new FilesystemBlobStore($this->dir);
        $a = $this->store($store, 'policy bytes');
        $b = $this->store($store, 'procedure bytes');

        $report = (new StorageVerifier($store))->verify([$a, $b]);

        self::assertTrue($report->isClean());
        self::assertSame(2, $report->checked);
        self::assertSame(2, $report->okCount());
    }

    public function testDetectsMissingAndCorruptBlobs(): void
    {
        $store = new FilesystemBlobStore($this->dir);
        $good = $this->store($store, 'intact bytes');
        $corrupt = $this->store($store, 'original bytes');
        $missing = str_repeat('a', 64); // valid hex id, never stored

        // Tamper the stored bytes of $corrupt (blobs are written read-only).
        $path = $this->blobPath($corrupt);
        chmod($path, 0640);
        file_put_contents($path, 'tampered bytes of a different length');

        $report = (new StorageVerifier($store))->verify([$good, $corrupt, $missing]);

        self::assertFalse($report->isClean());
        self::assertSame(3, $report->checked);
        self::assertSame(1, $report->okCount());
        self::assertSame([$missing], $report->missing);
        self::assertSame([$corrupt], $report->corrupt);
    }

    public function testEmptyInputIsClean(): void
    {
        $report = (new StorageVerifier(new FilesystemBlobStore($this->dir)))->verify([]);

        self::assertTrue($report->isClean());
        self::assertSame(0, $report->checked);
    }

    private function store(FilesystemBlobStore $store, string $bytes): string
    {
        $sha256 = hash('sha256', $bytes);
        $source = $this->dir.'/.src-'.bin2hex(random_bytes(4));
        file_put_contents($source, $bytes);
        $store->put($source, $sha256);
        @unlink($source);

        return $sha256;
    }

    private function blobPath(string $sha256): string
    {
        return $this->dir.'/sha256/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;
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
