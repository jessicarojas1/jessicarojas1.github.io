<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Postgres-backed PHP session handler for horizontal scaling.
 *
 * AEGIS uses a connection per request and (by default) the local-file session
 * handler, which pins a user to one app instance. Behind a load balancer with
 * multiple instances that breaks — a request routed to another instance can't
 * see the session. This handler stores sessions in Postgres (already a hard
 * dependency), so any instance can serve any request: true horizontal scale.
 *
 * Opt-in via SESSION_DRIVER=pg; the default file handler is unchanged, so this
 * is inert until an operator running multiple instances enables it.
 *
 * Design notes:
 *  - Session payloads are binary-serialized and may contain non-UTF-8 bytes, so
 *    they are base64-encoded into a TEXT column (Postgres TEXT requires valid
 *    UTF-8). Avoids the PDO/bytea encoding pitfalls.
 *  - Concurrent requests for the SAME session id are serialized with a Postgres
 *    advisory lock taken in read() and released in close()/destroy(), matching
 *    the default file handler's locking (prevents lost writes / race conditions).
 *  - The php_sessions table is a system table: no tenant_id and no RLS, because
 *    the handler runs at session_start() BEFORE authentication binds a tenant.
 */
class PgSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private int $ttl;
    /** @var array<string,true> session ids whose advisory lock this request holds */
    private array $locked = [];

    public function __construct(?int $ttl = null)
    {
        $this->ttl = $ttl ?? (int) (ini_get('session.gc_maxlifetime') ?: 1440);
        if ($this->ttl < 60) {
            $this->ttl = 1440;
        }
    }

    public function open(string $path, string $name): bool
    {
        // Fail fast if the DB is unreachable; the table is provisioned by migration 030.
        Database::getInstance();
        return true;
    }

    public function close(): bool
    {
        foreach (array_keys($this->locked) as $id) {
            $this->unlock($id);
        }
        return true;
    }

    public function read(string $id): string
    {
        $this->lock($id);
        $row = Database::fetchOne(
            'SELECT data FROM php_sessions WHERE id = ? AND expires_at > NOW()',
            [$id]
        );
        if (!$row) {
            return '';
        }
        $raw = base64_decode((string) $row['data'], true);
        return $raw === false ? '' : $raw;
    }

    public function write(string $id, string $data): bool
    {
        Database::query(
            "INSERT INTO php_sessions (id, data, expires_at, updated_at)
                  VALUES (?, ?, NOW() + (?::int * INTERVAL '1 second'), NOW())
             ON CONFLICT (id) DO UPDATE
                  SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at, updated_at = NOW()",
            [$id, base64_encode($data), $this->ttl]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::query('DELETE FROM php_sessions WHERE id = ?', [$id]);
        $this->unlock($id);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Database::query('DELETE FROM php_sessions WHERE expires_at < NOW()')->rowCount();
    }

    // ── SessionUpdateTimestampHandlerInterface (lazy_write support) ──────────

    public function validateId(string $id): bool
    {
        return (bool) Database::fetchOne(
            'SELECT 1 AS x FROM php_sessions WHERE id = ? AND expires_at > NOW()',
            [$id]
        );
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        Database::query(
            "UPDATE php_sessions
                SET expires_at = NOW() + (?::int * INTERVAL '1 second'), updated_at = NOW()
              WHERE id = ?",
            [$this->ttl, $id]
        );
        return true;
    }

    // ── Advisory locking (per session id, same connection for lock + unlock) ──

    private function lock(string $id): void
    {
        if (isset($this->locked[$id])) {
            return;
        }
        Database::query('SELECT pg_advisory_lock(hashtext(?))', [$id]);
        $this->locked[$id] = true;
    }

    private function unlock(string $id): void
    {
        if (!isset($this->locked[$id])) {
            return;
        }
        Database::query('SELECT pg_advisory_unlock(hashtext(?))', [$id]);
        unset($this->locked[$id]);
    }
}
