<?php
/**
 * PageAccess — per-page view/edit restriction checks, layered on top of the
 * baseline space/role permissions (which controllers already enforce via
 * Auth::requirePermission). Admins and the page owner/creator are never locked
 * out. A page with no restriction rows of a given mode falls back to the
 * baseline permission (i.e. unrestricted).
 */
final class PageAccess {

    /** @var array<int,array> per-request cache of restriction rows by page id */
    private static array $cache = [];
    /** @var array<int,?int> per-request cache of parent_id by page id */
    private static array $parentCache = [];

    public static function restrictions(int $pageId): array {
        if (!isset(self::$cache[$pageId])) {
            try {
                self::$cache[$pageId] = Database::fetchAll(
                    "SELECT * FROM page_restrictions WHERE page_id = ?", [$pageId]
                );
            } catch (Throwable) { self::$cache[$pageId] = []; }
        }
        return self::$cache[$pageId];
    }

    public static function clear(int $pageId): void { unset(self::$cache[$pageId]); }

    private static function parentId(int $pageId): ?int {
        if (!array_key_exists($pageId, self::$parentCache)) {
            try { $r = Database::fetchOne("SELECT parent_id FROM pages WHERE id = ?", [$pageId]); }
            catch (Throwable) { $r = null; }
            self::$parentCache[$pageId] = ($r && $r['parent_id'] !== null) ? (int)$r['parent_id'] : null;
        }
        return self::$parentCache[$pageId];
    }

    /**
     * Restriction rows of a given mode that apply to a page — its own if it has
     * any, otherwise inherited from the nearest ancestor that restricts that
     * mode (Confluence style). Returns [] when nothing up the chain restricts it.
     */
    private static function effectiveRows(int $pageId, string $mode): array {
        $cur = $pageId; $depth = 0;
        while ($cur !== null && $depth++ < 50) {
            $rows = array_values(array_filter(self::restrictions($cur), fn($r) => $r['mode'] === $mode));
            if ($rows) return $rows;
            $cur = self::parentId($cur);
        }
        return [];
    }

    public static function isRestricted(int $pageId): bool {
        return self::restrictions($pageId) !== [];
    }

    /** True when the page has no view rule of its own but inherits one from an ancestor. */
    public static function inheritsRestriction(int $pageId): bool {
        $own = array_filter(self::restrictions($pageId), fn($r) => $r['mode'] === 'view');
        if ($own) return false;
        $parent = self::parentId($pageId);
        return $parent !== null && self::effectiveRows($parent, 'view') !== [];
    }

    /**
     * Restrictions of $mode this page inherits from the nearest ancestor that
     * defines them — only when the page has no rules of its own for that mode.
     * Returns ['source' => ancestorPageId, 'rows' => [...]] or [] when nothing
     * is inherited.
     */
    public static function inheritedFrom(int $pageId, string $mode): array {
        $own = array_filter(self::restrictions($pageId), fn($r) => $r['mode'] === $mode);
        if ($own) { return []; }
        $cur = self::parentId($pageId); $depth = 0;
        while ($cur !== null && $depth++ < 50) {
            $rows = array_values(array_filter(self::restrictions($cur), fn($r) => $r['mode'] === $mode));
            if ($rows) { return ['source' => $cur, 'rows' => $rows]; }
            $cur = self::parentId($cur);
        }
        return [];
    }

    public static function canView(array $page): bool {
        if (self::privileged($page)) return true;
        $rows = self::effectiveRows((int)$page['id'], 'view');
        return $rows ? self::matches($rows) : true;
    }

    public static function canEdit(array $page): bool {
        if (self::privileged($page)) return true;
        // Must be able to view (own or inherited) before editing.
        $viewRows = self::effectiveRows((int)$page['id'], 'view');
        if ($viewRows && !self::matches($viewRows)) return false;
        $editRows = self::effectiveRows((int)$page['id'], 'edit');
        return $editRows ? self::matches($editRows) : true;
    }

    /** Admin, owner or original author always retain access (no self-lockout). */
    private static function privileged(array $page): bool {
        if (Auth::role() === 'admin') return true;
        $uid = Auth::id();
        return $uid !== null && ((int)($page['owner_id'] ?? 0) === $uid || (int)($page['created_by'] ?? 0) === $uid);
    }

    private static function matches(array $rows): bool {
        $uid = (string)Auth::id();
        $role = Auth::role();
        foreach ($rows as $r) {
            if ($r['principal_type'] === 'user' && $r['principal'] === $uid) return true;
            if ($r['principal_type'] === 'role' && $r['principal'] === $role) return true;
        }
        return false;
    }
}
