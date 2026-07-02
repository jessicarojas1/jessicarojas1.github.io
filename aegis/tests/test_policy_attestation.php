<?php
declare(strict_types=1);

// PolicyController::attestationCampaignStatus() is a pure function (active guard
// + date math); the class loads standalone for its static methods.
require_once __DIR__ . '/../controllers/PolicyController.php';

function pad(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── overdue: active campaign past its due date ──────────────────────────────
it('an active campaign past its due date is overdue', function () {
    expect_eq('overdue', PolicyController::attestationCampaignStatus(pad(-1),  true));
    expect_eq('overdue', PolicyController::attestationCampaignStatus(pad(-60), true));
});

// ── due: due within the next 14 days ────────────────────────────────────────
it('a campaign due within 14 days is due', function () {
    expect_eq('due', PolicyController::attestationCampaignStatus(pad(0),  true)); // today counts as due, not overdue
    expect_eq('due', PolicyController::attestationCampaignStatus(pad(13), true));
});

// ── ok: due comfortably in the future ───────────────────────────────────────
it('a campaign due more than 14 days out is ok', function () {
    expect_eq('ok', PolicyController::attestationCampaignStatus(pad(14),  true));
    expect_eq('ok', PolicyController::attestationCampaignStatus(pad(120), true));
});

// ── none: inactive campaign or no due date ──────────────────────────────────
it('inactive campaigns and campaigns without a due date are none', function () {
    expect_eq('none', PolicyController::attestationCampaignStatus(pad(-30), false)); // inactive, even if past due
    expect_eq('none', PolicyController::attestationCampaignStatus(null, true));
    expect_eq('none', PolicyController::attestationCampaignStatus('', true));
    expect_eq('none', PolicyController::attestationCampaignStatus('not-a-date', true));
});

// The notifier's SQL predicate (is_active AND due_date < today) selects exactly
// the 'overdue' bucket — guard drift.
it('only overdue active campaigns map to the notifier predicate', function () {
    expect_eq('overdue', PolicyController::attestationCampaignStatus(pad(-2), true));  // notified
    expect_eq('none',    PolicyController::attestationCampaignStatus(pad(-2), false)); // inactive excludes it
    expect_eq('due',     PolicyController::attestationCampaignStatus(pad(2), true));   // shown, not notified
});
