<?php

declare(strict_types=1);

/**
 * Database-backed tests. Self-skip unless DATABASE_URL is set AND
 * VERITY_TEST_DB=1 is set, so the suite is safe to run anywhere (CI sets
 * both against a throwaway Postgres instance). Builds its own small,
 * self-contained fixture inside a transaction that is ALWAYS rolled back at
 * the end — never touches or depends on seed.php's data.
 */

use Verity\Support\Authorize;
use Verity\Support\Db;

if (!Db::isConfigured() || getenv('VERITY_TEST_DB') !== '1') {
    T::group('Database-backed tests');
    T::skip('DATABASE_URL not configured or VERITY_TEST_DB!=1 — set both to run this group');
    return;
}

$pdo = Db::connection();
$pdo->beginTransaction();

try {
    // --- Fixture: a 3-level manager chain + one unrelated person ----------
    $ceo = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'CEO', 'display_name' => 'Test CEO',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test',
    ]);
    $manager = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'Manager', 'display_name' => 'Test Manager',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test', 'manager_person_id' => $ceo,
    ]);
    $report = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'Report', 'display_name' => 'Test Report',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test', 'manager_person_id' => $manager,
    ]);
    $unrelated = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'Unrelated', 'display_name' => 'Test Unrelated',
        'department' => 'Other', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test',
    ]);

    T::group('Authorize — reporting-chain scoping (real DB, isolated fixture)');
    T::ok(Authorize::isInReportingChain($manager, $report), 'a direct report is in the manager\'s reporting chain');
    T::ok(Authorize::isInReportingChain($ceo, $report), 'an indirect (two-level) report is in the chain');
    T::ok(Authorize::isInReportingChain($ceo, $manager), 'the manager itself is in the CEO\'s chain');
    T::ok(!Authorize::isInReportingChain($manager, $unrelated), 'an unrelated person is NOT in the chain — this is the acceptance-test-#9 boundary');
    T::ok(!Authorize::isInReportingChain($report, $manager), 'the relationship is directional — a report is not "above" their manager');
    $reports = Authorize::reportsOf($ceo);
    T::ok(in_array($manager, $reports, true) && in_array($report, $reports, true), 'reportsOf() returns the full downward chain');
    T::ok(!in_array($unrelated, $reports, true), 'reportsOf() excludes people outside the chain');

    // --- Fixture: an application an owner does/doesn't own -----------------
    $app = Db::insert('application', ['name' => 'Test App ' . uniqid(), 'system_owner_person_id' => $manager]);
    T::group('Authorize — application-ownership scoping (real DB, isolated fixture)');
    T::ok(Authorize::ownsApplication($manager, $app), 'the recorded system owner owns the application');
    T::ok(!Authorize::ownsApplication($report, $app), 'a non-owner does not own the application');

    // --- Db::update() behavior ----------------------------------------------
    T::group('Db::update — auto-appends updated_at only when the column exists');
    // NOW() is frozen at transaction start in Postgres (= transaction_timestamp()),
    // so two statements in this same uncommitted transaction would show an
    // identical NOW() no matter how long we sleep between them. Backdate to a
    // fixed sentinel instead, so any change away from it proves Db::update()
    // really appended `updated_at = NOW()` rather than leaving it untouched.
    Db::query('UPDATE person SET updated_at = :t WHERE id = :id', ['t' => '2000-01-01 00:00:00+00', 'id' => $report]);
    Db::update('person', ['department' => 'Executive (renamed)'], ['id' => $report]);
    $after = Db::fetchOne('SELECT updated_at, department FROM person WHERE id = :id', ['id' => $report]);
    T::eq('Executive (renamed)', $after['department'], 'the targeted column was updated');
    T::ok(substr((string) $after['updated_at'], 0, 4) !== '2000', 'updated_at was auto-bumped by Db::update() away from the backdated sentinel, without being passed explicitly');

    T::group('Db::insert / fetchOne — basic round trip');
    $fetched = Db::fetchOne('SELECT display_name FROM person WHERE id = :id', ['id' => $ceo]);
    T::eq('Test CEO', $fetched['display_name'] ?? null, 'a row inserted via Db::insert() is readable via Db::fetchOne()');

    T::group('Db::ident — SQL identifier allowlist');
    $rejected = false;
    try {
        Db::fetchAll('SELECT 1 FROM ' . Db::ident('person; DROP TABLE person; --'));
    } catch (\RuntimeException $e) {
        $rejected = true;
    }
    T::ok($rejected, 'a non-identifier string (attempted injection) is rejected before it reaches SQL');

    // --- Users::create() / Auth — the real bug fixed in this change set ----
    // Users::create() used to insert status='invited' with no password_hash
    // at all, producing an account that could never log in (there is no
    // invitation-acceptance flow). Regression-guards that specifically.
    T::group('Users::create — a newly created user can log in immediately');
    $newUserEmail = 'test-newuser-' . bin2hex(random_bytes(4)) . '@example.test';
    $newUserId = \Verity\Support\Users::create($newUserEmail, 'Test New User', 'a-perfectly-fine-password-12', ['auditor'], null, null);
    $row = Db::fetchOne('SELECT status, password_hash FROM app_user WHERE id = :id', ['id' => $newUserId]);
    T::eq('active', $row['status'] ?? null, 'a newly created user is status=active, not the unusable "invited" state');
    T::ok(!empty($row['password_hash']), 'a newly created user has a password_hash set');
    T::eq(
        $newUserId,
        \Verity\Support\Auth::checkLocalCredentials($newUserEmail, 'a-perfectly-fine-password-12'),
        'the exact password given at creation time authenticates successfully — this is the bug that was fixed'
    );
    T::ok(
        \Verity\Support\Auth::checkLocalCredentials($newUserEmail, 'wrong-password-entirely') === null,
        'an incorrect password is still rejected for the new account'
    );

    T::group('Users::updateDetails — updates name/email/person, never touches password or status');
    $beforeHash = Db::fetchValue('SELECT password_hash FROM app_user WHERE id = :id', ['id' => $newUserId]);
    \Verity\Support\Users::updateDetails($newUserId, 'Renamed User', $newUserEmail, $report, null);
    $afterUpdate = Db::fetchOne('SELECT display_name, person_id, password_hash, status FROM app_user WHERE id = :id', ['id' => $newUserId]);
    T::eq('Renamed User', $afterUpdate['display_name'], 'display_name was updated');
    T::eq($report, (int) $afterUpdate['person_id'], 'person_id (linked identity) was updated');
    T::ok($beforeHash === $afterUpdate['password_hash'], 'password_hash is untouched by a details update'); // T::ok, not T::eq — avoid echoing a hash value into test output
    T::eq('active', $afterUpdate['status'], 'status is untouched by a details update');
} finally {
    $pdo->rollBack();
}

