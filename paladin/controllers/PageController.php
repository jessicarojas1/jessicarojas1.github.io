<?php
declare(strict_types=1);

class PageController {

    private function loadSpace(int $spaceId): ?array {
        return Database::fetchOne("SELECT id, space_key, name FROM spaces WHERE id = ?", [$spaceId]);
    }

    public function createForm(int $spaceId = 0): void {
        Auth::requirePermission('page.create');
        // /pages/create?space=ID  OR  /spaces/{id}/pages/create
        $spaceId = $spaceId ?: (int)($_GET['space'] ?? 0);
        $space   = $spaceId ? $this->loadSpace($spaceId) : null;
        $spaces  = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $parents = $spaceId ? Database::fetchAll("SELECT id, title FROM pages WHERE space_id=? ORDER BY title", [$spaceId]) : [];
        $templates = Database::fetchAll("SELECT id, name, body FROM templates WHERE category IN ('page','document') AND is_active=TRUE ORDER BY name");
        $page = null;
        require PALADIN_ROOT . '/views/pages/form.php';
    }

    public function create(): void {
        Auth::requirePermission('page.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $spaceId = (int)($_POST['space_id'] ?? 0);
        $title   = Security::sanitizeInput($_POST['title'] ?? '');
        if (!$spaceId || $title === '') { $_SESSION['flash_error'] = 'Space and title are required.'; header('Location: /pages/create'); return; }
        if (!$this->loadSpace($spaceId)) { $_SESSION['flash_error'] = 'Invalid space.'; header('Location: /pages/create'); return; }

        $body   = Security::sanitizeHtml($_POST['body'] ?? '');
        $status = in_array($_POST['status'] ?? 'draft', ['draft','in_review','published'], true) ? $_POST['status'] : 'draft';
        $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        $id = Database::insert('pages', [
            'space_id'   => $spaceId,
            'parent_id'  => $parent,
            'title'      => $title,
            'slug'       => substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), 0, 200),
            'body'       => $body,
            'status'     => $status,
            'owner_id'   => Auth::id(),
            'created_by' => Auth::id(),
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        Database::insert('page_versions', ['page_id' => $id, 'version' => 1, 'title' => $title, 'body' => $body, 'change_note' => 'Created', 'edited_by' => Auth::id()]);
        Auth::log('create_page', 'pages', $id, ['title' => $title]);
        $_SESSION['flash_success'] = 'Page created.';
        header('Location: /pages/' . $id);
    }

    public function view(int $id): void {
        Auth::requirePermission('page.view');
        $page = Database::fetchOne(
            "SELECT p.*, s.space_key, s.name AS space_name, o.name AS owner_name
             FROM pages p JOIN spaces s ON s.id = p.space_id
             LEFT JOIN users o ON o.id = p.owner_id WHERE p.id = ?",
            [$id]
        );
        if (!$page) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $children = Database::fetchAll("SELECT id, title, status FROM pages WHERE parent_id = ? ORDER BY position, title", [$id]);
        $crumbs   = $this->ancestry($page);
        $comments = Database::fetchAll(
            "SELECT c.*, u.name AS user_name FROM comments c LEFT JOIN users u ON u.id=c.user_id
             WHERE c.entity_type='page' AND c.entity_id=? ORDER BY c.created_at", [$id]
        );
        $versionCount = (int)(Database::fetchOne("SELECT COUNT(*) c FROM page_versions WHERE page_id=?", [$id])['c'] ?? 0);
        $isWatching = (bool)Database::fetchOne("SELECT 1 FROM watches WHERE user_id=? AND entity_type='page' AND entity_id=?", [Auth::id(), $id]);
        $labels = Database::fetchAll(
            "SELECT t.id, t.name, t.color FROM entity_tags et JOIN tags t ON t.id=et.tag_id
             WHERE et.entity_type='page' AND et.entity_id=? ORDER BY t.name", [$id]
        );
        $allTags = Database::fetchAll("SELECT id, name, color FROM tags ORDER BY name");
        $attachments = Database::fetchAll(
            "SELECT a.*, u.name AS uploader FROM attachments a LEFT JOIN users u ON u.id=a.uploaded_by
             WHERE a.entity_type='page' AND a.entity_id=? ORDER BY a.created_at DESC", [$id]
        );
        require PALADIN_ROOT . '/views/pages/view.php';
    }

    /** Attach an existing tag to a page as a label. */
    public function addLabel(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM pages WHERE id=?", [$id])) { http_response_code(404); return; }
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($tagId && Database::fetchOne("SELECT id FROM tags WHERE id=?", [$tagId])) {
            try {
                Database::insert('entity_tags', ['tag_id' => $tagId, 'entity_type' => 'page', 'entity_id' => $id]);
                Auth::log('label_page', 'pages', $id, ['tag' => $tagId]);
            } catch (Throwable) { /* already labelled (unique) */ }
        }
        header('Location: /pages/' . $id);
    }

    public function removeLabel(int $id, int $tagId): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM entity_tags WHERE entity_type='page' AND entity_id=? AND tag_id=?", [$id, $tagId]);
        Auth::log('unlabel_page', 'pages', $id, ['tag' => $tagId]);
        header('Location: /pages/' . $id);
    }

