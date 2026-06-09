<?php
declare(strict_types=1);

/**
 * BlogController — Confluence-style blog posts (date-stamped news per space).
 * Permission-wise blogs are page-like content (page.view/create/edit/delete/
 * publish/comment).
 */
class BlogController {

    public function index(): void {
        Auth::requirePermission('page.view');
        $posts = Database::fetchAll(
            "SELECT b.*, s.space_key, s.name AS space_name, u.name AS author_name
             FROM blog_posts b LEFT JOIN spaces s ON s.id=b.space_id LEFT JOIN users u ON u.id=b.author_id
             WHERE b.status='published' OR b.author_id = ?
             ORDER BY COALESCE(b.published_at, b.created_at) DESC LIMIT 50",
            [Auth::id()]
        );
        $title = 'Blog'; $spaceFilter = null;
        require PALADIN_ROOT . '/views/blog/index.php';
    }

    public function space(int $id): void {
        Auth::requirePermission('page.view');
        $spaceFilter = Database::fetchOne("SELECT id, space_key, name FROM spaces WHERE id=?", [$id]);
        if (!$spaceFilter) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $posts = Database::fetchAll(
            "SELECT b.*, s.space_key, s.name AS space_name, u.name AS author_name
             FROM blog_posts b LEFT JOIN spaces s ON s.id=b.space_id LEFT JOIN users u ON u.id=b.author_id
             WHERE b.space_id = ? AND (b.status='published' OR b.author_id = ?)
             ORDER BY COALESCE(b.published_at, b.created_at) DESC", [$id, Auth::id()]
        );
        $title = 'Blog — ' . $spaceFilter['name'];
        require PALADIN_ROOT . '/views/blog/index.php';
    }

    public function view(int $id): void {
        Auth::requirePermission('page.view');
        $post = Database::fetchOne(
            "SELECT b.*, s.space_key, s.name AS space_name, u.name AS author_name
             FROM blog_posts b LEFT JOIN spaces s ON s.id=b.space_id LEFT JOIN users u ON u.id=b.author_id
             WHERE b.id=?", [$id]
        );
        if (!$post) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $comments = Database::fetchAll(
            "SELECT c.*, u.name AS user_name, r.name AS resolver_name
             FROM comments c LEFT JOIN users u ON u.id=c.user_id LEFT JOIN users r ON r.id=c.resolved_by
             WHERE c.entity_type='blog' AND c.entity_id=? ORDER BY c.created_at", [$id]
        );
        $postLike = Reactions::one('blog', $id);
        $cReactions = Reactions::summary('comment', array_map(fn($c) => (int)$c['id'], $comments));
        require PALADIN_ROOT . '/views/blog/view.php';
    }

    public function createForm(): void {
        Auth::requirePermission('page.create');
        $post = null;
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        require PALADIN_ROOT . '/views/blog/form.php';
    }

    public function create(): void {
        Auth::requirePermission('page.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        $spaceId = (int)($_POST['space_id'] ?? 0);
        if ($title === '' || !$spaceId) { $_SESSION['flash_error'] = 'Title and space are required.'; header('Location: /blog/create'); return; }
        $status = in_array($_POST['status'] ?? 'draft', ['draft','published'], true) ? $_POST['status'] : 'draft';
        if ($status === 'published' && !Auth::can('page.publish')) $status = 'draft';
        $id = Database::insert('blog_posts', [
            'space_id' => $spaceId, 'title' => $title,
            'slug' => substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), 0, 200),
            'body' => Security::sanitizeHtml($_POST['body'] ?? ''),
            'status' => $status, 'author_id' => Auth::id(),
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        Auth::log('create_blog', 'blog_posts', $id, ['title' => $title]);
        $_SESSION['flash_success'] = 'Blog post ' . ($status === 'published' ? 'published' : 'saved as draft') . '.';
        header('Location: /blog/' . $id);
    }

    public function editForm(int $id): void {
        Auth::requirePermission('page.edit');
        $post = Database::fetchOne("SELECT * FROM blog_posts WHERE id=?", [$id]);
        if (!$post) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        require PALADIN_ROOT . '/views/blog/form.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $post = Database::fetchOne("SELECT * FROM blog_posts WHERE id=?", [$id]);
        if (!$post) { http_response_code(404); return; }
        $title = Security::sanitizeInput($_POST['title'] ?? $post['title']);
        $status = in_array($_POST['status'] ?? $post['status'], ['draft','published'], true) ? $_POST['status'] : $post['status'];
        if ($status === 'published' && !Auth::can('page.publish')) $status = $post['status'];
        Database::update('blog_posts', [
            'title' => $title, 'body' => Security::sanitizeHtml($_POST['body'] ?? ''),
            'space_id' => (int)($_POST['space_id'] ?? $post['space_id']) ?: $post['space_id'],
            'status' => $status,
            'published_at' => $status === 'published' ? ($post['published_at'] ?: date('Y-m-d H:i:s')) : $post['published_at'],
        ], 'id=?', [$id]);
        Auth::log('update_blog', 'blog_posts', $id);
        $_SESSION['flash_success'] = 'Blog post updated.';
        header('Location: /blog/' . $id);
    }

    public function delete(int $id): void {
        Auth::requirePermission('page.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM blog_posts WHERE id=?", [$id]);
        Auth::log('delete_blog', 'blog_posts', $id);
        $_SESSION['flash_success'] = 'Blog post deleted.';
        header('Location: /blog');
    }

    public function comment(int $id): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM blog_posts WHERE id=?", [$id])) { http_response_code(404); return; }
        $body = Security::sanitizeInput($_POST['body'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        if ($parentId && !Database::fetchOne("SELECT 1 FROM comments WHERE id=? AND entity_type='blog' AND entity_id=? AND parent_id IS NULL", [$parentId, $id])) {
            $parentId = null;
        }
        if ($body !== '') {
            Database::insert('comments', ['entity_type' => 'blog', 'entity_id' => $id, 'user_id' => Auth::id(), 'parent_id' => $parentId, 'body' => $body]);
            Auth::log('comment_blog', 'blog_posts', $id);
            $b = Database::fetchOne("SELECT title FROM blog_posts WHERE id=?", [$id]);
            Mentions::process($body, 'blog', $id, $b['title'] ?? null);
        }
        header('Location: /blog/' . $id . '#comments');
    }
}
