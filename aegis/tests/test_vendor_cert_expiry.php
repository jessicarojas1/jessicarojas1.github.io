<?php
declare(strict_types=1);

// Phase 13 (vendor certification expiry) reuses EvidenceController::freshness()
// for the vendor-view "needs renewal" badge, and the notifier's SQL predicate
// (status='active' AND expiry_date <= today+30) must agree with it. These tests
// pin that shared contract at the boundaries the cert alert/badge depend on, so
// a change to freshness() breaks loudly here rather than silently mis-alerting.
require_once __DIR__ . '/../controllers/EvidenceController.php';

function cd(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── lapsed: expiry today or earlier -> 'expired' (freshness compares to now,
//    so today's midnight is already past). Badge counts it; notifier alerts. ──
it('a past or same-day expiry date is classified expired (cert needs renewal)', function () {
    expect_eq('expired', EvidenceController::freshness(cd(-1)));
    expect_eq('expired', EvidenceController::freshness(cd(-90)));
    expect_eq('expired', EvidenceController::freshness(cd(0)));  // today midnight < now
});

// ── expiring: within the next 30 days -> 'expiring' (badge counts; alerts) ────
it('an expiry within the next 30 days is classified expiring', function () {
    expect_eq('expiring', EvidenceController::freshness(cd(1)));
    expect_eq('expiring', EvidenceController::freshness(cd(29)));
});

// ── valid: more than 30 days out -> not alerted, not badged ──────────────────
it('an expiry more than 30 days out is valid (no alert, no badge)', function () {
    expect_eq('valid', EvidenceController::freshness(cd(31)));
    expect_eq('valid', EvidenceController::freshness(cd(365)));
});

// ── none: missing/unparseable expiry -> excluded everywhere ──────────────────
it('a missing or unparseable expiry is none (excluded)', function () {
    expect_eq('none', EvidenceController::freshness(null));
    expect_eq('none', EvidenceController::freshness(''));
    expect_eq('none', EvidenceController::freshness('not-a-date'));
});

// The notifier's 30-day window == the freshness buckets that trigger renewal.
it('exactly the expired + expiring buckets fall inside the notifier window', function () {
    $alerted = fn(string $d) => in_array(EvidenceController::freshness($d), ['expired','expiring'], true);
    expect_eq(true,  $alerted(cd(-5)));  // lapsed -> alert
    expect_eq(true,  $alerted(cd(20)));  // expiring soon -> alert
    expect_eq(false, $alerted(cd(45)));  // far future -> no alert
});
