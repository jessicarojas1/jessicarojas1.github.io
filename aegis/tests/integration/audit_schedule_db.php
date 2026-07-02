<?php
declare(strict_types=1);

/**
 * Integration: audit-schedule-overdue detection (Phase 16) against a live
 * Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the overdue predicate (scheduled_date < today AND completed_date IS NULL
 *       AND status NOT IN completed/cancelled) selects only open, past-due
 *       audits — ignoring completed, cancelled, future-dated and
 *       completed_date-set audits,
 *   (b) the owner join COALESCE(a.auditor_id, a.created_by) falls back to the
 *       audit's creator when no auditor is assigned,
 *   (c) deleting an audit cascades to its audit_items.
 *
 * Usage: php tests/integration/audit_schedule_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[audit_schedule_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[audit_schedule_db] ok: $m\n"; }

// Idempotent cleanup (items cascade from the audit).
Database::query("DELETE FROM audits WHERE name LIKE 'ASO %'");
Database::query("DELETE FROM users WHERE email IN ('aso-auditor@test.local','aso-creator@test.local')");

$uAuditor = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('ASO Auditor','aso-auditor@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$uCreator = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('ASO Creator','aso-creator@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$uAuditor || !$uCreator) fail('could not seed auditor/creator');

$mk = function (string $name, string $status, string $schedExpr, ?string $completedExpr, ?int $auditor) use ($uCreator): int {
    $completed = $completedExpr ?? 'NULL';
    $sql = "INSERT INTO audits (name,audit_type,status,scheduled_date,completed_date,auditor_id,created_by)
            VALUES (?, 'internal', ?, $schedExpr, $completed, ?, ?) RETURNING id";
    return (int) (Database::fetchOne($sql, [$name, $status, $auditor, $uCreator])['id'] ?? 0);
};

$aOver   = $mk('ASO Overdue Planned', 'planned',     'CURRENT_DATE - 5',  null, $uAuditor);        // overdue, has auditor -> selected (auditor)
$aProg   = $mk('ASO Overdue InProg',  'in_progress', 'CURRENT_DATE - 10', null, $uAuditor);        // in progress past due, not done -> selected (auditor)
$aFuture = $mk('ASO Future',          'planned',     'CURRENT_DATE + 20', null, $uAuditor);        // future -> NOT selected
$aDone   = $mk('ASO Completed',       'completed',   'CURRENT_DATE - 5',  'CURRENT_DATE - 1', $uAuditor); // completed -> NOT selected
$aCanc   = $mk('ASO Cancelled',       'cancelled',   'CURRENT_DATE - 5',  null, $uAuditor);        // cancelled -> NOT selected
$aFall   = $mk('ASO Overdue NoAuditor','planned',    'CURRENT_DATE - 3',  null, null);             // overdue, no auditor -> selected (creator)
if (!$aOver || !$aProg || !$aFuture || !$aDone || !$aCanc || !$aFall) fail('could not seed audits');

// (a)+(b) overdue predicate + owner fallback.
$rows = Database::fetchAll(
    "SELECT a.id, COALESCE(a.auditor_id, a.created_by) AS user_id
       FROM audits a
       JOIN users u ON u.id = COALESCE(a.auditor_id, a.created_by)
      WHERE a.scheduled_date IS NOT NULL
        AND a.scheduled_date < CURRENT_DATE
        AND a.completed_date IS NULL
        AND a.status NOT IN ('completed','cancelled')
        AND u.is_active = TRUE
        AND a.name LIKE 'ASO %'
      ORDER BY a.id",
    []
);
$got = [];
foreach ($rows as $r) { $got[(int) $r['id']] = (int) $r['user_id']; }

$expected = [$aOver => $uAuditor, $aProg => $uAuditor, $aFall => $uCreator];
if ($got !== $expected) {
    fail('audit overdue predicate/owner-join mismatch: got ' . json_encode($got) . ' expected ' . json_encode($expected));
}
ok('predicate selects only open past-due audits and attributes each to its owner (creator fallback)');

foreach (['future' => $aFuture, 'completed' => $aDone, 'cancelled' => $aCanc] as $why => $id) {
    if (isset($got[$id])) fail("audit excluded for being {$why} was selected (id {$id})");
}
ok('future, completed and cancelled audits are excluded');

// (c) deleting an audit cascades to its audit_items.
Database::query("INSERT INTO audit_items (audit_id,status) VALUES (?, 'not_assessed')", [$aOver]);
$before = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM audit_items WHERE audit_id = ?", [$aOver])['c'] ?? -1);
if ($before < 1) fail('could not seed audit_item');
Database::query("DELETE FROM audits WHERE id = ?", [$aOver]);
$after = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM audit_items WHERE audit_id = ?", [$aOver])['c'] ?? -1);
if ($after !== 0) fail("deleting audit did not cascade ({$after} items remain)");
ok('deleting an audit cascades to its audit_items');

// cleanup
Database::query("DELETE FROM audits WHERE name LIKE 'ASO %'");
Database::query("DELETE FROM users WHERE id IN (?,?)", [$uAuditor, $uCreator]);

echo "[audit_schedule_db] PASS\n";
