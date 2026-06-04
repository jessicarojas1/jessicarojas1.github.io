<?php
declare(strict_types=1);

class ProjectController {

    public function index(): void {
        Auth::requireAuth();

        $projects = Database::fetchAll(
            "SELECT gp.*, u.name AS lead_name,
                    COUNT(DISTINCT t.id) AS task_count,
                    COUNT(DISTINCT t.id) FILTER (WHERE t.status = 'done') AS done_count
             FROM grc_projects gp
             LEFT JOIN users u ON u.id = gp.project_lead
             LEFT JOIN grc_project_tasks t ON t.project_id = gp.id
             GROUP BY gp.id, u.name
             ORDER BY gp.updated_at DESC"
        );

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status = 'active')    AS active,
               COUNT(*) FILTER (WHERE status = 'completed') AS completed,
               COALESCE(SUM(budget_planned), 0)             AS total_budget
             FROM grc_projects"
        );

        $pageTitle    = 'GRC Projects';
        $activeModule = 'projects';
        $breadcrumbs  = [['GRC Projects', null]];
        ob_start();
        require AEGIS_ROOT . '/views/projects/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('compliance.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $pageTitle    = 'New GRC Project';
        $activeModule = 'projects';
        $breadcrumbs  = [['GRC Projects', '/projects'], ['New Project', null]];
        ob_start();
        require AEGIS_ROOT . '/views/projects/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title         = Security::sanitizeInput($_POST['title']          ?? '');
        $description   = Security::sanitizeInput($_POST['description']    ?? '');
        $priority      = Security::sanitizeInput($_POST['priority']       ?? 'medium');
        $status        = Security::sanitizeInput($_POST['status']         ?? 'planning');
        $projectLead   = !empty($_POST['project_lead']) ? (int)$_POST['project_lead'] : null;
        $startDate     = Security::sanitizeInput($_POST['start_date']     ?? '');
        $endDate       = Security::sanitizeInput($_POST['end_date']       ?? '');
        $budgetPlanned = !empty($_POST['budget_planned']) ? (float)$_POST['budget_planned'] : null;

        if (!$title) {
            $_SESSION['flash_error'] = 'Project title is required.';
            header('Location: /projects/create');
            return;
        }

        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $priority = 'medium';
        }
        if (!in_array($status, ['planning', 'active', 'on_hold', 'completed', 'cancelled'], true)) {
            $status = 'planning';
        }

        $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM grc_projects");
        $nextId = ((int)$maxRow['max_id']) + 1;
        $projectCode = 'PROJ-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);

        $id = Database::insert('grc_projects', [
            'project_code'   => $projectCode,
            'title'          => $title,
            'description'    => $description ?: null,
            'status'         => $status,
            'priority'       => $priority,
            'start_date'     => $startDate  ?: null,
            'end_date'       => $endDate    ?: null,
            'budget_planned' => $budgetPlanned,
            'project_lead'   => $projectLead,
            'created_by'     => Auth::id(),
        ]);

        Auth::log('created', 'grc_projects', $id, ['project_code' => $projectCode]);
        $_SESSION['flash_success'] = "Project {$projectCode} created successfully.";
        header('Location: /projects/' . $id);
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $project = Database::fetchOne(
            "SELECT gp.*, u1.name AS lead_name, u2.name AS created_by_name
             FROM grc_projects gp
             LEFT JOIN users u1 ON u1.id = gp.project_lead
             LEFT JOIN users u2 ON u2.id = gp.created_by
             WHERE gp.id = ?",
            [$id]
        );

        if (!$project) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $tasks = Database::fetchAll(
            "SELECT t.*, u.name AS assigned_name
             FROM grc_project_tasks t
             LEFT JOIN users u ON u.id = t.assigned_to
             WHERE t.project_id = ?
             ORDER BY t.created_at ASC",
            [$id]
        );

        $links = Database::fetchAll(
            "SELECT * FROM grc_project_links WHERE project_id = ? ORDER BY entity_type, entity_id",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = Security::h($project['project_code']) . ': ' . Security::h($project['title']);
        $activeModule = 'projects';
        $breadcrumbs  = [['GRC Projects', '/projects'], [Security::h($project['project_code']), null]];
        ob_start();
        require AEGIS_ROOT . '/views/projects/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id            = (int)$id;
        $title         = Security::sanitizeInput($_POST['title']          ?? '');
        $description   = Security::sanitizeInput($_POST['description']    ?? '');
        $priority      = Security::sanitizeInput($_POST['priority']       ?? 'medium');
        $status        = Security::sanitizeInput($_POST['status']         ?? 'planning');
        $projectLead   = !empty($_POST['project_lead']) ? (int)$_POST['project_lead'] : null;
        $startDate     = Security::sanitizeInput($_POST['start_date']     ?? '');
        $endDate       = Security::sanitizeInput($_POST['end_date']       ?? '');
        $budgetPlanned = isset($_POST['budget_planned']) && $_POST['budget_planned'] !== '' ? (float)$_POST['budget_planned'] : null;
        $budgetActual  = isset($_POST['budget_actual'])  && $_POST['budget_actual']  !== '' ? (float)$_POST['budget_actual']  : null;

        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $priority = 'medium';
        }
        if (!in_array($status, ['planning', 'active', 'on_hold', 'completed', 'cancelled'], true)) {
            $status = 'planning';
        }

        Database::query(
            "UPDATE grc_projects SET
               title = ?, description = ?, status = ?, priority = ?,
               project_lead = ?, start_date = ?, end_date = ?,
               budget_planned = ?, budget_actual = ?, updated_at = NOW()
             WHERE id = ?",
            [
                $title, $description ?: null, $status, $priority,
                $projectLead, $startDate ?: null, $endDate ?: null,
                $budgetPlanned, $budgetActual, $id,
            ]
        );

        Auth::log('updated', 'grc_projects', $id, ['status' => $status]);
        $_SESSION['flash_success'] = 'Project updated successfully.';
        header('Location: /projects/' . $id);
    }

    public function delete(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;
        $project = Database::fetchOne("SELECT project_code FROM grc_projects WHERE id = ?", [$id]);

        Database::query("DELETE FROM grc_projects WHERE id = ?", [$id]);

        Auth::log('deleted', 'grc_projects', $id, ['project_code' => $project['project_code'] ?? '']);
        $_SESSION['flash_success'] = 'Project deleted.';
        header('Location: /projects');
    }

    public function addTask(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $projectId   = (int)$id;
        $title       = Security::sanitizeInput($_POST['task_title']   ?? '');
        $description = Security::sanitizeInput($_POST['task_desc']    ?? '');
        $assignedTo  = !empty($_POST['task_assigned_to']) ? (int)$_POST['task_assigned_to'] : null;
        $dueDate     = Security::sanitizeInput($_POST['task_due_date'] ?? '');

        if (!$title) {
            $_SESSION['flash_error'] = 'Task title is required.';
            header('Location: /projects/' . $projectId);
            return;
        }

        $taskId = Database::insert('grc_project_tasks', [
            'project_id'  => $projectId,
            'title'       => $title,
            'description' => $description ?: null,
            'assigned_to' => $assignedTo,
            'due_date'    => $dueDate ?: null,
        ]);

        Database::query("UPDATE grc_projects SET updated_at = NOW() WHERE id = ?", [$projectId]);

        Auth::log('created', 'grc_project_tasks', $taskId, ['project_id' => $projectId]);
        $_SESSION['flash_success'] = 'Task added.';
        header('Location: /projects/' . $projectId);
    }

    public function completeTask(string $id, string $taskId): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $projectId = (int)$id;
        $taskId    = (int)$taskId;

        Database::query(
            "UPDATE grc_project_tasks SET status = 'done' WHERE id = ? AND project_id = ?",
            [$taskId, $projectId]
        );
        Database::query("UPDATE grc_projects SET updated_at = NOW() WHERE id = ?", [$projectId]);

        Auth::log('completed', 'grc_project_tasks', $taskId, []);
        $_SESSION['flash_success'] = 'Task marked as done.';
        header('Location: /projects/' . $projectId);
    }

    public function deleteTask(string $id, string $taskId): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $projectId = (int)$id;
        $taskId    = (int)$taskId;

        Database::query(
            "DELETE FROM grc_project_tasks WHERE id = ? AND project_id = ?",
            [$taskId, $projectId]
        );
        Database::query("UPDATE grc_projects SET updated_at = NOW() WHERE id = ?", [$projectId]);

        Auth::log('deleted', 'grc_project_tasks', $taskId, []);
        $_SESSION['flash_success'] = 'Task deleted.';
        header('Location: /projects/' . $projectId);
    }

    public function addLink(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $projectId  = (int)$id;
        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $entityId   = (int)($_POST['entity_id'] ?? 0);

        if (!in_array($entityType, ['risk', 'control', 'issue', 'finding'], true) || !$entityId) {
            $_SESSION['flash_error'] = 'Invalid link parameters.';
            header('Location: /projects/' . $projectId);
            return;
        }

        Database::query(
            "INSERT INTO grc_project_links (project_id, entity_type, entity_id)
             VALUES (?, ?, ?)
             ON CONFLICT (project_id, entity_type, entity_id) DO NOTHING",
            [$projectId, $entityType, $entityId]
        );
        Database::query("UPDATE grc_projects SET updated_at = NOW() WHERE id = ?", [$projectId]);

        Auth::log('created', 'grc_project_links', $projectId, ['entity_type' => $entityType, 'entity_id' => $entityId]);
        $_SESSION['flash_success'] = 'Link added.';
        header('Location: /projects/' . $projectId);
    }

    public function removeLink(string $id, string $linkId): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $projectId = (int)$id;
        $linkId    = (int)$linkId;

        Database::query(
            "DELETE FROM grc_project_links WHERE id = ? AND project_id = ?",
            [$linkId, $projectId]
        );
        Database::query("UPDATE grc_projects SET updated_at = NOW() WHERE id = ?", [$projectId]);

        Auth::log('deleted', 'grc_project_links', $linkId, []);
        $_SESSION['flash_success'] = 'Link removed.';
        header('Location: /projects/' . $projectId);
    }
}
