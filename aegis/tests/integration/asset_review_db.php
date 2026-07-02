<?php
declare(strict_types=1);

/**
 * Integration: asset review-overdue detection (Phase 19) against a live Postgres.
 *
 * Proves at the DB layer that the annual-review predicate — non-decommissioned
 * asset, (today - COALESCE(last_reviewed, created::date)) > 365, active owner —
 * selects only the assets that are genuinely overdue for review, judging
 * never-reviewed assets from their creation date, and excluding recently
 * reviewed, decommissioned, owner-less and inactive-owner assets.
 *
 * (The asset -> asset_risk_links cascade is guaranteed by its ON DELETE CASCADE
 * FK; seeding a risk would require tenant/RLS setup, so it is not re-tested here.)
 *
 * Usage: php tests/integration/asset_review_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[asset_review_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[asset_review_db] ok: $m\n"; }

// Idempotent cleanup.
Database::query("DELETE FROM assets WHERE name LIKE 'AR %'");
Database::query("DELETE FROM users WHERE email IN ('ar-owner@test.local','ar-inactive@test.local')");

$owner    = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('AR Owner','ar-owner@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$inactive = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('AR Inactive','ar-inactive@test.local','x','viewer',FALSE) RETURNING id")['id'] ?? 0);
if (!$owner || !$inactive) fail('could not seed owner/inactive users');

// last_reviewed and created_at are set via explicit expressions; $reviewedExpr is
// 'NULL' for never-reviewed assets. Integer date offsets are cast to avoid the
// unknown-parameter typing pitfall.
$mk = function (string $name, string $status, string $reviewedExpr, string $createdExpr, ?int $ownerId): int {
    return (int) (Database::fetchOne(
        "INSERT INTO assets (name, asset_type, status, last_reviewed, created_at, owner_id)
         VALUES (?, 'server', ?, $reviewedExpr, $createdExpr, ?) RETURNING id",
        [$name, $status, $ownerId]
    )['id'] ?? 0);
};

$aOver    = $mk('AR Overdue',       'active',         'CURRENT_DATE - (400::integer)', 'NOW() - (800 || \' days\')::interval', $owner);   // reviewed 400d ago -> overdue
$aNever   = $mk('AR Never',         'active',         'NULL',                          'NOW() - (500 || \' days\')::interval', $owner);   // never reviewed, created 500d ago -> overdue
$aFresh   = $mk('AR Fresh',         'active',         'CURRENT_DATE - (30::integer)',  'NOW() - (800 || \' days\')::interval', $owner);   // reviewed 30d ago -> ok
$aDecom   = $mk('AR Decommissioned','decommissioned', 'CURRENT_DATE - (400::integer)', 'NOW() - (800 || \' days\')::interval', $owner);   // decommissioned -> excluded
$aInact   = $mk('AR Inactive Owner','active',         'CURRENT_DATE - (400::integer)', 'NOW() - (800 || \' days\')::interval', $inactive);// inactive owner -> excluded
$aNoOwner = $mk('AR No Owner',      'active',         'CURRENT_DATE - (400::integer)', 'NOW() - (800 || \' days\')::interval', null);     // no owner -> excluded
if (!$aOver || !$aNever || !$aFresh || !$aDecom || !$aInact || !$aNoOwner) fail('could not seed assets');

$rows = Database::fetchAll(
    "SELECT a.id
       FROM assets a
       JOIN users u ON u.id = a.owner_id
      WHERE a.status <> 'decommissioned'
        AND (CURRENT_DATE - COALESCE(a.last_reviewed, a.created_at::date)) > 365
        AND u.is_active = TRUE
        AND a.name LIKE 'AR %'
      ORDER BY a.id",
    []
);
$got = array_map(fn($r) => (int) $r['id'], $rows);
sort($got);
$expected = [$aOver, $aNever];
sort($expected);
if ($got !== $expected) {
    fail('asset review predicate mismatch: got ' . json_encode($got) . ' expected ' . json_encode($expected));
}
ok('predicate selects only overdue active owned assets (never-reviewed judged from creation date)');

foreach (['fresh' => $aFresh, 'decommissioned' => $aDecom, 'inactive-owner' => $aInact, 'owner-less' => $aNoOwner] as $why => $id) {
    if (in_array($id, $got, true)) fail("asset excluded for being {$why} was selected (id {$id})");
}
ok('recently reviewed, decommissioned, inactive-owner and owner-less assets are excluded');

// cleanup
Database::query("DELETE FROM assets WHERE name LIKE 'AR %'");
Database::query("DELETE FROM users WHERE id IN (?,?)", [$owner, $inactive]);

echo "[asset_review_db] PASS\n";
