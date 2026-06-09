<?php
/**
 * Recent — tracks and surfaces each user's recently viewed content
 * (pages, documents, blog posts, processes). Never throws.
 */
final class Recent {

    private const PATH = ['page' => '/pages/', 'document' => '/documents/', 'blog' => '/blog/', 'process' => '/processes/'];
    private const ICON = ['page' => 'bi-file-richtext', 'document' => 'bi-file-earmark-text', 'blog' => 'bi-newspaper', 'process' => 'bi-diagram-3'];

    /** Record (or refresh) a view for the current user; prunes to the newest 50. */
    public static function track(string $type, int $id, ?string $title): void {
        $uid = Auth::id();
        if (!$uid || !isset(self::PATH[$type]) || $id <= 0) return;
        try {
            Database::query(
                "INSERT INTO recent_views (user_id, entity_type, entity_id, title, viewed_at)
                 VALUES (?,?,?,?,NOW())
                 ON CONFLICT (user_id, entity_type, entity_id)
                 DO UPDATE SET viewed_at = NOW(), title = EXCLUDED.title",
                [$uid, $type, $id, $title !== null ? mb_substr($title, 0, 255) : null]
            );
            Database::query(
                "DELETE FROM recent_views WHERE user_id = ? AND id NOT IN (
                    SELECT id FROM recent_views WHERE user_id = ? ORDER BY viewed_at DESC LIMIT 50
                 )",
                [$uid, $uid]
            );
        } catch (Throwable) {}
    }

    /** @return array<int,array> recent rows for the current user (newest first). */
    public static function recent(int $limit = 8): array {
        $uid = Auth::id();
        if (!$uid) return [];
        try {
            return Database::fetchAll(
                "SELECT * FROM recent_views WHERE user_id = ? ORDER BY viewed_at DESC LIMIT " . max(1, min(50, $limit)),
                [$uid]
            );
        } catch (Throwable) { return []; }
    }

    public static function url(string $type, int $id): string {
        return (self::PATH[$type] ?? '/') . $id;
    }

    public static function icon(string $type): string {
        return self::ICON[$type] ?? 'bi-file-earmark';
    }
}
