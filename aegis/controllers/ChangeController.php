<?php
class ChangeController {

    private const VALID_STATUSES = [
        'draft', 'submitted', 'under_review', 'approved',
        'rejected', 'implementing', 'implemented', 'closed',
    ];

    private const STATUS_TRANSITIONS = [
        'submitted'    => ['under_review'],
        'under_review' => ['approved', 'rejected'],
        'approved'     => ['implementing'],
        'implementing' => ['implemented'],
        // any → closed
    ];

    public function index(): void {
        Auth::requireAuth();

        $statusFilter = Security::sanitizeInput($_GET['status'] ?? '');

        $params = [];
        $where  = ['1=1'];

        if ($statusFilter && in_array($statusFilter, self::VALID_STATUSES, true)) {
            $where[]  = 'cr.status = ?';
            $params[] = $statusFilter;
        }

        $whereSQL = implode(' AND ', $where);

        $changeRequests = Database::fetchAll(
            "SELECT cr.*,
                    u.name AS submitter_name,
                    r.name AS reviewer_name
             FROM change_requests cr
             LEFT JOIN users u ON cr.submitter_id = u.id
             LEFT JOIN users r ON cr.cab_reviewer_id = r.id
             WHERE {$whereSQL}
             ORDER BY cr.created_at DESC",
            $params
        );

        $activeModule = 'change';
        require AEGIS_ROOT . '/views/change/index.php';
    }

    public function createForm(): void {
        Auth::requireAuth();

        $activeModule = 'change';
        require AEGIS_ROOT . '/views/change/create.php';
    }

    public function create(): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title              = Security::sanitizeInput($_POST['title'] ?? '');
        $description        = Security::sanitizeInput($_POST['description'] ?? '');
        $changeType         = Security::sanitizeInput($_POST['change_type'] ?? 'normal');
        $riskLevel          = Security::sanitizeInput($_POST['risk_level'] ?? 'medium');
        $implementationDate = Security::sanitizeInput($_POST['implementation_date'] ?? '');
        $rollbackPlan       = Security::sanitizeInput($_POST['rollback_plan'] ?? '');
        $impactAnalysis     = Security::sanitizeInput($_POST['impact_analysis'] ?? '');
        $testingPlan        = Security::sanitizeInput($_POST['testing_plan'] ?? '');

        $allowedTypes      = ['normal', 'emergency', 'standard'];
        $allowedRiskLevels = ['low', 'medium', 'high', 'critical'];

        if (!in_array($changeType, $allowedTypes, true)) {
            $changeType = 'normal';
        }
        if (!in_array($riskLevel, $allowedRiskLevels, true)) {
            $riskLevel = 'medium';
        }

        // Validate implementation date
        $implementationDateDb = null;
        if ($implementationDate) {
            $parsed = date_create($implementationDate);
            if ($parsed) {
                $implementationDateDb = date_format($parsed, 'Y-m-d H:i:s');
            }
        }

        if (!$title) {
            $_SESSION['change_error'] = 'Title is required.';
            header('Location: /change/create');
            return;
        }

        $id = Database::insert('change_requests', [
            'title'               => $title,
            'description'         => $description,
            'change_type'         => $changeType,
            'risk_level'          => $riskLevel,
            'implementation_date' => $implementationDateDb,
            'rollback_plan'       => $rollbackPlan,
            'impact_analysis'     => $impactAnalysis,
            'testing_plan'        => $testingPlan,
            'status'              => 'draft',
            'submitter_id'        => Auth::id(),
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        Auth::log('create', 'change_requests', $id, ['title' => $title, 'change_type' => $changeType]);

        header('Location: /change/' . $id);
    }

    public function view(string $id): void {
        Auth::requireAuth();

        $id = (int)$id;

        $change = Database::fetchOne(
            "SELECT cr.*,
                    u.name  AS submitter_name,
                    r.name  AS reviewer_name
             FROM change_requests cr
             LEFT JOIN users u ON cr.submitter_id = u.id
             LEFT JOIN users r ON cr.cab_reviewer_id = r.id
             WHERE cr.id = ?",
            [$id]
        );

        if (!$change) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $updates = Database::fetchAll(
            "SELECT cru.*, u.name AS author_name
             FROM change_request_updates cru
             LEFT JOIN users u ON cru.user_id = u.id
             WHERE cru.change_id = ?
             ORDER BY cru.created_at ASC",
            [$id]
        );

        $activeModule = 'change';
        require AEGIS_ROOT . '/views/change/view.php';
    }

    public function update(string $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /change/' . (int)$id);
            return;
        }

        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id        = (int)$id;
        $newStatus = Security::sanitizeInput($_POST['status'] ?? '');
        $note      = Security::sanitizeInput($_POST['note'] ?? '');

        if (!in_array($newStatus, self::VALID_STATUSES, true)) {
            header('Location: /change/' . $id);
            return;
        }

        $change = Database::fetchOne(
            "SELECT * FROM change_requests WHERE id = ?", [$id]
        );

        if (!$change) {
            http_response_code(404);
            return;
        }

        $currentStatus = $change['status'];

        // Validate transition: closed is always allowed as a terminal state
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];
        if ($newStatus !== 'closed' && !in_array($newStatus, $allowed, true)) {
            $_SESSION['change_error'] = "Transition from '{$currentStatus}' to '{$newStatus}' is not permitted.";
            header('Location: /change/' . $id);
            return;
        }

