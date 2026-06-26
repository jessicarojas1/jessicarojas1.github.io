<?php
declare(strict_types=1);

// KRIController::ragStatus() is pure logic (no DB) — classifies a KRI's latest
// value as green/amber/red per its direction + thresholds. Exercised here via
// reflection to lock in the RAG behaviour the dashboards and board pack rely on.
require_once __DIR__ . '/../controllers/KRIController.php';

function kri_rag(array $kri): string {
    $m = new ReflectionMethod('KRIController', 'ragStatus');
    $m->setAccessible(true);
    return (string) $m->invoke(null, $kri);
}

it('RAG is grey when no value has been recorded', function () {
    expect_eq('grey', kri_rag([
        'direction' => 'higher_worse', 'threshold_green' => 10,
        'threshold_amber' => 20, 'threshold_red' => 30, 'latest_value' => null,
    ]));
});

it('higher_worse: value classified against ascending thresholds', function () {
    $b = ['direction' => 'higher_worse', 'threshold_green' => 10, 'threshold_amber' => 20, 'threshold_red' => 30];
    expect_eq('green', kri_rag($b + ['latest_value' => 5]),  'below green is green');
    expect_eq('green', kri_rag($b + ['latest_value' => 10]), 'at green boundary is green');
    expect_eq('amber', kri_rag($b + ['latest_value' => 15]), 'between green and amber is amber');
    expect_eq('amber', kri_rag($b + ['latest_value' => 20]), 'at amber boundary is amber');
    expect_eq('red',   kri_rag($b + ['latest_value' => 25]), 'above amber is red');
});

it('lower_worse: value classified against descending thresholds', function () {
    $b = ['direction' => 'lower_worse', 'threshold_green' => 30, 'threshold_amber' => 20, 'threshold_red' => 10];
    expect_eq('green', kri_rag($b + ['latest_value' => 35]), 'above green is green');
    expect_eq('green', kri_rag($b + ['latest_value' => 30]), 'at green boundary is green');
    expect_eq('amber', kri_rag($b + ['latest_value' => 25]), 'between is amber');
    expect_eq('red',   kri_rag($b + ['latest_value' => 5]),  'below amber is red');
});
