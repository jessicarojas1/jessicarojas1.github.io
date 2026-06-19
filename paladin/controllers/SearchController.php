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

        // Advanced filter tokens (in:/space:, label:/tag:, by:/author:, type:) are
        // parsed out of the query; the remainder is free text.
        $f = $this->parseFilters($q);
        $text = $f['text'];
        if ($type === '' && $f['type'] !== '') $type = $f['type'];
        $spaceId = $f['space_id']; $tagId = $f['tag_id']; $userId = $f['user_id'];
        $contentFilter = $spaceId !== null || $tagId !== null || $userId !== null;
        $activeFilters = array_values(array_filter([
            $spaceId !== null ? ['in', $f['space_label']] : null,
            $tagId   !== null ? ['label', $f['tag_label']] : null,
            $userId  !== null ? ['by', $f['user_label']] : null,
        ]));

        $results = array_fill_keys(self::TYPES, []);
        $total   = 0;

        // Space-privacy fragment (aliased space "s"): open spaces, admins, or members.
        $priv = "(s.id IS NULL OR s.is_private = FALSE OR ? = 'admin'
                  OR EXISTS (SELECT 1 FROM space_members m WHERE m.space_id = s.id AND m.user_id = ?))";

        if ($text !== '' || $contentFilter) {
            $like = "%{$text}%";
            $role = Auth::role(); $uid = Auth::id();

            if ($type === '' || $type === 'documents') {
                $cond = []; $par = [];
                if ($text !== '') { $cond[] = "(d.title ILIKE ? OR d.document_code ILIKE ? OR d.description ILIKE ? OR d.body ILIKE ?)"; array_push($par, $like, $like, $like, $like); }
                if ($spaceId !== null) { $cond[] = "d.space_id = ?"; $par[] = $spaceId; }
                if ($tagId !== null)   { $cond[] = "EXISTS (SELECT 1 FROM entity_tags et WHERE et.entity_type='document' AND et.entity_id = d.id AND et.tag_id = ?)"; $par[] = $tagId; }
                if ($userId !== null)  { $cond[] = "(d.owner_id = ? OR d.created_by = ?)"; $par[] = $userId; $par[] = $userId; }
                $cond[] = $priv; array_push($par, $role, $uid); // hide private-space docs from non-members
                $results['documents'] = Database::fetchAll(
                    "SELECT d.id, d.document_code, d.title, d.status, d.doc_type
                     FROM documents d LEFT JOIN spaces s ON s.id = d.space_id
                     WHERE " . implode(' AND ', $cond) . "
                     ORDER BY d.updated_at DESC LIMIT 25", $par
                );
            }
            if ($type === '' || $type === 'pages') {
                $cond = []; $par = [];
                if ($text !== '') { $cond[] = "(p.title ILIKE ? OR p.body ILIKE ?)"; array_push($par, $like, $like); }
                if ($spaceId !== null) { $cond[] = "p.space_id = ?"; $par[] = $spaceId; }
                if ($tagId !== null)   { $cond[] = "EXISTS (SELECT 1 FROM entity_tags et WHERE et.entity_type='page' AND et.entity_id = p.id AND et.tag_id = ?)"; $par[] = $tagId; }
                if ($userId !== null)  { $cond[] = "(p.created_by = ? OR p.owner_id = ?)"; $par[] = $userId; $par[] = $userId; }
                $cond[] = "p.deleted_at IS NULL"; $cond[] = $priv; array_push($par, $role, $uid);
                $rows = Database::fetchAll(
                    "SELECT p.id, p.title, p.status, p.owner_id, p.created_by, p.space_id, s.name AS space_name
                     FROM pages p LEFT JOIN spaces s ON s.id = p.space_id
                     WHERE " . implode(' AND ', $cond) . "
                     ORDER BY p.updated_at DESC LIMIT 40", $par
                );
                // Honour per-page restrictions, then cap.
                $results['pages'] = array_slice(array_values(array_filter($rows, fn($p) => PageAccess::canView($p))), 0, 25);
            }
            // Blogs are space- and author-filterable but not label-filterable here.
            if (($type === '' || $type === 'blogs') && $tagId === null) {
                $cond = []; $par = [];
                if ($text !== '') { $cond[] = "(b.title ILIKE ? OR b.body ILIKE ?)"; array_push($par, $like, $like); }
                if ($spaceId !== null) { $cond[] = "b.space_id = ?"; $par[] = $spaceId; }
                if ($userId !== null)  { $cond[] = "b.author_id = ?"; $par[] = $userId; }
                $cond[] = "(b.status='published' OR b.author_id = ?)"; $par[] = $uid;
                $cond[] = $priv; array_push($par, $role, $uid);
                $results['blogs'] = Database::fetchAll(
                    "SELECT b.id, b.title, b.status, s.name AS space_name
                     FROM blog_posts b LEFT JOIN spaces s ON s.id = b.space_id
                     WHERE " . implode(' AND ', $cond) . "
                     ORDER BY b.published_at DESC NULLS LAST LIMIT 25", $par
                );
            }
            // Comments, processes, tasks and spaces are not space/label/author scoped.
            if (($type === '' || $type === 'comments') && !$contentFilter && $text !== '') {
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
            if (($type === '' || $type === 'processes') && !$contentFilter && $text !== '') {
                $results['processes'] = Database::fetchAll(
                    "SELECT pr.id, pr.process_code, pr.name, pr.status, pr.version
                     FROM processes pr LEFT JOIN spaces s ON s.id = pr.space_id
                     WHERE (pr.name ILIKE ? OR pr.process_code ILIKE ? OR pr.description ILIKE ?) AND {$priv}
                     ORDER BY pr.updated_at DESC LIMIT 25",
                    [$like, $like, $like, $role, $uid]
                );
            }
            if (($type === '' || $type === 'tasks') && !$contentFilter && $text !== '') {
                $results['tasks'] = Database::fetchAll(
                    "SELECT t.id, t.title, t.status, t.priority
                     FROM tasks t
                     WHERE t.title ILIKE ? OR t.description ILIKE ?
                     ORDER BY t.id DESC LIMIT 25",
                    [$like, $like]
                );
            }
            if (($type === '' || $type === 'spaces') && !$contentFilter && $text !== '') {
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

    /**
     * Parse advanced filter tokens out of a raw query. Supported prefixes:
     *   type:X        restrict to an entity type
     *   in:KEY        / space:KEY   restrict to a space (by key or name)
     *   label:NAME    / tag:NAME    restrict to a label
     *   by:NAME       / author:NAME restrict to a creator/owner (name or email)
     * Unrecognised or unresolved tokens fall back into the free text.
     * Returns ['text','type','space_id','space_label','tag_id','tag_label','user_id','user_label'].
     */
    private function parseFilters(string $raw): array {
        $f = ['text' => '', 'type' => '', 'space_id' => null, 'space_label' => '',
              'tag_id' => null, 'tag_label' => '', 'user_id' => null, 'user_label' => ''];
        $textParts = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $w) {
            if ($w === '') { continue; }
            if (!preg_match('/^([a-z]+):(.+)$/i', $w, $m)) { $textParts[] = $w; continue; }
            $k = strtolower($m[1]); $v = $m[2];
            switch ($k) {
                case 'type':
                    if (in_array($v, self::TYPES, true)) { $f['type'] = $v; } else { $textParts[] = $w; }
                    break;
                case 'in': case 'space':
                    $row = Database::fetchOne("SELECT id, space_key FROM spaces WHERE space_key ILIKE ? OR name ILIKE ? ORDER BY space_key LIMIT 1", [$v, $v]);
                    if ($row) { $f['space_id'] = (int)$row['id']; $f['space_label'] = $row['space_key']; } else { $textParts[] = $w; }
                    break;
                case 'label': case 'tag':
                    $row = Database::fetchOne("SELECT id, name FROM tags WHERE name ILIKE ? ORDER BY name LIMIT 1", [$v]);
                    if ($row) { $f['tag_id'] = (int)$row['id']; $f['tag_label'] = $row['name']; } else { $textParts[] = $w; }
                    break;
                case 'by': case 'author':
                    $row = Database::fetchOne("SELECT id, name FROM users WHERE email ILIKE ? OR name ILIKE ? ORDER BY name LIMIT 1", [$v, $v . '%']);
                    if ($row) { $f['user_id'] = (int)$row['id']; $f['user_label'] = $row['name']; } else { $textParts[] = $w; }
                    break;
                default:
                    $textParts[] = $w;
            }
        }
        $f['text'] = implode(' ', $textParts);
        return $f;
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
