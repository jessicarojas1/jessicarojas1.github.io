<?php
declare(strict_types=1);

// EvidenceController::freshness() is a pure function (strtotime/time only); the
// class can be required standalone since Auth/Security/Database are referenced
// only inside other methods, resolved at call time.
require_once __DIR__ . '/../controllers/EvidenceController.php';

// ── Evidence freshness (Phase 3 lifecycle) ──────────────────────────────────
it('treats a null/empty expiry as no expiry tracked', function () {
    expect_eq('none', EvidenceController::freshness(null));
    expect_eq('none', EvidenceController::freshness(''));
    expect_eq('none', EvidenceController::freshness('not-a-date'));
});

it('flags an expiry in the past as expired', function () {
    $past = date('Y-m-d H:i:s', time() - 86400);
    expect_eq('expired', EvidenceController::freshness($past));
});

it('flags an expiry within 30 days as expiring', function () {
    $soon = date('Y-m-d H:i:s', time() + 10 * 86400);
    expect_eq('expiring', EvidenceController::freshness($soon));
});

it('treats an expiry more than 30 days out as valid', function () {
    $far = date('Y-m-d H:i:s', time() + 90 * 86400);
    expect_eq('valid', EvidenceController::freshness($far));
});

it('uses a 30-day boundary for the expiring window', function () {
    // Just inside 30 days → expiring; well beyond → valid.
    $inside = date('Y-m-d H:i:s', time() + 29 * 86400);
    $beyond = date('Y-m-d H:i:s', time() + 31 * 86400);
    expect_eq('expiring', EvidenceController::freshness($inside));
    expect_eq('valid',    EvidenceController::freshness($beyond));
});
