<?php
declare(strict_types=1);

/**
 * LabelController — browse the shared label (tag) taxonomy and the content
 * filed under each label. Content links enforce their own access on open;
 * restricted pages are filtered out of the listing here too.
 */
class LabelController {

    public function index(): void {
        Auth::requireAuth();
        $labels = Database::fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM entity_tags et WHERE et.tag_id = t.id) AS cnt
             FROM tags t ORDER BY cnt DESC, t.name"
        );
        require PALADIN_ROOT . '/views/labels/index.php';
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $label = Database::fetchOne("SELECT * FROM tags WHERE id = ?", [$id]);
        if (!$label) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $rows = Database::fetchAll("SELECT entity_type, entity_id FROM entity_tags WHERE tag_id = ?", [$id]);
        $byType = [];
        foreach ($rows as $r) { $byType[$r['entity_type']][] = (int)$r['entity_id']; }

        $idList = fn(array $ids) => '{' . implode(',', array_map('intval', $ids)) . '}';
        $groups = []; // label => [ ['url'=>, 'title'=>, 'meta'=>], ... ]

        if (!empty($byType['page'])) {
            $pages = Database::fetchAll(
                "SELECT p.*, s.space_key FROM pages p LEFT JOIN spaces s ON s.id=p.space_id
                 WHERE p.id = ANY(?::int[])", [$idList($byType['page'])]
            );
            foreach ($pages as $p) {
                if (!PageAccess::canView($p)) continue;
                $groups['Pages'][] = ['url' => '/pages/' . (int)$p['id'], 'title' => $p['title'], 'meta' => $p['space_key'] ?? ''];
            }
        }
        if (!empty($byType['document'])) {
            foreach (Database::fetchAll("SELECT id, document_code, title FROM documents WHERE id = ANY(?::int[])", [$idList($byType['document'])]) as $d) {
                $groups['Documents'][] = ['url' => '/documents/' . (int)$d['id'], 'title' => $d['title'], 'meta' => $d['document_code']];
            }
        }
        if (!empty($byType['blog'])) {
            foreach (Database::fetchAll("SELECT id, title FROM blog_posts WHERE id = ANY(?::int[])", [$idList($byType['blog'])]) as $b) {
                $groups['Blog posts'][] = ['url' => '/blog/' . (int)$b['id'], 'title' => $b['title'], 'meta' => ''];
            }
        }
        if (!empty($byType['process'])) {
            foreach (Database::fetchAll("SELECT id, process_code, name FROM processes WHERE id = ANY(?::int[])", [$idList($byType['process'])]) as $pr) {
                $groups['Processes'][] = ['url' => '/processes/' . (int)$pr['id'], 'title' => $pr['name'], 'meta' => $pr['process_code']];
            }
        }
        require PALADIN_ROOT . '/views/labels/view.php';
    }
}
