<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/RiskScore.php';

it('computes score as likelihood × impact', function () {
    expect_eq(9,  RiskScore::score(3, 3));
    expect_eq(25, RiskScore::score(5, 5));
    expect_eq(1,  RiskScore::score(1, 1));
});

it('clamps axes to 1..5', function () {
    expect_eq(25, RiskScore::score(9, 9), 'over-max not clamped');
    expect_eq(1,  RiskScore::score(0, 0), 'under-min not clamped');
    expect_eq(5,  RiskScore::score(1, 9), 'mixed clamp');
});

it('bands scores to the historical AEGIS levels', function () {
    expect_eq('low',      RiskScore::level(1));
    expect_eq('low',      RiskScore::level(4));
    expect_eq('medium',   RiskScore::level(5));
    expect_eq('medium',   RiskScore::level(9));
    expect_eq('high',     RiskScore::level(10));
    expect_eq('high',     RiskScore::level(14));
    expect_eq('critical', RiskScore::level(15));
    expect_eq('critical', RiskScore::level(25));
});

it('emits SQL predicates identical to the legacy inline conditions', function () {
    expect_eq('r.inherent_score > 14',            RiskScore::sqlCondition('critical', 'r.inherent_score'));
    expect_eq('r.inherent_score BETWEEN 10 AND 14', RiskScore::sqlCondition('high', 'r.inherent_score'));
    expect_eq('r.inherent_score BETWEEN 5 AND 9',   RiskScore::sqlCondition('medium', 'r.inherent_score'));
    expect_eq('r.inherent_score <= 4',              RiskScore::sqlCondition('low', 'r.inherent_score'));
    expect_eq('inherent_score > 14',                RiskScore::sqlCondition('critical'));
});

it('rejects an unsafe column identifier in sqlCondition', function () {
    $threw = false;
    try { RiskScore::sqlCondition('low', 'x; DROP TABLE risks'); }
    catch (InvalidArgumentException) { $threw = true; }
    expect($threw, 'unsafe column was not rejected');
});

it('rejects an unknown level in sqlCondition', function () {
    $threw = false;
    try { RiskScore::sqlCondition('extreme'); }
    catch (InvalidArgumentException) { $threw = true; }
    expect($threw, 'unknown level was not rejected');
});

it('derives inherent/residual/target from a risk row', function () {
    $risk = [
        'likelihood' => 4, 'impact' => 5,
        'residual_likelihood' => 2, 'residual_impact' => 3,
        'target_likelihood' => 1, 'target_impact' => 2,
    ];
    expect_eq(20, RiskScore::inherent($risk));
    expect_eq(6,  RiskScore::residual($risk));
    expect_eq(2,  RiskScore::target($risk));
});

it('returns null residual/target when axes are unset', function () {
    expect(RiskScore::residual(['likelihood' => 3, 'impact' => 3]) === null, 'residual should be null');
    expect(RiskScore::target(['likelihood' => 3, 'impact' => 3]) === null, 'target should be null');
});

it('falls back to stored scores when axes absent', function () {
    expect_eq(12, RiskScore::inherent(['inherent_score' => 12]));
    expect_eq(8,  RiskScore::residual(['residual_score' => 8]));
});

it('detects appetite breaches', function () {
    expect(RiskScore::exceedsAppetite(15, 10), 'should breach');
    expect(!RiskScore::exceedsAppetite(10, 10), 'equal is not a breach');
    expect(!RiskScore::exceedsAppetite(20, null), 'null appetite never breaches');
});

it('maps levels to labels and CSS variable colors (no hex)', function () {
    expect_eq('Critical', RiskScore::label('critical'));
    expect(str_starts_with(RiskScore::colorVar('high'), 'var(--'), 'color must be a CSS var');
});
