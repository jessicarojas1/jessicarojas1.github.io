<?php
declare(strict_types=1);

// IncidentController::slaStatus() is a pure function (time/strtotime only); the
// class can be required standalone.
require_once __DIR__ . '/../controllers/IncidentController.php';

function ago(int $hours): string { return date('Y-m-d H:i:s', time() - $hours * 3600); }

// ── No policy / no start → n/a ──────────────────────────────────────────────
it('returns n/a when no SLA hours or no start time', function () {
    expect_eq('n/a', IncidentController::slaStatus(null, null, 24));
    expect_eq('n/a', IncidentController::slaStatus(ago(5), null, null));
    expect_eq('n/a', IncidentController::slaStatus(ago(5), null, 0));
});

// ── Event recorded → met (regardless of timing) ─────────────────────────────
it('returns met when the SLA event has occurred', function () {
    expect_eq('met', IncidentController::slaStatus(ago(100), ago(90), 24));
    expect_eq('met', IncidentController::slaStatus(ago(2), ago(1), 24));
});

// ── On track (well within window) ───────────────────────────────────────────
it('returns on_track when well within the SLA window', function () {
    expect_eq('on_track', IncidentController::slaStatus(ago(1), null, 24));
    expect_eq('on_track', IncidentController::slaStatus(ago(10), null, 24)); // ~42% elapsed
});

// ── At risk (>75% elapsed, not yet breached) ────────────────────────────────
it('returns at_risk past 75% of the window', function () {
    expect_eq('at_risk', IncidentController::slaStatus(ago(19), null, 24)); // ~79%
    expect_eq('at_risk', IncidentController::slaStatus(ago(23), null, 24)); // ~96%
});

// ── Breached (deadline passed, no event) ────────────────────────────────────
it('returns breached once the deadline has passed with no event', function () {
    expect_eq('breached', IncidentController::slaStatus(ago(25), null, 24));
    expect_eq('breached', IncidentController::slaStatus(ago(500), null, 72));
});

// The notifier's SQL breach predicate (created_at + resolve_hours < NOW(), no
// resolved event) must agree with this 'breached' result — guards drift.
it('breach predicate matches the breached band', function () {
    // exactly past the deadline → breached
    expect_eq('breached', IncidentController::slaStatus(ago(73), null, 72));
    // just inside the deadline → not breached
    expect('breached' !== IncidentController::slaStatus(ago(71), null, 72), 'inside deadline is not a breach');
});
