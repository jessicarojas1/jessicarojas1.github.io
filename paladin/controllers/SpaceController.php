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
        if (!SpaceAccess::canView($space)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        // Published pages in tree order (parents before children, then position).
        $pages = Database::fetchAll(
            "SELECT id, parent_id, title, body, position, updated_at
             FROM pages WHERE space_id = ? AND status = 'published' AND deleted_at IS NULL
             ORDER BY COALESCE(parent_id, 0), position, title",
            [$id]
        );
        // Drop pages the current user may not view (per-page restrictions).
        $pages = array_values(array_filter($pages, static fn($p) => PageAccess::canView(array_merge($p, ['space_id' => $id]))));
        Auth::log('export_space', 'spaces', $id, ['pages' => count($pages)]);
        require PALADIN_ROOT . '/views/spaces/export.php';
    }

    /** Export every viewable published page in the space as a single Word (.doc). */
    public function exportWord(int $id): void {
        Auth::requirePermission('space.view');
        $space = Database::fetchOne(
            "SELECT s.*, u.name AS owner_name FROM spaces s LEFT JOIN users u ON u.id = s.owner_id WHERE s.id = ?",
            [$id]
        );
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        if (!SpaceAccess::canView($space)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        $pages = Database::fetchAll(
            "SELECT id, parent_id, title, body, position, updated_at
             FROM pages WHERE space_id = ? AND status = 'published' AND deleted_at IS NULL
             ORDER BY COALESCE(parent_id, 0), position, title",
            [$id]
        );
        $pages = array_values(array_filter($pages, static fn($p) => PageAccess::canView(array_merge($p, ['space_id' => $id]))));
        if (!$pages) { $_SESSION['flash_error'] = 'No published pages to export.'; header('Location: /spaces/' . $id); return; }

        // One native .docx with a cover + contents, each page on its own page.
        $sections = [];
        foreach ($pages as $p) {
            $sections[] = [
                'title' => (string)$p['title'],
                'meta'  => 'Last updated ' . date('M j, Y', strtotime((string)$p['updated_at'])),
                'html'  => (string)$p['body'],
            ];
        }
        $bytes = Docx::fromSections((string)$space['name'], $sections, [
            'Space export' => count($pages) . ' page(s)',
            'Exported'     => date('M j, Y g:ia'),
        ]);

        Auth::log('export_space_word', 'spaces', $id, ['pages' => count($pages)]);
        $fname = preg_replace('/[^A-Za-z0-9._-]+/', '-', 'space-' . (string)($space['space_key'] ?? $id));
        $fname = trim((string)$fname, '-') ?: ('space-' . $id);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $fname . '.docx"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    /** Export every viewable published page in the space as a ZIP of PDFs. */
    public function exportPdfZip(int $id): void {
        Auth::requirePermission('space.view');
        $space = Database::fetchOne(
            "SELECT s.*, u.name AS owner_name FROM spaces s LEFT JOIN users u ON u.id = s.owner_id WHERE s.id = ?",
            [$id]
        );
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        if (!SpaceAccess::canView($space)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        if (!class_exists('ZipArchive')) { http_response_code(501); echo 'ZIP export is unavailable on this server.'; return; }

        $pages = Database::fetchAll(
            "SELECT id, parent_id, title, body, updated_at
             FROM pages WHERE space_id = ? AND status = 'published' AND deleted_at IS NULL
             ORDER BY COALESCE(parent_id, 0), position, title",
            [$id]
        );
        $pages = array_values(array_filter($pages, static fn($p) => PageAccess::canView(array_merge($p, ['space_id' => $id]))));
        if (!$pages) { $_SESSION['flash_error'] = 'No published pages to export.'; header('Location: /spaces/' . $id); return; }

        $tmp = tempnam(sys_get_temp_dir(), 'palzip');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) { http_response_code(500); echo 'Could not create archive.'; return; }
        $used = [];
        foreach ($pages as $i => $p) {
            $meta = [
                'Space'    => (string)$space['name'],
                'Updated'  => date('M j, Y', strtotime((string)$p['updated_at'])),
                'Exported' => date('M j, Y g:ia'),
            ];
            $pdf = Pdf::fromHtml((string)$p['title'], (string)$p['body'], $meta);
            $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$p['title']) ?: 'page');
            $slug = trim($slug, '-');
            $name = sprintf('%02d-%s-%d.pdf', $i + 1, substr($slug, 0, 50), (int)$p['id']);
            if (isset($used[$name])) { $name = ($i + 1) . '-' . (int)$p['id'] . '.pdf'; }
            $used[$name] = true;
            $zip->addFromString($name, $pdf);
        }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        Auth::log('export_space_zip', 'spaces', $id, ['pages' => count($pages)]);
        $fname = preg_replace('/[^A-Za-z0-9._-]/', '', ($space['space_key'] ?? 'space') . '-pdfs.zip');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen((string)$bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
    }

    public function index(): void {
        Auth::requirePermission('space.view');
        $type = Security::sanitizeInput($_GET['type'] ?? '');
        $q    = Security::sanitizeInput($_GET['q'] ?? '');

        $where = ['s.is_archived = FALSE']; $params = [];
        if ($type && in_array($type, View::spaceTypes(), true)) { $where[] = 's.type = ?'; $params[] = $type; }
        if ($q) { $where[] = '(s.name ILIKE ? OR s.space_key ILIKE ? OR s.description ILIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        // Hide private spaces from non-members (system admins see everything).
        if (Auth::role() !== 'admin') {
            $where[] = '(s.is_private = FALSE OR EXISTS (SELECT 1 FROM space_members m WHERE m.space_id = s.id AND m.user_id = ?))';
            $params[] = Auth::id();
        }
        $whereSql = implode(' AND ', $where);

        $spaces = Database::fetchAll(
            "SELECT s.*, u.name AS owner_name,
                    (SELECT COUNT(*) FROM pages p WHERE p.space_id = s.id AND p.deleted_at IS NULL) AS page_count,
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
        if (!SpaceAccess::canView($space)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        $canManageSpace = SpaceAccess::canManage($space);

        $pages = Database::fetchAll(
            "SELECT id, parent_id, title, status, position, owner_id, created_by FROM pages WHERE space_id = ? AND deleted_at IS NULL ORDER BY position, title",
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
        $addableUsers = $canManageSpace ? Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name") : [];
        $homepage = null;
        if (!empty($space['homepage_id'])) {
            $homepage = Database::fetchOne(
                "SELECT id, title, body FROM pages WHERE id = ? AND space_id = ? AND deleted_at IS NULL",
                [(int)$space['homepage_id'], $id]
            );
        }
        $shortcuts = Database::fetchAll(
            "SELECT id, label, url, icon FROM space_shortcuts WHERE space_id = ? ORDER BY sort_order, id", [$id]
        );
        require PALADIN_ROOT . '/views/spaces/view.php';
    }

    /**
     * Normalise a shortcut URL: allow only absolute http(s) URLs or same-site
     * root-relative paths. Returns null when the URL is unsafe.
     */
    private static function safeShortcutUrl(string $url): ?string {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) { return null; }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) { return $url; }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST)) { return $url; }
        return null;
    }

    public function addShortcut(int $id): void {
        $space = $this->guardManage($id);
        if (!$space) { return; }
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $label = Security::sanitizeInput($_POST['label'] ?? '');
        $url   = self::safeShortcutUrl((string)($_POST['url'] ?? ''));
        $icon  = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_POST['icon'] ?? 'bi-link-45deg'))) ?: 'bi-link-45deg';
        if ($label === '' || $url === null) {
            $_SESSION['flash_error'] = 'A label and a valid http(s) or /relative URL are required.';
            header('Location: /spaces/' . $id); return;
        }
        $next = (int)(Database::fetchOne("SELECT COALESCE(MAX(sort_order),0)+1 n FROM space_shortcuts WHERE space_id=?", [$id])['n'] ?? 1);
        Database::insert('space_shortcuts', [
            'space_id' => $id, 'label' => mb_substr($label, 0, 120), 'url' => $url,
            'icon' => substr($icon, 0, 40), 'sort_order' => $next, 'created_by' => Auth::id(),
        ]);
        Auth::log('add_space_shortcut', 'spaces', $id, ['label' => $label]);
        $_SESSION['flash_success'] = 'Shortcut added.';
        header('Location: /spaces/' . $id);
    }

    public function removeShortcut(int $id, int $shortcutId): void {
        $space = $this->guardManage($id);
        if (!$space) { return; }
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM space_shortcuts WHERE id = ? AND space_id = ?", [$shortcutId, $id]);
        Auth::log('remove_space_shortcut', 'spaces', $id, ['shortcut' => $shortcutId]);
        $_SESSION['flash_success'] = 'Shortcut removed.';
        header('Location: /spaces/' . $id);
    }

    /** Roles assignable to a space member. */
    private const SPACE_ROLES = ['admin', 'contributor', 'reviewer', 'approver', 'viewer'];

    /** Load a space and enforce manage rights; emits 403/404 and returns null. */
    private function guardManage(int $id): ?array {
        Auth::requirePermission('space.view');
        $space = Database::fetchOne("SELECT * FROM spaces WHERE id = ?", [$id]);
        if (!$space) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return null; }
        if (!SpaceAccess::canManage($space)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return null; }
        return $space;
    }

    public function addMember(int $id): void {
        if (!($space = $this->guardManage($id))) return;
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', self::SPACE_ROLES, true) ? $_POST['role'] : 'viewer';
        if ($userId && Database::fetchOne("SELECT 1 FROM users WHERE id=? AND is_active=TRUE", [$userId])) {
            Database::query(
                "INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)
                 ON CONFLICT (space_id, user_id) DO UPDATE SET role = EXCLUDED.role",
                [$id, $userId, $role]
            );
            Auth::log('add_space_member', 'spaces', $id, ['user' => $userId, 'role' => $role]);
            $_SESSION['flash_success'] = 'Member added.';
        }
        header('Location: /spaces/' . $id . '#members');
    }

    public function updateMember(int $id, int $userId): void {
        if (!($space = $this->guardManage($id))) return;
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $role = in_array($_POST['role'] ?? '', self::SPACE_ROLES, true) ? $_POST['role'] : 'viewer';
        // Never strip the last owner of their owner role.
        Database::query("UPDATE space_members SET role = ? WHERE space_id = ? AND user_id = ? AND role <> 'owner'", [$role, $id, $userId]);
        Auth::log('update_space_member', 'spaces', $id, ['user' => $userId, 'role' => $role]);
        header('Location: /spaces/' . $id . '#members');
    }

    public function removeMember(int $id, int $userId): void {
        if (!($space = $this->guardManage($id))) return;
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        // Keep the owner; they manage the space.
        Database::query("DELETE FROM space_members WHERE space_id = ? AND user_id = ? AND role <> 'owner'", [$id, $userId]);
        Auth::log('remove_space_member', 'spaces', $id, ['user' => $userId]);
        header('Location: /spaces/' . $id . '#members');
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