        $updateData = [
            'status'      => $newStatus,
            'cab_reviewer_id' => Auth::id(),
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        if ($newStatus === 'implemented') {
            $updateData['implemented_at'] = date('Y-m-d H:i:s');
        }

        Database::query(
            "UPDATE change_requests SET status = ?, cab_reviewer_id = ?, updated_at = ? WHERE id = ?",
            [$newStatus, Auth::id(), date('Y-m-d H:i:s'), $id]
        );

        if ($newStatus === 'implemented') {
            Database::query(
                "UPDATE change_requests SET implemented_at = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $id]
            );
        }

        // Record the status change as an update
        $updateContent = "Status changed to: " . str_replace('_', ' ', ucfirst($newStatus));
        if ($note) {
            $updateContent .= "\n\n" . $note;
        }

        Database::insert('change_request_updates', [
            'change_id' => $id,
            'user_id'           => Auth::id(),
            'update_type'       => 'status_change',
            'content'           => $updateContent,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        Auth::log('status_change', 'change_requests', $id, [
            'from'   => $currentStatus,
            'to'     => $newStatus,
        ]);

        header('Location: /change/' . $id);
    }

    public function addUpdate(string $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /change/' . (int)$id);
            return;
        }

        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id      = (int)$id;
        $content = Security::sanitizeInput($_POST['content'] ?? '');

        if (!$content) {
            header('Location: /change/' . $id);
            return;
        }

        $change = Database::fetchOne(
            "SELECT id FROM change_requests WHERE id = ?", [$id]
        );

        if (!$change) {
            http_response_code(404);
            return;
        }

        $updateId = Database::insert('change_request_updates', [
            'change_id' => $id,
            'user_id'           => Auth::id(),
            'update_type'       => 'comment',
            'content'           => $content,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        Auth::log('add_update', 'change_request_updates', $updateId, ['change_request_id' => $id]);

        header('Location: /change/' . $id . '#updates');
    }

    public function cabVote(string $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /change/' . (int)$id);
            return;
        }

        Auth::requirePermission('audit.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id    = (int)$id;
        $vote  = Security::sanitizeInput($_POST['vote'] ?? '');
        $notes = Security::sanitizeInput($_POST['notes'] ?? '');

        if (!in_array($vote, ['approve', 'reject'], true)) {
            header('Location: /change/' . $id);
            return;
        }

        $change = Database::fetchOne(
            "SELECT id, status FROM change_requests WHERE id = ?", [$id]
        );

        if (!$change) {
            http_response_code(404);
            return;
        }

        // Replace any prior vote by this CAB member
        Database::query(
            "DELETE FROM change_request_updates WHERE change_id = ? AND user_id = ? AND update_type = 'cab_vote'",
            [$id, Auth::id()]
        );

        $content = strtoupper($vote) . ($notes ? ': ' . $notes : '');

        Database::insert('change_request_updates', [
            'change_id'   => $id,
            'user_id'     => Auth::id(),
            'update_type' => 'cab_vote',
            'content'     => $content,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        Auth::log('cab_vote', 'change_requests', $id, ['vote' => $vote]);

        $_SESSION['change_success'] = 'CAB vote recorded.';
        header('Location: /change/' . $id . '#cab');
    }
}
