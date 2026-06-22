<?php
declare(strict_types=1);

class TaskController {

    /** Allowed enumerations. */
    private const TYPES      = ['task', 'review', 'approval', 'corrective_action'];
    private const STATUSES   = ['open', 'in_progress', 'blocked', 'done', 'cancelled'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function index(): void {
        Auth::requirePermission('task.view');

        $view     = Security::sanitizeInput($_GET['view'] ?? 'my');
        if (!in_array($view, ['my', 'team', 'overdue', 'completed', 'all'], true)) $view = 'my';
        $status   = Security::sanitizeInput($_GET['status'] ?? '');
        $priority = Security::sanitizeInput($_GET['priority'] ?? '');
        $assignee = !empty($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;

        $where = ['1=1']; $params = [];
        switch ($view) {
            case 'my':
                $where[] = 't.assigned_to = ?'; $params[] = Auth::id();
                $where[] = "t.status NOT IN ('done','cancelled')";
                break;
            case 'team':
                $where[] = "t.status != 'done'";
                break;
            case 'overdue':
                $where[] = 't.due_date < CURRENT_DATE';
                $where[] = "t.status NOT IN ('done','cancelled')";
                break;
            case 'completed':
                $where[] = "t.status = 'done'";
                break;
            case 'all':
            default:
                break;
        }
        if ($status && in_array($status, self::STATUSES, true))       { $where[] = 't.status = ?';   $params[] = $status; }
        if ($priority && in_array($priority, self::PRIORITIES, true)) { $where[] = 't.priority = ?'; $params[] = $priority; }
        if ($assignee) { $where[] = 't.assigned_to = ?'; $params[] = $assignee; }
        $whereSql = implode(' AND ', $where);

        $tasks = Database::fetchAll(
            "SELECT t.*, a.name AS assignee_name, c.name AS creator_name
             FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.created_by
             WHERE {$whereSql}
             ORDER BY (t.due_date IS NULL), t.due_date ASC, t.created_at DESC",
            $params
        );

        $stats = Database::fetchOne(
            "SELECT COUNT(*) total,
                    COUNT(*) FILTER (WHERE status IN ('open','in_progress')) open,
                    COUNT(*) FILTER (WHERE due_date < CURRENT_DATE AND status NOT IN ('done','cancelled')) overdue,
                    COUNT(*) FILTER (WHERE status='done') completed
             FROM tasks"
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/tasks/index.php';
    }

    public function view(int $id): void {
        Auth::requirePermission('task.view');
        $task = Database::fetchOne(
            "SELECT t.*, a.name AS assignee_name, c.name AS creator_name
             FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.created_by
             WHERE t.id = ?", [$id]
        );
        if (!$task) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/tasks/view.php';
    }

    public function createForm(): void {
        Auth::requirePermission('task.create');
        $task  = null;
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/tasks/form.php';
    }

    public function create(): void {
        Auth::requirePermission('task.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title = Security::sanitizeInput($_POST['title'] ?? '');
        if ($title === '') { $_SESSION['flash_error'] = 'Title is required.'; header('Location: /tasks/create'); return; }

        $type     = Security::sanitizeInput($_POST['type'] ?? 'task');
        $status   = Security::sanitizeInput($_POST['status'] ?? 'open');
        $priority = Security::sanitizeInput($_POST['priority'] ?? 'medium');
        if (!in_array($type, self::TYPES, true))           $type = 'task';
        if (!in_array($status, self::STATUSES, true))       $status = 'open';
        if (!in_array($priority, self::PRIORITIES, true))   $priority = 'medium';

        $data = [
            'title'       => $title,
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'type'        => $type,
            'status'      => $status,
            'priority'    => $priority,
            'assigned_to' => !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null,
            'created_by'  => Auth::id(),
            'due_date'    => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
            'entity_type' => Security::sanitizeInput($_POST['entity_type'] ?? '') ?: null,
            'entity_id'   => !empty($_POST['entity_id']) ? (int)$_POST['entity_id'] : null,
        ];
        if ($status === 'done') $data['completed_at'] = date('Y-m-d H:i:s');

        $id = Database::insert('tasks', $data);
        Auth::log('create_task', 'tasks', $id);
        $_SESSION['flash_success'] = 'Task created.';
        header('Location: /tasks/' . $id);
    }

    /**
     * Object-level guard: when a task is linked to a space-scoped entity
     * (page/document/process), the caller must be able to view that entity's
     * space — global task.edit alone is not enough (prevents IDOR). Tasks with
     * no space-scoped link (or none at all) fall back to the global permission.
     */
    private function canAccessTask(array $task): bool {
        $eid = (int)($task['entity_id'] ?? 0);
        if ($eid <= 0) { return true; }
        switch ((string)($task['entity_type'] ?? '')) {
            case 'page':
            case 'pages':
                $row = Database::fetchOne(
                    "SELECT p.*, s.is_private AS space_private
                     FROM pages p JOIN spaces s ON s.id = p.space_id
                     WHERE p.id = ? AND p.deleted_at IS NULL", [$eid]
                );
                if (!$row) { return true; } // dangling link: don't lock out edits
                return SpaceAccess::canView(['id' => (int)$row['space_id'], 'is_private' => $row['space_private']])
                    && PageAccess::canView($row);
            case 'document':
            case 'documents':
                $row = Database::fetchOne(
                    "SELECT d.id, d.space_id, s.is_private AS space_private
                     FROM documents d LEFT JOIN spaces s ON s.id = d.space_id WHERE d.id = ?", [$eid]
                );
                if (!$row) { return true; }
                return $row['space_id'] === null
                    || SpaceAccess::canView(['id' => (int)$row['space_id'], 'is_private' => $row['space_private']]);
            case 'process':
            case 'processes':
                $row = Database::fetchOne(
                    "SELECT p.id, p.space_id, s.is_private AS space_private
                     FROM processes p LEFT JOIN spaces s ON s.id = p.space_id WHERE p.id = ?", [$eid]
                );
                if (!$row) { return true; }
                return $row['space_id'] === null
                    || SpaceAccess::canView(['id' => (int)$row['space_id'], 'is_private' => $row['space_private']]);
        }
        return true;
    }

    public function update(int $id): void {
        Auth::requirePermission('task.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $task = Database::fetchOne("SELECT * FROM tasks WHERE id = ?", [$id]);
        if (!$task) { http_response_code(404); return; }
        if (!$this->canAccessTask($task)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        $title = Security::sanitizeInput($_POST['title'] ?? '');
        if ($title === '') { $_SESSION['flash_error'] = 'Title is required.'; header('Location: /tasks/' . $id); return; }

        $type     = Security::sanitizeInput($_POST['type'] ?? $task['type']);
        $status   = Security::sanitizeInput($_POST['status'] ?? $task['status']);
        $priority = Security::sanitizeInput($_POST['priority'] ?? $task['priority']);
        if (!in_array($type, self::TYPES, true))         $type = $task['type'];
        if (!in_array($status, self::STATUSES, true))     $status = $task['status'];
        if (!in_array($priority, self::PRIORITIES, true)) $priority = $task['priority'];

        $data = [
            'title'       => $title,
            'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'type'        => $type,
            'status'      => $status,
            'priority'    => $priority,
            'assigned_to' => !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null,
            'due_date'    => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
        ];
        if ($status === 'done') {
            $data['completed_at'] = $task['completed_at'] ?: date('Y-m-d H:i:s');
        } else {
            $data['completed_at'] = null;
        }

        Database::update('tasks', $data, 'id = ?', [$id]);
        Auth::log('update_task', 'tasks', $id);
        $_SESSION['flash_success'] = 'Task updated.';
        header('Location: /tasks/' . $id);
    }

    public function complete(int $id): void {
        Auth::requirePermission('task.complete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $task = Database::fetchOne("SELECT id, entity_type, entity_id FROM tasks WHERE id = ?", [$id]);
        if (!$task) { http_response_code(404); return; }
        if (!$this->canAccessTask($task)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        Database::update('tasks', ['status' => 'done', 'completed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Auth::log('complete_task', 'tasks', $id);
        $_SESSION['flash_success'] = 'Task marked complete.';
        header('Location: /tasks/' . $id);
    }

    public function delete(int $id): void {
        Auth::requirePermission('task.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $task = Database::fetchOne("SELECT id, title, entity_type, entity_id FROM tasks WHERE id = ?", [$id]);
        if (!$task) { http_response_code(404); return; }
        if (!$this->canAccessTask($task)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        Database::query("DELETE FROM tasks WHERE id = ?", [$id]);
        Auth::log('delete_task', 'tasks', $id, ['title' => $task['title']]);
        $_SESSION['flash_success'] = 'Task deleted.';
        header('Location: /tasks');
    }
}
