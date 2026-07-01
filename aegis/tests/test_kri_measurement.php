<?php
declare(strict_types=1);

// KRIController::measurementWindowDays() and measurementStatus() are pure
// (frequency map + date math); the class loads standalone for its statics.
require_once __DIR__ . '/../controllers/KRIController.php';

function md(int $daysFromToday): string { return date('Y-m-d', strtotime('today') + $daysFromToday * 86400); }

// ── window map: each cadence maps to its day count ──────────────────────────
it('measurement window maps each frequency to a day count', function () {
    expect_eq(1,  KRIController::measurementWindowDays('daily'));
    expect_eq(7,  KRIController::measurementWindowDays('weekly'));
    expect_eq(31, KRIController::measurementWindowDays('monthly'));
    expect_eq(92, KRIController::measurementWindowDays('quarterly'));
    expect_eq(31, KRIController::measurementWindowDays('unknown')); // defaults to monthly
});

// ── overdue: last reading older than the cadence window ─────────────────────
it('a KRI whose last reading predates its window is overdue', function () {
    expect_eq('overdue', KRIController::measurementStatus('monthly', md(-40), md(-400)));
    expect_eq('overdue', KRIController::measurementStatus('weekly',  md(-8),  md(-400)));
    expect_eq('overdue', KRIController::measurementStatus('daily',   md(-2),  md(-400)));
});

// ── due: within the last 20% of the window ──────────────────────────────────
it('a KRI nearing the end of its window is due', function () {
    expect_eq('due', KRIController::measurementStatus('monthly', md(-28), md(-400))); // 28 >= ceil(31*0.8)=25, <=31
    expect_eq('due', KRIController::measurementStatus('quarterly', md(-80), md(-400))); // 80 >= ceil(92*0.8)=74
});

// ── ok: recently measured, comfortably inside the window ────────────────────
it('a recently measured KRI is ok', function () {
    expect_eq('ok', KRIController::measurementStatus('monthly', md(-5),  md(-400)));
    expect_eq('ok', KRIController::measurementStatus('quarterly', md(-10), md(-400)));
});

// ── never measured: falls back to the creation date as baseline ─────────────
it('a never-measured KRI is judged from its creation date', function () {
    expect_eq('overdue', KRIController::measurementStatus('monthly', null, md(-40))); // created 40d ago, never measured
    expect_eq('ok',      KRIController::measurementStatus('monthly', null, md(-3)));  // brand-new KRI, not yet due
    expect_eq('ok',      KRIController::measurementStatus('monthly', null, null));    // no baseline at all -> ok
});

// The notifier's SQL predicate (elapsed > window) selects exactly the overdue
// bucket — guard drift between helper and query.
it('only overdue KRIs fall inside the notifier predicate', function () {
    $overdue = fn($freq, $last, $created) => KRIController::measurementStatus($freq, $last, $created) === 'overdue';
    expect_eq(true,  $overdue('monthly', md(-40), md(-400))); // notified
    expect_eq(false, $overdue('monthly', md(-28), md(-400))); // due, not yet overdue -> not notified
    expect_eq(false, $overdue('monthly', md(-5),  md(-400))); // ok -> not notified
});
