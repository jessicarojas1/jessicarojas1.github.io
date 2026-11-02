<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ClamdScanner;
use App\Storage\FilesystemBlobStore;
use App\Storage\FileTypePolicy;
use App\Storage\StorageException;
use App\Tests\Support\FakeClamd;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StoragePrimitivesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/pal-storage-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w '.escapeshellarg($this->dir).' && rm -rf '.escapeshellarg($this->dir));
    }

    private function file(string $name, string $content): string
    {
        file_put_contents($this->dir.'/'.$name, $content);

        return $this->dir.'/'.$name;
    }

    public function testClamdScannerReportsCleanAndInfectedOverInstream(): void
    {
        $clamd = new FakeClamd();
        $scanner = new ClamdScanner($clamd->address, false, 'test', new NullLogger(), 5.0);

        self::assertTrue($scanner->scan($this->file('ok.pdf', FakeClamd::minimalPdf()))->isClean());
        $infected = $scanner->scan($this->file('bad.txt', FakeClamd::eicar()));
        self::assertTrue($infected->isInfected());
        self::assertSame('Eicar-Test-Signature', $infected->detail);
        $clamd->stop();
    }

    public function testScannerFailsClosed(): void
    {
        $path = $this->file('ok.pdf', FakeClamd::minimalPdf());

        $unreachable = new ClamdScanner('tcp://127.0.0.1:1', false, 'test', new NullLogger(), 1.0);
        self::assertFalse($unreachable->scan($path)->isClean());
        $unconfigured = new ClamdScanner('', false, 'test', new NullLogger());
        self::assertFalse($unconfigured->scan($path)->isClean());
        $garbage = new FakeClamd('garbage');
        self::assertFalse((new ClamdScanner($garbage->address, false, 'test', new NullLogger(), 5.0))->scan($path)->isClean());
        $garbage->stop();
    }

    public function testUnscannedModeRequiresExplicitOptInAndIsLabelledPerEnvironment(): void
    {
        $path = $this->file('ok.pdf', FakeClamd::minimalPdf());

        // Fail closed by default: no scanner and no explicit opt-in => refused, in any environment.
        self::assertFalse((new ClamdScanner('', false, 'prod', new NullLogger()))->scan($path)->isClean());
        self::assertFalse((new ClamdScanner('', false, 'dev', new NullLogger()))->scan($path)->isClean());

        // Explicit opt-in (PAL_ALLOW_UNSCANNED_UPLOADS=1) accepts without scanning in any environment,
        // but the recorded scan engine marks production uploads as unscanned rather than "development".
        $dev = (new ClamdScanner('', true, 'dev', new NullLogger()))->scan($path);
        self::assertTrue($dev->isClean());
        self::assertSame('none (development)', $dev->engine);

        $prod = (new ClamdScanner('', true, 'prod', new NullLogger()))->scan($path);
        self::assertTrue($prod->isClean());
        self::assertSame('none (unscanned)', $prod->engine);
    }

    public function testTypeIsDecidedByContentAndMustMatchTheExtension(): void
    {
        $policy = new FileTypePolicy();
        $docx = $this->dir.'/real.docx';
        FakeClamd::minimalDocx($docx);
        $pdf = $this->file('real.pdf', FakeClamd::minimalPdf());
        $exe = $this->file('setup.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 64).'PE');

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $policy->detect($docx));
        self::assertNull($policy->rejectionReason($policy->detect($docx), 'real.docx'));
        self::assertNull($policy->rejectionReason($policy->detect($pdf), 'Quality Manual.PDF'));
        self::assertNotNull($policy->rejectionReason($policy->detect($pdf), 'renamed.docx'), 'extension must match content');
        self::assertNotNull($policy->rejectionReason($policy->detect($exe), 'setup.pdf'), 'executables are never accepted');
    }

    public function testFilenamesAreSanitised(): void
    {
        self::assertSame('passwd', FileTypePolicy::safeFilename('../../etc/passwd'));
        self::assertSame('evil.pdf', FileTypePolicy::safeFilename('C:\\Users\\x\\evil.pdf'));
        self::assertSame('a_b.pdf', FileTypePolicy::safeFilename("a\r\nb.pdf"));
        self::assertSame('file', FileTypePolicy::safeFilename('...'));
    }

    public function testBlobStoreIsContentAddressedAndWriteOnce(): void
    {
        $store = new FilesystemBlobStore($this->dir.'/blobs');
        $content = FakeClamd::minimalPdf();
        $sha = hash('sha256', $content);

        $store->put($this->file('a.pdf', $content), $sha);
        $store->put($this->file('b.pdf', $content), $sha); // idempotent

        self::assertTrue($store->exists($sha));
        $handle = $store->openRead($sha);
        self::assertSame($content, stream_get_contents($handle));
        fclose($handle);
        self::assertFileExists($this->dir.'/blobs/sha256/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha);
    }

    public function testBlobStoreRejectsWrongHashesAndHostileIds(): void
    {
        $store = new FilesystemBlobStore($this->dir.'/blobs');

        try {
            $store->put($this->file('a.pdf', 'content'), str_repeat('0', 64));
            self::fail('A hash that does not match the content must be refused');
        } catch (StorageException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(StorageException::class);
        $store->openRead('../../../etc/passwd');
    }
}
