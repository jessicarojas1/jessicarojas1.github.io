<?php
declare(strict_types=1);

/**
 * Integration: prove the Postgres session handler (SESSION_DRIVER=pg) stores and
 * retrieves sessions against a live Postgres — the mechanism horizontal scaling
 * depends on. Exercises binary-safe payloads, expiry/gc, destroy, and the
 * SessionUpdateTimestampHandlerInterface methods. Requires DATABASE_URL in env.
 *
 * Usage: php tests/integration/session_db.php
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/PgSessionHandler.php';

function fail(string $m): never { fwrite(STDERR, "[session_db] FAIL: $m\n"); exit(1); }

$h  = new PgSessionHandler(3600);
$id = 'itest_' . bin2hex(random_bytes(8));

if (!$h->open('', 'AEGIS')) fail('open() returned false');

// 1) A brand-new id reads as empty.
if ($h->read($id) !== '') fail('unknown session id should read as empty');

// 2) Round-trip a payload containing non-UTF-8 bytes (real PHP session blobs are
//    binary). base64 in a TEXT column must preserve it exactly.
$payload = "user|i:2;flag|b:1;raw|s:4:\"\x00\x01\xfe\xff\";";
if (!$h->write($id, $payload)) fail('write() returned false');
$back = $h->read($id);
if ($back !== $payload) fail('round-trip mismatch: data was not preserved byte-for-byte');

// 3) validateId reflects existence.
if (!$h->validateId($id))            fail('validateId() should be true for a live session');
if ($h->validateId($id . 'nope'))    fail('validateId() should be false for a missing session');

// 4) updateTimestamp keeps the row alive and does not corrupt data.
if (!$h->updateTimestamp($id, $payload)) fail('updateTimestamp() returned false');
if ($h->read($id) !== $payload)          fail('data changed after updateTimestamp()');

// 5) An overwrite replaces the payload.
$payload2 = 'user|i:9;';
$h->write($id, $payload2);
if ($h->read($id) !== $payload2) fail('overwrite did not take effect');

// 6) destroy() removes it.
$h->destroy($id);
if ($h->read($id) !== '') fail('session still readable after destroy()');

// 7) gc() deletes expired rows but leaves live ones. Seed one already-expired and
//    one live row directly, then collect garbage.
$expired = 'itest_exp_' . bin2hex(random_bytes(6));
$live    = 'itest_live_' . bin2hex(random_bytes(6));
Database::query(
    "INSERT INTO php_sessions (id, data, expires_at) VALUES (?, '', NOW() - INTERVAL '1 hour')", [$expired]);
Database::query(
    "INSERT INTO php_sessions (id, data, expires_at) VALUES (?, '', NOW() + INTERVAL '1 hour')", [$live]);
$deleted = $h->gc(3600);
if ($deleted < 1) fail("gc() should have deleted at least the expired row, deleted {$deleted}");
if (Database::fetchOne("SELECT 1 AS x FROM php_sessions WHERE id = ?", [$expired])) fail('expired row survived gc()');
if (!Database::fetchOne("SELECT 1 AS x FROM php_sessions WHERE id = ?", [$live]))   fail('gc() deleted a live row');
Database::query("DELETE FROM php_sessions WHERE id = ?", [$live]);

if (!$h->close()) fail('close() returned false');

fwrite(STDOUT, "[session_db] write/read (binary-safe), validateId, updateTimestamp, destroy, and gc verified. OK\n");
exit(0);
