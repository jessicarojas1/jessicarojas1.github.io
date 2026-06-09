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

    public static function isRestricted(int $pageId): bool {
        return self::restrictions($pageId) !== [];
    }

    public static function canView(array $page): bool {
        if (self::privileged($page)) return true;
        $rows = array_filter(self::restrictions((int)$page['id']), fn($r) => $r['mode'] === 'view');
        return $rows ? self::matches($rows) : true;
    }

    public static function canEdit(array $page): bool {
        if (self::privileged($page)) return true;
        // Must be able to view before editing
        $viewRows = array_filter(self::restrictions((int)$page['id']), fn($r) => $r['mode'] === 'view');
        if ($viewRows && !self::matches($viewRows)) return false;
        $editRows = array_filter(self::restrictions((int)$page['id']), fn($r) => $r['mode'] === 'edit');
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
