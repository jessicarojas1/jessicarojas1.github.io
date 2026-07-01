<?php
declare(strict_types=1);

/**
 * Integration: KRI measurement-overdue detection (Phase 15) against a live
 * Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the overdue predicate — (today - COALESCE(last recorded, created::date))
 *       greater than the frequency window (daily 1 / weekly 7 / monthly 31 /
 *       quarterly 92) — selects only active, owned KRIs that are behind on
 *       measurement, honouring the per-KRI cadence,
 *   (b) a never-measured KRI is judged from its creation date,
 *   (c) recently-measured, inactive and owner-less KRIs are excluded,
 *   (d) deleting a KRI cascades to its recorded values.
 *
 * Usage: php tests/integration/kri_measurement_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[kri_measurement_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[kri_measurement_db] ok: $m\n"; }

// Idempotent cleanup (values cascade from the KRI).
Database::query("DELETE FROM kris WHERE title LIKE 'KMO %'");
Database::query("DELETE FROM users WHERE email = 'kmo-owner@test.local'");

$owner = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('KMO Owner','kmo-owner@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$owner) fail('could not seed owner');

// Helper: create a KRI with a given frequency/owner/active flag and creation age.
$mkKri = function (string $title, string $freq, ?int $ownerId, bool $active, int $createdDaysAgo): int {
    return (int) (Database::fetchOne(
        "INSERT INTO kris (title,unit,direction,threshold_green,threshold_amber,threshold_red,frequency,owner_id,is_active,created_at)
         VALUES (?, 'count','higher_worse',10,20,30,?,?,?, NOW() - (? || ' days')::interval) RETURNING id",
        [$title, $freq, $ownerId, $active, $createdDaysAgo]
    )['id'] ?? 0);
};
$rec = function (int $kriId, int $daysAgo): void {
    Database::query("INSERT INTO kri_values (kri_id,value,recorded_at) VALUES (?, 15, CURRENT_DATE - ?)", [$kriId, $daysAgo]);
};

// monthly KRI, last recorded 40 days ago -> OVERDUE
$kOver = $mkKri('KMO Overdue Monthly', 'monthly', $owner, true, 400); $rec($kOver, 40);
// weekly KRI, last recorded 3 days ago -> OK (within 7)
$kOk   = $mkKri('KMO Fresh Weekly', 'weekly', $owner, true, 400);     $rec($kOk, 3);
// monthly KRI, never recorded, created 50 days ago -> OVERDUE (baseline = created)
$kNever = $mkKri('KMO Never Monthly', 'monthly', $owner, true, 50);
// monthly KRI, never recorded, created 3 days ago -> OK (brand new)
$kNew  = $mkKri('KMO New Monthly', 'monthly', $owner, true, 3);
// inactive KRI, last recorded 90 days ago -> excluded (inactive)
$kInact = $mkKri('KMO Inactive', 'monthly', $owner, false, 400);      $rec($kInact, 90);
// active but owner-less, last recorded 90 days ago -> excluded (no owner)
$kNoOwner = $mkKri('KMO No Owner', 'monthly', null, true, 400);       $rec($kNoOwner, 90);
if (!$kOver || !$kOk || !$kNever || !$kNew || !$kInact || !$kNoOwner) fail('could not seed KRIs');

// (a)-(c) overdue predicate joined to the owner.
$rows = Database::fetchAll(
    "SELECT k.id
       FROM kris k
       JOIN users u ON u.id = k.owner_id
       LEFT JOIN LATERAL (
         SELECT kv.recorded_at FROM kri_values kv WHERE kv.kri_id = k.id
         ORDER BY kv.recorded_at DESC, kv.id DESC LIMIT 1
       ) latest ON TRUE
      WHERE k.is_active = TRUE
        AND u.is_active = TRUE
        AND (CURRENT_DATE - COALESCE(latest.recorded_at, k.created_at::date))
            > CASE k.frequency
                WHEN 'daily' THEN 1 WHEN 'weekly' THEN 7
                WHEN 'monthly' THEN 31 WHEN 'quarterly' THEN 92 ELSE 31 END
        AND k.title LIKE 'KMO %'
      ORDER BY k.id",
    []
);
$got = array_map(fn($r) => (int) $r['id'], $rows);
sort($got);
$expected = [$kOver, $kNever];
sort($expected);
if ($got !== $expected) {
    fail('overdue predicate mismatch: got ' . json_encode($got) . ' expected ' . json_encode($expected));
}
ok('predicate selects only overdue active owned KRIs (incl. never-measured judged from creation date)');

foreach (['fresh' => $kOk, 'brand-new' => $kNew, 'inactive' => $kInact, 'owner-less' => $kNoOwner] as $why => $id) {
    if (in_array($id, $got, true)) fail("KRI excluded for being {$why} was selected (id {$id})");
}
ok('fresh, brand-new, inactive and owner-less KRIs are excluded');

// (d) deleting a KRI cascades to its recorded values.
$before = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM kri_values WHERE kri_id = ?", [$kOver])['c'] ?? -1);
if ($before < 1) fail("expected seeded value for KRI {$kOver}");
Database::query("DELETE FROM kris WHERE id = ?", [$kOver]);
$after = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM kri_values WHERE kri_id = ?", [$kOver])['c'] ?? -1);
if ($after !== 0) fail("deleting KRI did not cascade ({$after} values remain)");
ok('deleting a KRI cascades to its recorded values');

// cleanup
Database::query("DELETE FROM kris WHERE title LIKE 'KMO %'");
Database::query("DELETE FROM users WHERE id = ?", [$owner]);

echo "[kri_measurement_db] PASS\n";
