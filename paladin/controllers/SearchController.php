<?php
declare(strict_types=1);

class SearchController {

    /** Allowed entity types for the optional ?type restriction. */
    private const TYPES = ['documents', 'pages', 'blogs', 'comments', 'processes', 'tasks', 'spaces'];

    public function index(): void {
        Auth::requirePermission('search.view');
        $q    = trim(Security::sanitizeInput($_GET['q'] ?? ''));
        $type = Security::sanitizeInput($_GET['type'] ?? '');
        if ($type !== '' && !in_array($type, self::TYPES, true)) $type = '';

        $results = array_fill_keys(self::TYPES, []);
        $total   = 0;

        // Space-privacy fragment (aliased space "s"): open spaces, admins, or members.
        $priv = "(s.id IS NULL OR s.is_private = FALSE OR ? = 'admin'
                  OR EXISTS (SELECT 1 FROM space_members m WHERE m.space_id = s.id AND m.user_id = ?))";

        if ($q !== '') {
            $like = "%{$q}%";
            $role = Auth::role(); $uid = Auth::id();

            if ($type === '' || $type === 'documents') {
                $results['documents'] = Database::fetchAll(
                    "SELECT d.id, d.document_code, d.title, d.status, d.doc_type
                     FROM documents d
                     WHERE d.title ILIKE ? OR d.document_code ILIKE ? OR d.description ILIKE ? OR d.body ILIKE ?
                     ORDER BY d.updated_at DESC LIMIT 25",
                    [$like, $like, $like, $like]
                );
            }
            if ($type === '' || $type === 'pages') {
                $rows = Database::fetchAll(
                    "SELECT p.id, p.title, p.status, p.owner_id, p.created_by, p.space_id, s.name AS space_name
                     FROM pages p LEFT JOIN spaces s ON s.id = p.space_id
                     WHERE (p.title ILIKE ? OR p.body ILIKE ?) AND p.deleted_at IS NULL AND {$priv}
                     ORDER BY p.updated_at DESC LIMIT 40",
                    [$like, $like, $role, $uid]
                );
                // Honour per-page restrictions, then cap.
                $results['pages'] = array_slice(array_values(array_filter($rows, fn($p) => PageAccess::canView($p))), 0, 25);
            }
            if ($type === '' || $type === 'blogs') {
                $results['blogs'] = Database::fetchAll(
                    "SELECT b.id, b.title, b.status, s.name AS space_name
                     FROM blog_posts b LEFT JOIN spaces s ON s.id = b.space_id
                     WHERE (b.title ILIKE ? OR b.body ILIKE ?) AND (b.status='published' OR b.author_id = ?) AND {$priv}
                     ORDER BY b.published_at DESC NULLS LAST LIMIT 25",
                    [$like, $like, $uid, $role, $uid]
                );
            }
            if ($type === '' || $type === 'comments') {
                $results['comments'] = Database::fetchAll(
                    "SELECT c.id, c.entity_type, c.entity_id, c.body, u.name AS author
                     FROM comments c
                     LEFT JOIN users u ON u.id = c.user_id
                     LEFT JOIN pages p ON c.entity_type='page' AND p.id = c.entity_id
                     LEFT JOIN blog_posts bp ON c.entity_type='blog' AND bp.id = c.entity_id
                     LEFT JOIN spaces s ON s.id = COALESCE(p.space_id, bp.space_id)
                     WHERE c.body ILIKE ?
                       AND (c.entity_type='page' AND p.deleted_at IS NULL OR c.entity_type<>'page')
                       AND (c.entity_type='document' OR {$priv})
                     ORDER BY c.created_at DESC LIMIT 25",
                    [$like, $role, $uid]
                );
            }
            if ($type === '' || $type === 'processes') {
                $results['processes'] = Database::fetchAll(
                    "SELECT pr.id, pr.process_code, pr.name, pr.status, pr.version
                     FROM processes pr
                     WHERE pr.name ILIKE ? OR pr.process_code ILIKE ? OR pr.description ILIKE ?
                     ORDER BY pr.updated_at DESC LIMIT 25",
                    [$like, $like, $like]
                );
            }
            if ($type === '' || $type === 'tasks') {
                $results['tasks'] = Database::fetchAll(
                    "SELECT t.id, t.title, t.status, t.priority
                     FROM tasks t
                     WHERE t.title ILIKE ? OR t.description ILIKE ?
                     ORDER BY t.id DESC LIMIT 25",
                    [$like, $like]
                );
            }
            if ($type === '' || $type === 'spaces') {
                $results['spaces'] = Database::fetchAll(
                    "SELECT s.id, s.space_key, s.name, s.description
                     FROM spaces s
                     WHERE s.is_archived = FALSE
                       AND (s.name ILIKE ? OR s.space_key ILIKE ? OR s.description ILIKE ?)
                     ORDER BY s.name LIMIT 25",
                    [$like, $like, $like]
                );
            }
            foreach ($results as $rows) $total += count($rows);
        }

        $savedSearches = Database::fetchAll(
            "SELECT id, name, query FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC LIMIT 25",
            [Auth::id()]
        );

        require PALADIN_ROOT . '/views/search/index.php';
    }

    public function save(): void {
        Auth::requirePermission('search.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name  = Security::sanitizeInput($_POST['name'] ?? '');
        $query = trim(Security::sanitizeInput($_POST['query'] ?? ''));
        if ($name === '' || $query === '') {
            $_SESSION['flash_error'] = 'A name and search term are required to save a search.';
            header('Location: /search?q=' . urlencode($query));
            return;
        }

        Database::insert('saved_searches', [
            'user_id' => Auth::id(),
            'name'    => $name,
            'query'   => $query,
            'filters' => null,
        ]);
        Auth::log('save_search', 'saved_searches', null, ['name' => $name]);
        $_SESSION['flash_success'] = 'Search saved.';
        header('Location: /search?q=' . urlencode($query));
    }
}
