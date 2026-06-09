<?php
declare(strict_types=1);

class SpaceController {

    /** Export a space's published pages as one print-friendly HTML document. */
    public function export(int $id): void {
        Auth::requirePermission('space.view');
        $space = Database::fetchOne(
            "SELECT s.*, u.name AS owner_name FROM spaces s LEFT JOIN users u ON u.id = s.owner_id WHERE s.id = ?",
            [$id]
        );
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        // Published pages in tree order (parents before children, then position).
        $pages = Database::fetchAll(
            "SELECT id, parent_id, title, body, position, updated_at
             FROM pages WHERE space_id = ? AND status = 'published'
             ORDER BY COALESCE(parent_id, 0), position, title",
            [$id]
        );
        // Drop pages the current user may not view (per-page restrictions).
        $pages = array_values(array_filter($pages, static fn($p) => PageAccess::canView(array_merge($p, ['space_id' => $id]))));
        Auth::log('export_space', 'spaces', $id, ['pages' => count($pages)]);
        require PALADIN_ROOT . '/views/spaces/export.php';
    }

    public function index(): void {
        Auth::requirePermission('space.view');
        $type = Security::sanitizeInput($_GET['type'] ?? '');
        $q    = Security::sanitizeInput($_GET['q'] ?? '');

        $where = ['s.is_archived = FALSE']; $params = [];
        if ($type && in_array($type, View::spaceTypes(), true)) { $where[] = 's.type = ?'; $params[] = $type; }
        if ($q) { $where[] = '(s.name ILIKE ? OR s.space_key ILIKE ? OR s.description ILIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        $whereSql = implode(' AND ', $where);

        $spaces = Database::fetchAll(
            "SELECT s.*, u.name AS owner_name,
                    (SELECT COUNT(*) FROM pages p WHERE p.space_id = s.id) AS page_count,
                    (SELECT COUNT(*) FROM documents d WHERE d.space_id = s.id) AS doc_count,
                    (SELECT COUNT(*) FROM favorites f WHERE f.entity_type='space' AND f.entity_id=s.id AND f.user_id=?) AS is_fav
             FROM spaces s LEFT JOIN users u ON u.id = s.owner_id
             WHERE {$whereSql} ORDER BY is_fav DESC, s.name",
            [Auth::id(), ...$params]
        );
        require PALADIN_ROOT . '/views/spaces/index.php';
    }

    public function view(int $id): void {
        Auth::requirePermission('space.view');
        $space = Database::fetchOne(
            "SELECT s.*, u.name AS owner_name FROM spaces s LEFT JOIN users u ON u.id = s.owner_id WHERE s.id = ?",
            [$id]
        );
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $pages = Database::fetchAll(
            "SELECT id, parent_id, title, status, position, owner_id, created_by FROM pages WHERE space_id = ? ORDER BY position, title",
            [$id]
        );
        // Hide pages the current user is restricted from viewing
        $pages = array_values(array_filter($pages, fn($p) => PageAccess::canView($p)));
        $documents = Database::fetchAll(
            "SELECT id, document_code, title, doc_type, status, revision, updated_at FROM documents WHERE space_id = ? ORDER BY updated_at DESC",
            [$id]
        );
        $processes = Database::fetchAll(
            "SELECT id, process_code, name, status, version FROM processes WHERE space_id = ? ORDER BY name",
            [$id]
        );
        $members = Database::fetchAll(
            "SELECT sm.role, u.id, u.name, u.title FROM space_members sm JOIN users u ON u.id = sm.user_id WHERE sm.space_id = ? ORDER BY sm.role, u.name",
            [$id]
        );
        $isWatching = (bool)Database::fetchOne("SELECT 1 FROM watches WHERE user_id=? AND entity_type='space' AND entity_id=?", [Auth::id(), $id]);
        $isFav      = (bool)Database::fetchOne("SELECT 1 FROM favorites WHERE user_id=? AND entity_type='space' AND entity_id=?", [Auth::id(), $id]);
        require PALADIN_ROOT . '/views/spaces/view.php';
    }

    public function createForm(): void {
        Auth::requirePermission('space.create');
        $space = null;
        require PALADIN_ROOT . '/views/spaces/form.php';
    }

    public function create(): void {
        Auth::requirePermission('space.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $key  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Security::sanitizeInput($_POST['space_key'] ?? '')));
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        $type = Security::sanitizeInput($_POST['type'] ?? 'team');
        if ($key === '' || $name === '') { $_SESSION['flash_error'] = 'Space key and name are required.'; header('Location: /spaces/create'); return; }
        if (!in_array($type, View::spaceTypes(), true)) $type = 'team';
        if (Database::fetchOne("SELECT 1 FROM spaces WHERE space_key = ?", [$key])) {
            $_SESSION['flash_error'] = "Space key '{$key}' is already in use."; header('Location: /spaces/create'); return;
        }

        $id = Database::insert('spaces', [
            'space_key'   => $key,
            'name'        => $name,
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'type'        => $type,
            'icon'        => Security::sanitizeInput($_POST['icon'] ?? 'bi-folder2-open') ?: 'bi-folder2-open',
            'color'       => Branding::sanitizeColor($_POST['color'] ?? '') ?: '#2563eb',
            'owner_id'    => Auth::id(),
            'is_private'  => !empty($_POST['is_private']) ? 't' : 'f',
            'created_by'  => Auth::id(),
        ]);
        Database::insert('space_members', ['space_id' => $id, 'user_id' => Auth::id(), 'role' => 'owner']);
        Auth::log('create_space', 'spaces', $id, ['space_key' => $key]);
        Webhook::dispatch('space.created', ['id' => $id, 'key' => $key, 'name' => $name, 'actor' => Auth::id()]);
        $_SESSION['flash_success'] = "Space '{$name}' created.";
        header('Location: /spaces/' . $id);
    }

    public function editForm(int $id): void {
        Auth::requirePermission('space.edit');
        $space = Database::fetchOne("SELECT * FROM spaces WHERE id = ?", [$id]);
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        require PALADIN_ROOT . '/views/spaces/form.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('space.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $space = Database::fetchOne("SELECT id FROM spaces WHERE id = ?", [$id]);
        if (!$space) { http_response_code(404); return; }

        $type = Security::sanitizeInput($_POST['type'] ?? 'team');
        if (!in_array($type, View::spaceTypes(), true)) $type = 'team';
        Database::update('spaces', [
            'name'        => Security::sanitizeInput($_POST['name'] ?? ''),
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'type'        => $type,
            'icon'        => Security::sanitizeInput($_POST['icon'] ?? 'bi-folder2-open') ?: 'bi-folder2-open',
            'color'       => Branding::sanitizeColor($_POST['color'] ?? '') ?: '#2563eb',
            'is_private'  => !empty($_POST['is_private']) ? 't' : 'f',
        ], 'id = ?', [$id]);
        Auth::log('update_space', 'spaces', $id);
        $_SESSION['flash_success'] = 'Space updated.';
        header('Location: /spaces/' . $id);
    }

    public function delete(int $id): void {
        Auth::requirePermission('space.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('spaces', ['is_archived' => 't'], 'id = ?', [$id]);
        Auth::log('archive_space', 'spaces', $id);
        $_SESSION['flash_success'] = 'Space archived.';
        header('Location: /spaces');
    }

    public function toggleWatch(int $id): void {
        Auth::requirePermission('space.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $exists = Database::fetchOne("SELECT id FROM watches WHERE user_id=? AND entity_type='space' AND entity_id=?", [Auth::id(), $id]);
        if ($exists) Database::query("DELETE FROM watches WHERE id=?", [$exists['id']]);
        else Database::insert('watches', ['user_id' => Auth::id(), 'entity_type' => 'space', 'entity_id' => $id]);
        header('Location: /spaces/' . $id);
    }

    public function toggleFavorite(int $id): void {
        Auth::requirePermission('space.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $exists = Database::fetchOne("SELECT id FROM favorites WHERE user_id=? AND entity_type='space' AND entity_id=?", [Auth::id(), $id]);
        if ($exists) Database::query("DELETE FROM favorites WHERE id=?", [$exists['id']]);
        else Database::insert('favorites', ['user_id' => Auth::id(), 'entity_type' => 'space', 'entity_id' => $id]);
        header('Location: /spaces/' . $id);
    }
}
