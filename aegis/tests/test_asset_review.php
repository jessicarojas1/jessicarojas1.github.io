<?php
declare(strict_types=1);

// AssetController::reviewStatus() is a pure function (status guard + date math);
// the class loads standalone for its static methods.
require_once __DIR__ . '/../controllers/AssetController.php';

function asrd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: last review older than the annual interval ─────────────────────
it('an asset last reviewed over a year ago is overdue', function () {
    expect_eq('overdue', AssetController::reviewStatus(asrd(-370), 'active', asrd(-800)));
    expect_eq('overdue', AssetController::reviewStatus(asrd(-366), 'maintenance', asrd(-800)));
});

// ── due: within 30 days of the interval ─────────────────────────────────────
it('an asset nearing its annual review is due', function () {
    expect_eq('due', AssetController::reviewStatus(asrd(-340), 'active', asrd(-800))); // 340 >= 365-30=335, <=365
    expect_eq('due', AssetController::reviewStatus(asrd(-365), 'active', asrd(-800)));
});

// ── ok: reviewed recently ───────────────────────────────────────────────────
it('a recently reviewed asset is ok', function () {
    expect_eq('ok', AssetController::reviewStatus(asrd(-30),  'active', asrd(-800)));
    expect_eq('ok', AssetController::reviewStatus(asrd(-200), 'active', asrd(-800)));
});

// ── never reviewed: judged from the creation date ───────────────────────────
it('a never-reviewed asset is judged from its creation date', function () {
    expect_eq('overdue', AssetController::reviewStatus(null, 'active', asrd(-400))); // created >1y ago, never reviewed
    expect_eq('ok',      AssetController::reviewStatus(null, 'active', asrd(-10)));   // freshly created
    expect_eq('ok',      AssetController::reviewStatus(null, 'active', null));        // no baseline -> ok
});

// ── none: decommissioned assets never need review ───────────────────────────
it('decommissioned assets are never overdue', function () {
    expect_eq('none', AssetController::reviewStatus(asrd(-500), 'decommissioned', asrd(-800)));
    expect_eq('none', AssetController::reviewStatus(null, 'decommissioned', asrd(-800)));
});

// The notifier's SQL predicate (status <> decommissioned AND elapsed > 365)
// selects exactly the 'overdue' bucket — guard drift.
it('only overdue non-decommissioned assets map to the notifier predicate', function () {
    $overdue = fn($last, $status, $created) => AssetController::reviewStatus($last, $status, $created) === 'overdue';
    expect_eq(true,  $overdue(asrd(-400), 'active', asrd(-800)));         // notified
    expect_eq(false, $overdue(asrd(-400), 'decommissioned', asrd(-800)));// decommissioned excludes it
    expect_eq(false, $overdue(asrd(-340), 'active', asrd(-800)));        // due, not yet overdue
});
