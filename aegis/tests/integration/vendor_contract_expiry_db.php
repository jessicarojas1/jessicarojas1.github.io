<?php
declare(strict_types=1);

/**
 * Integration: vendor contract renewal/expiry detection (Phase 14) against a
 * live Postgres.
 *
 * Proves at the DB layer that:
 *   (a) the predicate (status='active' AND end_date <= today + the contract's
 *       own renewal_notice_days) selects lapsed and within-notice contracts —
 *       and that the window is PER-CONTRACT: a 60-day-out contract is selected
 *       when its notice is 90 days but not when it is 30,
 *   (b) draft/expired/terminated contracts and far-future ones are excluded,
 *   (c) the owner join COALESCE(vc.owner_id, v.created_by) falls back to the
 *       vendor's creator when owner is null,
 *   (d) deleting a vendor cascades to its contracts.
 *
 * Usage: php tests/integration/vendor_contract_expiry_db.php   (requires DATABASE_URL)
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

function fail(string $m): never { fwrite(STDERR, "[vendor_contract_expiry_db] FAIL: $m\n"); exit(1); }
function ok(string $m): void { echo "[vendor_contract_expiry_db] ok: $m\n"; }

// Idempotent cleanup (contracts cascade from the vendor).
Database::query("DELETE FROM vendors WHERE name = 'Contract Expiry Test Vendor'");
Database::query("DELETE FROM users WHERE email IN ('vct-owner@test.local','vct-creator@test.local')");

$uOwner   = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('VCT Owner','vct-owner@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
$uCreator = (int) (Database::fetchOne("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('VCT Creator','vct-creator@test.local','x','viewer',TRUE) RETURNING id")['id'] ?? 0);
if (!$uOwner || !$uCreator) fail('could not seed owner/creator');

$vid = (int) (Database::fetchOne("INSERT INTO vendors (name,status,created_by) VALUES ('Contract Expiry Test Vendor','active',?) RETURNING id", [$uCreator])['id'] ?? 0);
if (!$vid) fail('could not seed vendor');

$mk = function (string $status, string $endExpr, int $noticeDays, ?int $owner) use ($vid): int {
    $sql = "INSERT INTO vendor_contracts (vendor_id,title,status,start_date,end_date,renewal_notice_days,owner_id)
            VALUES (?, 'Managed Services', ?, CURRENT_DATE - 400, $endExpr, ?, ?) RETURNING id";
    return (int) (Database::fetchOne($sql, [$vid, $status, $noticeDays, $owner])['id'] ?? 0);
};

$cLapsed = $mk('active',     'CURRENT_DATE - 3',  30, $uOwner);   // expired, owned          -> selected (owner)
$cDue30  = $mk('active',     'CURRENT_DATE + 10', 30, $uOwner);   // within 30d notice        -> selected (owner)
$cWide   = $mk('active',     'CURRENT_DATE + 60', 90, $uOwner);   // 60d out, 90d notice      -> selected (owner)
$cNarrow = $mk('active',     'CURRENT_DATE + 60', 30, $uOwner);   // 60d out, 30d notice      -> NOT selected
$cDraft  = $mk('draft',      'CURRENT_DATE - 3',  30, $uOwner);   // draft                    -> NOT selected
$cTerm   = $mk('terminated', 'CURRENT_DATE - 3',  30, $uOwner);   // terminated               -> NOT selected
$cFall   = $mk('active',     'CURRENT_DATE + 5',  30, null);      // due, no owner            -> selected (creator)
if (!$cLapsed || !$cDue30 || !$cWide || !$cNarrow || !$cDraft || !$cTerm || !$cFall) fail('could not seed contracts');

// (a)+(b)+(c) predicate with per-contract window + owner fallback.
$rows = Database::fetchAll(
    "SELECT vc.id, COALESCE(vc.owner_id, v.created_by) AS user_id
       FROM vendor_contracts vc
       JOIN vendors v ON v.id = vc.vendor_id
       JOIN users u ON u.id = COALESCE(vc.owner_id, v.created_by)
      WHERE vc.status = 'active'
        AND vc.end_date IS NOT NULL
        AND vc.end_date <= CURRENT_DATE + (COALESCE(vc.renewal_notice_days, 30) || ' days')::interval
        AND u.is_active = TRUE
        AND vc.vendor_id = ?
      ORDER BY vc.id",
    [$vid]
);

$got = [];
foreach ($rows as $r) { $got[(int) $r['id']] = (int) $r['user_id']; }

$expected = [$cLapsed => $uOwner, $cDue30 => $uOwner, $cWide => $uOwner, $cFall => $uCreator];
if ($got !== $expected) {
    fail('contract predicate/owner-join mismatch: got ' . json_encode($got) . ' expected ' . json_encode($expected));
}
ok('predicate honours the per-contract notice window and attributes each contract to its owner (creator fallback)');

foreach (['30-day notice, 60d out' => $cNarrow, 'draft' => $cDraft, 'terminated' => $cTerm] as $why => $cid) {
    if (isset($got[$cid])) fail("contract excluded for [{$why}] was selected (id {$cid})");
}
ok('narrow-notice, draft and terminated contracts are excluded');

// (d) deleting the vendor cascades to its contracts.
Database::query("DELETE FROM vendors WHERE id = ?", [$vid]);
$remaining = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM vendor_contracts WHERE vendor_id = ?", [$vid])['c'] ?? -1);
if ($remaining !== 0) fail("deleting vendor did not cascade ({$remaining} contracts remain)");
ok('deleting a vendor cascades to its contracts');

// cleanup
Database::query("DELETE FROM users WHERE id IN (?,?)", [$uOwner, $uCreator]);

echo "[vendor_contract_expiry_db] PASS\n";
