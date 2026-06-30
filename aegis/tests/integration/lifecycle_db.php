<?php
declare(strict_types=1);

/**
 * Integration: Phase 4 lifecycle/CAPA schema behavior against a live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) issues.status accepts the CAPA 'reopened' state (widened CHECK) and that
 *       root_cause/preventive_action persist,
 *   (b) audit_findings accepts root_cause/preventive_action and the 'reopened'
 *       state (no DB CHECK there — code-validated),
 *   (c) policies carry a hard expiry date and a 'retired' status,
 *   (d) vendor_certifications enforce its status CHECK, stamp tenant_id, and
 *       cascade-delete with the parent vendor.
 *
 * Usage: php tests/integration/lifecycle_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[lifecycle_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[lifecycle_db] ok: $m\n"; }

// Idempotency: remove any rows left by a previous run (CI uses a fresh DB, but
// this keeps the test re-runnable against a reused database).
Database::query("DELETE FROM issues WHERE issue_number = 'ISS-T001'");
Database::query("DELETE FROM audit_findings WHERE finding_number = 'FIND-T001'");
Database::query("DELETE FROM policies WHERE title = 'Phase4 policy'");
Database::query("DELETE FROM vendors WHERE name = 'Phase4 Vendor'");

// ── (a) issues: reopened state + CAPA columns ──────────────────────────────────
$issueId = (int)(Database::fetchOne(
    "INSERT INTO issues (issue_number, title, status) VALUES ('ISS-T001','Phase4 test','closed') RETURNING id"
)['id'] ?? 0);
if ($issueId <= 0) fail('could not seed issue');

Database::update('issues', [
    'status'            => 'reopened',
    'root_cause'        => 'missing input validation',
    'preventive_action' => 'add a linter rule',
], 'id = ?', [$issueId]);
$row = Database::fetchOne("SELECT status, root_cause, preventive_action FROM issues WHERE id = ?", [$issueId]);
if ($row['status'] !== 'reopened') fail("issue not reopened (got {$row['status']})");
if ($row['root_cause'] !== 'missing input validation' || $row['preventive_action'] !== 'add a linter rule') fail('CAPA columns did not persist on issue');
ok('issue accepts reopened state + root_cause/preventive_action persist');

$badStatus = false;
try { Database::query("UPDATE issues SET status = 'bogus' WHERE id = ?", [$issueId]); }
catch (Throwable $e) { $badStatus = true; }
if (!$badStatus) fail('issues status CHECK did not reject an invalid state');
ok('issues status CHECK still rejects unknown states');

// ── (b) audit_findings: CAPA columns + reopened ────────────────────────────────
$findingId = (int)(Database::fetchOne(
    "INSERT INTO audit_findings (finding_number, title, status) VALUES ('FIND-T001','Phase4 finding','closed') RETURNING id"
)['id'] ?? 0);
if ($findingId <= 0) fail('could not seed finding');
Database::query("UPDATE audit_findings SET status='reopened', closed_at=NULL, root_cause='gap', preventive_action='training' WHERE id=?", [$findingId]);
$f = Database::fetchOne("SELECT status, closed_at, root_cause, preventive_action FROM audit_findings WHERE id = ?", [$findingId]);
if ($f['status'] !== 'reopened' || $f['closed_at'] !== null) fail('finding reopen did not clear closed_at');
if ($f['root_cause'] !== 'gap' || $f['preventive_action'] !== 'training') fail('finding CAPA columns did not persist');
ok('audit_finding accepts reopened + CAPA columns persist; closed_at cleared');

// ── (c) policies: expiry date + retired status ─────────────────────────────────
$polId = (int)(Database::fetchOne(
    "INSERT INTO policies (title, status, expires_at) VALUES ('Phase4 policy','published', CURRENT_DATE + 10) RETURNING id"
)['id'] ?? 0);
if ($polId <= 0) fail('could not seed policy');
Database::query("UPDATE policies SET status='retired' WHERE id=?", [$polId]);
$p = Database::fetchOne("SELECT status, expires_at FROM policies WHERE id = ?", [$polId]);
if ($p['status'] !== 'retired') fail('policy not retired');
if (empty($p['expires_at'])) fail('policy expires_at did not persist');
ok('policy carries expires_at and accepts retired status');

// ── (d) vendor_certifications: CHECK, tenant stamp, cascade ─────────────────────
$vendorId = (int)(Database::fetchOne(
    "INSERT INTO vendors (name) VALUES ('Phase4 Vendor') RETURNING id"
)['id'] ?? 0);
if ($vendorId <= 0) fail('could not seed vendor');

$certId = Database::insert('vendor_certifications', [
    'vendor_id'          => $vendorId,
    'certification_type' => 'ISO 27001',
    'issuer'             => 'BSI',
    'expiry_date'        => date('Y-m-d', time() + 20 * 86400),
    'status'             => 'active',
]);
$tid = Database::fetchOne("SELECT tenant_id FROM vendor_certifications WHERE id = ?", [$certId])['tenant_id'] ?? null;
if ((int)$tid !== 1) fail("cert tenant_id not stamped (got " . var_export($tid, true) . ')');
ok('vendor_certification inserted + tenant_id auto-stamped');

$certBad = false;
try { Database::query("UPDATE vendor_certifications SET status='bogus' WHERE id = ?", [$certId]); }
catch (Throwable $e) { $certBad = true; }
if (!$certBad) fail('vendor_certifications status CHECK did not reject an invalid state');
ok('vendor_certifications status CHECK rejects unknown states');

Database::query("DELETE FROM vendors WHERE id = ?", [$vendorId]);
$remain = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM vendor_certifications WHERE id = ?", [$certId])['c'] ?? -1);
if ($remain !== 0) fail("deleting vendor did not cascade to certifications ({$remain} remain)");
ok('deleting a vendor cascades to its certifications');

echo "[lifecycle_db] PASS\n";
