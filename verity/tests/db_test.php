<?php

declare(strict_types=1);

/**
 * Database-backed tests. Self-skip unless DATABASE_URL is set AND
 * VERITY_TEST_DB=1 is set, so the suite is safe to run anywhere (CI sets
 * both against a throwaway Postgres instance). Builds its own small,
 * self-contained fixture inside a transaction that is ALWAYS rolled back at
 * the end — never touches or depends on seed.php's data.
 */

use Verity\Support\Accounts;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Campaigns;
use Verity\Support\Connectors;
use Verity\Support\CsvImport;
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

    // --- Fixture: a self-reference and a 2-node cycle in manager_person_id -
    // Found live via a 20,000-row synthetic benchmark dataset: a floating-
    // point boundary case in the generator produced a few self-referencing
    // rows, and the un-guarded recursive CTE hung indefinitely (confirmed:
    // the Postgres backend pegged at ~100% CPU for minutes) rather than
    // erroring or returning. Real data isn't guaranteed to stay a clean
    // DAG either (a manual edit or a bad connector import could do the
    // same) — this regression test fails (by hanging the whole suite) if
    // the path-tracking guard in reportsOf()/isInReportingChain() is ever
    // removed. See CLAUDE.md.
    $selfRef = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'SelfRef', 'display_name' => 'Test SelfRef',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test',
    ]);
    Db::update('person', ['manager_person_id' => $selfRef], ['id' => $selfRef]);
    $cycleA = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'CycleA', 'display_name' => 'Test CycleA',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test',
    ]);
    $cycleB = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'CycleB', 'display_name' => 'Test CycleB',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test', 'manager_person_id' => $cycleA,
    ]);
    Db::update('person', ['manager_person_id' => $cycleB], ['id' => $cycleA]);

    T::group('Authorize — recursive CTEs terminate against cyclic manager_person_id data');
    $selfRefReports = Authorize::reportsOf($selfRef);
    T::ok(is_array($selfRefReports), 'reportsOf() against a self-referencing node returns (does not hang) and includes itself exactly once');
    T::eq(1, count(array_filter($selfRefReports, static fn ($id) => $id === $selfRef)), 'the self-referencing node is not duplicated by the un-deduplicated UNION ALL recursion');
    $cycleReports = Authorize::reportsOf($cycleA);
    T::ok(is_array($cycleReports) && in_array($cycleB, $cycleReports, true), 'reportsOf() against a 2-node cycle returns (does not hang) and still finds the other node in the cycle');
    T::ok(Authorize::isInReportingChain($cycleB, $cycleA), 'isInReportingChain() against the same cycle returns (does not hang)');

    // --- Fixture: an application an owner does/doesn't own -----------------
    $app = Db::insert('application', ['name' => 'Test App ' . uniqid(), 'system_owner_person_id' => $manager]);
    T::group('Authorize — application-ownership scoping (real DB, isolated fixture)');
    T::ok(Authorize::ownsApplication($manager, $app), 'the recorded system owner owns the application');
    T::ok(!Authorize::ownsApplication($report, $app), 'a non-owner does not own the application');

    // --- Fixture: deterministic account-matching candidates ----------------
    Db::update('person', ['employee_id' => 'EMP-UNIQ-1', 'email' => 'unique.report@benchmark.test'], ['id' => $report]);
    Db::update('person', ['employee_id' => 'EMP-DUP-1', 'email' => 'ambiguous@benchmark.test'], ['id' => $manager]);
    $dupPerson = Db::insert('person', [
        'first_name' => 'Test', 'last_name' => 'Duplicate', 'display_name' => 'Test Duplicate',
        'department' => 'Executive', 'identity_type' => 'employee', 'employment_status' => 'active',
        'identity_authority' => 'test', 'email' => 'ambiguous@benchmark.test',
    ]);
    $acctByEmployeeId = Db::insert('system_account', [
        'application_id' => $app, 'external_account_id' => 'EMP-UNIQ-1', 'username' => 'unrelated-username', 'source' => 'manual',
    ]);
    $acctByEmail = Db::insert('system_account', [
        'application_id' => $app, 'external_account_id' => uniqid('ext-'), 'username' => 'unique.report@benchmark.test', 'source' => 'manual',
    ]);
    $acctByLocalPart = Db::insert('system_account', [
        'application_id' => $app, 'external_account_id' => uniqid('ext-'), 'username' => 'unique.report', 'source' => 'manual',
    ]);
    $acctAmbiguous = Db::insert('system_account', [
        'application_id' => $app, 'external_account_id' => uniqid('ext-'), 'username' => 'ambiguous@benchmark.test', 'source' => 'manual',
    ]);
    $acctNoMatch = Db::insert('system_account', [
        'application_id' => $app, 'external_account_id' => uniqid('ext-nomatch-'), 'username' => uniqid('nomatch-'), 'source' => 'manual',
    ]);

    T::group('Accounts::suggestMatch — deterministic matching heuristics, never auto-applies');
    $byEmployeeId = Accounts::suggestMatch($acctByEmployeeId);
    T::ok($byEmployeeId !== null && $byEmployeeId['person_id'] === $report && $byEmployeeId['confidence'] === 'high', 'exact employee_id match finds the right person at high confidence');
    $byEmail = Accounts::suggestMatch($acctByEmail);
    T::ok($byEmail !== null && $byEmail['person_id'] === $report && $byEmail['confidence'] === 'high', 'exact email match (username = email) finds the right person at high confidence');
    $byLocalPart = Accounts::suggestMatch($acctByLocalPart);
    T::ok($byLocalPart !== null && $byLocalPart['person_id'] === $report && $byLocalPart['confidence'] === 'medium', 'email local-part match (bare username, no @) finds the right person at medium confidence');
    T::ok(Accounts::suggestMatch($acctAmbiguous) === null, 'two people sharing the same email is treated as NO confident match, never a guess');
    T::ok(Accounts::suggestMatch($acctNoMatch) === null, 'an account matching nobody returns null, not a weak guess');
    Accounts::link($acctByEmployeeId, $report, 'deterministic', null);
    T::ok(Accounts::suggestMatch($acctByEmployeeId) === null, 'an already-linked account is never suggested again');

    // --- Fixture: a CSV import connector ------------------------------------
    $csvConnector = Connectors::create(['application_id' => $app, 'connector_type' => 'csv_import'], null);

    T::group('CsvImport::run — the first real connector sync engine');
    $csv1 = "external_account_id,username,account_type,status,entitlements\n"
        . "csv-acct-1,csvuser1,standard,enabled,Role A|Role B\n"
        . "csv-acct-2,csvuser2,privileged,enabled,Role A\n"
        . "csv-acct-3,csvuser3,not-a-real-type,enabled,\n"; // invalid account_type -> row-level failure
    $r1 = CsvImport::run($csvConnector, $csv1, null);
    T::eq(2, $r1['imported_accounts'], 'two valid rows are imported; the third (bad account_type) is not');
    T::eq(1, $r1['failure_count'], 'exactly one row failed validation');
    T::eq('partial', $r1['status'], 'some rows succeeded and some failed => status is "partial", not "failed" or "succeeded"');
    T::ok($r1['error_summary'] !== null && str_contains($r1['error_summary'], 'account_type'), 'the error summary names the actual problem (account_type), not a generic failure message');
    $csvAcct1 = Db::fetchOne('SELECT * FROM system_account WHERE application_id = :aid AND external_account_id = :ext', ['aid' => $app, 'ext' => 'csv-acct-1']);
    T::ok($csvAcct1 !== null && $csvAcct1['source'] === 'csv_import' && (int) $csvAcct1['connector_id'] === $csvConnector, 'the imported account is tagged source=csv_import and linked to the connector that discovered it');
    $assignmentCount = (int) Db::fetchValue(
        'SELECT COUNT(*) FROM entitlement_assignment ea JOIN system_account sa ON sa.id = ea.system_account_id WHERE sa.id = :id',
        ['id' => (int) $csvAcct1['id']]
    );
    T::eq(2, $assignmentCount, 'both pipe-separated entitlements were created and assigned to the first account');

    $csv2 = "external_account_id,status\ncsv-acct-1,disabled\n"; // re-import: same account, entitlements column omitted entirely
    $r2 = CsvImport::run($csvConnector, $csv2, null);
    T::eq(1, $r2['imported_accounts'], 'idempotent re-import updates the same account rather than creating a duplicate');
    $accountCountAfter = (int) Db::fetchValue('SELECT COUNT(*) FROM system_account WHERE application_id = :aid AND external_account_id = :ext', ['aid' => $app, 'ext' => 'csv-acct-1']);
    T::eq(1, $accountCountAfter, 'exactly one system_account row exists for this external_account_id after two imports — no duplicate from the UNIQUE-constraint upsert');
    $assignmentCountAfter = (int) Db::fetchValue(
        'SELECT COUNT(*) FROM entitlement_assignment ea JOIN system_account sa ON sa.id = ea.system_account_id WHERE sa.id = :id',
        ['id' => (int) $csvAcct1['id']]
    );
    T::eq(2, $assignmentCountAfter, 'ADD-ONLY design: omitting the entitlements column on a later import does NOT remove the assignments granted by an earlier one');

    $csvBadHeader = "wrong_column_name\nsomething\n";
    $r3 = CsvImport::run($csvConnector, $csvBadHeader, null);
    T::eq('failed', $r3['status'], 'a file missing the required external_account_id header fails immediately');
    T::eq(0, $r3['imported_accounts'], 'nothing is imported when the header itself is invalid');

    // --- Fixture: entitlement assignments for a certification campaign -----
    // $report's manager is $manager (set up in the reporting-chain fixture
    // above) — exactly what the 'manager' reviewer_strategy should resolve.
    $campEnt = Db::insert('entitlement', ['application_id' => $app, 'name' => 'Campaign Test Role ' . uniqid(), 'is_privileged' => true]);
    $campAcctMatched = Db::insert('system_account', ['application_id' => $app, 'person_id' => $report, 'external_account_id' => uniqid('camp-matched-')]);
    $campAcctUnmatched = Db::insert('system_account', ['application_id' => $app, 'person_id' => null, 'external_account_id' => uniqid('camp-unmatched-')]);
    Db::insert('entitlement_assignment', ['system_account_id' => $campAcctMatched, 'entitlement_id' => $campEnt]);
    Db::insert('entitlement_assignment', ['system_account_id' => $campAcctUnmatched, 'entitlement_id' => $campEnt]);

    T::group('Campaigns::create — scope snapshot + reviewer resolution');
    $launch = Campaigns::create([
        'name' => 'Test Privileged Review', 'scope_type' => 'privileged',
        'reviewer_strategy' => 'manager', 'default_reviewer_person_id' => $ceo,
    ], null);
    T::ok($launch['item_count'] >= 2, 'at least the two privileged fixture assignments were snapshotted');
    $matchedItem = Db::fetchOne('SELECT * FROM certification_campaign_item WHERE campaign_id = :cid AND system_account_id = :sa', ['cid' => $launch['id'], 'sa' => $campAcctMatched]);
    T::eq($manager, (int) $matchedItem['reviewer_person_id'], 'manager strategy resolves the reviewer to the account holder\'s actual manager, not the default');
    $unmatchedItem = Db::fetchOne('SELECT * FROM certification_campaign_item WHERE campaign_id = :cid AND system_account_id = :sa', ['cid' => $launch['id'], 'sa' => $campAcctUnmatched]);
    T::eq($ceo, (int) $unmatchedItem['reviewer_person_id'], 'manager strategy falls back to the campaign default reviewer for an unmatched account');

    $fixedLaunch = Campaigns::create([
        'name' => 'Test Fixed Review', 'scope_type' => 'application', 'scope_application_id' => $app,
        'reviewer_strategy' => 'fixed', 'default_reviewer_person_id' => $ceo,
    ], null);
    $fixedItem = Db::fetchOne('SELECT * FROM certification_campaign_item WHERE campaign_id = :cid AND system_account_id = :sa', ['cid' => $fixedLaunch['id'], 'sa' => $campAcctMatched]);
    T::eq($ceo, (int) $fixedItem['reviewer_person_id'], 'fixed strategy assigns the named reviewer regardless of the account holder\'s actual manager');
    try {
        Campaigns::create(['name' => 'x', 'scope_type' => 'bogus', 'reviewer_strategy' => 'fixed', 'default_reviewer_person_id' => $ceo], null);
        T::ok(false, 'an invalid scope_type must be rejected before any snapshot is attempted');
    } catch (\InvalidArgumentException $e) {
        T::ok(true, 'an invalid scope_type throws InvalidArgumentException rather than silently creating a malformed campaign');
    }

    T::group('Campaigns::decide — reviewer authorization, idempotency, and last_certified_at');
    try {
        Campaigns::decide((int) $matchedItem['id'], $report, 'approved', null, 1);
        T::ok(false, 'deciding as someone other than the assigned reviewer must throw');
    } catch (\RuntimeException $e) {
        T::ok(str_contains($e->getMessage(), 'not assigned'), 'the correct, specific error is thrown for a reviewer mismatch');
    }
    $beforeCert = Db::fetchValue('SELECT last_certified_at FROM entitlement_assignment WHERE system_account_id = :sa AND entitlement_id = :e', ['sa' => $campAcctMatched, 'e' => $campEnt]);
    T::ok($beforeCert === null, 'last_certified_at starts NULL — never certified yet');
    Campaigns::decide((int) $matchedItem['id'], $manager, 'approved', 'looks fine', 1);
    $afterCert = Db::fetchValue('SELECT last_certified_at FROM entitlement_assignment WHERE system_account_id = :sa AND entitlement_id = :e', ['sa' => $campAcctMatched, 'e' => $campEnt]);
    T::ok($afterCert !== null, 'approving a campaign item sets last_certified_at on the real entitlement_assignment row');
    try {
        Campaigns::decide((int) $matchedItem['id'], $manager, 'approved', null, 1);
        T::ok(false, 'deciding an already-decided item must throw');
    } catch (\RuntimeException $e) {
        T::ok(str_contains($e->getMessage(), 'already'), 'the correct, specific error is thrown for a double-decision attempt');
    }
    Campaigns::decide((int) $unmatchedItem['id'], $ceo, 'revoked', 'access no longer needed', 1);
    $unmatchedAssignmentStillExists = (int) Db::fetchValue('SELECT COUNT(*) FROM entitlement_assignment WHERE system_account_id = :sa AND entitlement_id = :e', ['sa' => $campAcctUnmatched, 'e' => $campEnt]);
    T::eq(1, $unmatchedAssignmentStillExists, 'a "revoked" decision records the judgment but does NOT delete the underlying assignment — this app has no connector that can push a revocation back to a source system');

    Campaigns::complete($launch['id'], null);
    $completed = Campaigns::get($launch['id']);
    T::eq('completed', $completed['status'], 'Campaigns::complete() marks the campaign completed');

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

    // --- Auth::isLoginThrottled — a real timezone bug fixed in this change
    // set: the threshold used to be computed in PHP (gmdate(), UTC) and
    // compared against a TIMESTAMPTZ column, which Postgres interprets a
    // naive/no-offset string against using the SESSION's timezone, not UTC —
    // silently pushing the cutoff hours into the future and making the
    // count always read 0 (never throttled, no matter how many failures).
    // Fixed by computing the interval inside the SQL itself (NOW() -
    // make_interval(...)), so there is no PHP/Postgres timezone boundary to
    // get wrong. These insert directly into audit_event (not via Audit::log,
    // to control the exact timestamps/targets precisely) inside the same
    // fixture transaction, so they roll back with everything else.
    T::group('Auth::isLoginThrottled — per-account and per-IP rate limiting');
    $throttleEmail = 'throttle-test-' . bin2hex(random_bytes(4)) . '@example.test';
    $throttleIp = '203.0.113.' . random_int(1, 254); // TEST-NET-3, RFC 5737 — guaranteed not a real client IP
    for ($i = 0; $i < Auth::MAX_FAILED_ATTEMPTS_PER_EMAIL - 1; $i++) {
        Db::insert('audit_event', ['action' => 'auth.login_failed', 'target' => $throttleEmail, 'ip' => $throttleIp, 'result' => 'denied']);
    }
    T::ok(!Auth::isLoginThrottled($throttleEmail), 'one under the threshold: not yet throttled');
    Db::insert('audit_event', ['action' => 'auth.login_failed', 'target' => $throttleEmail, 'ip' => $throttleIp, 'result' => 'denied']);
    T::ok(Auth::isLoginThrottled($throttleEmail), 'at the threshold: throttled');
    T::ok(!Auth::isLoginThrottled('someone-else-entirely@example.test'), 'a different, never-attempted account is NOT throttled — this is per-account, not global');
    // An old failure outside the window must not count towards the threshold.
    Db::query(
        "UPDATE audit_event SET created_at = NOW() - INTERVAL '1 hour' WHERE target = :t",
        ['t' => $throttleEmail]
    );
    T::ok(!Auth::isLoginThrottled($throttleEmail), 'failures outside the time window no longer count — the lockout is time-boxed, not permanent');

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

