<?php
declare(strict_types=1);

class IssueController {

    public function index(): void {
        Auth::requireAuth();

        $severity   = Security::sanitizeInput($_GET['severity']    ?? '');
        $status     = Security::sanitizeInput($_GET['status']      ?? '');
        $assignedTo = !empty($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;

        $where  = ['1=1'];
        $params = [];

        if ($severity && in_array($severity, ['critical', 'high', 'medium', 'low'])) {
            $where[] = 'i.severity = ?';
            $params[] = $severity;
        }
        if ($status && in_array($status, ['open', 'in_progress', 'pending_review', 'resolved', 'closed', 'wont_fix'])) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if ($assignedTo) {
            $where[] = 'i.assigned_to = ?';
            $params[] = $assignedTo;
        }

        $whereSQL = implode(' AND ', $where);

        $issues = Database::fetchAll(
            "SELECT i.*,
                    u1.name AS assigned_to_name,
                    u2.name AS created_by_name
             FROM issues i
             LEFT JOIN users u1 ON i.assigned_to  = u1.id
             LEFT JOIN users u2 ON i.created_by   = u2.id
             WHERE {$whereSQL}
             ORDER BY i.created_at DESC",
            $params
        );

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status = 'open')                              AS open,
               COUNT(*) FILTER (WHERE status = 'in_progress')                      AS in_progress,
               COUNT(*) FILTER (WHERE severity = 'critical')                       AS critical,
               COUNT(*) FILTER (WHERE due_date < NOW() AND status NOT IN ('resolved','closed','wont_fix')) AS overdue
             FROM issues"
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        require AEGIS_ROOT . '/views/issue/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('issue.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/issue/create.php';
    }

    public function create(): void {
        Auth::requirePermission('issue.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title                 = Security::sanitizeInput($_POST['title']                  ?? '');
        $description           = Security::sanitizeInput($_POST['description']             ?? '');
        $severity              = Security::sanitizeInput($_POST['severity']                ?? 'medium');
        $sourceType            = Security::sanitizeInput($_POST['source_type']             ?? 'manual');
        $sourceId              = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;
        $assignedTo            = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $dueDate               = Security::sanitizeInput($_POST['due_date']                ?? '');

        if (!$title) {
            $_SESSION['flash_error'] = 'Issue title is required.';
            header('Location: /issue/create');
            return;
        }

        if (!in_array($severity, ['critical', 'high', 'medium', 'low'])) {
            $severity = 'medium';
        }
        if (!in_array($sourceType, ['audit', 'risk', 'incident', 'manual', 'compliance'])) {
            $sourceType = 'manual';
        }

        // Generate issue number based on next sequential ID
        $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM issues");
        $nextId = ((int)$maxRow['max_id']) + 1;
        $issueNumber = 'ISS-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);

        $id = Database::insert('issues', [
            'issue_number' => $issueNumber,
            'title'        => $title,
            'description'  => $description ?: null,
            'severity'     => $severity,
            'status'       => 'open',
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'assigned_to'  => $assignedTo,
            'created_by'   => Auth::id(),
            'due_date'     => $dueDate ?: null,
        ]);

        Auth::log('create_issue', 'issues', $id, [
            'issue_number' => $issueNumber,
            'severity'     => $severity,
        ]);

        $_SESSION['flash_success'] = "Issue {$issueNumber} created successfully.";
        header('Location: /issue/' . $id);
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $issue = Database::fetchOne(
            "SELECT i.*,
                    u1.name AS assigned_to_name,
                    u2.name AS created_by_name
             FROM issues i
             LEFT JOIN users u1 ON i.assigned_to = u1.id
             LEFT JOIN users u2 ON i.created_by  = u2.id
             WHERE i.id = ?",
            [$id]
        );

        if (!$issue) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $updates = Database::fetchAll(
            "SELECT iu.*, u.name AS user_name
             FROM issue_updates iu
             LEFT JOIN users u ON iu.user_id = u.id
             WHERE iu.issue_id = ?
             ORDER BY iu.created_at ASC",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        require AEGIS_ROOT . '/views/issue/view.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('issue.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id                    = (int)$id;
        $title                 = Security::sanitizeInput($_POST['title']                  ?? '');
        $description           = Security::sanitizeInput($_POST['description']             ?? '');
        $severity              = Security::sanitizeInput($_POST['severity']                ?? 'medium');
        $status                = Security::sanitizeInput($_POST['status']                  ?? 'open');
        $assignedTo            = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $dueDate               = Security::sanitizeInput($_POST['due_date']                ?? '');
        $resolution            = Security::sanitizeInput($_POST['resolution']              ?? '');
        $recurrencePrevention  = Security::sanitizeInput($_POST['recurrence_prevention']   ?? '');

        if (!in_array($severity, ['critical', 'high', 'medium', 'low'])) {
            $severity = 'medium';
        }
        if (!in_array($status, ['open', 'in_progress', 'pending_review', 'resolved', 'closed', 'wont_fix'])) {
            $status = 'open';
        }

        $data = [
            'title'                 => $title,
            'description'           => $description ?: null,
            'severity'              => $severity,
            'status'                => $status,
            'assigned_to'           => $assignedTo,
            'due_date'              => $dueDate ?: null,
            'resolution'            => $resolution ?: null,
            'recurrence_prevention' => $recurrencePrevention ?: null,
            'updated_at'            => date('Y-m-d H:i:s'),
        ];

        if ($status === 'resolved') {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }

        Database::update('issues', $data, 'id = ?', [$id]);

        Auth::log('update_issue', 'issues', $id, ['status' => $status, 'severity' => $severity]);

        $_SESSION['flash_success'] = 'Issue updated successfully.';
        header('Location: /issue/' . $id);
    }

    public function addUpdate(string $id): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id         = (int)$id;
        $content    = Security::sanitizeInput($_POST['content']     ?? '');
        $updateType = Security::sanitizeInput($_POST['update_type'] ?? 'comment');
        $newStatus  = Security::sanitizeInput($_POST['new_status']  ?? '');

        if (!$content) {
            $_SESSION['flash_error'] = 'Update content cannot be empty.';
            header('Location: /issue/' . $id);
            return;
        }

        if (!in_array($updateType, ['comment', 'status_change', 'assignment'])) {
            $updateType = 'comment';
        }

        Database::insert('issue_updates', [
            'issue_id'    => $id,
            'user_id'     => Auth::id(),
            'content'     => $content,
            'update_type' => $updateType,
        ]);

        if ($newStatus && in_array($newStatus, ['open', 'in_progress', 'pending_review', 'resolved', 'closed', 'wont_fix'])) {
            $statusData = [
                'status'     => $newStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($newStatus === 'resolved') {
                $statusData['resolved_at'] = date('Y-m-d H:i:s');
            }
            Database::update('issues', $statusData, 'id = ?', [$id]);
        } else {
            Database::update('issues', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        }

        Auth::log('add_issue_update', 'issues', $id, ['update_type' => $updateType]);

        $_SESSION['flash_success'] = 'Update added successfully.';
        header('Location: /issue/' . $id);
    }
}
