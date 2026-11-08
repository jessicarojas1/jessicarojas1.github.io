<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * The production session store (PdoSessionHandler against PostgreSQL) exercised directly — the
 * HTTP tests use the mock file store, so read/write, destroy and idle garbage collection were
 * otherwise unproven against the real `sessions` table.
 */
final class SessionHandlerTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;
    private PdoSessionHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
        // Build the handler exactly as config/services.yaml does (DATABASE_URL + the sessions table,
        // advisory lock), so the real production store — including its DSN parsing — is exercised.
        $url = (string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL'));
        $this->handler = new PdoSessionHandler($url, ['db_table' => 'sessions', 'lock_mode' => PdoSessionHandler::LOCK_ADVISORY]);
    }

    private function rows(): int
    {
        return (int) $this->db->fetchOne('SELECT count(*) FROM sessions');
    }

    public function testWriteThenReadRoundTripsThroughPostgres(): void
    {
        $this->handler->open('', 'PHPSESSID');
        self::assertSame('', $this->handler->read('sid-1'), 'a new session reads empty');
        self::assertTrue($this->handler->write('sid-1', 'the-payload'));
        $this->handler->close();

        $this->handler->open('', 'PHPSESSID');
        self::assertSame('the-payload', $this->handler->read('sid-1'));
        $this->handler->close();

        self::assertSame(1, $this->rows());
    }

    public function testDestroyRemovesTheSession(): void
    {
        $this->handler->open('', 'PHPSESSID');
        $this->handler->read('sid-2');
        $this->handler->write('sid-2', 'data');
        self::assertTrue($this->handler->destroy('sid-2'));
        $this->handler->close();

        self::assertSame(0, $this->rows());
    }

    public function testGarbageCollectionRemovesIdleSessionsButKeepsFreshOnes(): void
    {
        foreach (['stale', 'fresh'] as $id) {
            $this->handler->open('', 'PHPSESSID');
            $this->handler->read('sid-'.$id);
            $this->handler->write('sid-'.$id, $id);
            $this->handler->close();
        }
        // sess_lifetime holds the absolute expiry timestamp; set the stale one into the past so it
        // is idle (gc deletes rows whose sess_lifetime < now).
        $this->db->executeStatement("UPDATE sessions SET sess_lifetime = extract(epoch from now())::int - 100 WHERE sess_id = ?", ['sid-stale']);

        // PdoSessionHandler defers the GC delete to close() (to avoid deadlocks), so run the cycle.
        $this->handler->open('', 'PHPSESSID');
        $this->handler->gc(1);
        $this->handler->close();

        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM sessions WHERE sess_id = ?', ['sid-stale']), 'the idle session is collected');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM sessions WHERE sess_id = ?', ['sid-fresh']), 'the fresh session survives');
    }
}
