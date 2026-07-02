<?php
declare(strict_types=1);

// AuditController::scheduleStatus() is a pure function (status guard + date
// math); the class loads standalone for its static methods.
require_once __DIR__ . '/../controllers/AuditController.php';

function sd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: scheduled date passed, audit still open ────────────────────────
it('an open audit past its scheduled date is overdue', function () {
    expect_eq('overdue', AuditController::scheduleStatus(sd(-1),  'planned', null));
    expect_eq('overdue', AuditController::scheduleStatus(sd(-30), 'in_progress', null));
    expect_eq('overdue', AuditController::scheduleStatus(sd(-5),  'overdue', null));
});

// ── due: scheduled within the next 14 days ──────────────────────────────────
it('an audit scheduled within 14 days is due', function () {
    expect_eq('due', AuditController::scheduleStatus(sd(0),  'planned', null)); // today counts as due, not overdue
    expect_eq('due', AuditController::scheduleStatus(sd(13), 'planned', null));
});

// ── ok: scheduled comfortably in the future ─────────────────────────────────
it('an audit scheduled more than 14 days out is ok', function () {
    expect_eq('ok', AuditController::scheduleStatus(sd(14),  'planned', null));
    expect_eq('ok', AuditController::scheduleStatus(sd(120), 'in_progress', null));
});

// ── none: settled status, completed, or no scheduled date ───────────────────
it('completed/cancelled audits and completed-date audits are never overdue', function () {
    expect_eq('none', AuditController::scheduleStatus(sd(-30), 'completed', null));
    expect_eq('none', AuditController::scheduleStatus(sd(-30), 'cancelled', null));
    expect_eq('none', AuditController::scheduleStatus(sd(-30), 'in_progress', sd(-2))); // has a completed_date
    expect_eq('none', AuditController::scheduleStatus(null, 'planned', null));
    expect_eq('none', AuditController::scheduleStatus('', 'planned', null));
    expect_eq('none', AuditController::scheduleStatus('not-a-date', 'planned', null));
});

// The notifier's SQL predicate (scheduled_date < today AND completed_date IS
// NULL AND status NOT IN completed/cancelled) selects exactly the 'overdue'
// bucket — guard drift.
it('only overdue open audits map to the notifier predicate', function () {
    expect_eq('overdue', AuditController::scheduleStatus(sd(-2), 'planned', null));      // notified
    expect_eq('none',    AuditController::scheduleStatus(sd(-2), 'completed', null));    // terminal excludes it
    expect_eq('none',    AuditController::scheduleStatus(sd(-2), 'in_progress', sd(-1)));// completed_date excludes it
    expect_eq('due',     AuditController::scheduleStatus(sd(2), 'planned', null));       // shown, not notified
});
