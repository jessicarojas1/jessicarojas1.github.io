<?php
declare(strict_types=1);

/**
 * Integration: prove Database::setTenant()/currentTenant() drive the
 * `aegis.tenant_id` GUC against a live Postgres, AND that the binding actually
 * enforces Row-Level Security on a representative table (the mechanism the
 * per-table tenancy rollout depends on). Requires DATABASE_URL in env.
 *
 * Usage: php tests/integration/tenancy_db.php
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[tenancy_db] FAIL: $m\n"); exit(1); }

// 1) GUC round-trip via the helpers.
Database::setTenant(7);
if (Database::currentTenant() !== 7) fail('currentTenant() != 7 after setTenant(7)');
Database::clearTenant();
if (Database::currentTenant() !== null) fail('currentTenant() not null after clearTenant()');

// 2) The default tenant exists (migration 026).
$row = Database::fetchOne("SELECT id, slug FROM tenants WHERE id = 1");
if (!$row || $row['slug'] !== 'default') fail('default tenant (id 1) missing');

// 3) End-to-end RLS enforcement driven by the helper.
Database::query("DROP TABLE IF EXISTS aegis.tenancy_probe");
Database::query("CREATE TABLE aegis.tenancy_probe (id SERIAL PRIMARY KEY, tenant_id BIGINT NOT NULL, v TEXT)");
Database::query("ALTER TABLE aegis.tenancy_probe ENABLE ROW LEVEL SECURITY");
Database::query("ALTER TABLE aegis.tenancy_probe FORCE ROW LEVEL SECURITY");
Database::query(
    "CREATE POLICY tenant_isolation ON aegis.tenancy_probe
       USING (tenant_id = current_setting('aegis.tenant_id', true)::bigint)
       WITH CHECK (tenant_id = current_setting('aegis.tenant_id', true)::bigint)"
);
Database::query("GRANT SELECT, INSERT ON aegis.tenancy_probe TO aegis_app");
Database::query("GRANT USAGE, SELECT ON SEQUENCE aegis.tenancy_probe_id_seq TO aegis_app");
// Seed both tenants as owner (superuser bypasses RLS for setup).
Database::query("INSERT INTO aegis.tenancy_probe (tenant_id, v) VALUES (1,'a'),(1,'b'),(2,'c')");

// Act as the non-superuser runtime role so RLS is enforced, and drive the tenant
// binding through the application helper.
Database::query("SET ROLE aegis_app");
Database::setTenant(2);
$c = (int)(Database::fetchOne("SELECT count(*) AS c FROM aegis.tenancy_probe")['c'] ?? -1);
if ($c !== 1) fail("setTenant(2) should isolate to 1 row, saw {$c}");
Database::setTenant(1);
$c = (int)(Database::fetchOne("SELECT count(*) AS c FROM aegis.tenancy_probe")['c'] ?? -1);
if ($c !== 2) fail("setTenant(1) should isolate to 2 rows, saw {$c}");
Database::query("RESET ROLE");
Database::query("DROP TABLE aegis.tenancy_probe");

// 4) Migration 027 added tenant_id (default 1) to primary tables, and
//    Database::insert() auto-stamps it from the tenant context.
$col = Database::fetchOne(
    "SELECT column_default FROM information_schema.columns
      WHERE table_schema='aegis' AND table_name='risks' AND column_name='tenant_id'"
);
if (!$col) fail('risks.tenant_id column missing (migration 027)');

Database::useTenant(2);
$id = Database::insert('risks', ['title' => 'tenancy stamp probe']);
$got = (int)(Database::fetchOne("SELECT tenant_id FROM aegis.risks WHERE id = ?", [$id])['tenant_id'] ?? 0);
Database::query("DELETE FROM aegis.risks WHERE id = ?", [$id]);
Database::useTenant(null);
if ($got !== 2) fail("insert() did not stamp tenant_id=2 (got {$got})");

// With no context, the column DEFAULT (1) applies.
$id2 = Database::insert('risks', ['title' => 'tenancy default probe']);
$got2 = (int)(Database::fetchOne("SELECT tenant_id FROM aegis.risks WHERE id = ?", [$id2])['tenant_id'] ?? 0);
Database::query("DELETE FROM aegis.risks WHERE id = ?", [$id2]);
if ($got2 !== 1) fail("default tenant_id should be 1 (got {$got2})");

fwrite(STDOUT, "[tenancy_db] setTenant/currentTenant, RLS isolation, and write-path stamping verified. OK\n");
exit(0);
