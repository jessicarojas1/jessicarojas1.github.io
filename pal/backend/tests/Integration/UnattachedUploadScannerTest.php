<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Storage\UnattachedUploadScanner;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The never-attached-upload report: a stored_file is reclaimable only when a clean upload
 * produced it and no revision references it. Attached files, and stored_file rows with no
 * upload behind them, are excluded. Read-only — the scanner changes nothing.
 */
final class UnattachedUploadScannerTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;
    private UnattachedUploadScanner $scanner;
    private string $user;
    private string $document;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->scanner = self::getContainer()->get(UnattachedUploadScanner::class);
        self::resetTables($this->db);
        $this->user = self::createUser($this->db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $dept = (string) $this->db->fetchOne("INSERT INTO org_unit (code, name) VALUES ('QA', 'Quality') RETURNING id");
        $type = (string) $this->db->fetchOne("SELECT id FROM document_type WHERE code = 'SOP'");
        $this->document = (string) $this->db->fetchOne(
            "INSERT INTO document (document_number, document_type_id, org_unit_id, owner_user_id, created_by) VALUES ('SOP-QA-0001', ?, ?, ?, ?) RETURNING id",
            [$type, $dept, $this->user, $this->user],
        );
    }

    private function storedFile(string $sha, int $size, string $createdAt = 'now()'): void
    {
        $this->db->executeStatement(
            "INSERT INTO stored_file (sha256, size_bytes, detected_mime, scan_engine, scanned_at, storage_backend, created_at) VALUES (?, ?, 'application/pdf', 'test', now(), 'fs', $createdAt)",
            [$sha, $size],
        );
    }

    private function cleanUpload(string $sha, int $size): void
    {
        $this->db->executeStatement(
            "INSERT INTO upload (uploaded_by, original_filename, size_bytes, sha256, detected_mime, status, stored_sha256) VALUES (?, 'f.pdf', ?, ?, 'application/pdf', 'CLEAN', ?)",
            [$this->user, $size, $sha, $sha],
        );
    }

    private function attachToDraft(string $sha): void
    {
        $draft = (string) $this->db->fetchOne(
            "INSERT INTO revision (document_id, major, state, title, created_by) VALUES (?, 1, 'DRAFT', 'Title', ?) RETURNING id",
            [$this->document, $this->user],
        );
        $this->db->executeStatement(
            "INSERT INTO revision_file (revision_id, sha256, role, original_filename) VALUES (?, ?, 'PRIMARY', 'f.pdf')",
            [$draft, $sha],
        );
    }

    public function testOnlyNeverAttachedUploadBlobsAreReported(): void
    {
        $attached = str_repeat('a', 64);
        $this->storedFile($attached, 10);
        $this->cleanUpload($attached, 10);
        $this->attachToDraft($attached); // referenced by a revision → not reclaimable

        $orphan = str_repeat('b', 64);
        $this->storedFile($orphan, 2048);
        $this->cleanUpload($orphan, 2048); // uploaded, never attached → reclaimable

        $noUpload = str_repeat('c', 64);
        $this->storedFile($noUpload, 999); // a stored_file with no upload behind it → excluded

        $report = $this->scanner->scan();

        self::assertSame(1, $report->fileCount);
        self::assertSame(2048, $report->totalBytes);
        self::assertCount(1, $report->sample);
        self::assertSame($orphan, $report->sample[0]['sha256']);
        self::assertSame(1, $report->sample[0]['uploadCount']);
    }

    public function testTheSameContentUploadedTwiceButAttachedIsNotReclaimable(): void
    {
        // Content-addressed: one blob, two upload rows, attached once. It must be kept.
        $sha = str_repeat('d', 64);
        $this->storedFile($sha, 500);
        $this->cleanUpload($sha, 500);
        $this->cleanUpload($sha, 500);
        $this->attachToDraft($sha);

        self::assertSame(0, $this->scanner->scan()->fileCount);
    }

    public function testAgeFilterExcludesRecentUploads(): void
    {
        $sha = str_repeat('e', 64);
        $this->storedFile($sha, 4096, "now() - interval '10 days'");
        $this->cleanUpload($sha, 4096);

        self::assertSame(1, $this->scanner->scan(7 * 86400)->fileCount, 'older than 7 days → counted');
        self::assertSame(0, $this->scanner->scan(30 * 86400)->fileCount, 'older than 30 days → not yet');
        self::assertSame(1, $this->scanner->scan(0)->fileCount, 'no age filter → counted');
    }

    public function testCommandIsReadOnlyAndSignalsOverTheAlertThreshold(): void
    {
        $sha = str_repeat('f', 64);
        $this->storedFile($sha, 4096);
        $this->cleanUpload($sha, 4096);

        $tester = new CommandTester((new Application(self::$kernel))->find('pal:storage:unattached'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--fail-over-bytes' => '8192']), 'below the threshold → success');
        self::assertSame(Command::FAILURE, $tester->execute(['--fail-over-bytes' => '1024']), 'at/over the threshold → non-zero for alerting');
        self::assertStringContainsString('4.0 KiB', $tester->getDisplay());

        // Read-only: the stored_file and upload rows are untouched.
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM stored_file WHERE sha256 = ?', [$sha]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM upload WHERE stored_sha256 = ?', [$sha]));
    }
}
