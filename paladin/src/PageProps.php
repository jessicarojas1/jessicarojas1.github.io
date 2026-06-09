<?php
declare(strict_types=1);

/**
 * PageProps — extracts "Page Properties" (labelled key/value rows) from a page
 * body and keeps them in page_properties so a Page Properties Report can
 * aggregate them across pages sharing a label.
 *
 * Authors add a two-column table with class "page-properties"; the first cell
 * of each row is the property name, the second its value.
 */
final class PageProps
{
    /** @return array<int,array{key:string,value:string}> in document order */
    public static function parse(string $html): array
    {
        if (stripos($html, 'page-properties') === false) return [];
        $out = [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        foreach ($xp->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' page-properties ')]") as $table) {
            foreach ($xp->query('.//tr', $table) as $tr) {
                $cells = $xp->query('./th | ./td', $tr);
                if ($cells->length < 2) continue;
                $key = self::clean($cells->item(0)->textContent);
                $val = self::clean($cells->item(1)->textContent);
                if ($key === '' || strcasecmp($key, 'key') === 0) continue;
                $out[] = ['key' => mb_substr($key, 0, 160), 'value' => mb_substr($val, 0, 2000)];
            }
        }
        return $out;
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    public static function sync(int $pageId, string $html): void
    {
        $props = self::parse($html);
        try {
            Database::query("DELETE FROM page_properties WHERE page_id = ?", [$pageId]);
            $seq = 0;
            foreach ($props as $p) {
                Database::insert('page_properties', [
                    'page_id' => $pageId, 'seq' => $seq++,
                    'prop_key' => $p['key'], 'prop_value' => $p['value'],
                ]);
            }
        } catch (\Throwable) { /* best effort */ }
    }
}
