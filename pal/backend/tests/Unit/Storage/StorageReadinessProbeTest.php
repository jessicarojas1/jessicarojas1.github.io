<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\StorageReadinessProbe;
use PHPUnit\Framework\TestCase;

final class StorageReadinessProbeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/pal-probe-'.bin2hex(random_bytes(6));
        mkdir($this->base, 0750);
    }

    protected function tearDown(): void
    {
        @chmod($this->base.'/locked', 0750);
        @rmdir($this->base.'/locked');
        @rmdir($this->base.'/new/blobs');
        @rmdir($this->base.'/new');
        @rmdir($this->base);
    }

    public function testWritableDirectoryIsReady(): void
    {
        self::assertTrue((new StorageReadinessProbe($this->base))->isReady());
        self::assertSame('storage', (new StorageReadinessProbe($this->base))->name());
    }

    public function testMissingDirectoryIsCreated(): void
    {
        self::assertTrue((new StorageReadinessProbe($this->base.'/new/blobs/'))->isReady());
        self::assertDirectoryExists($this->base.'/new/blobs');
    }

    public function testReadOnlyDirectoryIsNotReady(): void
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('root ignores directory permissions');
        }
        mkdir($this->base.'/locked', 0550);

        self::assertFalse((new StorageReadinessProbe($this->base.'/locked'))->isReady());
    }

    public function testUnconfiguredDirectoryIsNotReady(): void
    {
        self::assertFalse((new StorageReadinessProbe('  '))->isReady());
    }
}
