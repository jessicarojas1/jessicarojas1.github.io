<?php

declare(strict_types=1);

/**
 * VERITY — synthetic at-scale performance benchmark.
 *
 * Generates a synthetic dataset near this platform's stated design target
 * (OPEN_ITEMS.md: "100k-account/1M-assignment") directly via set-based SQL
 * (no PHP-level insert loop — far too slow at this scale), then times the
 * real Matrix/Authorize query code every view in the app actually runs
 * through, including an EXPLAIN ANALYZE of the heaviest one.
 *
 * DESTRUCTIVE. Always TRUNCATEs every table it touches before generating
 * fresh data, unconditionally. This exists to be pointed at a disposable,
 * dedicated benchmark database — never a real development or production
 * one. Refuses to run against APP_ENV=production (same convention as
 * seed.php) and refuses to run at all without --confirm.
 *
 * Usage:
 *   DATABASE_URL=postgresql://user@127.0.0.1:5432/verity_benchmark \
 *     php database/benchmark.php --confirm
 *
 * Optional overrides (defaults hit the ~100k-account/~1M-assignment target):
 *   --people=20000 --applications=50 --entitlements-per-app=20
 *   --accounts=100000 --assignment-fraction=0.5
 *   (assignment-fraction is the sampling rate over the full account x
 *   same-application-entitlement cross join — at the defaults that cross
 *   join has accounts * entitlements-per-app = 2,000,000 candidate rows, so
 *   0.5 yields ~1,000,000 assignments.)
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Verity\Support\Authorize;
use Verity\Support\Config;
use Verity\Support\Db;
use Verity\Support\Matrix;

function arg(array $argv, string $name, string $default): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$name}=")) {
            return substr($a, strlen("--{$name}="));
        }
    }
    return $default;
}

function ms(float $seconds): string
{
    return number_format($seconds * 1000, 1) . 'ms';
}

/** Run $fn $iterations times after one warmup run; report min/avg/max. */
function timeIt(string $label, int $iterations, callable $fn): void
{
    $fn(); // warmup — excluded, so a first-call connection/plan-cache cost doesn't skew results
    $samples = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $fn();
        $samples[] = microtime(true) - $start;
    }
    sort($samples);
    $min = $samples[0];
    $max = $samples[count($samples) - 1];
    $avg = array_sum($samples) / count($samples);
    printf("  %-55s min %-9s avg %-9s max %-9s (n=%d)\n", $label, ms($min), ms($avg), ms($max), $iterations);
}

if (!Db::isConfigured()) {
    fwrite(STDERR, "DATABASE_URL is not configured.\n");
    exit(1);
}
if (Config::env() === 'production') {
    fwrite(STDERR, "Refusing to run the benchmark generator against APP_ENV=production.\n");
    exit(1);
}
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "This DESTROYS existing data in person/application/system_account/entitlement/\n");
    fwrite(STDERR, "entitlement_assignment (and everything that cascades from them) before\n");
    fwrite(STDERR, "generating a large synthetic dataset. Point DATABASE_URL at a disposable,\n");
    fwrite(STDERR, "dedicated benchmark database — never a real dev or production one — then\n");
    fwrite(STDERR, "re-run with --confirm.\n");
    exit(1);
}

$people = (int) arg($argv, 'people', '20000');
$applications = (int) arg($argv, 'applications', '50');
$entitlementsPerApp = (int) arg($argv, 'entitlements-per-app', '20');
$accounts = (int) arg($argv, 'accounts', '100000');
$assignmentFraction = (float) arg($argv, 'assignment-fraction', '0.5');

$pdo = Db::connection();

echo "=== VERITY performance benchmark ===\n";
echo "Target scale: {$people} people, {$applications} applications, " .
    "{$entitlementsPerApp} entitlements/app, {$accounts} accounts, " .
    "~" . number_format($accounts * $entitlementsPerApp * $assignmentFraction) . " assignments\n\n";

echo "Wiping existing data...\n";
$pdo->exec(
    'TRUNCATE audit_event, dynamic_field_value, dynamic_field_definition, identity_account_link, '
    . 'entitlement_assignment, entitlement, system_account, connector_sync_job, connector, '
    . 'application, person_relationship, person RESTART IDENTITY CASCADE'
);

$t0 = microtime(true);

