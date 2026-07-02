<?php
declare(strict_types=1);

// VendorController::contractRenewalStatus() is a pure function (status guard +
// date math honouring the per-contract notice window); the class requires no DB
// to load its static methods.
require_once __DIR__ . '/../controllers/VendorController.php';

function nd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── expired: active contract past its end date ──────────────────────────────
it('an active contract past its end date is expired', function () {
    expect_eq('expired', VendorController::contractRenewalStatus(nd(-1), 'active', 30));
    expect_eq('expired', VendorController::contractRenewalStatus(nd(-100), 'active', 90));
});

// ── due: end date within the contract's own notice window ───────────────────
it('a contract within its renewal-notice window is due', function () {
    expect_eq('due', VendorController::contractRenewalStatus(nd(0),  'active', 30));  // ends today
    expect_eq('due', VendorController::contractRenewalStatus(nd(29), 'active', 30));
});

// ── the notice window is per-contract, not a fixed 30 days ──────────────────
it('the notice window honours renewal_notice_days', function () {
    // 60 days out: inside a 90-day notice window (due) but outside a 30-day one (ok).
    expect_eq('due', VendorController::contractRenewalStatus(nd(60), 'active', 90));
    expect_eq('ok',  VendorController::contractRenewalStatus(nd(60), 'active', 30));
    // Null/zero notice days fall back to the 30-day default.
    expect_eq('ok',  VendorController::contractRenewalStatus(nd(45), 'active', null));
    expect_eq('ok',  VendorController::contractRenewalStatus(nd(45), 'active', 0));
    expect_eq('due', VendorController::contractRenewalStatus(nd(20), 'active', null));
});

// ── ok: end date beyond the notice window ───────────────────────────────────
it('a contract beyond its notice window is ok', function () {
    expect_eq('ok', VendorController::contractRenewalStatus(nd(31),  'active', 30));
    expect_eq('ok', VendorController::contractRenewalStatus(nd(400), 'active', 90));
});

// ── none: non-active status or missing/unparseable end date ─────────────────
it('non-active contracts and missing end dates are none', function () {
    expect_eq('none', VendorController::contractRenewalStatus(nd(-1), 'draft', 30));
    expect_eq('none', VendorController::contractRenewalStatus(nd(-1), 'expired', 30));
    expect_eq('none', VendorController::contractRenewalStatus(nd(-1), 'terminated', 30));
    expect_eq('none', VendorController::contractRenewalStatus(null, 'active', 30));
    expect_eq('none', VendorController::contractRenewalStatus('', 'active', 30));
    expect_eq('none', VendorController::contractRenewalStatus('not-a-date', 'active', 30));
});

// The notifier's SQL predicate (status='active' AND end_date <= today+notice)
// selects exactly the expired + due buckets — guard drift.
it('only expired + due active contracts fall inside the notifier window', function () {
    $alerted = fn($d, $n) => in_array(VendorController::contractRenewalStatus($d, 'active', $n), ['expired','due'], true);
    expect_eq(true,  $alerted(nd(-3), 30));  // lapsed -> alert
    expect_eq(true,  $alerted(nd(10), 30));  // within notice -> alert
    expect_eq(true,  $alerted(nd(60), 90));  // within a wider notice -> alert
    expect_eq(false, $alerted(nd(60), 30));  // outside notice -> no alert
});
