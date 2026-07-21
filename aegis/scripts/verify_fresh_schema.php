#!/usr/bin/env php
<?php
/**
 * Fresh-install schema completeness & drift guard (OPEN_ITEMS TD-4).
 *
 * A fresh / migrate-only install.php must build a COMPLETE, WARNING-FREE schema
 * without relying on the index.php runtime heal (TD-1b). This gate makes future
 * drift loud in CI instead of silent:
 *   1. Re-runs install.php against the (already-installed) DB — this idempotently
 *      re-applies the index block + all migrations — and asserts it reports
 *      "Schema is complete — 0 warnings" with no "warning:" lines. A drifted
 *      column/table would surface as a warning here.
 *   2. Asserts a curated set of columns/tables that application code depends on
 *      actually exist (kept in sync with tests/integration/schema_completeness_db.php).
 *
 * Exit codes:
 *   0 — fresh schema is complete and warning-free
 *   1 — drift detected (warning emitted, or an expected object is missing)
 *   2 — configuration / bootstrap error
 *
 * Usage (CI, after `php install.php` on a fresh DB):
 *   php scripts/verify_fresh_schema.php [--quiet]
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('AEGIS_ROOT', dirname(__DIR__));
$quiet = in_array('--quiet', $argv, true);

foreach (['.env.local', '.env'] as $envFile) {
    if (file_exists(AEGIS_ROOT . '/' . $envFile)) {
        foreach (file(AEGIS_ROOT . '/' . $envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}
foreach ((getenv() ?: []) as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function say(string $m): void { global $quiet; if (!$quiet) echo $m . PHP_EOL; }
function fail(string $m): never { fwrite(STDERR, "[verify_fresh_schema] FAIL: $m\n"); exit(1); }

// ── 1. install.php must complete warning-free ────────────────────────────────
// Re-running install.php on an installed DB runs the migrate path (index block +
// migrations), idempotently — any schema drift surfaces as a "warning:" line.
$installPhp = AEGIS_ROOT . '/install.php';
$out = [];
$code = 0;
exec('php ' . escapeshellarg($installPhp) . ' 2>&1', $out, $code);
$output = implode("\n", $out);

if ($code !== 0) {
    fail("install.php exited with code {$code}:\n{$output}");
}
$warningLines = array_values(array_filter($out, fn($l) => stripos($l, 'warning:') !== false || stripos($l, 'Completed with') !== false));
if ($warningLines) {
    fail("install.php emitted schema/migration warning(s) — a fresh install must be clean:\n  " . implode("\n  ", $warningLines));
}
if (!str_contains($output, 'Schema is complete — 0 warnings')) {
    fail("install.php did not report a clean schema (missing the '0 warnings' summary):\n{$output}");
}
say('[verify_fresh_schema] ok: install.php completes warning-free (Schema is complete — 0 warnings)');

// ── 2. Curated objects code depends on must exist ────────────────────────────
// Keep in sync with tests/integration/schema_completeness_db.php.
$expectedColumns = [
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
    ['vendors', 'risk_tier'],       // VendorController filters/orders by this
    ['vendors', 'vendor_code'],
];
$expectedTables = ['totp_used_codes', 'ai_inference_log', 'password_history', 'user_notification_prefs'];

$missing = [];
foreach ($expectedColumns as [$t, $c]) {
    $exists = Database::fetchOne(
        "SELECT 1 FROM information_schema.columns
          WHERE table_name = ? AND column_name = ? AND table_schema = ANY(ARRAY['public','aegis'])",
        [$t, $c]
    );
    if (!$exists) $missing[] = "column {$t}.{$c}";
}
foreach ($expectedTables as $t) {
    $exists = Database::fetchOne(
        "SELECT 1 FROM information_schema.tables
          WHERE table_name = ? AND table_schema = ANY(ARRAY['public','aegis'])",
        [$t]
    );
    if (!$exists) $missing[] = "table {$t}";
}
if ($missing) {
    fail("expected schema object(s) missing from a fresh install:\n  " . implode("\n  ", $missing));
}
say('[verify_fresh_schema] ok: all ' . (count($expectedColumns) + count($expectedTables)) . ' curated schema objects present');

say('[verify_fresh_schema] PASS — fresh schema is complete and warning-free.');
exit(0);
