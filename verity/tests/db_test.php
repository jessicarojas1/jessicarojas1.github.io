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
} finally {
    $pdo->rollBack();
}
