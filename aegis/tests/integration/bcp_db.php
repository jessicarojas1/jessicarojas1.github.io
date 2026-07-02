<?php
declare(strict_types=1);

/**
 * Integration: BCP lifecycle overdue detection (Phase 7) against a live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the overdue-exercise predicate (scheduled_date < today, conducted_date
 *       NULL) selects a stale exercise and ignores conducted/future ones,
 *   (b) the plan-testing-due predicate (next_test_date <= today+30) selects an
 *       overdue/soon plan,
 *   (c) the index overdue_exercise_count subquery counts correctly,
 *   (d) deleting a plan cascades to its exercises.
 *
 * Usage: php tests/integration/bcp_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[bcp_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[bcp_db] ok: $m\n"; }

// Idempotency: clean prior test rows.
Database::query("DELETE FROM bcp_plans WHERE title = 'BCP Phase7 Test Plan'");

// Seed a plan whose next_test_date is overdue.
$planId = (int) (Database::fetchOne(
    "INSERT INTO bcp_plans (title, status, next_test_date)
     VALUES ('BCP Phase7 Test Plan', 'active', CURRENT_DATE - 5)
     RETURNING id"
)['id'] ?? 0);
if ($planId <= 0) fail('could not seed bcp plan');

// Three exercises: one overdue (past, not conducted), one conducted (past but done),
// one future (not yet due).
Database::insert('bcp_exercises', ['plan_id' => $planId, 'exercise_type' => 'tabletop', 'name' => 'Overdue ex',   'scheduled_date' => date('Y-m-d', strtotime('-10 days'))]);
Database::insert('bcp_exercises', ['plan_id' => $planId, 'exercise_type' => 'tabletop', 'name' => 'Conducted ex', 'scheduled_date' => date('Y-m-d', strtotime('-10 days')), 'conducted_date' => date('Y-m-d', strtotime('-8 days')), 'outcome' => 'passed']);
Database::insert('bcp_exercises', ['plan_id' => $planId, 'exercise_type' => 'tabletop', 'name' => 'Future ex',    'scheduled_date' => date('Y-m-d', strtotime('+10 days'))]);

// (a) overdue predicate selects exactly the one overdue exercise.
$overdue = Database::fetchAll(
    "SELECT name FROM bcp_exercises
      WHERE plan_id = ? AND conducted_date IS NULL AND scheduled_date < CURRENT_DATE",
    [$planId]
);
if (count($overdue) !== 1 || $overdue[0]['name'] !== 'Overdue ex') {
    fail('overdue predicate selected ' . count($overdue) . ' rows (expected just "Overdue ex")');
}
ok('overdue-exercise predicate selects only the stale, unconducted exercise');

// (b) plan-testing-due predicate selects the plan.
$due = Database::fetchOne(
    "SELECT bp.id FROM bcp_plans bp
      WHERE bp.id = ? AND bp.status = 'active' AND bp.next_test_date IS NOT NULL
        AND bp.next_test_date <= CURRENT_DATE + INTERVAL '30 days'",
    [$planId]
);
if (!$due) fail('plan-testing-due predicate did not select the overdue plan');
ok('plan-testing-due predicate selects an overdue/soon active plan');

// (c) the index overdue_exercise_count subquery counts correctly.
$cnt = (int) (Database::fetchOne(
    "SELECT (SELECT COUNT(*) FROM bcp_exercises be WHERE be.plan_id = bp.id
              AND be.conducted_date IS NULL AND be.scheduled_date < CURRENT_DATE) AS c
       FROM bcp_plans bp WHERE bp.id = ?",
    [$planId]
)['c'] ?? -1);
if ($cnt !== 1) fail("overdue_exercise_count subquery returned {$cnt} (expected 1)");
ok('index overdue_exercise_count subquery counts correctly');

// (d) deleting the plan cascades to its exercises.
Database::query("DELETE FROM bcp_plans WHERE id = ?", [$planId]);
$after = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM bcp_exercises WHERE plan_id = ?", [$planId])['c'] ?? -1);
if ($after !== 0) fail("deleting plan did not cascade to exercises ({$after} remain)");
ok('deleting a BCP plan cascades to its exercises');

echo "[bcp_db] PASS\n";
