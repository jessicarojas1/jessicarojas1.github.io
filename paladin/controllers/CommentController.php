<?php
declare(strict_types=1);

/**
 * CommentController — resolve / reopen / delete comment threads on any entity.
 * Resolve/reopen is allowed for the comment author, anyone with the parent
 * entity's edit permission, or an admin. Delete is author-or-admin.
 */
class CommentController {

    private const EDIT_PERM = ['page' => 'page.edit', 'document' => 'document.edit', 'process' => 'process.edit', 'blog' => 'page.edit'];

    private function load(int $id): ?array {
        return Database::fetchOne("SELECT * FROM comments WHERE id = ?", [$id]);
    }

    private function backLink(array $c): string {
        return match ($c['entity_type']) {
            'page'     => '/pages/' . (int)$c['entity_id'] . '#comments',
            'document' => '/documents/' . (int)$c['entity_id'] . '#comments',
            'blog'     => '/blog/' . (int)$c['entity_id'] . '#comments',
            'process'  => '/processes/' . (int)$c['entity_id'],
            default    => '/',
        };
    }

    private function canModerate(array $c): bool {
        if (Auth::role() === 'admin') return true;
        if ((int)$c['user_id'] === Auth::id()) return true;
        $perm = self::EDIT_PERM[$c['entity_type']] ?? null;
        return $perm ? Auth::can($perm) : false;
    }

    public function resolve(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $c = $this->load($id);
        if (!$c) { http_response_code(404); return; }
        if (!$this->canModerate($c)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        Database::query("UPDATE comments SET resolved_at = NOW(), resolved_by = ? WHERE id = ?", [Auth::id(), $id]);
        Auth::log('resolve_comment', $c['entity_type'], (int)$c['entity_id']);
        header('Location: ' . $this->backLink($c));
    }

    public function reopen(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $c = $this->load($id);
        if (!$c) { http_response_code(404); return; }
        if (!$this->canModerate($c)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        Database::query("UPDATE comments SET resolved_at = NULL, resolved_by = NULL WHERE id = ?", [$id]);
        Auth::log('reopen_comment', $c['entity_type'], (int)$c['entity_id']);
        header('Location: ' . $this->backLink($c));
    }

    public function delete(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $c = $this->load($id);
        if (!$c) { http_response_code(404); return; }
        if (!$this->canModerate($c)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        Database::query("DELETE FROM comments WHERE id = ? OR parent_id = ?", [$id, $id]); // thread + replies
        Auth::log('delete_comment', $c['entity_type'], (int)$c['entity_id']);
        $_SESSION['flash_success'] = 'Comment removed.';
        header('Location: ' . $this->backLink($c));
    }
}
