<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Audit\AuditChainVerifier;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The single hash chain depends on one guarantee: a writer reads the latest committed hash and
 * appends under a PostgreSQL advisory lock held for its whole transaction, so no second writer
 * can read the same predecessor and fork the chain. These tests prove that guarantee against a
 * real PostgreSQL, rather than only reasoning about it.
 */
final class AuditConcurrencyTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
    }

    public function testTheChainLockIsExclusiveAndHeldUntilCommit(): void
    {
        $other = DriverManager::getConnection($this->db->getParams());
        try {
            $this->db->beginTransaction();
            $this->db->executeStatement('SELECT pg_advisory_xact_lock(?)', [AuditLogger::CHAIN_LOCK_KEY]);

            // A second session cannot enter the chain's critical section while the first holds it,
            // so a concurrent writer blocks instead of reading a stale latest-hash and forking.
            self::assertFalse(
                (bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [AuditLogger::CHAIN_LOCK_KEY]),
                'a concurrent writer cannot acquire the chain lock while another transaction holds it',
            );

            $this->db->commit();

            // The lock is a transaction-scoped (xact) lock: it releases on commit, not before.
            self::assertTrue(
                (bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [AuditLogger::CHAIN_LOCK_KEY]),
                'the lock is released when the holding transaction commits',
            );
        } finally {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $other->close();
        }
    }

    public function testManySequentialWritesProduceOneUnbrokenChainWithNoFork(): void
    {
        $actor = AuditActor::system('concurrency-test');
        $logger = self::getContainer()->get(AuditLogger::class);
        $count = 50;
        for ($i = 0; $i < $count; ++$i) {
            $logger->record(new AuditEntry('TEST_EVENT', $actor, 'n', (string) $i));
        }

        $report = self::getContainer()->get(AuditChainVerifier::class)->verify();
        self::assertTrue($report->valid, (string) $report->failure);
        self::assertSame($count, $report->eventsChecked);

        // No two events chain onto the same predecessor — the exact corruption a lost race would
        // cause. Distinct prev_hash (and hash) equal to the row count proves a single linear chain.
        self::assertSame($count, (int) $this->db->fetchOne('SELECT count(DISTINCT prev_hash) FROM audit_event'));
        self::assertSame($count, (int) $this->db->fetchOne('SELECT count(DISTINCT hash) FROM audit_event'));
    }
}
