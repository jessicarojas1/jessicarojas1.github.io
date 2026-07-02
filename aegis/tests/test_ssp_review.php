<?php
declare(strict_types=1);

// SSPController::reviewStatus() is a pure function (date math); the class loads
// standalone for its static methods.
require_once __DIR__ . '/../controllers/SSPController.php';

function sspd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: next review date has passed ────────────────────────────────────
it('an SSP whose next review date has passed is overdue', function () {
    expect_eq('overdue', SSPController::reviewStatus(sspd(-1)));
    expect_eq('overdue', SSPController::reviewStatus(sspd(-180)));
});

// ── due: next review within 30 days ─────────────────────────────────────────
it('an SSP due for review within 30 days is due', function () {
    expect_eq('due', SSPController::reviewStatus(sspd(0)));   // today counts as due, not overdue
    expect_eq('due', SSPController::reviewStatus(sspd(29)));
});

// ── ok: next review comfortably in the future ───────────────────────────────
it('an SSP with a review date more than 30 days out is ok', function () {
    expect_eq('ok', SSPController::reviewStatus(sspd(30)));
    expect_eq('ok', SSPController::reviewStatus(sspd(365)));
});

// ── none: missing / unparseable review date ─────────────────────────────────
it('an SSP with no usable review date is none', function () {
    expect_eq('none', SSPController::reviewStatus(null));
    expect_eq('none', SSPController::reviewStatus(''));
    expect_eq('none', SSPController::reviewStatus('not-a-date'));
});

// The notifier's SQL predicate (next_review_date < today) selects exactly the
// 'overdue' bucket — guard drift.
it('only overdue SSPs map to the notifier predicate', function () {
    expect_eq('overdue', SSPController::reviewStatus(sspd(-2))); // notified
    expect_eq('due',     SSPController::reviewStatus(sspd(2)));  // shown, not notified
    expect_eq('ok',      SSPController::reviewStatus(sspd(60))); // neither
});
