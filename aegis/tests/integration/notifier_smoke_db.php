<?php
declare(strict_types=1);

/**
 * Integration: end-to-end smoke of scripts/send_notifications.php (Phase 12).
 *
 * Regression guard for the "silently-broken notifier query" class of bug: four
 * sections referenced columns that do not exist (incidents.owner_id,
 * documents.document_number, evidence_files.filename,
 * vendor_assessments.next_assessment_date), so those alerts threw a caught
 * error and NEVER fired in production. Postgres validates column names at plan
 * time, so a bad column errors even with zero matching rows.
 *
 * This test seeds one qualifying row per repaired section, runs the notifier,
 * and asserts NO "ERROR: column ... does not exist" (or any "[section] ERROR:")
 * line reaches stderr and that the run completes (summary line present).
 *
 * Usage: php tests/integration/notifier_smoke_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[notifier_smoke_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[notifier_smoke_db] ok: $m\n"; }

// ── Idempotent cleanup of prior seed rows ──────────────────────────────────
Database::query("DELETE FROM incidents WHERE incident_number = 'SMOKE-INC-1'");
Database::query("DELETE FROM documents WHERE title = 'Smoke Expiring Policy'");
Database::query("DELETE FROM evidence_files WHERE original_name = 'smoke-evidence.pdf'");
Database::query("DELETE FROM vendor_assessments WHERE vendor_id IN (SELECT id FROM vendors WHERE name = 'Smoke Vendor Co')");
Database::query("DELETE FROM vendors WHERE name = 'Smoke Vendor Co'");
Database::query("DELETE FROM notification_log WHERE user_id IN (SELECT id FROM users WHERE email = 'smoke-notify@test.local')");
Database::query("DELETE FROM users WHERE email = 'smoke-notify@test.local'");

// ── Seed an active recipient ───────────────────────────────────────────────
$uid = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('Smoke Notify','smoke-notify@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$uid) fail('could not seed recipient user');

// open_incident_aging: assigned, open, older than 48h.
Database::query(
    "INSERT INTO incidents (incident_number,title,severity,status,assigned_to,created_at)
     VALUES ('SMOKE-INC-1','Smoke aging incident','high','open',?, NOW() - INTERVAL '3 days')",
    [$uid]
);
// document_expiring: owned, expiring within 30 days, not archived/expired.
Database::query(
    "INSERT INTO documents (title,doc_number,classification,status,current_version,owner_id,expiry_date)
     VALUES ('Smoke Expiring Policy','SMK-001','internal','published','1.0',?, CURRENT_DATE + 10)",
    [$uid]
);
// evidence_expiring: uploaded by user, expiring within 30 days.
Database::query(
    "INSERT INTO evidence_files (entity_type,entity_id,original_name,stored_name,uploaded_by,expires_at)
     VALUES ('control',1,'smoke-evidence.pdf','stored-smoke.pdf',?, CURRENT_DATE + 10)",
    [$uid]
);
// vendor_assessment_expiring: planned assessment scheduled within 30 days.
$vid = (int) (Database::fetchOne("INSERT INTO vendors (name,status,created_by) VALUES ('Smoke Vendor Co','active',?) RETURNING id", [$uid])['id'] ?? 0);
if (!$vid) fail('could not seed vendor');
Database::query(
    "INSERT INTO vendor_assessments (vendor_id,assessment_type,status,assessed_by,scheduled_date)
     VALUES (?, 'security','planned',?, CURRENT_DATE + 10)",
    [$vid, $uid]
);
ok('seeded one qualifying row per repaired notifier section');

// ── Run the notifier and capture combined output ───────────────────────────
$cmd = 'php ' . escapeshellarg(AEGIS_ROOT . '/scripts/send_notifications.php') . ' 2>&1';
$out = shell_exec($cmd) ?? '';

// ── Assertions ─────────────────────────────────────────────────────────────
if (preg_match('/ERROR: column .* does not exist/i', $out, $m)) {
    fail('notifier still has a broken column reference: ' . trim($m[0]));
}
ok('no "column does not exist" errors from any notifier section');

foreach (['open_incident_aging','document_expiring','evidence_expiring','vendor_assessment_expiring'] as $section) {
    if (strpos($out, "[$section] ERROR:") !== false) {
        fail("section [$section] still logged an error");
    }
}
ok('the four repaired sections run without error');

if (strpos($out, 'Notifications:') === false) {
    fail("notifier did not reach its summary line — output:\n" . $out);
}
ok('notifier completed and printed its summary');

// ── Cleanup ────────────────────────────────────────────────────────────────
Database::query("DELETE FROM incidents WHERE incident_number = 'SMOKE-INC-1'");
Database::query("DELETE FROM documents WHERE title = 'Smoke Expiring Policy'");
Database::query("DELETE FROM evidence_files WHERE original_name = 'smoke-evidence.pdf'");
Database::query("DELETE FROM vendor_assessments WHERE vendor_id = ?", [$vid]);
Database::query("DELETE FROM vendors WHERE id = ?", [$vid]);
Database::query("DELETE FROM notification_log WHERE user_id = ?", [$uid]);
Database::query("DELETE FROM users WHERE id = ?", [$uid]);

echo "[notifier_smoke_db] PASS\n";
