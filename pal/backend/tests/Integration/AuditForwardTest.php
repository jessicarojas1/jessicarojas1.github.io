<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The SIEM forward (pal_audit channel) must be emitted only after the audit row commits, so an
 * event written inside a transaction that then rolls back is never forwarded.
 */
final class AuditForwardTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;
    private AuditLogger $audit;
    private TestHandler $siem;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->db = $container->get(Connection::class);
        $this->audit = $container->get(AuditLogger::class);
        $handler = $container->get('monolog.handler.pal_audit_test');
        self::assertInstanceOf(TestHandler::class, $handler);
        $this->siem = $handler;
        self::resetTables($this->db);
        $this->siem->clear();
    }

    public function testCommittedEventsAreForwarded(): void
    {
        $id = $this->audit->record(new AuditEntry('USER_SIGNED_IN', AuditActor::system('test'), 'app_user', 'subject-1'));

        self::assertTrue($this->forwarded($id), 'a committed event is forwarded to the SIEM');
    }

    public function testEventsFromARolledBackTransactionAreNotForwarded(): void
    {
        $this->db->beginTransaction();
        $id = $this->audit->record(new AuditEntry('USER_SIGNED_IN', AuditActor::system('test'), 'app_user', 'subject-2'));
        $this->db->rollBack();

        // The transaction rolled back; flushing (as the terminate subscriber does) must drop it.
        $this->audit->flushPendingForward();

        self::assertFalse($this->forwarded($id), 'a rolled-back event must not reach the SIEM');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM audit_event WHERE event_id = ?', [$id]));
    }

    private function forwarded(string $eventId): bool
    {
        foreach ($this->siem->getRecords() as $record) {
            if (($record->context['event_id'] ?? null) === $eventId) {
                return true;
            }
        }

        return false;
    }
}
