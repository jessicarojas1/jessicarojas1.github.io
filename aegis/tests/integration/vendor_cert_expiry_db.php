<?php
declare(strict_types=1);

/**
 * Integration: vendor certification expiry detection (Phase 13) against a live
 * Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the expiry predicate (status = 'active' AND expiry_date <= today+30)
 *       selects only the active certs that are lapsed or expiring within 30
 *       days — ignoring future-dated, revoked and pending certs,
 *   (b) the owner join COALESCE(vc.owner_id, v.created_by) attributes each cert
 *       to its owner, falling back to the vendor's creator when owner is null,
 *   (c) deleting a vendor cascades to its certifications.
 *
 * Usage: php tests/integration/vendor_cert_expiry_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[vendor_cert_expiry_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[vendor_cert_expiry_db] ok: $m\n"; }

// Idempotent cleanup (certs cascade from the vendor).
Database::query("DELETE FROM vendors WHERE name = 'VC Expiry Test Vendor'");
Database::query("DELETE FROM users WHERE email IN ('vc-owner@test.local','vc-creator@test.local')");

// Owner + creator (both active).
$uOwner   = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('VC Owner','vc-owner@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$uCreator = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('VC Creator','vc-creator@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$uOwner || !$uCreator) fail('could not seed owner/creator');

$vid = (int) (Database::fetchOne("INSERT INTO vendors (name,status,created_by) VALUES ('VC Expiry Test Vendor','active',?) RETURNING id", [$uCreator])['id'] ?? 0);
if (!$vid) fail('could not seed vendor');

$mk = function (string $status, string $expiryExpr, ?int $owner) use ($vid): int {
    $sql = "INSERT INTO vendor_certifications (vendor_id,certification_type,status,expiry_date,owner_id)
            VALUES (?, 'ISO 27001', ?, $expiryExpr, ?) RETURNING id";
    return (int) (Database::fetchOne($sql, [$vid, $status, $owner])['id'] ?? 0);
};

$c1 = $mk('active',  'CURRENT_DATE - 3',  $uOwner);   // lapsed, owned        -> selected (owner)
$c2 = $mk('active',  'CURRENT_DATE + 10', $uOwner);   // expiring soon, owned -> selected (owner)
$c3 = $mk('active',  'CURRENT_DATE + 60', $uOwner);   // far future           -> NOT selected
$c4 = $mk('revoked', 'CURRENT_DATE - 3',  $uOwner);   // revoked              -> NOT selected
$c5 = $mk('pending', 'CURRENT_DATE - 3',  $uOwner);   // pending              -> NOT selected
$c6 = $mk('active',  'CURRENT_DATE - 3',  null);      // lapsed, no owner     -> selected (creator fallback)
if (!$c1 || !$c2 || !$c3 || !$c4 || !$c5 || !$c6) fail('could not seed certifications');

// (a)+(b) predicate + owner-fallback join.
$rows = Database::fetchAll(
    "SELECT vc.id, COALESCE(vc.owner_id, v.created_by) AS user_id
       FROM vendor_certifications vc
       JOIN vendors v ON v.id = vc.vendor_id
       JOIN users u ON u.id = COALESCE(vc.owner_id, v.created_by)
      WHERE vc.status = 'active'
        AND vc.expiry_date IS NOT NULL
        AND vc.expiry_date <= CURRENT_DATE + INTERVAL '30 days'
        AND u.is_active = TRUE
        AND vc.vendor_id = ?
      ORDER BY vc.id",
    [$vid]
);

$got = [];
foreach ($rows as $r) { $got[(int) $r['id']] = (int) $r['user_id']; }

$expected = [$c1 => $uOwner, $c2 => $uOwner, $c6 => $uCreator];
if (count($got) !== 3 || $got !== $expected) {
    fail('expiry predicate/owner-join mismatch: got ' . json_encode($got) . ' expected ' . json_encode($expected));
}
ok('predicate selects only lapsed/expiring active certs and attributes each to its owner (creator fallback)');

// Explicitly confirm the excluded certs are absent.
foreach (['future' => $c3, 'revoked' => $c4, 'pending' => $c5] as $why => $cid) {
    if (isset($got[$cid])) fail("cert excluded for being {$why} was selected (id {$cid})");
}
ok('future-dated, revoked and pending certs are excluded');

// (c) deleting the vendor cascades to its certifications.
Database::query("DELETE FROM vendors WHERE id = ?", [$vid]);
$remaining = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM vendor_certifications WHERE vendor_id = ?", [$vid])['c'] ?? -1);
if ($remaining !== 0) fail("deleting vendor did not cascade ({$remaining} certs remain)");
ok('deleting a vendor cascades to its certifications');

// cleanup
Database::query("DELETE FROM users WHERE id IN (?,?)", [$uOwner, $uCreator]);

echo "[vendor_cert_expiry_db] PASS\n";
