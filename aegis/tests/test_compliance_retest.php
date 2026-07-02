<?php
declare(strict_types=1);

// ComplianceController::retestStatus() is a pure function (truthiness + date
// math); the class can be required standalone.
require_once __DIR__ . '/../controllers/ComplianceController.php';

function rd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: latest next-test date has passed ───────────────────────────────
it('re-test is overdue when the next test date is in the past', function () {
    expect_eq('overdue', ComplianceController::retestStatus(rd(-1)));
    expect_eq('overdue', ComplianceController::retestStatus(rd(-90)));
});

// ── due: next test date within the 30-day window ────────────────────────────
it('re-test is due when the next test date is today or within 30 days', function () {
    expect_eq('due', ComplianceController::retestStatus(rd(0)));    // today counts as due, not overdue
    expect_eq('due', ComplianceController::retestStatus(rd(29)));
});

// ── ok: next test date comfortably in the future ────────────────────────────
it('re-test is ok when the next test date is more than 30 days out', function () {
    expect_eq('ok', ComplianceController::retestStatus(rd(30)));
    expect_eq('ok', ComplianceController::retestStatus(rd(365)));
});

// ── none: no / unparseable next test date ───────────────────────────────────
it('re-test status is none when there is no usable next test date', function () {
    expect_eq('none', ComplianceController::retestStatus(null));
    expect_eq('none', ComplianceController::retestStatus(''));
    expect_eq('none', ComplianceController::retestStatus('not-a-date'));
});

// The notifier's SQL predicate (next_test_date < CURRENT_DATE) selects exactly
// the 'overdue' bucket — guard drift between helper and query.
it('only overdue maps to the notifier predicate; due/ok do not', function () {
    expect_eq('overdue', ComplianceController::retestStatus(rd(-2)));  // notified
    expect_eq('due',     ComplianceController::retestStatus(rd(2)));   // shown, not notified
    expect_eq('ok',      ComplianceController::retestStatus(rd(60)));  // neither
});
