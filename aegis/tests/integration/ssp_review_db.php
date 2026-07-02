<?php
declare(strict_types=1);

/**
 * Integration: SSP review-overdue detection (Phase 18) against a live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the overdue predicate (next_review_date < today) joined to the owner
 *       (ssp_plans.created_by) selects only the past-due plan — ignoring
 *       future-dated and no-review-date plans,
 *   (b) plans owned by an inactive user are excluded,
 *   (c) deleting an SSP plan cascades to its control statements.
 *
 * Usage: php tests/integration/ssp_review_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[ssp_review_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[ssp_review_db] ok: $m\n"; }

// Idempotent cleanup (control statements cascade from the plan).
Database::query("DELETE FROM ssp_plans WHERE title LIKE 'SSPR %'");
Database::query("DELETE FROM compliance_objectives WHERE code = 'SSPR-OBJ'");
Database::query("DELETE FROM users WHERE email IN ('sspr-owner@test.local','sspr-inactive@test.local')");

$owner    = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('SSPR Owner','sspr-owner@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$inactive = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('SSPR Inactive','sspr-inactive@test.local','x','viewer',FALSE) RETURNING id")['id'] ?? 0);
if (!$owner || !$inactive) fail('could not seed owner/inactive users');

$mk = function (string $title, string $reviewExpr, ?int $creator) {
    return (int) (Database::fetchOne(
        "INSERT INTO ssp_plans (title, next_review_date, created_by) VALUES (?, $reviewExpr, ?) RETURNING id",
        [$title, $creator]
    )['id'] ?? 0);
};

$pOver   = $mk('SSPR Overdue',   'CURRENT_DATE - 5',  $owner);     // overdue, active owner  -> selected
$pFuture = $mk('SSPR Future',    'CURRENT_DATE + 60', $owner);     // future                 -> NOT selected
$pNone   = $mk('SSPR NoDate',    'NULL',              $owner);     // no review date         -> NOT selected
$pInact  = $mk('SSPR Inactive',  'CURRENT_DATE - 5',  $inactive);  // overdue, inactive owner-> NOT selected
if (!$pOver || !$pFuture || !$pNone || !$pInact) fail('could not seed SSP plans');

// (a)+(b) overdue predicate + active-owner join.
$rows = Database::fetchAll(
    "SELECT sp.id, sp.created_by AS user_id
       FROM ssp_plans sp
       JOIN users u ON u.id = sp.created_by
      WHERE sp.next_review_date IS NOT NULL
        AND sp.next_review_date < CURRENT_DATE
        AND u.is_active = TRUE
        AND sp.title LIKE 'SSPR %'
      ORDER BY sp.id",
    []
);
$got = array_map(fn($r) => (int) $r['id'], $rows);
if ($got !== [$pOver]) {
    fail('SSP overdue predicate mismatch: got ' . json_encode($got) . ' expected ' . json_encode([$pOver]));
}
ok('predicate selects only the past-due plan owned by an active user');

foreach (['future' => $pFuture, 'no-date' => $pNone, 'inactive-owner' => $pInact] as $why => $id) {
    if (in_array($id, $got, true)) fail("plan excluded for being {$why} was selected (id {$id})");
}
ok('future, no-review-date and inactive-owner plans are excluded');

// (c) deleting an SSP plan cascades to its control statements.
$obj = (int) (Database::fetchOne("INSERT INTO compliance_objectives (code,title,level) VALUES ('SSPR-OBJ','SSPR objective',1) RETURNING id")['id'] ?? 0);
if (!$obj) fail('could not seed objective');
Database::query("INSERT INTO ssp_control_statements (ssp_id,objective_id,implementation_statement) VALUES (?, ?, 'x')", [$pOver, $obj]);
$before = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM ssp_control_statements WHERE ssp_id = ?", [$pOver])['c'] ?? -1);
if ($before < 1) fail('could not seed control statement');
Database::query("DELETE FROM ssp_plans WHERE id = ?", [$pOver]);
$after = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM ssp_control_statements WHERE ssp_id = ?", [$pOver])['c'] ?? -1);
if ($after !== 0) fail("deleting SSP did not cascade ({$after} control statements remain)");
ok('deleting an SSP plan cascades to its control statements');

// cleanup
Database::query("DELETE FROM ssp_plans WHERE title LIKE 'SSPR %'");
Database::query("DELETE FROM compliance_objectives WHERE id = ?", [$obj]);
Database::query("DELETE FROM users WHERE id IN (?,?)", [$owner, $inactive]);

echo "[ssp_review_db] PASS\n";
