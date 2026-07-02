<?php
declare(strict_types=1);

/**
 * Integration: policy attestation-overdue detection (Phase 17) against a live
 * Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the outstanding-attestation predicate — active campaign, due_date in the
 *       past, active user with NO policy_attestations row for the campaign's
 *       policy — selects exactly the pending users, excluding those who already
 *       attested and inactive users,
 *   (b) inactive campaigns and future-due campaigns are excluded,
 *   (c) deleting a policy cascades to its campaigns and attestations.
 *
 * The predicate CROSS JOINs all active users, so assertions are scoped to the
 * seeded 'pa-%@test.local' users.
 *
 * Usage: php tests/integration/policy_attestation_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[policy_attestation_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[policy_attestation_db] ok: $m\n"; }

// Idempotent cleanup (campaigns + attestations cascade from the policy).
Database::query("DELETE FROM policies WHERE title = 'PA Test Policy'");
Database::query("DELETE FROM users WHERE email IN ('pa-attested@test.local','pa-pending@test.local','pa-inactive@test.local')");

$uAttested = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('PA Attested','pa-attested@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$uPending  = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('PA Pending','pa-pending@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$uInactive = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('PA Inactive','pa-inactive@test.local','x','viewer',FALSE) RETURNING id")['id'] ?? 0);
if (!$uAttested || !$uPending || !$uInactive) fail('could not seed users');

$pid = (int) (Database::fetchOne("INSERT INTO policies (title,status) VALUES ('PA Test Policy','published') RETURNING id")['id'] ?? 0);
if (!$pid) fail('could not seed policy');

$mkCampaign = function (string $title, string $dueExpr, bool $active) use ($pid): int {
    return (int) (Database::fetchOne(
        "INSERT INTO policy_attestation_campaigns (policy_id,title,due_date,is_active)
         VALUES (?, ?, $dueExpr, ?::boolean) RETURNING id",
        [$pid, $title, $active ? 'true' : 'false']
    )['id'] ?? 0);
};

$cOver     = $mkCampaign('PA Overdue',  'CURRENT_DATE - 3',  true);   // active, past due -> outstanding users alerted
$cFuture   = $mkCampaign('PA Future',   'CURRENT_DATE + 20', true);   // future -> excluded
$cInactive = $mkCampaign('PA Inactive', 'CURRENT_DATE - 3',  false);  // inactive -> excluded
if (!$cOver || !$cFuture || !$cInactive) fail('could not seed campaigns');

// uAttested has attested to the policy; uPending has not.
Database::query("INSERT INTO policy_attestations (policy_id,user_id) VALUES (?, ?)", [$pid, $uAttested]);

// (a)+(b) outstanding predicate, scoped to seeded users.
$rows = Database::fetchAll(
    "SELECT pac.id AS campaign_id, u.id AS user_id
       FROM policy_attestation_campaigns pac
       JOIN policies p ON p.id = pac.policy_id
       CROSS JOIN users u
      WHERE pac.is_active = TRUE
        AND pac.due_date IS NOT NULL
        AND pac.due_date < CURRENT_DATE
        AND u.is_active = TRUE
        AND u.email LIKE 'pa-%@test.local'
        AND NOT EXISTS (
          SELECT 1 FROM policy_attestations pa
          WHERE pa.policy_id = pac.policy_id AND pa.user_id = u.id
        )
      ORDER BY pac.id, u.id",
    []
);
$got = array_map(fn($r) => [(int) $r['campaign_id'], (int) $r['user_id']], $rows);

$expected = [[$cOver, $uPending]];
if ($got !== $expected) {
    fail('attestation-overdue predicate mismatch: got ' . json_encode($got) . ' expected ' . json_encode($expected));
}
ok('predicate selects only the pending active user for the overdue active campaign');

// Explicit exclusion checks.
foreach ($got as [$camp, $usr]) {
    if ($camp === $cFuture)   fail('future-due campaign was selected');
    if ($camp === $cInactive) fail('inactive campaign was selected');
    if ($usr === $uAttested)  fail('already-attested user was selected');
    if ($usr === $uInactive)  fail('inactive user was selected');
}
ok('future/inactive campaigns, attested users and inactive users are excluded');

// (c) deleting the policy cascades to campaigns and attestations.
Database::query("DELETE FROM policies WHERE id = ?", [$pid]);
$campLeft = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM policy_attestation_campaigns WHERE policy_id = ?", [$pid])['c'] ?? -1);
$attLeft  = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM policy_attestations WHERE policy_id = ?", [$pid])['c'] ?? -1);
if ($campLeft !== 0 || $attLeft !== 0) fail("deleting policy did not cascade (campaigns={$campLeft}, attestations={$attLeft})");
ok('deleting a policy cascades to its campaigns and attestations');

// cleanup
Database::query("DELETE FROM users WHERE id IN (?,?,?)", [$uAttested, $uPending, $uInactive]);

echo "[policy_attestation_db] PASS\n";
