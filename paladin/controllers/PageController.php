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
        $parents = $spaceId ? Database::fetchAll("SELECT id, title FROM pages WHERE space_id=? AND deleted_at IS NULL ORDER BY title", [$spaceId]) : [];
        $templates = Database::fetchAll("SELECT id, name, body FROM templates WHERE category IN ('page','document') AND is_active=TRUE ORDER BY name");
        $page = null;
        // Prefill from a built-in blueprint (?blueprint=KEY) or a saved template (?template=ID).
        if (!empty($_GET['blueprint']) && ($bp = Blueprint::get((string)$_GET['blueprint']))) {
            $page = ['title' => $bp['title'], 'body' => $bp['body'], 'parent_id' => null];
        } elseif (!empty($_GET['template'])) {
            $tpl = Database::fetchOne("SELECT name, body FROM templates WHERE id=? AND is_active=TRUE", [(int)$_GET['template']]);
            if ($tpl) $page = ['title' => '', 'body' => $tpl['body'], 'parent_id' => null];
        }
        require PALADIN_ROOT . '/views/pages/form.php';
    }

    /** Blueprint & template gallery for starting a new page. */
    public function templateGallery(int $spaceId = 0): void {
        Auth::requirePermission('page.create');
        $spaceId = $spaceId ?: (int)($_GET['space'] ?? 0);
        $space   = $spaceId ? $this->loadSpace($spaceId) : null;
        $spaces  = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $blueprints = Blueprint::all();
        $templates = Database::fetchAll("SELECT id, name, description FROM templates WHERE category IN ('page','document') AND is_active=TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/pages/templates.php';
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
        PageTasks::sync($id, $body);
        PageProps::sync($id, $body);
        if ($status === 'published') { Webhook::dispatch('page.published', ['id' => $id, 'actor' => Auth::id()]); $this->notifySpaceWatchers($spaceId, $id, $title); }
        $_SESSION['flash_success'] = 'Page created.';
        header('Location: /pages/' . $id);
    }

    public function importForm(int $spaceId = 0): void {
        Auth::requirePermission('page.create');
        $spaceId = $spaceId ?: (int)($_GET['space'] ?? 0);
        $space   = $spaceId ? $this->loadSpace($spaceId) : null;
        $spaces  = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $parents = $spaceId ? Database::fetchAll("SELECT id, title FROM pages WHERE space_id=? AND deleted_at IS NULL ORDER BY title", [$spaceId]) : [];
        require PALADIN_ROOT . '/views/pages/import.php';
    }

    public function import(): void {
        Auth::requirePermission('page.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $spaceId = (int)($_POST['space_id'] ?? 0);
        $title   = Security::sanitizeInput($_POST['title'] ?? '');
        $markdown = (string)($_POST['markdown'] ?? '');
        if (!$spaceId || $title === '') { $_SESSION['flash_error'] = 'Space and title are required.'; header('Location: /pages/import'); return; }
        if (!$this->loadSpace($spaceId)) { $_SESSION['flash_error'] = 'Invalid space.'; header('Location: /pages/import'); return; }
        if (trim($markdown) === '') { $_SESSION['flash_error'] = 'Paste some Markdown to import.'; header('Location: /pages/import?space=' . $spaceId); return; }

        $body   = Security::sanitizeHtml(Markdown::toHtml($markdown));
        $status = (Auth::can('page.publish') && !empty($_POST['publish'])) ? 'published' : 'draft';
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
        Database::insert('page_versions', ['page_id' => $id, 'version' => 1, 'title' => $title, 'body' => $body, 'change_note' => 'Imported from Markdown', 'edited_by' => Auth::id()]);
        Auth::log('import_page', 'pages', $id, ['title' => $title]);
        PageTasks::sync($id, $body);
        PageProps::sync($id, $body);
        if ($status === 'published') { Webhook::dispatch('page.published', ['id' => $id, 'actor' => Auth::id()]); $this->notifySpaceWatchers($spaceId, $id, $title); }
        $_SESSION['flash_success'] = 'Page imported from Markdown.';
        header('Location: /pages/' . $id);
    }

    public function view(int $id): void {
        Auth::requirePermission('page.view');
        $page = Database::fetchOne(
            "SELECT p.*, s.space_key, s.name AS space_name, s.is_private AS space_private, s.homepage_id AS space_homepage, o.name AS owner_name
             FROM pages p JOIN spaces s ON s.id = p.space_id
             LEFT JOIN users o ON o.id = p.owner_id WHERE p.id = ? AND p.deleted_at IS NULL",
            [$id]
        );
        if (!$page) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        // A page in a private space is only visible to that space's members.
        if (!SpaceAccess::canView(['id' => (int)$page['space_id'], 'is_private' => $page['space_private']])) {
            http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return;
        }
        if (!PageAccess::canView($page)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        $children = Database::fetchAll("SELECT id, title, status FROM pages WHERE parent_id = ? AND deleted_at IS NULL ORDER BY position, title", [$id]);
        $crumbs   = $this->ancestry($page);
        $restrictions = Database::fetchAll(
            "SELECT pr.*, u.name AS user_name FROM page_restrictions pr
             LEFT JOIN users u ON u.id = (CASE WHEN pr.principal_type='user' AND pr.principal ~ '^[0-9]+$' THEN pr.principal::int ELSE NULL END)
             WHERE pr.page_id = ? ORDER BY pr.mode, pr.principal_type", [$id]
        );
        $canEditPage = PageAccess::canEdit($page);
        $canManageSpace = SpaceAccess::canManage(['id' => (int)$page['space_id'], 'is_private' => $page['space_private']]);
        $isHomepage = (int)($page['space_homepage'] ?? 0) === $id;
        $allUsers = $canEditPage ? Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name") : [];
        $pageTasks = PageTasks::forPage($id);
        $taskUsers = $pageTasks ? Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name") : [];
        $pageLike = Reactions::one('page', $id);
        $comments = Database::fetchAll(
            "SELECT c.*, u.name AS user_name, r.name AS resolver_name
             FROM comments c LEFT JOIN users u ON u.id=c.user_id LEFT JOIN users r ON r.id=c.resolved_by
             WHERE c.entity_type='page' AND c.entity_id=? ORDER BY c.created_at", [$id]
        );
        $cReactions = Reactions::summary('comment', array_map(fn($c) => (int)$c['id'], $comments));
        $versionCount = (int)(Database::fetchOne("SELECT COUNT(*) c FROM page_versions WHERE page_id=?", [$id])['c'] ?? 0);
        $isWatching = (bool)Database::fetchOne("SELECT 1 FROM watches WHERE user_id=? AND entity_type='page' AND entity_id=?", [Auth::id(), $id]);
        $watchers = Database::fetchAll(
            "SELECT u.id, u.name FROM watches w JOIN users u ON u.id=w.user_id
             WHERE w.entity_type='page' AND w.entity_id=? ORDER BY u.name", [$id]
        );
        $isFav = (bool)Database::fetchOne("SELECT 1 FROM favorites WHERE user_id=? AND entity_type='page' AND entity_id=?", [Auth::id(), $id]);
        $moveSpaces = Auth::can('page.edit')
            ? Database::fetchAll("SELECT id, name, space_key FROM spaces WHERE is_archived=FALSE AND id <> ? ORDER BY name", [(int)$page['space_id']])
            : [];
        $inlineComments = Database::fetchAll(
            "SELECT ic.*, u.name AS user_name, r.name AS resolver_name
             FROM inline_comments ic LEFT JOIN users u ON u.id=ic.user_id LEFT JOIN users r ON r.id=ic.resolved_by
             WHERE ic.page_id=? ORDER BY ic.resolved, ic.created_at", [$id]
        );
        // Backlinks: other live pages whose body links to this page (/pages/{id}).
        $backlinks = Database::fetchAll(
            "SELECT id, title FROM pages
             WHERE deleted_at IS NULL AND id <> ?
               AND (body LIKE ? OR body LIKE ?)
             ORDER BY title LIMIT 25",
            [$id, '%/pages/' . $id . '"%', '%/pages/' . $id . '#%']
        );
        $backlinks = array_values(array_filter($backlinks, fn($b) => PageAccess::canView(['id' => (int)$b['id']])));
        $labels = Database::fetchAll(
            "SELECT t.id, t.name, t.color FROM entity_tags et JOIN tags t ON t.id=et.tag_id
             WHERE et.entity_type='page' AND et.entity_id=? ORDER BY t.name", [$id]
        );
        $allTags = Database::fetchAll("SELECT id, name, color FROM tags ORDER BY name");
        $attachments = Database::fetchAll(
            "SELECT a.*, u.name AS uploader FROM attachments a LEFT JOIN users u ON u.id=a.uploaded_by
             WHERE a.entity_type='page' AND a.entity_id=? ORDER BY a.created_at DESC", [$id]
        );
        $wfStatus = Workflow::status('page', $id);
        $wfTransitions = $wfStatus ? Workflow::transitions((int)$wfStatus['template_id'], (int)$wfStatus['state_id']) : [];
        $wfHistory = Workflow::history('page', $id);
        $wfApplicable = $canEditPage ? Workflow::applicable($page['space_id'] !== null ? (int)$page['space_id'] : null) : [];
        $wfEsign = Workflow::esignatureRequired();
        Recent::track('page', $id, $page['title']);
        require PALADIN_ROOT . '/views/pages/view.php';
    }

    /** Attach an existing tag to a page as a label. */
    public function addLabel(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!$this->guardEdit($id)) return;
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
        if (!$this->guardEdit($id)) return;
        Database::query("DELETE FROM entity_tags WHERE entity_type='page' AND entity_id=? AND tag_id=?", [$id, $tagId]);
        Auth::log('unlabel_page', 'pages', $id, ['tag' => $tagId]);
        header('Location: /pages/' . $id);
    }

    public function uploadAttachment(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!$this->guardEdit($id)) return;
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

    /** Load a page and enforce per-page EDIT access; emits 404/403 and returns null on failure. */
    private function guardEdit(int $id): ?array {
        $page = Database::fetchOne("SELECT * FROM pages WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$page) { http_response_code(404); return null; }
        if (!PageAccess::canEdit($page)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return null; }
        return $page;
    }

    /** Load a page and enforce per-page VIEW access; emits 404/403 and returns null on failure. */
    private function guardView(int $id): ?array {
        $page = Database::fetchOne("SELECT * FROM pages WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$page) { http_response_code(404); return null; }
        if (!PageAccess::canView($page)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return null; }
        return $page;
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
        $page = $this->guardEdit($id);
        if (!$page) { if (http_response_code() === 404) require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $space   = $this->loadSpace((int)$page['space_id']);
        $spaces  = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $parents = Database::fetchAll("SELECT id, title FROM pages WHERE space_id=? AND id<>? AND deleted_at IS NULL ORDER BY title", [$page['space_id'], $id]);
        $templates = [];
        require PALADIN_ROOT . '/views/pages/form.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardEdit($id);
        if (!$page) return;

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
        PageTasks::sync($id, $body);
        PageProps::sync($id, $body);
        // Newly published this save → page.published; otherwise a plain update.
        if ($status === 'published' && $page['status'] !== 'published') {
            Webhook::dispatch('page.published', ['id' => $id, 'version' => $newVersion, 'actor' => Auth::id()]);
            $this->notifySpaceWatchers((int)$page['space_id'], $id, $title);
        } else {
            Webhook::dispatch('page.updated', ['id' => $id, 'version' => $newVersion, 'actor' => Auth::id()]);
        }
        $this->notifyWatchers($id, $title, 'updated');
        $_SESSION['flash_success'] = 'Page saved (v' . $newVersion . ').';
        header('Location: /pages/' . $id);
    }

    public function publish(int $id): void {
        Auth::requirePermission('page.publish');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardEdit($id);
        if (!$page) return;
        Database::update('pages', ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Auth::log('publish_page', 'pages', $id);
        Webhook::dispatch('page.published', ['id' => $id, 'actor' => Auth::id()]);
        $this->notifyWatchers($id, (string)($page['title'] ?? 'Page'), 'published');
        if ($page['status'] !== 'published') $this->notifySpaceWatchers((int)$page['space_id'], $id, (string)($page['title'] ?? 'Page'));
        $_SESSION['flash_success'] = 'Page published.';
        header('Location: /pages/' . $id);
    }

    public function history(int $id): void {
        Auth::requirePermission('page.view');
        $page = $this->guardView($id);
        if (!$page) { if (http_response_code() === 404) require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $versions = Database::fetchAll(
            "SELECT pv.*, u.name AS editor FROM page_versions pv LEFT JOIN users u ON u.id=pv.edited_by
             WHERE pv.page_id=? ORDER BY pv.version DESC", [$id]
        );
        require PALADIN_ROOT . '/views/pages/history.php';
    }

    /** Compare two revisions of a page (line-level diff of title + body). */
    public function diff(int $id): void {
        Auth::requirePermission('page.view');
        $page = $this->guardView($id);
        if (!$page) { if (http_response_code() === 404) require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $fromV = (int)($_GET['from'] ?? 0);
        $toV   = (int)($_GET['to'] ?? (int)$page['current_version']);
        if ($fromV === $toV) { $fromV = max(1, $toV - 1); }
        // Ensure from < to for a natural "old → new" reading
        if ($fromV > $toV) { [$fromV, $toV] = [$toV, $fromV]; }

        $from = Database::fetchOne("SELECT * FROM page_versions WHERE page_id=? AND version=?", [$id, $fromV]);
        $to   = Database::fetchOne("SELECT * FROM page_versions WHERE page_id=? AND version=?", [$id, $toV]);
        if (!$from || !$to) { $_SESSION['flash_error'] = 'Those revisions could not be found.'; header('Location: /pages/' . $id . '/history'); return; }

        $bodyDiff  = Diff::lines(Diff::htmlToLines($from['body']), Diff::htmlToLines($to['body']));
        $stats     = Diff::stats($bodyDiff);
        $titleDiff = $from['title'] !== $to['title'];
        $versions  = Database::fetchAll("SELECT version, created_at FROM page_versions WHERE page_id=? ORDER BY version DESC", [$id]);
        require PALADIN_ROOT . '/views/pages/diff.php';
    }

    public function restore(int $id, int $version): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardEdit($id);
        if (!$page) return;
        $v    = Database::fetchOne("SELECT * FROM page_versions WHERE page_id=? AND version=?", [$id, $version]);
        if (!$v) { http_response_code(404); return; }
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
        if (!$this->guardView($id)) return;
        $body = Security::sanitizeInput($_POST['body'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        if ($parentId && !Database::fetchOne("SELECT 1 FROM comments WHERE id=? AND entity_type='page' AND entity_id=? AND parent_id IS NULL", [$parentId, $id])) {
            $parentId = null; // only reply to a top-level comment on this page
        }
        if ($body !== '') {
            Database::insert('comments', ['entity_type' => 'page', 'entity_id' => $id, 'user_id' => Auth::id(), 'parent_id' => $parentId, 'body' => $body]);
            Auth::log('comment_page', 'pages', $id);
            $pg = Database::fetchOne("SELECT title FROM pages WHERE id=?", [$id]);
            Mentions::process($body, 'page', $id, $pg['title'] ?? null);
            Webhook::dispatch('comment.created', ['entity_type' => 'page', 'entity_id' => $id, 'actor' => Auth::id()]);
        }
        header('Location: /pages/' . $id . '#comments');
    }

    public function delete(int $id): void {
        Auth::requirePermission('page.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardEdit($id);
        if (!$page) return;
        // Soft delete: move to Trash (id preserved so links survive a restore).
        // Re-parent direct children to this page's parent so they stay visible.
        Database::query("UPDATE pages SET parent_id = ? WHERE parent_id = ? AND deleted_at IS NULL", [$page['parent_id'], $id]);
        Database::query("UPDATE pages SET deleted_at = NOW(), deleted_by = ? WHERE id = ?", [Auth::id(), $id]);
        Auth::log('trash_page', 'pages', $id);
        $_SESSION['flash_success'] = 'Page moved to Trash.';
        header('Location: /spaces/' . (int)($page['space_id'] ?? 0));
    }

    public function untrash(int $id): void {
        Auth::requirePermission('page.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = Database::fetchOne("SELECT * FROM pages WHERE id = ? AND deleted_at IS NOT NULL", [$id]);
        if (!$page) { http_response_code(404); return; }
        // If the original parent is gone (or itself trashed), restore at top level.
        $parentOk = $page['parent_id'] && Database::fetchOne("SELECT 1 FROM pages WHERE id=? AND deleted_at IS NULL", [$page['parent_id']]);
        Database::query("UPDATE pages SET deleted_at = NULL, deleted_by = NULL, parent_id = ? WHERE id = ?",
            [$parentOk ? $page['parent_id'] : null, $id]);
        Auth::log('restore_page', 'pages', $id);
        $_SESSION['flash_success'] = 'Page restored.';
        header('Location: /pages/' . $id);
    }

    public function purge(int $id): void {
        Auth::requirePermission('page.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = Database::fetchOne("SELECT space_id FROM pages WHERE id = ? AND deleted_at IS NOT NULL", [$id]);
        if (!$page) { http_response_code(404); return; }
        Database::query("DELETE FROM pages WHERE id = ?", [$id]); // permanent (versions/comments cascade)
        Auth::log('purge_page', 'pages', $id);
        $_SESSION['flash_success'] = 'Page permanently deleted.';
        header('Location: /spaces/' . (int)$page['space_id'] . '/trash');
    }

    public function trash(int $spaceId): void {
        Auth::requirePermission('page.view');
        $space = Database::fetchOne("SELECT id, name, space_key FROM spaces WHERE id = ?", [$spaceId]);
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $pages = Database::fetchAll(
            "SELECT p.id, p.title, p.status, p.deleted_at, u.name AS deleted_by_name
             FROM pages p LEFT JOIN users u ON u.id = p.deleted_by
             WHERE p.space_id = ? AND p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC",
            [$spaceId]
        );
        require PALADIN_ROOT . '/views/pages/trash.php';
    }

    // ── Per-page restrictions ────────────────────────────────────────────────
    public function addRestriction(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!$this->guardEdit($id)) return;
        $mode = in_array($_POST['mode'] ?? '', ['view','edit'], true) ? $_POST['mode'] : 'view';
        $ptype = in_array($_POST['principal_type'] ?? '', ['user','role'], true) ? $_POST['principal_type'] : 'user';
        $principal = '';
        if ($ptype === 'user') {
            $uid = (int)($_POST['principal_user'] ?? 0);
            if ($uid && Database::fetchOne("SELECT 1 FROM users WHERE id=?", [$uid])) $principal = (string)$uid;
        } else {
            $rk = Security::sanitizeInput($_POST['principal_role'] ?? '');
            if ($rk !== '' && Auth::roleExists($rk)) $principal = $rk;
        }
        if ($principal === '') { $_SESSION['flash_error'] = 'Choose a valid user or role.'; header('Location: /pages/' . $id); return; }
        try {
            Database::insert('page_restrictions', [
                'page_id' => $id, 'mode' => $mode, 'principal_type' => $ptype, 'principal' => $principal, 'created_by' => Auth::id(),
            ]);
            Auth::log('restrict_page', 'pages', $id, ['mode' => $mode, 'type' => $ptype, 'principal' => $principal]);
            $_SESSION['flash_success'] = 'Restriction added.';
        } catch (Throwable) { $_SESSION['flash_warning'] = 'That restriction already exists.'; }
        header('Location: /pages/' . $id);
    }

    public function removeRestriction(int $id, int $rid): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!$this->guardEdit($id)) return;
        Database::query("DELETE FROM page_restrictions WHERE id=? AND page_id=?", [$rid, $id]);
        Auth::log('unrestrict_page', 'pages', $id, ['restriction' => $rid]);
        $_SESSION['flash_success'] = 'Restriction removed.';
        header('Location: /pages/' . $id);
    }

    /** Printable / export-to-PDF view (clean, auto-opens the print dialog). */
    public function printView(int $id): void {
        Auth::requirePermission('page.view');
        $page = Database::fetchOne(
            "SELECT p.*, s.space_key, s.name AS space_name, o.name AS owner_name
             FROM pages p JOIN spaces s ON s.id=p.space_id LEFT JOIN users o ON o.id=p.owner_id WHERE p.id=?",
            [$id]
        );
        if (!$page) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        if (!PageAccess::canView($page)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        $labels = Database::fetchAll(
            "SELECT t.name FROM entity_tags et JOIN tags t ON t.id=et.tag_id
             WHERE et.entity_type='page' AND et.entity_id=? ORDER BY t.name", [$id]
        );
        Auth::log('export_page', 'pages', $id);
        require PALADIN_ROOT . '/views/pages/print.php';
    }

    /** Reorder a page among its siblings (same space + parent). dir = up|down. */
    public function move(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardEdit($id);
        if (!$page) return;
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';

        // Normalize sibling positions to 1..n (stable order), then swap with neighbour
        $siblings = Database::fetchAll(
            "SELECT id FROM pages WHERE space_id = ? AND parent_id IS NOT DISTINCT FROM ? AND deleted_at IS NULL
             ORDER BY position, title, id",
            [$page['space_id'], $page['parent_id']]
        );
        $ids = array_map(fn($r) => (int)$r['id'], $siblings);
        foreach ($ids as $i => $sid) { Database::query("UPDATE pages SET position = ? WHERE id = ?", [$i + 1, $sid]); }
        $idx = array_search($id, $ids, true);
        $swapWith = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx !== false && isset($ids[$swapWith])) {
            Database::query("UPDATE pages SET position = ? WHERE id = ?", [$swapWith + 1, $id]);
            Database::query("UPDATE pages SET position = ? WHERE id = ?", [$idx + 1, $ids[$swapWith]]);
            Auth::log('move_page', 'pages', $id, ['dir' => $dir]);
        }
        header('Location: /spaces/' . (int)$page['space_id']);
    }

    /** Duplicate a page into the same space as a fresh "Copy of …" draft. */
    public function duplicate(int $id): void {
        Auth::requirePermission('page.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardView($id);
        if (!$page) return;

        $title = 'Copy of ' . $page['title'];
        $title = mb_substr($title, 0, 255);
        $body  = (string)$page['body'];
        $newId = Database::insert('pages', [
            'space_id'   => (int)$page['space_id'],
            'parent_id'  => $page['parent_id'] !== null ? (int)$page['parent_id'] : null,
            'title'      => $title,
            'slug'       => substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), 0, 200),
            'body'       => $body,
            'status'     => 'draft',
            'owner_id'   => Auth::id(),
            'created_by' => Auth::id(),
            'published_at' => null,
        ]);
        Database::insert('page_versions', ['page_id' => $newId, 'version' => 1, 'title' => $title, 'body' => $body, 'change_note' => 'Duplicated from page #' . $id, 'edited_by' => Auth::id()]);
        // Carry over labels (best-effort).
        try {
            Database::query(
                "INSERT INTO entity_tags (tag_id, entity_type, entity_id)
                 SELECT tag_id, 'page', ? FROM entity_tags WHERE entity_type='page' AND entity_id=?
                 ON CONFLICT DO NOTHING", [$newId, $id]
            );
        } catch (Throwable) {}
        PageTasks::sync($newId, $body);
        PageProps::sync($newId, $body);
        Auth::log('duplicate_page', 'pages', $newId, ['from' => $id]);
        $_SESSION['flash_success'] = 'Page duplicated as a draft.';
        header('Location: /pages/' . $newId . '/edit');
    }

    /** Move a page (and its whole subtree) to another space. */
    public function moveToSpace(int $id): void {
        Auth::requirePermission('page.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardEdit($id);
        if (!$page) return;
        $targetSpace = (int)($_POST['target_space_id'] ?? 0);
        if (!$targetSpace || $targetSpace === (int)$page['space_id']) {
            $_SESSION['flash_error'] = 'Choose a different destination space.';
            header('Location: /pages/' . $id); return;
        }
        $dest = Database::fetchOne("SELECT id FROM spaces WHERE id = ? AND is_archived = FALSE", [$targetSpace]);
        if (!$dest) {
            $_SESSION['flash_error'] = 'You cannot move pages into that space.';
            header('Location: /pages/' . $id); return;
        }
        // Collect the subtree (BFS) so every descendant follows the page.
        $subtree = [$id]; $frontier = [$id]; $guard = 0;
        while ($frontier && $guard++ < 1000) {
            $place = implode(',', array_fill(0, count($frontier), '?'));
            $kids = Database::fetchAll("SELECT id FROM pages WHERE parent_id IN ($place) AND deleted_at IS NULL", $frontier);
            $frontier = array_map(fn($r) => (int)$r['id'], $kids);
            foreach ($frontier as $k) { if (!in_array($k, $subtree, true)) $subtree[] = $k; }
        }
        $place = implode(',', array_fill(0, count($subtree), '?'));
        Database::query("UPDATE pages SET space_id = ?, updated_at = NOW() WHERE id IN ($place)", array_merge([$targetSpace], $subtree));
        // The moved root detaches from its old parent and lands at the space root.
        Database::query("UPDATE pages SET parent_id = NULL WHERE id = ?", [$id]);
        Auth::log('move_page_space', 'pages', $id, ['to_space' => $targetSpace, 'pages' => count($subtree)]);
        $_SESSION['flash_success'] = count($subtree) > 1
            ? ('Moved this page and ' . (count($subtree) - 1) . ' descendant(s).')
            : 'Page moved.';
        header('Location: /pages/' . $id);
    }

    /** Set (or clear) this page as its space's homepage — space managers only. */
    public function setHomepage(int $id): void {
        Auth::requirePermission('page.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = Database::fetchOne("SELECT id, space_id FROM pages WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$page) { http_response_code(404); return; }
        $space = Database::fetchOne("SELECT * FROM spaces WHERE id = ?", [(int)$page['space_id']]);
        if (!$space || !SpaceAccess::canManage($space)) {
            $_SESSION['flash_error'] = 'Only space admins can set the homepage.';
            header('Location: /pages/' . $id); return;
        }
        $clear = !empty($_POST['clear']);
        Database::query("UPDATE spaces SET homepage_id = ?, updated_at = NOW() WHERE id = ?",
            [$clear ? null : $id, (int)$page['space_id']]);
        Auth::log($clear ? 'clear_space_homepage' : 'set_space_homepage', 'spaces', (int)$page['space_id'], ['page' => $id]);
        $_SESSION['flash_success'] = $clear ? 'Homepage cleared.' : 'Set as the space homepage.';
        header('Location: /pages/' . $id);
    }

    /** Bulk operations on selected pages within a space: trash / label / move. */
    public function bulk(int $spaceId): void {
        Auth::requirePermission('page.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $space = Database::fetchOne("SELECT id FROM spaces WHERE id = ?", [$spaceId]);
        if (!$space) { http_response_code(404); return; }

        $action = Security::sanitizeInput($_POST['action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['page_ids'] ?? '')))));
        if (!$ids) { $_SESSION['flash_error'] = 'No pages selected.'; header('Location: /spaces/' . $spaceId); return; }
        // Restrict to live pages actually in this space.
        $place = implode(',', array_fill(0, count($ids), '?'));
        $pages = Database::fetchAll(
            "SELECT id FROM pages WHERE space_id = ? AND deleted_at IS NULL AND id IN ($place)",
            array_merge([$spaceId], $ids)
        );
        $valid = array_map(fn($r) => (int)$r['id'], $pages);
        if (!$valid) { $_SESSION['flash_error'] = 'No matching pages.'; header('Location: /spaces/' . $spaceId); return; }

        $n = count($valid);
        if ($action === 'trash') {
            foreach ($valid as $pid) {
                Database::query("UPDATE pages SET parent_id = (SELECT parent_id FROM pages WHERE id=?) WHERE parent_id = ? AND deleted_at IS NULL", [$pid, $pid]);
                Database::query("UPDATE pages SET deleted_at = NOW(), deleted_by = ? WHERE id = ?", [Auth::id(), $pid]);
            }
            $_SESSION['flash_success'] = "{$n} page(s) moved to Trash.";
        } elseif ($action === 'label') {
            $tagId = (int)($_POST['tag_id'] ?? 0);
            if ($tagId && Database::fetchOne("SELECT 1 FROM tags WHERE id=?", [$tagId])) {
                foreach ($valid as $pid) {
                    Database::query("INSERT INTO entity_tags (tag_id, entity_type, entity_id) VALUES (?, 'page', ?) ON CONFLICT DO NOTHING", [$tagId, $pid]);
                }
                $_SESSION['flash_success'] = "Labelled {$n} page(s).";
            } else { $_SESSION['flash_error'] = 'Pick a label.'; }
        } elseif ($action === 'move') {
            $target = (int)($_POST['target_space'] ?? 0);
            if ($target && Database::fetchOne("SELECT 1 FROM spaces WHERE id=? AND is_archived=FALSE", [$target])) {
                foreach ($valid as $pid) {
                    Database::query("UPDATE pages SET space_id = ?, parent_id = NULL WHERE id = ?", [$target, $pid]);
                }
                $_SESSION['flash_success'] = "Moved {$n} page(s).";
                header('Location: /spaces/' . $target); return;
            }
            $_SESSION['flash_error'] = 'Pick a destination space.';
        } else {
            $_SESSION['flash_error'] = 'Unknown bulk action.';
        }
        Auth::log('bulk_pages', 'spaces', $spaceId, ['action' => $action, 'count' => $n]);
        header('Location: /spaces/' . $spaceId);
    }

    // ── Inline tasks / action items ──────────────────────────────────────────
    private function loadTask(int $taskId): ?array {
        return Database::fetchOne(
            "SELECT pt.*, p.space_id, p.deleted_at FROM page_tasks pt JOIN pages p ON p.id = pt.page_id WHERE pt.id = ?",
            [$taskId]
        );
    }

    public function toggleTask(int $taskId): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $t = $this->loadTask($taskId);
        if (!$t || $t['deleted_at'] !== null) { http_response_code(404); return; }
        $done = !in_array(strtolower((string)$t['done']), ['1','t','true'], true);
        Database::query(
            "UPDATE page_tasks SET done = ?, done_at = ?, done_by = ? WHERE id = ?",
            [$done ? 't' : 'f', $done ? date('Y-m-d H:i:s') : null, $done ? Auth::id() : null, $taskId]
        );
        Auth::log('toggle_task', 'pages', (int)$t['page_id'], ['task' => $taskId, 'done' => $done]);
        $default = '/pages/' . (int)$t['page_id'] . '#action-items';
        $ret = (string)($_POST['return'] ?? '');
        // Only allow same-site relative paths (no //host, no scheme) to avoid open redirects.
        $back = ($ret !== '' && $ret[0] === '/' && !str_starts_with($ret, '//') && preg_match('~^/[A-Za-z0-9/_#?=&.-]*$~', $ret)) ? $ret : $default;
        header('Location: ' . $back);
    }

    public function assignTask(int $taskId): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $t = $this->loadTask($taskId);
        if (!$t || $t['deleted_at'] !== null) { http_response_code(404); return; }
        $assignee = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : null;
        if ($assignee !== null && !Database::fetchOne("SELECT 1 FROM users WHERE id=? AND is_active=TRUE", [$assignee])) $assignee = null;
        $due = Security::sanitizeInput($_POST['due_date'] ?? '');
        Database::query("UPDATE page_tasks SET assignee_id = ?, due_date = ? WHERE id = ?",
            [$assignee, $due !== '' ? $due : null, $taskId]);
        if ($assignee && $assignee !== Auth::id()) {
            Database::insert('alerts', [
                'user_id' => $assignee, 'title' => 'Action item assigned',
                'body' => 'You were assigned: "' . mb_strimwidth($t['text'], 0, 120, '…') . '"',
                'severity' => 'info', 'link' => '/pages/' . (int)$t['page_id'] . '#action-items', 'is_read' => 'f',
            ]);
        }
        Auth::log('assign_task', 'pages', (int)$t['page_id'], ['task' => $taskId]);
        header('Location: /pages/' . (int)$t['page_id'] . '#action-items');
    }

    /** "My Action Items" — tasks assigned to the current user across pages. */
    public function myActionItems(): void {
        Auth::requireAuth();
        $tasks = Database::fetchAll(
            "SELECT pt.*, p.title AS page_title, s.name AS space_name
             FROM page_tasks pt
             JOIN pages p ON p.id = pt.page_id AND p.deleted_at IS NULL
             LEFT JOIN spaces s ON s.id = p.space_id
             WHERE pt.assignee_id = ? ORDER BY pt.done, pt.due_date NULLS LAST, pt.created_at",
            [Auth::id()]
        );
        require PALADIN_ROOT . '/views/pages/action_items.php';
    }

    /**
     * Drag-and-drop reorder/re-parent (AJAX). Body JSON:
     * { "csrf": "...", "parent_id": <int|null>, "position": <int> }.
     * Moves the page under parent_id (same space, no cycles) at the given
     * 1-based position among its new siblings. Returns {ok, csrf}.
     */
    public function reorder(int $id): void {
        Auth::requirePermission('page.edit');
        header('Content-Type: application/json');
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body) || !Security::validateCsrf((string)($body['csrf'] ?? ''))) {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'csrf']); return;
        }
        $page = Database::fetchOne("SELECT * FROM pages WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$page || !PageAccess::canEdit($page)) { http_response_code(404); echo json_encode(['ok' => false]); return; }

        $newParent = isset($body['parent_id']) && $body['parent_id'] !== null && $body['parent_id'] !== ''
            ? (int)$body['parent_id'] : null;
        if ($newParent !== null) {
            $p = Database::fetchOne("SELECT id, space_id FROM pages WHERE id = ? AND deleted_at IS NULL", [$newParent]);
            if (!$p || (int)$p['space_id'] !== (int)$page['space_id']) {
                http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Invalid parent.']); return;
            }
            // Cycle guard: a page cannot become a descendant of itself.
            $cursor = $newParent; $guard = 0;
            while ($cursor !== null && $guard++ < 1000) {
                if ((int)$cursor === $id) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Cannot move a page into its own subtree.']); return; }
                $row = Database::fetchOne("SELECT parent_id FROM pages WHERE id = ?", [$cursor]);
                $cursor = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            }
        }

        $pos = max(1, (int)($body['position'] ?? 1));
        // Re-sequence the destination sibling set with the moved page inserted at $pos.
        $siblings = Database::fetchAll(
            "SELECT id FROM pages WHERE space_id = ? AND parent_id IS NOT DISTINCT FROM ? AND deleted_at IS NULL AND id <> ?
             ORDER BY position, title, id",
            [$page['space_id'], $newParent, $id]
        );
        $ids = array_map(fn($r) => (int)$r['id'], $siblings);
        $insertAt = min(max(0, $pos - 1), count($ids));
        array_splice($ids, $insertAt, 0, [$id]);

        Database::query("UPDATE pages SET parent_id = ? WHERE id = ?", [$newParent, $id]);
        foreach ($ids as $i => $sid) { Database::query("UPDATE pages SET position = ? WHERE id = ?", [$i + 1, $sid]); }
        Auth::log('reorder_page', 'pages', $id, ['parent' => $newParent, 'position' => $insertAt + 1]);
        echo json_encode(['ok' => true, 'csrf' => Security::generateCsrfToken()]);
    }

    // ── Inline (anchored) comments ───────────────────────────────────────────
    public function addInlineComment(int $id): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $page = $this->guardView($id);
        if (!$page) { http_response_code(404); return; }
        $quote = trim((string)($_POST['quote'] ?? ''));
        $body  = Security::sanitizeInput($_POST['body'] ?? '');
        if ($quote === '' || $body === '') {
            $_SESSION['flash_error'] = 'Select some text and write a comment.';
            header('Location: /pages/' . $id); return;
        }
        $quote  = mb_substr($quote, 0, 1000);
        $prefix = mb_substr(trim((string)($_POST['prefix'] ?? '')), -160);
        $suffix = mb_substr(trim((string)($_POST['suffix'] ?? '')), 0, 160);
        $cid = Database::insert('inline_comments', [
            'page_id' => $id, 'user_id' => Auth::id(),
            'quote' => $quote, 'prefix' => $prefix ?: null, 'suffix' => $suffix ?: null,
            'body' => $body,
        ]);
        Auth::log('inline_comment_page', 'pages', $id, ['comment' => $cid]);
        Mentions::process($body, 'page', $id, $page['title'] ?? null);
        $_SESSION['flash_success'] = 'Inline comment added.';
        header('Location: /pages/' . $id . '#ic-' . $cid);
    }

    public function resolveInlineComment(int $id): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $c = Database::fetchOne("SELECT * FROM inline_comments WHERE id=?", [$id]);
        if (!$c) { http_response_code(404); return; }
        if (!$this->guardView((int)$c['page_id'])) { http_response_code(403); return; }
        if ((int)$c['user_id'] !== Auth::id() && !Auth::can('page.edit')) {
            $_SESSION['flash_error'] = 'You can only resolve your own inline comments.';
            header('Location: /pages/' . (int)$c['page_id']); return;
        }
        // inline_comments has no updated_at column, so avoid Database::update (which appends it).
        Database::query("UPDATE inline_comments SET resolved = TRUE, resolved_by = ?, resolved_at = NOW() WHERE id = ?", [Auth::id(), $id]);
        Auth::log('resolve_inline_comment', 'pages', (int)$c['page_id'], ['comment' => $id]);
        header('Location: /pages/' . (int)$c['page_id']);
    }

    public function deleteInlineComment(int $id): void {
        Auth::requirePermission('page.comment');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $c = Database::fetchOne("SELECT * FROM inline_comments WHERE id=?", [$id]);
        if (!$c) { http_response_code(404); return; }
        if ((int)$c['user_id'] !== Auth::id() && !Auth::can('page.edit')) {
            $_SESSION['flash_error'] = 'You can only delete your own inline comments.';
            header('Location: /pages/' . (int)$c['page_id']); return;
        }
        Database::query("DELETE FROM inline_comments WHERE id=?", [$id]);
        Auth::log('delete_inline_comment', 'pages', (int)$c['page_id'], ['comment' => $id]);
        header('Location: /pages/' . (int)$c['page_id']);
    }

    public function toggleWatch(int $id): void {
        Auth::requirePermission('page.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $exists = Database::fetchOne("SELECT id FROM watches WHERE user_id=? AND entity_type='page' AND entity_id=?", [Auth::id(), $id]);
        if ($exists) Database::query("DELETE FROM watches WHERE id=?", [$exists['id']]);
        else Database::insert('watches', ['user_id' => Auth::id(), 'entity_type' => 'page', 'entity_id' => $id]);
        header('Location: /pages/' . $id);
    }

    public function toggleFavorite(int $id): void {
        Auth::requirePermission('page.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $exists = Database::fetchOne("SELECT id FROM favorites WHERE user_id=? AND entity_type='page' AND entity_id=?", [Auth::id(), $id]);
        if ($exists) Database::query("DELETE FROM favorites WHERE id=?", [$exists['id']]);
        else Database::insert('favorites', ['user_id' => Auth::id(), 'entity_type' => 'page', 'entity_id' => $id]);
        header('Location: /pages/' . $id);
    }

    /**
     * Alert everyone watching a page (except the actor) that it changed.
     * Best-effort — never blocks the save.
     */
    private function notifyWatchers(int $pageId, string $title, string $verb): void {
        try {
            $watchers = Database::fetchAll(
                "SELECT user_id FROM watches WHERE entity_type='page' AND entity_id=? AND user_id <> ?",
                [$pageId, Auth::id()]
            );
            foreach ($watchers as $w) {
                Database::insert('alerts', [
                    'user_id'  => (int)$w['user_id'],
                    'title'    => 'Watched page ' . $verb,
                    'body'     => '"' . $title . '" was ' . $verb . ' by ' . (Auth::user()['name'] ?? 'someone') . '.',
                    'severity' => 'info',
                    'link'     => '/pages/' . $pageId,
                    'is_read'  => 'f',
                ]);
            }
        } catch (\Throwable) { /* best effort */ }
    }

    /**
     * Alert everyone watching the space (except the actor and anyone already
     * watching the page) that a new page was published in it. Best-effort.
     */
    private function notifySpaceWatchers(int $spaceId, int $pageId, string $title): void {
        try {
            $watchers = Database::fetchAll(
                "SELECT user_id FROM watches
                 WHERE entity_type='space' AND entity_id=? AND user_id <> ?
                   AND user_id NOT IN (SELECT user_id FROM watches WHERE entity_type='page' AND entity_id=?)",
                [$spaceId, Auth::id(), $pageId]
            );
            $sp = Database::fetchOne("SELECT name FROM spaces WHERE id=?", [$spaceId]);
            foreach ($watchers as $w) {
                Database::insert('alerts', [
                    'user_id'  => (int)$w['user_id'],
                    'title'    => 'New page in a watched space',
                    'body'     => '"' . $title . '" was published in ' . ($sp['name'] ?? 'a space') . ' by ' . (Auth::user()['name'] ?? 'someone') . '.',
                    'severity' => 'info',
                    'link'     => '/pages/' . $pageId,
                    'is_read'  => 'f',
                ]);
            }
        } catch (\Throwable) { /* best effort */ }
    }
}
