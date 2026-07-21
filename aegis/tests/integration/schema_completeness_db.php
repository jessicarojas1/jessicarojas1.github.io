<?php
declare(strict_types=1);

/**
 * Integration: fresh-install schema completeness (Phase 22) against a live
 * Postgres.
 *
 * Migration 038 promotes schema that previously existed only as runtime guards
 * in index.php into a proper migration. This test asserts that, in a database
 * built by install.php (migrations only — NOT the web front controller), every
 * promoted column/table is present. It relies on the CI DB being built by
 * install.php without ever serving an HTTP request; to reproduce locally, run
 * `DROP SCHEMA aegis CASCADE; CREATE SCHEMA aegis;` then `php install.php`
 * before this test.
 *
 * Usage: php tests/integration/schema_completeness_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[schema_completeness_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[schema_completeness_db] ok: $m\n"; }

function columnExists(string $table, string $column): bool {
    return (bool) Database::fetchOne(
        "SELECT 1 FROM information_schema.columns
          WHERE table_name = ? AND column_name = ? AND table_schema = ANY(ARRAY['public','aegis'])",
        [$table, $column]
    );
}
function tableExists(string $table): bool {
    return (bool) Database::fetchOne(
        "SELECT 1 FROM information_schema.tables
          WHERE table_name = ? AND table_schema = ANY(ARRAY['public','aegis'])",
        [$table]
    );
}

// Columns promoted by migration 038. (change_requests is intentionally dropped
// by migration 032, so it is deliberately not promoted.)
$columns = [
    ['assets', 'created_by'],
    ['compliance_objectives', 'additional_information'],
    ['users', 'sessions_revoked_at'],
    ['users', 'force_password_change'],
    ['users', 'password_changed_at'],
    ['incidents', 'phi_involved'],
    ['incidents', 'breach_notification_required'],
    ['incidents', 'breach_notification_sent_at'],
    ['incidents', 'root_cause'],
    ['issues', 'resolution'],
    ['audit_findings', 'audit_id'],
    // Phase 24 (TD-1b): enterprise vendor fields folded into schema.sql so a fresh
    // install has them before the idx_vendors_risk_tier index / VendorController.
    ['vendors', 'risk_tier'],
    ['vendors', 'vendor_code'],
];
foreach ($columns as [$t, $c]) {
    if (!columnExists($t, $c)) fail("column {$t}.{$c} missing from a migrations-only install");
}
ok('all promoted columns exist in a migrations-only install');

// Tables that must exist in a fresh install: migration 038's three, plus
// user_notification_prefs (Phase 24 / TD-1b — folded into schema.sql so it exists
// before migration 006's ALTERs).
foreach (['totp_used_codes', 'ai_inference_log', 'password_history', 'user_notification_prefs'] as $t) {
    if (!tableExists($t)) fail("table {$t} missing from a migrations-only install");
}
ok('promoted tables + user_notification_prefs exist in a migrations-only install');

// Widened status constraints: the new enum values must be accepted.
Database::query("DELETE FROM incidents WHERE incident_number = 'SC-CONTAINED'");
try {
    Database::query("INSERT INTO incidents (incident_number,title,severity,status) VALUES ('SC-CONTAINED','sc','low','contained')");
} catch (\Throwable $e) {
    fail("incidents_status_check rejects 'contained': " . $e->getMessage());
}
Database::query("DELETE FROM incidents WHERE incident_number = 'SC-CONTAINED'");
ok("incidents status accepts the widened 'contained' value");

echo "[schema_completeness_db] PASS\n";
