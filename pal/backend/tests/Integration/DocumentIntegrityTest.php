<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The database-level guarantees the document lifecycle relies on, tested directly
 * (independent of application code).
 */
final class DocumentIntegrityTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;
    private string $user;
    private string $document;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
        $this->user = self::createUser($this->db, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $dept = (string) $this->db->fetchOne("INSERT INTO org_unit (code, name) VALUES ('QA', 'Quality') RETURNING id");
        $type = (string) $this->db->fetchOne("SELECT id FROM document_type WHERE code = 'SOP'");
        $this->document = (string) $this->db->fetchOne(
            "INSERT INTO document (document_number, document_type_id, org_unit_id, owner_user_id, created_by) VALUES ('SOP-QA-0001', ?, ?, ?, ?) RETURNING id",
            [$type, $dept, $this->user, $this->user],
        );
        $this->db->executeStatement("INSERT INTO stored_file (sha256, size_bytes, detected_mime, scan_engine, scanned_at, storage_backend) VALUES (repeat('a', 64), 10, 'application/pdf', 'test', now(), 'fs')");
    }

    private function revision(string $state = 'DRAFT', int $major = 1, bool $confirmed = false): string
    {
        return (string) $this->db->fetchOne(
            'INSERT INTO revision (document_id, major, state, title, content_manifest_hash, created_by, label_confirmed) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$this->document, $major, $state, 'Title', 'DRAFT' === $state ? null : str_repeat('b', 64), $this->user, $confirmed ? 'true' : 'false'],
        );
    }

    /**
     * @param list<mixed> $params
     */
    private function assertBlocked(string $sql, array $params = []): void
    {
        try {
            $this->db->executeStatement($sql, $params);
            self::fail('Expected the database to refuse: '.$sql);
        } catch (DriverException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testApprovedRevisionsAreImmutable(): void
    {
        $rev = $this->revision('APPROVED', 1, true);

        $this->assertBlocked("UPDATE revision SET title = 'Edited' WHERE id = ?", [$rev]);
        $this->assertBlocked("UPDATE revision SET state = 'DRAFT' WHERE id = ?", [$rev]);
        $this->assertBlocked('UPDATE revision SET content_manifest_hash = repeat(\'c\', 64) WHERE id = ?', [$rev]);
    }

    public function testContentChangesOnlyWhileDraft(): void
    {
        $rev = $this->revision('IN_WORKFLOW');

        $this->assertBlocked("UPDATE revision SET title = 'Sneaky edit' WHERE id = ?", [$rev]);
        $this->db->executeStatement("UPDATE revision SET state = 'CHANGES_REQUESTED' WHERE id = ?", [$rev]);
        // The explicit reopen back to DRAFT may change content.
        $this->db->executeStatement("UPDATE revision SET state = 'DRAFT', title = 'Revised', content_manifest_hash = NULL WHERE id = ?", [$rev]);
        self::assertSame('Revised', $this->db->fetchOne('SELECT title FROM revision WHERE id = ?', [$rev]));
    }

    public function testFilesChangeOnlyWhileDraft(): void
    {
        $draft = $this->revision();
        $this->db->executeStatement("INSERT INTO revision_file (revision_id, sha256, role, original_filename) VALUES (?, repeat('a', 64), 'PRIMARY', 'a.pdf')", [$draft]);
        $this->db->executeStatement("UPDATE revision SET state = 'IN_WORKFLOW', content_manifest_hash = repeat('b', 64) WHERE id = ?", [$draft]);

        $this->assertBlocked("UPDATE revision_file SET original_filename = 'b.pdf' WHERE revision_id = ?", [$draft]);
        $this->assertBlocked('DELETE FROM revision_file WHERE revision_id = ?', [$draft]);
    }

    public function testOnlyOneOpenDraftPerDocument(): void
    {
        $this->revision();

        $this->assertBlocked("INSERT INTO revision (document_id, major, title, created_by) VALUES (?, 2, 'Second draft', ?)", [$this->document, $this->user]);
    }

    public function testOverlappingEffectivePublicationsAreImpossible(): void
    {
        $first = $this->revision('APPROVED', 1, true);
        $this->db->executeStatement(
            "INSERT INTO publication (document_id, revision_id, status, visible_period, effective_period, released_at) VALUES (?, ?, 'RELEASED', tstzrange(now() - interval '1 day', NULL), tstzrange(now() - interval '1 day', NULL), now())",
            [$this->document, $first],
        );
        $second = $this->revision('APPROVED', 2, true);

        // A second open-ended effective period for the same document overlaps the first.
        $this->assertBlocked(
            "INSERT INTO publication (document_id, revision_id, status, visible_period, effective_period, released_at) VALUES (?, ?, 'RELEASED', tstzrange(now(), NULL), tstzrange(now(), NULL), now())",
            [$this->document, $second],
        );

        // Closing the first period first (supersession) makes the second legal.
        $this->db->transactional(function (Connection $db) use ($first, $second): void {
            $db->executeStatement("UPDATE publication SET visible_period = tstzrange(lower(visible_period), now()), effective_period = tstzrange(lower(effective_period), now()) WHERE revision_id = ?", [$first]);
            $db->executeStatement(
                "INSERT INTO publication (document_id, revision_id, status, visible_period, effective_period, released_at) VALUES (?, ?, 'RELEASED', tstzrange(now(), NULL), tstzrange(now(), NULL), now())",
                [$this->document, $second],
            );
        });
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM publication WHERE document_id = ? AND effective_period @> now() AND status = 'RELEASED'", [$this->document]));
    }

    public function testSearchVectorIncludesTheDocumentNumberAndTitle(): void
    {
        $rev = $this->revision();
        $this->db->executeStatement("UPDATE revision SET title = 'Calibration of torque wrenches', keywords = '{metrology}' WHERE id = ?", [$rev]);

        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM revision WHERE id = ? AND search_vector @@ websearch_to_tsquery('english', 'torque metrology')", [$rev]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM revision WHERE id = ? AND search_vector @@ websearch_to_tsquery('simple', 'SOP-QA-0001')", [$rev]));
    }
}