echo "Generating {$people} people (with a synthetic management hierarchy)...\n";
$pdo->exec("
    INSERT INTO person (employee_id, first_name, last_name, display_name, email, department, employment_status, manager_person_id)
    SELECT
        'EMP' || gs,
        'First' || gs,
        'Last' || gs,
        'Synthetic Person ' || gs,
        'person' || gs || '@benchmark.local',
        (ARRAY['Engineering','Finance','Sales','Operations','Legal','HR','IT'])[1 + (gs % 7)],
        CASE WHEN gs % 37 = 0 THEN 'terminated' WHEN gs % 23 = 0 THEN 'on_leave' ELSE 'active' END,
        -- LEAST(...) guards a real floating-point boundary case: random() can
        -- round (gs-1)*random() up to exactly gs-1 as a double, which would
        -- otherwise produce manager_person_id = gs (a self-reference) for a
        -- handful of rows out of tens of thousands. Caught live by this exact
        -- generator before this guard existed — see Authorize.php's cycle-
        -- safety fix and CLAUDE.md for the full story.
        CASE WHEN gs = 1 THEN NULL WHEN random() < 0.95 THEN LEAST(gs - 1, (random() * (gs - 1))::bigint + 1) ELSE NULL END
    FROM generate_series(1, {$people}) AS gs
    ORDER BY gs
");

echo "Generating {$applications} applications...\n";
$pdo->exec("
    INSERT INTO application (name, description, classification, system_owner_person_id, status)
    SELECT
        'Benchmark App ' || gs,
        'Synthetic application generated for the performance benchmark.',
        (ARRAY['public','internal','confidential','restricted'])[1 + (gs % 4)],
        (random() * ({$people} - 1))::bigint + 1,
        'active'
    FROM generate_series(1, {$applications}) AS gs
    ORDER BY gs
");

echo "Generating " . ($applications * $entitlementsPerApp) . " entitlements...\n";
$pdo->exec("
    INSERT INTO entitlement (application_id, name, entitlement_type, is_privileged, risk_level)
    SELECT
        app_id,
        'Entitlement ' || n,
        (ARRAY['role','group','permission','license'])[1 + (n % 4)],
        (n % 10 = 0),
        (ARRAY['low','medium','high','critical'])[1 + (n % 4)]
    FROM generate_series(1, {$applications}) AS app_id
    CROSS JOIN generate_series(1, {$entitlementsPerApp}) AS n
");

echo "Generating {$accounts} system accounts...\n";
$pdo->exec("
    INSERT INTO system_account (application_id, person_id, external_account_id, username, account_type, status)
    SELECT
        1 + (gs % {$applications}),
        CASE WHEN random() < 0.7 THEN (random() * ({$people} - 1))::bigint + 1 ELSE NULL END,
        'acct-' || gs,
        'user' || gs,
        CASE WHEN gs % 15 = 0 THEN 'privileged' WHEN gs % 40 = 0 THEN 'service' ELSE 'standard' END,
        CASE WHEN gs % 20 = 0 THEN 'disabled' ELSE 'enabled' END
    FROM generate_series(1, {$accounts}) AS gs
    ORDER BY gs
");

echo "Generating entitlement assignments (sampling the account x entitlement cross join at {$assignmentFraction})...\n";
$assignStart = microtime(true);
$pdo->exec("
    INSERT INTO entitlement_assignment (system_account_id, entitlement_id, assignment_type, granted_at, expires_at)
    SELECT
        sa.id,
        e.id,
        (ARRAY['direct','inherited','privileged','temporary','external'])[1 + (sa.id % 5)],
        NOW() - ((random() * 365)::int || ' days')::interval,
        CASE WHEN random() < 0.1 THEN NOW() - ((random() * 30)::int || ' days')::interval ELSE NULL END
    FROM system_account sa
    JOIN entitlement e ON e.application_id = sa.application_id
    WHERE random() < {$assignmentFraction}
");
$assignSeconds = microtime(true) - $assignStart;

$totalSeconds = microtime(true) - $t0;
$actualAssignments = (int) Db::fetchValue('SELECT COUNT(*) FROM entitlement_assignment');
echo "\nData generation complete in " . ms($totalSeconds) . " (assignment step alone: " . ms($assignSeconds) . ").\n";
echo "Actual row counts: " . Db::fetchValue('SELECT COUNT(*) FROM person') . " people, "
    . Db::fetchValue('SELECT COUNT(*) FROM system_account') . " accounts, "
    . "{$actualAssignments} assignments.\n\n";

echo "Running ANALYZE so the planner has real statistics (matches production practice after a bulk load)...\n";
$pdo->exec('ANALYZE person, application, entitlement, system_account, entitlement_assignment');

echo "\n=== Query timings (Matrix::query / Authorize — the actual app code, not reimplemented SQL) ===\n";

timeIt('Enterprise view, page 1 (no filter)', 10, static fn () => Matrix::query(['view' => 'enterprise'], 50, 0));
timeIt('Enterprise view, deep page (offset 50000)', 10, static fn () => Matrix::query(['view' => 'enterprise'], 50, 50000));
timeIt('Enterprise view, free-text search (ILIKE)', 10, static fn () => Matrix::query(['view' => 'enterprise', 'search' => 'Synthetic'], 50, 0));
timeIt('Privileged view', 10, static fn () => Matrix::query(['view' => 'privileged'], 50, 0));
timeIt('Exception view (unmatched/disabled/expired)', 10, static fn () => Matrix::query(['view' => 'exception'], 50, 0));

// Pick a real supervisor to benchmark rather than an arbitrary id — the
// synthetic hierarchy is random, so a fixed id can land on a leaf with zero
// reports and tell us nothing. Low ids are structurally more likely to have
// large subtrees (every later person independently has a chance to pick
// them as manager), so sample a few and keep the one with the most reports.
$bestPersonId = 1;
$bestReports = [];
foreach ([1, 2, 3, 5, 10, 25, 50, 100] as $candidate) {
    if ($candidate > $people) {
        continue;
    }
    $candidateReports = Authorize::reportsOf($candidate);
    if (count($candidateReports) > count($bestReports)) {
        $bestPersonId = $candidate;
        $bestReports = $candidateReports;
    }
}
echo "Sampled supervisor id {$bestPersonId} (" . count($bestReports) . "-person reporting chain) for the timed queries below.\n";
timeIt("Authorize::reportsOf (recursive CTE, {$bestPersonId}-person chain)", 20, static fn () => Authorize::reportsOf($bestPersonId));
if ($bestReports !== []) {
    // buildWhere()'s 'supervisor' case binds one named SQL parameter PER
    // person id in the chain — capped here at 500 so an unusually large
    // sampled chain doesn't make this one benchmark step dominate the whole
    // run. If the real reporting chain is routinely much larger than this in
    // production, that per-id-parameter binding approach is itself worth
    // benchmarking deliberately at that size — this script reports the real
    // chain size above either way, so the gap is visible, not hidden.
    $supervisorSampleIds = array_slice($bestReports, 0, 500);
    $cappedNote = count($bestReports) > 500 ? ' (capped from ' . count($bestReports) . ')' : '';
    timeIt('Supervisor view (' . count($supervisorSampleIds) . "-person IN-list{$cappedNote})", 10, static fn () => Matrix::query(['view' => 'supervisor', 'person_ids' => $supervisorSampleIds], 50, 0));
}

timeIt('CSV export query shape (5,000-row cap, no pagination)', 5, static function () {
    [$rows] = [Matrix::query(['view' => 'enterprise'], 5000, 0)['rows']];
    return $rows;
});

echo "\n=== EXPLAIN ANALYZE: enterprise view, no filter (the heaviest unfiltered query) ===\n";
$plan = Db::fetchAll(
    'EXPLAIN (ANALYZE, BUFFERS) SELECT sa.id, p.display_name, a.name, e.name
     FROM system_account sa
     JOIN application a ON a.id = sa.application_id
     LEFT JOIN person p ON p.id = sa.person_id
     LEFT JOIN entitlement_assignment ea ON ea.system_account_id = sa.id
     LEFT JOIN entitlement e ON e.id = ea.entitlement_id
     ORDER BY p.display_name ASC NULLS LAST, sa.id LIMIT 50 OFFSET 0'
);
foreach ($plan as $row) {
    echo '  ' . reset($row) . "\n";
}

echo "\nDone. Copy the numbers above into OPEN_ITEMS.md / docs/ARCHITECTURE.md — do not\n";
echo "hand-wave a conclusion; the actual min/avg/max and query plan are the evidence.\n";
