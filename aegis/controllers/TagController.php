<?php
declare(strict_types=1);

class TagController {

    public function index(): void {
        Auth::requireAdmin();
        $tags = Database::fetchAll(
            "SELECT t.*, COUNT(et.id) as usage_count
             FROM tags t
             LEFT JOIN entity_tags et ON et.tag_id = t.id
             GROUP BY t.id ORDER BY t.name"
        );
        $pageTitle    = 'Tag Management';
        $activeModule = 'admin_tags';
        $breadcrumbs  = [['Administration', '/admin'], ['Tags', null]];
        ob_start();
        require AEGIS_ROOT . '/views/admin/tags.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $name  = trim(Security::sanitizeInput($_POST['name'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#6366f1';
        if (!$name) {
            $_SESSION['flash_error'] = 'Tag name is required.';
            header('Location: /admin/tags'); return;
        }
        try {
            Database::insert('tags', ['name' => $name, 'color' => $color]);
            $_SESSION['flash_success'] = 'Tag created.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Tag name already exists.';
        }
        header('Location: /admin/tags');
    }

    public function delete(string $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $id = (int)$id;
        Database::query("DELETE FROM tags WHERE id = ?", [$id]);
        $_SESSION['flash_success'] = 'Tag deleted.';
        header('Location: /admin/tags');
    }

    public function addToEntity(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $tagId      = (int)($_POST['tag_id'] ?? 0);
        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $entityId   = (int)($_POST['entity_id'] ?? 0);
        $allowed    = ['risk', 'vendor', 'asset', 'incident', 'policy', 'control'];
        if (!$tagId || !$entityId || !in_array($entityType, $allowed, true)) {
            http_response_code(400); echo json_encode(['error' => 'Invalid parameters']); return;
        }
        Database::query(
            "INSERT INTO entity_tags (tag_id, entity_type, entity_id) VALUES (?,?,?)
             ON CONFLICT (tag_id, entity_type, entity_id) DO NOTHING",
            [$tagId, $entityType, $entityId]
        );
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    public function removeFromEntity(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $tagId      = (int)($_POST['tag_id'] ?? 0);
        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $entityId   = (int)($_POST['entity_id'] ?? 0);
        Database::query(
            "DELETE FROM entity_tags WHERE tag_id=? AND entity_type=? AND entity_id=?",
            [$tagId, $entityType, $entityId]
        );
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    public function entityTags(): void {
        Auth::requireAuth();
        $entityType = Security::sanitizeInput($_GET['entity_type'] ?? '');
        $entityId   = (int)($_GET['entity_id'] ?? 0);
        $allowed    = ['risk', 'vendor', 'asset', 'incident', 'policy', 'control'];
        if (!$entityId || !in_array($entityType, $allowed, true)) {
            header('Content-Type: application/json');
            echo json_encode([]); return;
        }
        $tags = Database::fetchAll(
            "SELECT t.id, t.name, t.color FROM tags t
             JOIN entity_tags et ON et.tag_id = t.id
             WHERE et.entity_type = ? AND et.entity_id = ?
             ORDER BY t.name",
            [$entityType, $entityId]
        );
        header('Content-Type: application/json');
        echo json_encode($tags);
    }
}
