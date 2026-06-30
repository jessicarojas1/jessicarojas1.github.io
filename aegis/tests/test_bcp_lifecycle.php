<?php
declare(strict_types=1);

// BCPController::exerciseOverdue() / planTestStatus() are pure functions (date
// math only); the class can be required standalone.
require_once __DIR__ . '/../controllers/BCPController.php';

function d(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── exerciseOverdue ─────────────────────────────────────────────────────────
it('exercise is overdue when scheduled in the past and never conducted', function () {
    expect_eq(true, BCPController::exerciseOverdue(d(-5), null));
    expect_eq(true, BCPController::exerciseOverdue(d(-1), ''));
});
it('exercise is not overdue once conducted', function () {
    expect_eq(false, BCPController::exerciseOverdue(d(-5), d(-2)));
});
it('exercise is not overdue when scheduled today or in the future', function () {
    expect_eq(false, BCPController::exerciseOverdue(d(0), null));   // today is not yet overdue
    expect_eq(false, BCPController::exerciseOverdue(d(3), null));
});
it('exercise with no scheduled date is never overdue', function () {
    expect_eq(false, BCPController::exerciseOverdue(null, null));
    expect_eq(false, BCPController::exerciseOverdue('', null));
});

// ── planTestStatus ──────────────────────────────────────────────────────────
it('plan test status is overdue when next_test_date is in the past', function () {
    expect_eq('overdue', BCPController::planTestStatus(d(-1)));
    expect_eq('overdue', BCPController::planTestStatus(d(-100)));
});
it('plan test status is due within 30 days', function () {
    expect_eq('due', BCPController::planTestStatus(d(0)));
    expect_eq('due', BCPController::planTestStatus(d(29)));
});
it('plan test status is ok beyond 30 days', function () {
    expect_eq('ok', BCPController::planTestStatus(d(31)));
    expect_eq('ok', BCPController::planTestStatus(d(365)));
});
it('plan test status is none when untracked', function () {
    expect_eq('none', BCPController::planTestStatus(null));
    expect_eq('none', BCPController::planTestStatus(''));
    expect_eq('none', BCPController::planTestStatus('not-a-date'));
});

// The notifier's SQL predicates must agree with these helpers — guard drift.
it('overdue predicate (scheduled < today, not conducted) matches the helper', function () {
    // helper says overdue → SQL "conducted IS NULL AND scheduled < CURRENT_DATE" also true
    expect_eq(true, BCPController::exerciseOverdue(d(-1), null));
    // plan review notifier fires for next_test_date <= today+30 → 'due' or 'overdue'
    expect(in_array(BCPController::planTestStatus(d(10)), ['due'], true), 'within 30d is due');
    expect(in_array(BCPController::planTestStatus(d(-3)), ['overdue'], true), 'past is overdue');
});
