<?php
declare(strict_types=1);

class TemplateController {

    /** Allowed template categories. */
    private const CATEGORIES = ['document', 'page', 'process', 'meeting', 'project', 'risk', 'audit'];

    public function index(): void {
        Auth::requirePermission('template.view');
        $category = Security::sanitizeInput($_GET['category'] ?? '');

        $where = ['t.is_active = TRUE']; $params = [];
        if ($category && in_array($category, self::CATEGORIES, true)) { $where[] = 't.category = ?'; $params[] = $category; }
        $whereSql = implode(' AND ', $where);

        $templates = Database::fetchAll(
            "SELECT t.*, u.name AS creator_name
             FROM templates t LEFT JOIN users u ON u.id = t.created_by
             WHERE {$whereSql} ORDER BY t.category, t.name",
            $params
        );
        require PAL_ROOT . '/views/templates/index.php';
    }

    public function view(int $id): void {
        Auth::requirePermission('template.view');
        $template = Database::fetchOne(
            "SELECT t.*, u.name AS creator_name
             FROM templates t LEFT JOIN users u ON u.id = t.created_by
             WHERE t.id = ? AND t.is_active = TRUE", [$id]
        );
        if (!$template) { http_response_code(404); require PAL_ROOT . '/views/errors/404.php'; return; }
        require PAL_ROOT . '/views/templates/view.php';
    }

    public function createForm(): void {
        Auth::requirePermission('template.manage');
        $template = null;
        require PAL_ROOT . '/views/templates/form.php';
    }

    public function create(): void {
        Auth::requirePermission('template.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Name is required.'; header('Location: /templates/create'); return; }

        $category = Security::sanitizeInput($_POST['category'] ?? 'document');
        if (!in_array($category, self::CATEGORIES, true)) $category = 'document';

        $docType = null;
        if ($category === 'document') {
            $dt = Security::sanitizeInput($_POST['doc_type'] ?? '');
            if (in_array($dt, View::docTypes(), true)) $docType = $dt;
        }

        $data = [
            'name'        => $name,
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'category'    => $category,
            'doc_type'    => $docType,
            'body'        => Security::sanitizeHtml($_POST['body'] ?? ''),
            'is_active'   => !empty($_POST['is_active']) ? 't' : 'f',
            'created_by'  => Auth::id(),
        ];

        $id = Database::insert('templates', $data);
        Auth::log('create', 'templates', $id);
        $_SESSION['flash_success'] = "Template \"{$name}\" created.";
        header('Location: /templates/' . $id);
    }

    public function delete(int $id): void {
        Auth::requirePermission('template.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('templates', ['is_active' => 'f'], 'id=?', [$id]);
        Auth::log('delete', 'templates', $id);
        $_SESSION['flash_success'] = 'Template deleted.';
        header('Location: /templates');
    }
}
