<?php
declare(strict_types=1);

/**
 * Integration: audit-finding remediation-overdue detection (Phase 11) against a
 * live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the remediation-overdue predicate (deadline < today AND status NOT IN
 *       the terminal set) joined to the owner selects only the open, past-due
 *       finding — and ignores a past-due finding that is risk_accepted and one
 *       whose deadline is still in the future,
 *   (b) the owner alert joins audit_findings.owner_id to an active user,
 *   (c) deleting a finding cascades to its finding_updates.
 *
 * Usage: php tests/integration/finding_remediation_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[finding_remediation_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[finding_remediation_db] ok: $m\n"; }

// Idempotency: clean prior test rows (finding_updates cascade from findings).
Database::query("DELETE FROM audit_findings WHERE finding_number IN ('FRM-OVERDUE','FRM-ACCEPTED','FRM-FUTURE')");
Database::query("DELETE FROM users WHERE email = 'frm-owner@test.local'");

// Owner of the findings (active).
$owner = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('FRM Owner','frm-owner@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$owner) fail('could not seed owner');

// Three findings owned by $owner:
//   - open + deadline in the past  -> OVERDUE (should be selected)
//   - risk_accepted + past deadline -> settled, NOT selected
//   - open + future deadline        -> not yet due, NOT selected
$fOver = (int) (Database::fetchOne("INSERT INTO audit_findings (finding_number,title,severity,status,owner_id,deadline) VALUES ('FRM-OVERDUE','Overdue open finding','high','open',?, CURRENT_DATE - 5) RETURNING id", [$owner])['id'] ?? 0);
$fAcc  = (int) (Database::fetchOne("INSERT INTO audit_findings (finding_number,title,severity,status,owner_id,deadline) VALUES ('FRM-ACCEPTED','Past-due but risk accepted','medium','risk_accepted',?, CURRENT_DATE - 20) RETURNING id", [$owner])['id'] ?? 0);
$fFut  = (int) (Database::fetchOne("INSERT INTO audit_findings (finding_number,title,severity,status,owner_id,deadline) VALUES ('FRM-FUTURE','Open, future deadline','low','open',?, CURRENT_DATE + 20) RETURNING id", [$owner])['id'] ?? 0);
if (!$fOver || !$fAcc || !$fFut) fail('could not seed findings');

// (a)+(b) overdue predicate joined to owner selects exactly FRM-OVERDUE.
$hits = Database::fetchAll(
    "SELECT af.finding_number, u.email
       FROM audit_findings af
       JOIN users u ON u.id = af.owner_id
      WHERE af.deadline IS NOT NULL
        AND af.deadline < CURRENT_DATE
        AND af.status NOT IN ('closed','resolved','risk_accepted')
        AND u.is_active = TRUE
        AND af.finding_number IN ('FRM-OVERDUE','FRM-ACCEPTED','FRM-FUTURE')",
    []
);
if (count($hits) !== 1 || $hits[0]['finding_number'] !== 'FRM-OVERDUE' || $hits[0]['email'] !== 'frm-owner@test.local') {
    fail('remediation-overdue predicate selected ' . count($hits) . ' rows (expected just FRM-OVERDUE owned by frm-owner)');
}
ok('remediation-overdue predicate selects only the open, past-due finding, joined to its owner');

// (c) deleting a finding cascades to its finding_updates.
Database::query("INSERT INTO finding_updates (finding_id,user_id,content) VALUES (?,?,'progress note')", [$fOver, $owner]);
$before = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM finding_updates WHERE finding_id = ?", [$fOver])['c'] ?? -1);
if ($before !== 1) fail("could not seed finding_update ({$before})");
Database::query("DELETE FROM audit_findings WHERE id = ?", [$fOver]);
$after = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM finding_updates WHERE finding_id = ?", [$fOver])['c'] ?? -1);
if ($after !== 0) fail("deleting finding did not cascade ({$after} updates remain)");
ok('deleting a finding cascades to its finding_updates');

// cleanup
Database::query("DELETE FROM audit_findings WHERE id IN (?,?)", [$fAcc, $fFut]);
Database::query("DELETE FROM users WHERE id = ?", [$owner]);

echo "[finding_remediation_db] PASS\n";
