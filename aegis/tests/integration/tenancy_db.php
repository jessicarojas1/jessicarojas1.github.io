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

// A second tenant must exist for the FK on risks.tenant_id to accept stamped rows.
Database::query(
    "INSERT INTO tenants (id, name, slug) VALUES (2, 'Probe Tenant', 'probe') ON CONFLICT (id) DO NOTHING"
);

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

// 5) Phase 3 read-path RLS on a REAL table (migration 028): setTenant() must
//    isolate reads on aegis.risks, and an unbound GUC must stay permissive
//    (inert single-tenant). Seed as superuser (bypasses RLS), assert as the
//    non-superuser runtime role (subject to RLS).
$tag = 'rls-readpath-probe';
$r1 = Database::insert('risks', ['title' => $tag, 'tenant_id' => 1]);
$r2 = Database::insert('risks', ['title' => $tag, 'tenant_id' => 2]);

Database::query("SET ROLE aegis_app");
Database::setTenant(2);
$seen2 = (int)(Database::fetchOne(
    "SELECT count(*) AS c FROM aegis.risks WHERE title = ?", [$tag])['c'] ?? -1);
if ($seen2 !== 1) { Database::query("RESET ROLE"); fail("setTenant(2) should see 1 probe row, saw {$seen2}"); }

Database::setTenant(1);
$seen1 = (int)(Database::fetchOne(
    "SELECT count(*) AS c FROM aegis.risks WHERE title = ?", [$tag])['c'] ?? -1);
if ($seen1 !== 1) { Database::query("RESET ROLE"); fail("setTenant(1) should see 1 probe row, saw {$seen1}"); }

// A same-tenant write must SUCCEED (proves the runtime role holds INSERT, so a
// blocked cross-tenant write below is the policy — not a missing grant). Bound to
// tenant 1, tenant_id defaults to 1 and satisfies WITH CHECK.
$okId = (int)(Database::query(
    "INSERT INTO aegis.risks (title) VALUES (?) RETURNING id", [$tag])->fetchColumn() ?: 0);
if ($okId <= 0) { Database::query("RESET ROLE"); fail('same-tenant insert as aegis_app did not succeed'); }

// Cross-tenant write must be blocked: bound to tenant 1, inserting tenant 2 fails
// the policy WITH CHECK.
$blocked = false;
try { Database::query("INSERT INTO aegis.risks (title, tenant_id) VALUES (?, 2)", [$tag]); }
catch (Throwable) { $blocked = true; }
if (!$blocked) { Database::query("RESET ROLE"); fail('WITH CHECK did not block a cross-tenant insert'); }

// Unbound GUC ⇒ permissive ⇒ all probe rows visible (inert single-tenant):
// r1 (tenant 1), r2 (tenant 2), and the same-tenant row just inserted = 3.
Database::clearTenant();
$seenAll = (int)(Database::fetchOne(
    "SELECT count(*) AS c FROM aegis.risks WHERE title = ?", [$tag])['c'] ?? -1);
Database::query("RESET ROLE");
if ($seenAll !== 3) fail("unbound GUC should be permissive (3 rows), saw {$seenAll}");

Database::query("DELETE FROM aegis.risks WHERE title = ?", [$tag]);

// 6) Policy coverage: every tenant-owned table that carries tenant_id must have
//    the tenant_isolation RLS policy (the MULTI_TENANCY hard rule).
$missing = Database::fetchAll(
    "SELECT c.table_name
       FROM information_schema.columns c
      WHERE c.table_schema = 'aegis' AND c.column_name = 'tenant_id'
        AND NOT EXISTS (
          SELECT 1 FROM pg_policies p
           WHERE p.schemaname = 'aegis' AND p.tablename = c.table_name
             AND p.policyname = 'tenant_isolation')
      ORDER BY c.table_name"
);
if ($missing) {
    $names = implode(', ', array_map(fn($r) => $r['table_name'], $missing));
    fail("tenant tables missing tenant_isolation policy: {$names}");
}

fwrite(STDOUT, "[tenancy_db] setTenant/currentTenant, RLS isolation, write-path stamping, read-path RLS, and policy coverage verified. OK\n");
exit(0);
