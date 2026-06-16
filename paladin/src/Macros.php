<?php
declare(strict_types=1);

/**
 * Macros — server-side expansion of dynamic Confluence-style content macros
 * embedded in a page body. Authors drop an empty placeholder element with a
 * `macro-*` class (and optional data-* attributes); at render time it is
 * replaced with live, access-filtered content.
 *
 * Supported:
 *   <div class="macro-children"></div>                         direct child pages
 *   <div class="macro-pagetree" data-depth="3"></div>          nested descendant tree
 *   <div class="macro-recently-updated" data-limit="10"
 *        data-scope="space|all"></div>                         recently updated pages
 *
 * All output is escaped; only links to pages the current user may view are
 * emitted. CSP-safe (no inline handlers, server-rendered).
 */
final class Macros {

    /** Expand macros in $html. $ctx: ['page_id'=>int, 'space_id'=>?int]. */
    public static function expand(string $html, array $ctx): string {
        if ($html === '' || stripos($html, 'macro-') === false) { return $html; }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__pal_macro_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);
        $nodes = iterator_to_array($xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' macro-')]"));
        $changed = false;
        foreach ($nodes as $el) {
            /** @var \DOMElement $el */
            $cls = ' ' . $el->getAttribute('class') . ' ';
            $repl = null;
            if (strpos($cls, ' macro-children ') !== false) {
                $repl = self::renderChildren($ctx);
            } elseif (strpos($cls, ' macro-pagetree ') !== false) {
                $depth = max(1, min(6, (int)($el->getAttribute('data-depth') ?: 3)));
                $repl = self::renderTree($ctx, $depth);
            } elseif (strpos($cls, ' macro-recently-updated ') !== false || strpos($cls, ' macro-recent ') !== false) {
                $limit = max(1, min(50, (int)($el->getAttribute('data-limit') ?: 10)));
                $scope = $el->getAttribute('data-scope') === 'all' ? 'all' : 'space';
                $repl = self::renderRecent($ctx, $limit, $scope);
            }
            if ($repl !== null) {
                $new = self::fragment($dom, $repl);
                if ($new) { $el->parentNode->replaceChild($new, $el); $changed = true; }
            }
        }
        if (!$changed) { return $html; }

        $root = $dom->getElementById('__pal_macro_root');
        $inner = '';
        if ($root) { foreach ($root->childNodes as $c) { $inner .= $dom->saveHTML($c); } }
        return $inner !== '' ? $inner : $html;
    }

    /** Parse an HTML snippet and import its single wrapper element into $dom. */
    private static function fragment(\DOMDocument $dom, string $snippet): ?\DOMNode {
        $tmp = new \DOMDocument();
        libxml_use_internal_errors(true);
        $tmp->loadHTML('<?xml encoding="UTF-8"><div id="__w">' . $snippet . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $w = $tmp->getElementById('__w');
        if (!$w) { return null; }
        return $dom->importNode($w, true);
    }

    /** Pages the current user may view, given minimal row data. */
    private static function visible(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            if (PageAccess::canView($r)) { $out[] = $r; }
        }
        return $out;
    }

    private static function renderChildren(array $ctx): string {
        $pid = (int)($ctx['page_id'] ?? 0);
        if (!$pid) { return self::empty('No child pages.'); }
        $rows = Database::fetchAll(
            "SELECT id, title, slug, owner_id, created_by, space_id, parent_id
             FROM pages WHERE parent_id = ? AND deleted_at IS NULL AND status = 'published'
             ORDER BY position, title", [$pid]
        );
        $rows = self::visible($rows);
        if (!$rows) { return self::empty('No child pages.'); }
        $items = '';
        foreach ($rows as $r) {
            $items .= '<li><i class="bi bi-file-earmark-text"></i> <a href="/pages/' . (int)$r['id'] . '">'
                   . Security::h($r['title']) . '</a></li>';
        }
        return '<div class="macro-out macro-children-out"><ul class="macro-list">' . $items . '</ul></div>';
    }

    private static function renderTree(array $ctx, int $depth): string {
        $pid = (int)($ctx['page_id'] ?? 0);
        if (!$pid) { return self::empty('No child pages.'); }
        $html = self::treeLevel($pid, $depth);
        return $html === '' ? self::empty('No child pages.')
            : '<div class="macro-out macro-pagetree-out">' . $html . '</div>';
    }

    private static function treeLevel(int $parentId, int $depth): string {
        if ($depth < 1) { return ''; }
        $rows = Database::fetchAll(
            "SELECT id, title, owner_id, created_by, space_id, parent_id
             FROM pages WHERE parent_id = ? AND deleted_at IS NULL AND status = 'published'
             ORDER BY position, title", [$parentId]
        );
        $rows = self::visible($rows);
        if (!$rows) { return ''; }
        $items = '';
        foreach ($rows as $r) {
            $children = self::treeLevel((int)$r['id'], $depth - 1);
            $items .= '<li><i class="bi bi-file-earmark-text"></i> <a href="/pages/' . (int)$r['id'] . '">'
                   . Security::h($r['title']) . '</a>' . $children . '</li>';
        }
        return '<ul class="macro-list macro-tree">' . $items . '</ul>';
    }

    private static function renderRecent(array $ctx, int $limit, string $scope): string {
        $params = []; $where = "deleted_at IS NULL AND status = 'published'";
        if ($scope === 'space' && !empty($ctx['space_id'])) {
            $where .= ' AND space_id = ?'; $params[] = (int)$ctx['space_id'];
        }
        // Over-fetch so access filtering still yields up to $limit rows.
        $params[] = $limit * 3;
        $rows = Database::fetchAll(
            "SELECT id, title, owner_id, created_by, space_id, parent_id, updated_at
             FROM pages WHERE {$where} ORDER BY updated_at DESC LIMIT ?", $params
        );
        $rows = array_slice(self::visible($rows), 0, $limit);
        if (!$rows) { return self::empty('Nothing updated recently.'); }
        $items = '';
        foreach ($rows as $r) {
            $items .= '<li><a href="/pages/' . (int)$r['id'] . '">' . Security::h($r['title']) . '</a>'
                   . ' <span class="macro-meta">' . Security::h(View::timeAgo($r['updated_at'])) . '</span></li>';
        }
        return '<div class="macro-out macro-recent-out"><ul class="macro-list">' . $items . '</ul></div>';
    }

    private static function empty(string $msg): string {
        return '<div class="macro-out macro-empty"><i class="bi bi-info-circle"></i> ' . Security::h($msg) . '</div>';
    }
}
