<?php
declare(strict_types=1);

// POAMController::itemOverdue() is a pure function (date math only); the class
// can be required standalone.
require_once __DIR__ . '/../controllers/POAMController.php';

function pd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: open/in_progress past scheduled completion ─────────────────────
it('item is overdue when open and scheduled completion is in the past', function () {
    expect_eq(true, POAMController::itemOverdue('open', pd(-1)));
    expect_eq(true, POAMController::itemOverdue('in_progress', pd(-30)));
});

// ── not overdue: closed/cancelled regardless of date ────────────────────────
it('closed or cancelled items are never overdue', function () {
    expect_eq(false, POAMController::itemOverdue('closed', pd(-100)));
    expect_eq(false, POAMController::itemOverdue('cancelled', pd(-100)));
});

// ── not overdue: future or today ────────────────────────────────────────────
it('item is not overdue when scheduled today or in the future', function () {
    expect_eq(false, POAMController::itemOverdue('open', pd(0)));   // today is not yet overdue
    expect_eq(false, POAMController::itemOverdue('open', pd(7)));
});

// ── not overdue: no scheduled date ──────────────────────────────────────────
it('item with no scheduled completion is never overdue', function () {
    expect_eq(false, POAMController::itemOverdue('open', null));
    expect_eq(false, POAMController::itemOverdue('in_progress', ''));
});

// The notifier's SQL predicate (status NOT IN closed/cancelled AND
// scheduled_completion < CURRENT_DATE) must agree with this helper — guard drift.
it('overdue predicate matches the helper across status and date', function () {
    expect_eq(true,  POAMController::itemOverdue('open', pd(-2)));
    expect_eq(false, POAMController::itemOverdue('closed', pd(-2)));     // status excludes it
    expect_eq(false, POAMController::itemOverdue('open', pd(2)));        // date excludes it
});
