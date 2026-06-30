<?php
declare(strict_types=1);

/**
 * Pagination — shared server-side pagination for high-cardinality list views
 * (TECH_DEBT TD-5). Most list controllers `fetchAll` unbounded, so memory and
 * render time grow linearly with row count. build() turns a total row count and
 * the ?page= query param into a LIMIT/OFFSET window; render() emits the page
 * controls.
 *
 * Pure logic apart from reading $_GET — unit-tested via the $page/$query args.
 * The controls are plain <a> links (no inline JS), so they are CSP-safe, and
 * they preserve the current query string (filters/search) minus `page`.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 25;

    /**
     * Build the pagination window.
     *
     * @param int      $total   total matching rows (from a COUNT query)
     * @param int      $perPage rows per page
     * @param int|null $page    explicit page (defaults to ?page=, clamped to range)
     * @return array{total:int,perPage:int,pages:int,page:int,offset:int,from:int,to:int}
     */
    public static function build(int $total, int $perPage = self::DEFAULT_PER_PAGE, ?int $page = null): array
    {
        $perPage = max(1, $perPage);
        $total   = max(0, $total);
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = $page ?? (int) ($_GET['page'] ?? 1);
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;
        return [
            'total'   => $total,
            'perPage' => $perPage,
            'pages'   => $pages,
            'page'    => $page,
            'offset'  => $offset,
            'from'    => $total === 0 ? 0 : $offset + 1,
            'to'      => min($page * $perPage, $total),
        ];
    }

    /**
     * Render the pagination bar (empty string when there's only one page).
     * Preserves the current query string (minus `page`) so filters survive.
     *
     * @param array $p        the array returned by build()
     * @param string $basePath e.g. '/risk' — the list route
     * @param array|null $query override query params (defaults to current $_GET)
     */
    public static function render(array $p, string $basePath, ?array $query = null): string
    {
        $pages = (int) ($p['pages'] ?? 1);
        $page  = (int) ($p['page'] ?? 1);
        if ($pages <= 1) {
            return '';
        }
        $query = $query ?? $_GET;
        unset($query['page']);

        $href = static function (int $pg) use ($basePath, $query): string {
            $query['page'] = $pg;
            return Security::h($basePath . '?' . http_build_query($query));
        };

        // Page window: first, last, and ±2 around the current page, with gaps.
        $nums = [];
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
                $nums[] = $i;
            }
        }

        $out  = '<nav class="pagination" aria-label="Pagination">';
        $out .= '<span class="pagination-info">Showing ' . (int) $p['from'] . '–' . (int) $p['to']
              . ' of ' . (int) $p['total'] . '</span>';
        $out .= '<span class="pagination-links">';

        // Prev
        if ($page > 1) {
            $out .= '<a class="page-link" rel="prev" href="' . $href($page - 1) . '" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>';
        } else {
            $out .= '<span class="page-link disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i></span>';
        }

        // Numbered links with gap markers
        $prev = 0;
        foreach ($nums as $n) {
            if ($prev && $n - $prev > 1) {
                $out .= '<span class="page-gap">…</span>';
            }
            if ($n === $page) {
                $out .= '<span class="page-link active" aria-current="page">' . $n . '</span>';
            } else {
                $out .= '<a class="page-link" href="' . $href($n) . '">' . $n . '</a>';
            }
            $prev = $n;
        }

        // Next
        if ($page < $pages) {
            $out .= '<a class="page-link" rel="next" href="' . $href($page + 1) . '" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>';
        } else {
            $out .= '<span class="page-link disabled" aria-disabled="true"><i class="bi bi-chevron-right"></i></span>';
        }

        $out .= '</span></nav>';
        return $out;
    }
}