    public function uploadAttachment(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM pages WHERE id=?", [$id])) { http_response_code(404); return; }
        if (empty($_FILES['file']['name'])) { $_SESSION['flash_error'] = 'Choose a file to attach.'; header('Location: /pages/' . $id); return; }
        $up = Upload::handle($_FILES['file'], 'uploads/attachments');
        if (!$up['ok']) { $_SESSION['flash_error'] = $up['error']; header('Location: /pages/' . $id); return; }
        Database::insert('attachments', [
            'entity_type' => 'page', 'entity_id' => $id,
            'original_name' => $up['name'], 'stored_name' => $up['key'], 'mime_type' => $up['mime'],
            'file_size' => $up['size'], 'file_hash' => $up['hash'],
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'uploaded_by' => Auth::id(),
        ]);
        Auth::log('attach_page', 'pages', $id);
        $_SESSION['flash_success'] = 'Attachment uploaded.';
        header('Location: /pages/' . $id);
    }

    private function ancestry(array $page): array {
        $chain = []; $cur = $page;
        $guard = 0;
        while (!empty($cur['parent_id']) && $guard++ < 20) {
            $cur = Database::fetchOne("SELECT id, title, parent_id FROM pages WHERE id=?", [$cur['parent_id']]);
            if (!$cur) break;
            array_unshift($chain, ['/pages/' . (int)$cur['id'], $cur['title']]);
        }
        return $chain;
    }

    public function editForm(int $id): void {
        Auth::requirePermission('page.edit');
        $page = Database::fetchOne("SELECT * FROM pages WHERE id = ?", [$id]);
        if (!$page) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $space   = $this->loadSpace((int)$page['space_id']);
        $spaces  = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $parents = Database::fetchAll("SELECT id, title FROM pages WHERE space_id=? AND id<>? ORDER BY title", [$page['space_id'], $id]);
        $templates = [];
        require PALADIN_ROOT . '/views/pages/form.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = Database::fetchOne("SELECT * FROM pages WHERE id = ?", [$id]);
        if (!$page) { http_response_code(404); return; }

        $title  = Security::sanitizeInput($_POST['title'] ?? '');
        $body   = Security::sanitizeHtml($_POST['body'] ?? '');
        $status = in_array($_POST['status'] ?? $page['status'], ['draft','in_review','published'], true) ? $_POST['status'] : $page['status'];
        $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $newVersion = (int)$page['current_version'] + 1;

        Database::update('pages', [
            'title'           => $title,
            'body'            => $body,
            'status'          => $status,
            'parent_id'       => $parent,
            'current_version' => $newVersion,
            'published_at'    => $status === 'published' ? ($page['published_at'] ?: date('Y-m-d H:i:s')) : $page['published_at'],
        ], 'id = ?', [$id]);
        Database::insert('page_versions', [
            'page_id' => $id, 'version' => $newVersion, 'title' => $title, 'body' => $body,
            'change_note' => Security::sanitizeInput($_POST['change_note'] ?? '') ?: 'Updated', 'edited_by' => Auth::id(),
        ]);
        Auth::log('update_page', 'pages', $id, ['version' => $newVersion]);
        $_SESSION['flash_success'] = 'Page saved (v' . $newVersion . ').';
        header('Location: /pages/' . $id);
    }

    public function publish(int $id): void {
        Auth::requirePermission('page.publish');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('pages', ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Auth::log('publish_page', 'pages', $id);
        $_SESSION['flash_success'] = 'Page published.';
        header('Location: /pages/' . $id);
    }

    public function history(int $id): void {
        Auth::requirePermission('page.view');
        $page = Database::fetchOne("SELECT id, title, space_id, current_version FROM pages WHERE id=?", [$id]);
        if (!$page) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $versions = Database::fetchAll(
            "SELECT pv.*, u.name AS editor FROM page_versions pv LEFT JOIN users u ON u.id=pv.edited_by
             WHERE pv.page_id=? ORDER BY pv.version DESC", [$id]
        );
        require PALADIN_ROOT . '/views/pages/history.php';
    }

    public function restore(int $id, int $version): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = Database::fetchOne("SELECT * FROM pages WHERE id=?", [$id]);
        $v    = Database::fetchOne("SELECT * FROM page_versions WHERE page_id=? AND version=?", [$id, $version]);
        if (!$page || !$v) { http_response_code(404); return; }
        $newVersion = (int)$page['current_version'] + 1;
        Database::update('pages', ['title' => $v['title'], 'body' => $v['body'], 'current_version' => $newVersion], 'id = ?', [$id]);
        Database::insert('page_versions', [
            'page_id' => $id, 'version' => $newVersion, 'title' => $v['title'], 'body' => $v['body'],
            'change_note' => 'Restored from v' . $version, 'edited_by' => Auth::id(),
        ]);
        Auth::log('restore_page', 'pages', $id, ['from_version' => $version]);
        $_SESSION['flash_success'] = 'Restored version ' . $version . '.';
        header('Location: /pages/' . $id);
    }

    public function comment(int $id): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $body = Security::sanitizeInput($_POST['body'] ?? '');
        if ($body !== '') {
            Database::insert('comments', ['entity_type' => 'page', 'entity_id' => $id, 'user_id' => Auth::id(), 'body' => $body]);
            Auth::log('comment_page', 'pages', $id);
        }
        header('Location: /pages/' . $id . '#comments');
    }

    public function delete(int $id): void {
        Auth::requirePermission('page.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = Database::fetchOne("SELECT space_id FROM pages WHERE id=?", [$id]);
        Database::query("DELETE FROM pages WHERE id = ?", [$id]);
        Auth::log('delete_page', 'pages', $id);
        $_SESSION['flash_success'] = 'Page deleted.';
        header('Location: /spaces/' . (int)($page['space_id'] ?? 0));
    }
}
