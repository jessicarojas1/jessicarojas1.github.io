<?php
declare(strict_types=1);

/**
 * Integration: awareness training-overdue detection (Phase 9) against a live
 * Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the training-overdue predicate (assignment.completed = FALSE AND
 *       program.due_date < today) selects only the incomplete, past-due
 *       assignment and ignores completed / future-due ones,
 *   (b) deleting a program cascades to its assignments,
 *   (c) deleting a user cascades to their assignments.
 *
 * Usage: php tests/integration/awareness_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[awareness_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[awareness_db] ok: $m\n"; }

// Idempotency: clean prior test rows.
Database::query("DELETE FROM awareness_programs WHERE title IN ('AW Overdue Prog','AW Future Prog')");
Database::query("DELETE FROM users WHERE email IN ('aw-u1@test.local','aw-u2@test.local')");

// Two assignees.
$u1 = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role) VALUES ('AW User1','aw-u1@test.local','x','viewer') RETURNING id")['id'] ?? 0);
$u2 = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role) VALUES ('AW User2','aw-u2@test.local','x','viewer') RETURNING id")['id'] ?? 0);

// Overdue program (due in the past) + a future program.
$pOver = (int) (Database::fetchOne("INSERT INTO awareness_programs (title,status,due_date) VALUES ('AW Overdue Prog','active', CURRENT_DATE - 5) RETURNING id")['id'] ?? 0);
$pFut  = (int) (Database::fetchOne("INSERT INTO awareness_programs (title,status,due_date) VALUES ('AW Future Prog','active', CURRENT_DATE + 5) RETURNING id")['id'] ?? 0);
if (!$u1 || !$u2 || !$pOver || !$pFut) fail('could not seed users/programs');

// Assignments: u1 incomplete on overdue prog (OVERDUE), u2 completed on overdue
// prog (not overdue), u1 incomplete on future prog (not overdue).
Database::insert('awareness_assignments', ['program_id' => $pOver, 'user_id' => $u1]);
Database::query("INSERT INTO awareness_assignments (program_id,user_id,completed,completed_at) VALUES (?,?,TRUE,NOW())", [$pOver, $u2]);
Database::insert('awareness_assignments', ['program_id' => $pFut, 'user_id' => $u1]);

// (a) overdue predicate selects exactly u1's assignment on the overdue program.
$hits = Database::fetchAll(
    "SELECT u.email
       FROM awareness_assignments aa
       JOIN awareness_programs ap ON ap.id = aa.program_id
       JOIN users u ON u.id = aa.user_id
      WHERE aa.completed = FALSE AND ap.due_date IS NOT NULL AND ap.due_date < CURRENT_DATE
        AND u.email IN ('aw-u1@test.local','aw-u2@test.local')",
    []
);
if (count($hits) !== 1 || $hits[0]['email'] !== 'aw-u1@test.local') {
    fail('training-overdue predicate selected ' . count($hits) . ' rows (expected just aw-u1)');
}
ok('training-overdue predicate selects only the incomplete, past-due assignment');

// (b) deleting a program cascades to its assignments.
Database::query("DELETE FROM awareness_programs WHERE id = ?", [$pOver]);
$afterProg = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM awareness_assignments WHERE program_id = ?", [$pOver])['c'] ?? -1);
if ($afterProg !== 0) fail("deleting program did not cascade ({$afterProg} assignments remain)");
ok('deleting a program cascades to its assignments');

// (c) deleting a user cascades to their assignments.
Database::query("DELETE FROM users WHERE id = ?", [$u1]);
$afterUser = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM awareness_assignments WHERE user_id = ?", [$u1])['c'] ?? -1);
if ($afterUser !== 0) fail("deleting user did not cascade ({$afterUser} assignments remain)");
ok('deleting a user cascades to their assignments');

// cleanup
Database::query("DELETE FROM awareness_programs WHERE id = ?", [$pFut]);
Database::query("DELETE FROM users WHERE id = ?", [$u2]);

echo "[awareness_db] PASS\n";
