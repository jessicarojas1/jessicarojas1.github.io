<?php
declare(strict_types=1);

/**
 * Integration: Database::update() timestamp handling against a live Postgres.
 *
 * Regression guard for the CRITICAL bug where Database::update() unconditionally
 * appended ", updated_at = NOW()" while some callers ALSO passed 'updated_at' in
 * the data array — Postgres then rejected the statement with
 *   ERROR: multiple assignments to same column "updated_at"
 * which broke those UPDATE paths at runtime. update() is now defensive: it skips
 * the auto-append when the caller already supplied updated_at.
 *
 * Usage: php tests/integration/database_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[database_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[database_db] ok: $m\n"; }

// Connection-scoped throwaway table with an updated_at column (no pollution).
Database::query("CREATE TEMP TABLE itest_upd (id serial PRIMARY KEY, name text, updated_at timestamptz DEFAULT now() - interval '1 day')");
Database::query("INSERT INTO itest_upd (name) VALUES ('seed')");
$id = (int) Database::fetchOne("SELECT id FROM itest_upd ORDER BY id LIMIT 1")['id'];

// 1) update() WITHOUT updated_at: changes the column AND auto-stamps updated_at.
$old = Database::fetchOne("SELECT updated_at FROM itest_upd WHERE id = ?", [$id])['updated_at'];
$n = Database::update('itest_upd', ['name' => 'first'], 'id = ?', [$id]);
if ($n !== 1) fail("expected 1 row updated, got {$n}");
$row = Database::fetchOne("SELECT name, updated_at FROM itest_upd WHERE id = ?", [$id]);
if ($row['name'] !== 'first') fail('name was not updated');
if ($row['updated_at'] === $old || empty($row['updated_at'])) fail('updated_at was not auto-stamped');
ok('update() without updated_at: updates column and auto-stamps the timestamp');

// 2) update() WITH updated_at in the data array: must NOT raise the duplicate
//    "multiple assignments to same column" error (the regression), and must
//    honour the caller-supplied value.
$explicit = '2020-01-02 03:04:05+00';
try {
    $n = Database::update('itest_upd', ['name' => 'second', 'updated_at' => $explicit], 'id = ?', [$id]);
} catch (Throwable $e) {
    fail('update() with updated_at in array threw (regression!): ' . $e->getMessage());
}
if ($n !== 1) fail("expected 1 row updated with explicit updated_at, got {$n}");
$row = Database::fetchOne("SELECT name, to_char(updated_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') AS ts FROM itest_upd WHERE id = ?", [$id]);
if ($row['name'] !== 'second') fail('name was not updated when updated_at supplied');
if ($row['ts'] !== '2020-01-02 03:04:05') fail("caller-supplied updated_at not honoured (got {$row['ts']})");
ok('update() with updated_at in array: no duplicate-column error; caller value honoured');

echo "[database_db] PASS\n";
