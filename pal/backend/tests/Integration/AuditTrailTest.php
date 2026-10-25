<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Audit\AuditChainVerifier;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditTrailTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
    }

    private function logger(): AuditLogger
    {
        return self::getContainer()->get(AuditLogger::class);
    }

    private function verifier(): AuditChainVerifier
    {
        return self::getContainer()->get(AuditChainVerifier::class);
    }

    private function recordThree(): void
    {
        $actor = AuditActor::system('test');
        $this->logger()->record(new AuditEntry('TEST_ONE', $actor, changes: ['b' => 'x', 'a' => ['n' => 1, 'flag' => true]]));
        $this->logger()->record(new AuditEntry('TEST_TWO', $actor, 'thing', '42', reason: 'Ünïcode reason / with slashes'));
        $this->logger()->record(new AuditEntry('TEST_THREE', $actor, fromState: 'DRAFT', toState: 'IN_WORKFLOW'));
    }

    public function testEventsChainAndVerifyAfterARoundTripThroughPostgres(): void
    {
        $this->recordThree();

        $report = $this->verifier()->verify();

        self::assertTrue($report->valid, (string) $report->failure);
        self::assertSame(3, $report->eventsChecked);
        $first = $this->db->fetchAssociative('SELECT prev_hash FROM audit_event ORDER BY id LIMIT 1');
        self::assertSame(str_repeat('0', 64), $first['prev_hash']);
    }

    public function testUpdateDeleteAndTruncateAreBlocked(): void
    {
        $this->recordThree();

        foreach ([
            "UPDATE audit_event SET reason = 'edited'",
            'DELETE FROM audit_event',
            'TRUNCATE audit_event',
        ] as $sql) {
            try {
                $this->db->executeStatement($sql);
                self::fail('Expected the append-only guard to block: '.$sql);
            } catch (DriverException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
        self::assertSame(3, (int) $this->db->fetchOne('SELECT count(*) FROM audit_event'));
    }

    public function testTamperingOutsideTheAppIsDetected(): void
    {
        $this->recordThree();
        // Simulate a privileged actor bypassing the guard and editing history.
        $this->db->executeStatement('ALTER TABLE audit_event DISABLE TRIGGER trg_audit_event_no_update_delete');
        $this->db->executeStatement("UPDATE audit_event SET reason = 'rewritten' WHERE event_type = 'TEST_TWO'");
        $this->db->executeStatement('ALTER TABLE audit_event ENABLE TRIGGER trg_audit_event_no_update_delete');

        $report = $this->verifier()->verify();

        self::assertFalse($report->valid);
        self::assertSame(2, $report->firstBrokenEventId);
    }

    public function testDeletionOutsideTheAppIsDetected(): void
    {
        $this->recordThree();
        $this->db->executeStatement('ALTER TABLE audit_event DISABLE TRIGGER trg_audit_event_no_update_delete');
        $this->db->executeStatement("DELETE FROM audit_event WHERE event_type = 'TEST_TWO'");
        $this->db->executeStatement('ALTER TABLE audit_event ENABLE TRIGGER trg_audit_event_no_update_delete');

        $report = $this->verifier()->verify();

        self::assertFalse($report->valid);
        self::assertSame(3, $report->firstBrokenEventId);
    }

    public function testAnAuditRecordRollsBackWithItsTransaction(): void
    {
        try {
            $this->db->transactional(function (): void {
                $this->logger()->record(new AuditEntry('TEST_ROLLED_BACK', AuditActor::system('test')));
                throw new \RuntimeException('business change failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_event'));
    }
}
