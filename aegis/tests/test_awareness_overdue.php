<?php
declare(strict_types=1);

// AwarenessController::assignmentOverdue() is a pure function (truthiness + date
// math); the class can be required standalone.
require_once __DIR__ . '/../controllers/AwarenessController.php';

function ad(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: incomplete + program due date in the past ──────────────────────
it('assignment is overdue when incomplete and the program due date has passed', function () {
    expect_eq(true, AwarenessController::assignmentOverdue(false, ad(-1)));
    expect_eq(true, AwarenessController::assignmentOverdue(0, ad(-30)));
});

// ── not overdue: completed regardless of date ───────────────────────────────
it('completed assignments are never overdue', function () {
    expect_eq(false, AwarenessController::assignmentOverdue(true, ad(-30)));
    expect_eq(false, AwarenessController::assignmentOverdue(1, ad(-1)));
});

// ── not overdue: due today or in the future ─────────────────────────────────
it('assignment is not overdue when the due date is today or in the future', function () {
    expect_eq(false, AwarenessController::assignmentOverdue(false, ad(0)));   // today is not yet overdue
    expect_eq(false, AwarenessController::assignmentOverdue(false, ad(7)));
});

// ── not overdue: no program due date ────────────────────────────────────────
it('assignment with no program due date is never overdue', function () {
    expect_eq(false, AwarenessController::assignmentOverdue(false, null));
    expect_eq(false, AwarenessController::assignmentOverdue(false, ''));
});

// The notifier's SQL predicate (completed = FALSE AND due_date < CURRENT_DATE)
// must agree with this helper — guard drift.
it('overdue predicate matches the helper across completion and date', function () {
    expect_eq(true,  AwarenessController::assignmentOverdue(false, ad(-2)));
    expect_eq(false, AwarenessController::assignmentOverdue(true,  ad(-2)));  // completion excludes it
    expect_eq(false, AwarenessController::assignmentOverdue(false, ad(2)));   // date excludes it
});
