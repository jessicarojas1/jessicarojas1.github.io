<?php
declare(strict_types=1);

/**
 * Integration: control re-test cadence detection (Phase 10) against a live
 * Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the "latest test per objective" predicate (DISTINCT ON objective_id,
 *       newest control_tests.id, next_test_date < today) flags only controls
 *       whose MOST RECENT test is past its next_test_date — and ignores a
 *       control whose latest test is future-dated even though an OLDER test
 *       was overdue,
 *   (b) the owner alert joins control_implementations.assigned_to (the control
 *       owner), distinct from the remediation due_date used by overdue_controls,
 *   (c) deleting an objective cascades to its control_tests.
 *
 * Usage: php tests/integration/control_retest_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[control_retest_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[control_retest_db] ok: $m\n"; }

// Idempotency: clean prior test rows (children cascade from the package/objectives).
Database::query("DELETE FROM compliance_packages WHERE name = 'CRT Test Package'");
Database::query("DELETE FROM users WHERE email = 'crt-owner@test.local'");

// Owner of the controls.
$owner = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role) VALUES ('CRT Owner','crt-owner@test.local','x','viewer') RETURNING id")['id'] ?? 0);

// Package + two control objectives under it.
$pkg = (int) (Database::fetchOne("INSERT INTO compliance_packages (name) VALUES ('CRT Test Package') RETURNING id")['id'] ?? 0);
$obOver = (int) (Database::fetchOne("INSERT INTO compliance_objectives (package_id,code,title,level) VALUES (?, 'CRT-1','Overdue control',2) RETURNING id", [$pkg])['id'] ?? 0);
$obFresh = (int) (Database::fetchOne("INSERT INTO compliance_objectives (package_id,code,title,level) VALUES (?, 'CRT-2','Recently retested control',2) RETURNING id", [$pkg])['id'] ?? 0);
if (!$owner || !$pkg || !$obOver || !$obFresh) fail('could not seed package/objectives/owner');

// Both controls owned by $owner.
Database::insert('control_implementations', ['objective_id' => $obOver, 'status' => 'compliant', 'assigned_to' => $owner]);
Database::insert('control_implementations', ['objective_id' => $obFresh, 'status' => 'compliant', 'assigned_to' => $owner]);

// obOver: a single test whose next_test_date has passed -> OVERDUE.
Database::query("INSERT INTO control_tests (objective_id,package_id,result,test_date,next_test_date) VALUES (?,?,'pass', CURRENT_DATE - 200, CURRENT_DATE - 5)", [$obOver, $pkg]);

// obFresh: an OLD test that was overdue, then a NEWER test scheduled in the
// future. The latest-test predicate must treat this control as NOT overdue.
Database::query("INSERT INTO control_tests (objective_id,package_id,result,test_date,next_test_date) VALUES (?,?,'pass', CURRENT_DATE - 400, CURRENT_DATE - 100)", [$obFresh, $pkg]);
Database::query("INSERT INTO control_tests (objective_id,package_id,result,test_date,next_test_date) VALUES (?,?,'pass', CURRENT_DATE - 10,  CURRENT_DATE + 80)", [$obFresh, $pkg]);

// (a)+(b) latest-test overdue predicate, joined to the owner, selects exactly obOver.
$hits = Database::fetchAll(
    "SELECT co.code, u.email
       FROM control_implementations ci
       JOIN compliance_objectives co ON co.id = ci.objective_id
       JOIN users u ON u.id = ci.assigned_to
       JOIN LATERAL (
         SELECT ct.next_test_date
         FROM control_tests ct
         WHERE ct.objective_id = co.id AND ct.next_test_date IS NOT NULL
         ORDER BY ct.id DESC LIMIT 1
       ) latest ON TRUE
      WHERE latest.next_test_date < CURRENT_DATE
        AND co.package_id = ?",
    [$pkg]
);
if (count($hits) !== 1 || $hits[0]['code'] !== 'CRT-1' || $hits[0]['email'] !== 'crt-owner@test.local') {
    fail('latest-test re-test predicate selected ' . count($hits) . ' rows (expected just CRT-1 owned by crt-owner)');
}
ok('latest-test predicate flags only the control whose newest test is past due, joined to its owner');

// Sanity: the package-level rollup counts exactly one overdue, zero due-soon.
$stats = Database::fetchOne(
    "SELECT
       COUNT(*) FILTER (WHERE latest.next_test_date < CURRENT_DATE) AS overdue,
       COUNT(*) FILTER (WHERE latest.next_test_date >= CURRENT_DATE
                          AND latest.next_test_date < CURRENT_DATE + INTERVAL '30 days') AS due_soon
     FROM (
       SELECT DISTINCT ON (ct.objective_id) ct.objective_id, ct.next_test_date
       FROM control_tests ct
       JOIN compliance_objectives co ON co.id = ct.objective_id
       WHERE co.package_id = ? AND ct.next_test_date IS NOT NULL
       ORDER BY ct.objective_id, ct.id DESC
     ) latest",
    [$pkg]
);
if ((int) $stats['overdue'] !== 1 || (int) $stats['due_soon'] !== 0) {
    fail("package rollup wrong (overdue={$stats['overdue']}, due_soon={$stats['due_soon']}; expected 1/0)");
}
ok('package rollup counts one overdue, zero due-soon');

// (c) deleting an objective cascades to its control_tests.
Database::query("DELETE FROM compliance_objectives WHERE id = ?", [$obFresh]);
$afterDel = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM control_tests WHERE objective_id = ?", [$obFresh])['c'] ?? -1);
if ($afterDel !== 0) fail("deleting objective did not cascade ({$afterDel} tests remain)");
ok('deleting an objective cascades to its control_tests');

// cleanup (package delete cascades to remaining objectives + tests + impls).
Database::query("DELETE FROM compliance_packages WHERE id = ?", [$pkg]);
Database::query("DELETE FROM users WHERE id = ?", [$owner]);

echo "[control_retest_db] PASS\n";
