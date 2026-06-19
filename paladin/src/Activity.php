<?php
declare(strict_types=1);

/**
 * Activity — turns the audit log (activity_log) into a human-readable
 * "Recently updated" stream: who did what to which page/document/blog/space,
 * with a link and a relative time. Read-only; safe titles resolved via joins.
 */
final class Activity
{
    /** action => [verb, bootstrap-icon]. Only these surface in the stream. */
    private const ACTIONS = [
        'create_page'         => ['created page', 'bi-file-earmark-plus'],
        'update_page'         => ['updated page', 'bi-pencil'],
        'publish_page'        => ['published page', 'bi-send-check'],
        'trash_page'          => ['deleted page', 'bi-trash'],
        'restore_page'        => ['restored page', 'bi-arrow-counterclockwise'],
        'import_page'         => ['imported page', 'bi-markdown'],
        'comment_page'        => ['commented on', 'bi-chat-left-text'],
        'inline_comment_page' => ['commented inline on', 'bi-chat-left-quote'],
        'create_document'     => ['created document', 'bi-file-earmark-text'],
        'update_document'     => ['updated document', 'bi-pencil'],
        'document_transition' => ['changed status of', 'bi-arrow-left-right'],
        'comment_document'    => ['commented on', 'bi-chat-left-text'],
        'create_blog'         => ['wrote blog post', 'bi-newspaper'],
        'comment_blog'        => ['commented on blog', 'bi-chat-left-text'],
        'create_space'        => ['created space', 'bi-collection'],
        'create_process'      => ['created process', 'bi-diagram-3'],
    ];

    /** Entity type => URL prefix for linking the target. */
    private const LINKS = [
        'pages'      => '/pages/',
        'documents'  => '/documents/',
        'blog_posts' => '/blog/',
        'spaces'     => '/spaces/',
        'processes'  => '/processes/',
    ];

    /**
     * Recent activity items. When $spaceId is given, only content in that space.
     * Each item: action, verb, icon, actor, target_title, link, created_at.
     */
    public static function feed(int $limit = 40, ?int $spaceId = null): array
    {
        $actions = array_keys(self::ACTIONS);
        $place = implode(',', array_fill(0, count($actions), '?'));
        $params = $actions;

        $spaceFilter = '';
        if ($spaceId !== null) {
            $spaceFilter = " AND COALESCE(p.space_id, d.space_id, b.space_id, al.entity_id) = ?
                             AND al.entity_type IN ('pages','documents','blog_posts','spaces')";
        }

        // Hide activity about content in private spaces the viewer is not a member
        // of (admins see all); the content's space is resolved per row.
        $memberFilter = '';
        if (class_exists('Auth') && Auth::role() !== 'admin') {
            $memberFilter = " AND (
                COALESCE(p.space_id, d.space_id, b.space_id, sp.id) IS NULL
                OR EXISTS (SELECT 1 FROM spaces sx
                           WHERE sx.id = COALESCE(p.space_id, d.space_id, b.space_id, sp.id)
                             AND (sx.is_private = FALSE
                                  OR EXISTS (SELECT 1 FROM space_members m WHERE m.space_id = sx.id AND m.user_id = ?))))";
        }

        $sql =
            "SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.name AS actor,
                    CASE al.entity_type
                        WHEN 'pages'      THEN p.title
                        WHEN 'documents'  THEN d.title
                        WHEN 'blog_posts' THEN b.title
                        WHEN 'spaces'     THEN sp.name
                        ELSE NULL END AS target_title,
                    CASE WHEN al.entity_type='pages' THEN p.deleted_at ELSE NULL END AS page_deleted
             FROM activity_log al
             LEFT JOIN users u  ON u.id = al.user_id
             LEFT JOIN pages p      ON al.entity_type='pages'      AND p.id  = al.entity_id
             LEFT JOIN documents d  ON al.entity_type='documents'  AND d.id  = al.entity_id
             LEFT JOIN blog_posts b ON al.entity_type='blog_posts' AND b.id  = al.entity_id
             LEFT JOIN spaces sp    ON al.entity_type='spaces'     AND sp.id = al.entity_id
             WHERE al.action IN ($place){$spaceFilter}{$memberFilter}
             ORDER BY al.id DESC LIMIT " . max(1, min(100, $limit));
        if ($spaceId !== null) $params[] = $spaceId;
        if ($memberFilter !== '') $params[] = Auth::id();

        try { $rows = Database::fetchAll($sql, $params); }
        catch (\Throwable) { return []; }

        $out = [];
        foreach ($rows as $r) {
            // Skip events whose target page is now trashed (keeps the feed clean).
            if ($r['entity_type'] === 'pages' && $r['page_deleted'] !== null) continue;
            [$verb, $icon] = self::ACTIONS[$r['action']] ?? ['did', 'bi-dot'];
            $prefix = self::LINKS[$r['entity_type']] ?? null;
            $out[] = [
                'verb'         => $verb,
                'icon'         => $icon,
                'actor'        => $r['actor'] ?: 'Someone',
                'target_title' => $r['target_title'] ?: ('#' . (int)$r['entity_id']),
                'link'         => ($prefix && $r['entity_id']) ? $prefix . (int)$r['entity_id'] : null,
                'created_at'   => $r['created_at'],
            ];
        }
        return $out;
    }
}
