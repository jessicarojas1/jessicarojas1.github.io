<?php
declare(strict_types=1);

/**
 * ReactionController — toggle a "like" on a page, document or comment.
 * Requires the viewer to have read access to the underlying entity.
 */
class ReactionController {

    private const VIEW_PERM = ['page' => 'page.view', 'document' => 'document.view', 'process' => 'process.view', 'blog' => 'page.view'];

    public function toggle(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $type = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $id   = (int)($_POST['entity_id'] ?? 0);
        if (!in_array($type, ['page', 'document', 'comment', 'blog'], true) || $id <= 0) { http_response_code(400); return; }

        // Resolve the underlying entity for permission + redirect
        [$ownerType, $ownerId, $back] = $this->resolve($type, $id);
        if ($ownerType === null) { http_response_code(404); return; }
        $perm = self::VIEW_PERM[$ownerType] ?? null;
        if ($perm && !Auth::can($perm)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        if ($ownerType === 'page') {
            $pg = Database::fetchOne("SELECT * FROM pages WHERE id = ?", [$ownerId]);
            if ($pg && !PageAccess::canView($pg)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        }

        $existing = Database::fetchOne("SELECT id FROM reactions WHERE entity_type=? AND entity_id=? AND user_id=?", [$type, $id, Auth::id()]);
        if ($existing) {
            Database::query("DELETE FROM reactions WHERE id = ?", [$existing['id']]);
        } else {
            try { Database::insert('reactions', ['entity_type' => $type, 'entity_id' => $id, 'user_id' => Auth::id()]); } catch (Throwable) {}
        }
        header('Location: ' . $back);
    }

    private const TABLE = ['page' => 'pages', 'document' => 'documents', 'blog' => 'blog_posts'];
    private const PATH  = ['page' => '/pages/', 'document' => '/documents/', 'blog' => '/blog/'];

    /** @return array{0:?string,1:int,2:string} [ownerEntityType, ownerEntityId, backUrl] */
    private function resolve(string $type, int $id): array {
        if ($type === 'comment') {
            $c = Database::fetchOne("SELECT entity_type, entity_id FROM comments WHERE id = ?", [$id]);
            if (!$c) return [null, 0, '/'];
            $path = self::PATH[$c['entity_type']] ?? null;
            $back = $path ? $path . (int)$c['entity_id'] . '#comments' : '/';
            return [$c['entity_type'], (int)$c['entity_id'], $back];
        }
        $table = self::TABLE[$type] ?? null;
        if (!$table || !Database::fetchOne("SELECT 1 FROM {$table} WHERE id = ?", [$id])) return [null, 0, '/'];
        return [$type, $id, self::PATH[$type] . $id];
    }
}
