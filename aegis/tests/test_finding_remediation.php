<?php
declare(strict_types=1);

// AuditFindingController::remediationStatus() is a pure function (status guard +
// date math); the class can be required standalone.
require_once __DIR__ . '/../controllers/AuditFindingController.php';

function fd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: deadline passed while still open ───────────────────────────────
it('remediation is overdue when the deadline passed and the finding is still open', function () {
    expect_eq('overdue', AuditFindingController::remediationStatus(fd(-1), 'open'));
    expect_eq('overdue', AuditFindingController::remediationStatus(fd(-30), 'in_progress'));
    expect_eq('overdue', AuditFindingController::remediationStatus(fd(-5), 'reopened'));
});

// ── due: deadline within the next 14 days ───────────────────────────────────
it('remediation is due when the deadline is today or within 14 days', function () {
    expect_eq('due', AuditFindingController::remediationStatus(fd(0), 'open'));   // today counts as due, not overdue
    expect_eq('due', AuditFindingController::remediationStatus(fd(13), 'open'));
});

// ── ok: deadline comfortably in the future ──────────────────────────────────
it('remediation is ok when the deadline is more than 14 days out', function () {
    expect_eq('ok', AuditFindingController::remediationStatus(fd(14), 'open'));
    expect_eq('ok', AuditFindingController::remediationStatus(fd(90), 'in_progress'));
});

// ── none: settled statuses never chase a deadline ───────────────────────────
it('settled findings (closed/resolved/risk_accepted) are never overdue', function () {
    expect_eq('none', AuditFindingController::remediationStatus(fd(-30), 'closed'));
    expect_eq('none', AuditFindingController::remediationStatus(fd(-30), 'resolved'));
    expect_eq('none', AuditFindingController::remediationStatus(fd(-30), 'risk_accepted'));
});

// ── none: no / unparseable deadline ─────────────────────────────────────────
it('remediation status is none when there is no usable deadline', function () {
    expect_eq('none', AuditFindingController::remediationStatus(null, 'open'));
    expect_eq('none', AuditFindingController::remediationStatus('', 'open'));
    expect_eq('none', AuditFindingController::remediationStatus('not-a-date', 'open'));
});

// The notifier's SQL predicate (deadline < CURRENT_DATE AND status NOT IN the
// terminal set) selects exactly the 'overdue' bucket — guard drift.
it('only overdue open findings map to the notifier predicate', function () {
    expect_eq('overdue', AuditFindingController::remediationStatus(fd(-2), 'open'));          // notified
    expect_eq('none',    AuditFindingController::remediationStatus(fd(-2), 'risk_accepted')); // terminal excludes it
    expect_eq('due',     AuditFindingController::remediationStatus(fd(2), 'open'));           // shown, not notified
});
