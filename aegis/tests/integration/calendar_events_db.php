<?php
declare(strict_types=1);

/**
 * Integration: compliance-calendar aggregation (Phase 20) against a live Postgres.
 *
 * Phase 20 extends CalendarController::getEvents() to fold the cadence signals
 * built across the GRC monitoring arc (control re-tests, audit finding
 * remediation, vendor certifications & contracts, policy attestation, SSP review,
 * asset review, KRI measurement) into the existing month-grid calendar. This
 * seeds one event per new category dated in the current month and asserts the
 * real getEvents() aggregation returns each on its date with the right type.
 *
 * Usage: php tests/integration/calendar_events_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/controllers/CalendarController.php';

function fail(string $m): never { fwrite(STDERR, "[calendar_events_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[calendar_events_db] ok: $m\n"; }

// Mid-month target date (the 15th) — always inside the current month window.
$MID = "(DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '14 days')::date";

// Idempotent cleanup (children cascade from their parents).
Database::query("DELETE FROM compliance_packages WHERE name = 'CAL20 Package'");
Database::query("DELETE FROM audit_findings WHERE finding_number = 'CAL20-F1'");
Database::query("DELETE FROM vendors WHERE name = 'CAL20 Vendor'");
Database::query("DELETE FROM policies WHERE title = 'CAL20 Policy'");
Database::query("DELETE FROM ssp_plans WHERE title = 'CAL20 SSP'");
Database::query("DELETE FROM assets WHERE name = 'CAL20 Asset'");
Database::query("DELETE FROM kris WHERE title = 'CAL20 KRI'");
Database::query("DELETE FROM users WHERE email = 'cal20@test.local'");

$uid = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('CAL20','cal20@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$uid) fail('could not seed user');

// Control re-test: package + objective + implementation + a test due mid-month.
$pkg = (int) (Database::fetchOne("INSERT INTO compliance_packages (name) VALUES ('CAL20 Package') RETURNING id")['id'] ?? 0);
$obj = (int) (Database::fetchOne("INSERT INTO compliance_objectives (package_id,code,title,level) VALUES (?, 'CAL20-C1','Control one',2) RETURNING id", [$pkg])['id'] ?? 0);
Database::query("INSERT INTO control_implementations (objective_id,status,assigned_to) VALUES (?, 'compliant', ?)", [$obj, $uid]);
Database::query("INSERT INTO control_tests (objective_id,package_id,result,next_test_date) VALUES (?,?,'pass', $MID)", [$obj, $pkg]);

// Audit finding remediation deadline mid-month.
Database::query("INSERT INTO audit_findings (finding_number,title,status,deadline) VALUES ('CAL20-F1','Finding one','open', $MID)");

// Vendor certification expiry + contract end mid-month.
$vid = (int) (Database::fetchOne("INSERT INTO vendors (name,status,created_by) VALUES ('CAL20 Vendor','active',?) RETURNING id", [$uid])['id'] ?? 0);
Database::query("INSERT INTO vendor_certifications (vendor_id,certification_type,status,expiry_date) VALUES (?, 'ISO 27001','active', $MID)", [$vid]);
Database::query("INSERT INTO vendor_contracts (vendor_id,title,status,start_date,end_date) VALUES (?, 'CAL20 Contract','active', CURRENT_DATE - 400, $MID)", [$vid]);

// Policy attestation campaign due mid-month.
$pid = (int) (Database::fetchOne("INSERT INTO policies (title,status) VALUES ('CAL20 Policy','published') RETURNING id")['id'] ?? 0);
Database::query("INSERT INTO policy_attestation_campaigns (policy_id,title,due_date,is_active) VALUES (?, 'CAL20 Campaign', $MID, TRUE)", [$pid]);

// SSP review mid-month.
Database::query("INSERT INTO ssp_plans (title,next_review_date,created_by) VALUES ('CAL20 SSP', $MID, ?)", [$uid]);

// Asset annual review lands mid-month (last_reviewed = mid - 365 days).
Database::query("INSERT INTO assets (name,asset_type,status,last_reviewed,owner_id) VALUES ('CAL20 Asset','server','active', ($MID - INTERVAL '365 days')::date, ?)", [$uid]);

// KRI next measurement lands mid-month (monthly cadence: last recorded = mid - 31 days).
$kid = (int) (Database::fetchOne("INSERT INTO kris (title,unit,direction,threshold_green,threshold_amber,threshold_red,frequency,owner_id,is_active) VALUES ('CAL20 KRI','count','higher_worse',10,20,30,'monthly',?,TRUE) RETURNING id", [$uid])['id'] ?? 0);
Database::query("INSERT INTO kri_values (kri_id,value,recorded_at) VALUES (?, 15, ($MID - INTERVAL '31 days')::date)", [$kid]);

// Run the real aggregation for the current month.
$ym = Database::fetchOne("SELECT EXTRACT(YEAR FROM CURRENT_DATE)::int AS y, EXTRACT(MONTH FROM CURRENT_DATE)::int AS m");
$events = CalendarController::getEvents((int) $ym['y'], (int) $ym['m']);

// Flatten to (type => set of titles).
$byType = [];
foreach ($events as $date => $dayEvents) {
    foreach ($dayEvents as $ev) {
        $byType[$ev['type']][] = $ev['title'];
    }
}

$expect = [
    'control_retest'  => 'CAL20-C1: Control one',
    'finding'         => 'CAL20-F1: Finding one',
    'vendor_cert'     => 'ISO 27001 — CAL20 Vendor',
    'vendor_contract' => 'CAL20 Contract',
    'attestation'     => 'CAL20 Campaign',
    'ssp_review'      => 'CAL20 SSP',
    'asset_review'    => 'CAL20 Asset',
    'kri_measurement' => 'CAL20 KRI',
];
foreach ($expect as $type => $title) {
    if (!isset($byType[$type]) || !in_array($title, $byType[$type], true)) {
        fail("calendar did not surface {$type} event '{$title}' (got: " . json_encode($byType[$type] ?? []) . ")");
    }
}
ok('all eight new cadence categories surface on the calendar for the current month');

// cleanup
Database::query("DELETE FROM compliance_packages WHERE id = ?", [$pkg]);
Database::query("DELETE FROM audit_findings WHERE finding_number = 'CAL20-F1'");
Database::query("DELETE FROM vendors WHERE id = ?", [$vid]);
Database::query("DELETE FROM policies WHERE id = ?", [$pid]);
Database::query("DELETE FROM ssp_plans WHERE title = 'CAL20 SSP'");
Database::query("DELETE FROM assets WHERE name = 'CAL20 Asset'");
Database::query("DELETE FROM kris WHERE id = ?", [$kid]);
Database::query("DELETE FROM users WHERE id = ?", [$uid]);

echo "[calendar_events_db] PASS\n";