// --- DB_SCHEMA isolation (Config::dbSchema() / Db::connection()) -----------
// Db's PDO connection is a per-process singleton, so this can't be exercised
// in-process alongside the fixture above (which already holds the real
// connection) — it needs a genuinely fresh process, the same way a real
// deployment would pick up DB_SCHEMA for the first time. Verifies the exact
// scenario this feature exists for: Verity sharing a Postgres instance with
// another application without any table-name collision risk.
T::group('Db::connection() — dedicated-schema isolation (DB_SCHEMA)');
$testSchema = 'verity_test_schema_' . bin2hex(random_bytes(4));
$bootstrapPath = dirname(__DIR__) . '/app/bootstrap.php';
$probeLines = [
    'require ' . var_export($bootstrapPath, true) . ';',
    'use Verity\Support\Db;',
    '$pdo = Db::connection();',
    "echo \$pdo->query('SELECT current_schema()')->fetchColumn();",
    "echo ',';",
    "\$pdo->exec('CREATE TABLE IF NOT EXISTS isolation_probe (id int)');",
    "echo (int) \$pdo->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'isolation_probe'\")->fetchColumn();",
];
$probe = implode("\n", $probeLines);
$env = [
    'DATABASE_URL' => (string) getenv('DATABASE_URL'),
    'DB_SCHEMA' => $testSchema,
];
$process = proc_open([PHP_BINARY, '-r', $probe], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
$output = $process !== false ? stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]) : '';
if ($process !== false) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
[$reportedSchema, $tableCreated] = array_pad(explode(',', trim((string) $output)), 2, null);
T::eq($testSchema, $reportedSchema, 'a fresh connection with DB_SCHEMA set reports that schema as current_schema(), not public');
T::eq('1', $tableCreated, 'Db::connection() auto-creates the schema (idempotent) so schema.sql-style DDL works with zero manual setup');

// Confirm total isolation from the default/public schema and clean up.
$inPublic = (int) Db::fetchValue(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'isolation_probe'"
);
T::eq(0, $inPublic, 'the probe table was created ONLY inside the dedicated schema — public is untouched, proving real isolation');
Db::query('DROP SCHEMA IF EXISTS ' . Db::ident($testSchema) . ' CASCADE');
