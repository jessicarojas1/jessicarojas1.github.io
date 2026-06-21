<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/DueStatus.php';

// Fixed reference "now" so tests are deterministic: 2026-06-17T12:00:00Z.
$now = strtotime('2026-06-17 12:00:00 UTC');

it('classifies a past date as overdue', function () use ($now) {
    expect_eq(DueStatus::OVERDUE, DueStatus::classify('2026-06-10', false, 7, $now));
});

it('treats due-today as due-soon (imminent), never overdue', function () use ($now) {
    expect_eq(DueStatus::DUE_SOON, DueStatus::classify('2026-06-17', false, 7, $now));
    expect(!DueStatus::isOverdue('2026-06-17', false, $now), 'due today must not be overdue');
});

it('classifies within the window as due-soon', function () use ($now) {
    expect_eq(DueStatus::DUE_SOON, DueStatus::classify('2026-06-20', false, 7, $now));
});

it('classifies beyond the window as on-track', function () use ($now) {
    expect_eq(DueStatus::ON_TRACK, DueStatus::classify('2026-07-30', false, 7, $now));
});

it('returns complete when the item is closed regardless of date', function () use ($now) {
    expect_eq(DueStatus::COMPLETE, DueStatus::classify('2020-01-01', true, 7, $now));
});

it('returns none for missing/blank/unparseable dates', function () use ($now) {
    expect_eq(DueStatus::NONE, DueStatus::classify(null, false, 7, $now));
    expect_eq(DueStatus::NONE, DueStatus::classify('', false, 7, $now));
    expect_eq(DueStatus::NONE, DueStatus::classify('not-a-date', false, 7, $now));
});

it('computes signed days until due', function () use ($now) {
    expect_eq(3,  DueStatus::daysUntil('2026-06-20', $now));
    expect_eq(-7, DueStatus::daysUntil('2026-06-10', $now));
    expect(DueStatus::daysUntil(null, $now) === null, 'null date should give null');
});

it('buckets overdue items for aging dashboards', function () use ($now) {
    expect_eq('0-30',  DueStatus::agingBucket('2026-06-01', false, $now)); // 16 days
    expect_eq('31-60', DueStatus::agingBucket('2026-05-10', false, $now)); // 38 days
    expect_eq('61-90', DueStatus::agingBucket('2026-04-10', false, $now)); // 68 days
    expect_eq('90+',   DueStatus::agingBucket('2026-01-01', false, $now));
    expect(DueStatus::agingBucket('2026-12-31', false, $now) === null, 'future not bucketed');
    expect(DueStatus::agingBucket('2020-01-01', true, $now) === null, 'completed not bucketed');
});

it('exposes labels and CSS-var colors (no hex)', function () {
    expect_eq('Overdue', DueStatus::label(DueStatus::OVERDUE));
    expect(str_starts_with(DueStatus::colorVar(DueStatus::DUE_SOON), 'var(--'), 'color must be a CSS var');
});

it('isOverdue convenience matches classify', function () use ($now) {
    expect(DueStatus::isOverdue('2026-06-10', false, $now), 'should be overdue');
    expect(!DueStatus::isOverdue('2026-06-10', true, $now), 'completed not overdue');
});
