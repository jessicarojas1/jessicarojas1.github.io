<?php
declare(strict_types=1);

// Pagination is pure apart from reading $_GET; build()/render() both accept the
// page/query explicitly so they can be exercised without a request. Covers the
// LIMIT/OFFSET math (TD-5) and the filter-preserving, CSP-safe controls.
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/Pagination.php';

it('build() computes the window and clamps the page', function () {
    $p = Pagination::build(100, 25, 2);
    expect_eq(4, $p['pages'], '100/25 = 4 pages');
    expect_eq(25, $p['offset'], 'page 2 offset');
    expect_eq(26, $p['from'], 'page 2 starts at row 26');
    expect_eq(50, $p['to'], 'page 2 ends at row 50');

    $clamped = Pagination::build(100, 25, 99);
    expect_eq(4, $clamped['page'], 'out-of-range page clamps to last');

    $low = Pagination::build(100, 25, -3);
    expect_eq(1, $low['page'], 'page below 1 clamps to 1');
});

it('build() handles an empty result set', function () {
    $p = Pagination::build(0, 25, 1);
    expect_eq(1, $p['pages'], 'always at least one page');
    expect_eq(0, $p['from'], 'from is 0 when empty');
    expect_eq(0, $p['to'], 'to is 0 when empty');
    expect_eq(0, $p['offset'], 'offset 0 when empty');
});

it('build() with a partial last page', function () {
    $p = Pagination::build(30, 25, 2);
    expect_eq(2, $p['pages'], '30/25 = 2 pages');
    expect_eq(30, $p['to'], 'last page ends at total, not page*perPage');
    expect_eq(5, $p['to'] - $p['from'] + 1, 'last page has 5 rows (26..30 inclusive)');
});

it('render() is empty for a single page', function () {
    expect_eq('', Pagination::render(Pagination::build(10, 25, 1), '/risk'), 'one page → no controls');
});

it('render() preserves filters and marks the active page', function () {
    $p = Pagination::build(100, 25, 2);
    $html = Pagination::render($p, '/risk', ['status' => 'open', 'search' => 'a b']);
    expect(str_contains($html, 'status=open'), 'preserves the status filter in links');
    expect(str_contains($html, 'page=3'), 'has a next/number link to page 3');
    expect(str_contains($html, 'search=a+b'), 'url-encodes preserved params');
    expect(!str_contains($html, 'onclick'), 'no inline JS handlers (CSP-safe)');
    expect(str_contains($html, 'aria-current="page"'), 'active page marked for a11y');
    expect(str_contains($html, 'Showing 26–50 of 100'), 'shows the row range');
});
