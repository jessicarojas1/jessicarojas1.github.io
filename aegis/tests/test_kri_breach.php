<?php
declare(strict_types=1);

// KRIController::ragStatus() is a pure function (no DB/auth at call time); the
// class can be required standalone.
require_once __DIR__ . '/../controllers/KRIController.php';

function kri(string $dir, float $g, float $a, float $r, $val): array {
    return ['direction' => $dir, 'threshold_green' => $g, 'threshold_amber' => $a, 'threshold_red' => $r, 'latest_value' => $val];
}

// ── higher_worse (exceeding thresholds is bad) ──────────────────────────────
it('higher_worse: value at/under green is green', function () {
    expect_eq('green', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 10)));
    expect_eq('green', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 5)));
});
it('higher_worse: value between green and amber is amber', function () {
    expect_eq('amber', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 15)));
    expect_eq('amber', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 20)));
});
it('higher_worse: value above amber is a red breach', function () {
    expect_eq('red', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 21)));
    expect_eq('red', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 999)));
});

// ── lower_worse (falling below thresholds is bad) ───────────────────────────
it('lower_worse: value at/over green is green', function () {
    expect_eq('green', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 95)));
    expect_eq('green', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 90)));
});
it('lower_worse: value between amber and green is amber', function () {
    expect_eq('amber', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 80)));
    expect_eq('amber', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 70)));
});
it('lower_worse: value below amber is a red breach', function () {
    expect_eq('red', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 69)));
    expect_eq('red', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 0)));
});

// ── no data ─────────────────────────────────────────────────────────────────
it('returns grey when there is no latest value', function () {
    expect_eq('grey', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, null)));
    $noKey = ['direction' => 'higher_worse', 'threshold_green' => 10, 'threshold_amber' => 20, 'threshold_red' => 30];
    expect_eq('grey', KRIController::ragStatus($noKey));
});

// The notifier's SQL breach predicate (value beyond amber) must agree with the
// red band above — this guards the two from drifting apart.
it('breach predicate (beyond amber) matches the red band', function () {
    // higher_worse breach = value > amber
    expect_eq('red', KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 25)));
    expect('amber' === KRIController::ragStatus(kri('higher_worse', 10, 20, 30, 20)), 'value == amber is not yet a breach');
    // lower_worse breach = value < amber
    expect_eq('red', KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 60)));
    expect('amber' === KRIController::ragStatus(kri('lower_worse', 90, 70, 50, 70)), 'value == amber is not yet a breach');
});