// --- Auth::user() live re-validation — a real bug fixed in this build ------
// Auth::user() used to return a snapshot cached in $_SESSION at login time;
// a permission/status change for an already-signed-in user had no effect
// until their next login. Like the DB_SCHEMA test above, this needs a
// genuinely fresh process: Auth's per-request cache (self::$requestUser) is
// a static property that would otherwise make a second in-process call
// return a stale cached answer regardless of what the first call saw.
T::group('Auth::user() — live re-validation of status on every request (not session-cached)');
$sessionTestEmail = 'test-session-' . bin2hex(random_bytes(4)) . '@example.test';
$sessionTestUserId = \Verity\Support\Users::create($sessionTestEmail, 'Test Session User', 'a-perfectly-fine-password-12', ['auditor'], null, null);

$probeAuthUser = function () use ($sessionTestUserId) {
    $script = implode("\n", [
        'require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . ';',
        'use Verity\Support\Auth;',
        '$_SESSION = $_SESSION ?? [];',
        '$_SESSION[\'user_id\'] = ' . $sessionTestUserId . ';',
        '$u = Auth::user();',
        'echo $u === null ? \'null\' : $u[\'status\'];',
    ]);
    $env = ['DATABASE_URL' => (string) getenv('DATABASE_URL')];
    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if ($process === false) {
        return 'proc_open failed';
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return trim($out);
};

T::eq('active', $probeAuthUser(), 'a fresh process with user_id in $_SESSION gets the live, current status back (active)');
\Verity\Support\Users::setStatus($sessionTestUserId, 'disabled', null);
T::eq('null', $probeAuthUser(), 'after the account is disabled, the VERY NEXT request (a fresh process, no re-login) sees it immediately — Auth::user() returns null rather than a stale cached "active"');

$terminatedEvent = Db::fetchOne(
    "SELECT after_value FROM audit_event WHERE action = 'auth.session_terminated' AND target = :t ORDER BY created_at DESC LIMIT 1",
    ['t' => 'app_user#' . $sessionTestUserId]
);
T::ok($terminatedEvent !== null, 'the live termination was itself audited (auth.session_terminated)');

Db::delete('app_user', ['id' => $sessionTestUserId]);
