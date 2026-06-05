<?php
declare(strict_types=1);

class TreatmentController {

    // ──────────────────────────────────────────────────
    // GET /treatment
    // ──────────────────────────────────────────────────
    public function index(): void {
        Auth::requireAuth();

        $plans = Database::fetchAll(
            "SELECT tp.*, r.title AS risk_title, r.id AS risk_id,
                    u.name AS owner_name,
                    COUNT(tm.id) AS total_milestones,
                    COUNT(tm.completed_at) AS completed_milestones
             FROM treatment_plans tp
             JOIN risks r ON r.id = tp.risk_id
             LEFT JOIN users u ON u.id = tp.owner_id
             LEFT JOIN treatment_milestones tm ON tm.plan_id = tp.id
             WHERE tp.status NOT IN ('cancelled')
             GROUP BY tp.id, r.title, r.id, u.name
             ORDER BY tp.created_at DESC"
        );

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'active') AS active_count,
               COUNT(*) FILTER (WHERE status = 'completed') AS completed_count,
               COUNT(*) FILTER (WHERE status = 'active' AND target_date < CURRENT_DATE) AS overdue_count
             FROM treatment_plans"
        );

        $pageTitle    = 'Treatment Plans';
        $activeModule = 'treatment_plans';
        $breadcrumbs  = [['Treatment Plans', null]];
        ob_start();
        require AEGIS_ROOT . '/views/treatment/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ──────────────────────────────────────────────────
    // GET /risk/{riskId}/treatment/create
    // ──────────────────────────────────────────────────
    public function createForm(string $riskId): void {
        Auth::requirePermission('risk.write');
        $riskId = (int)$riskId;

        $risk = Database::fetchOne("SELECT * FROM risks WHERE id = ?", [$riskId]);
        if (!$risk) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = 'New Treatment Plan';
        $activeModule = 'treatment_plans';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            [$risk['title'], '/risk/' . $riskId],
            ['New Treatment Plan', null],
        ];
        ob_start();
        require AEGIS_ROOT . '/views/treatment/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ──────────────────────────────────────────────────
    // POST /risk/{riskId}/treatment/create
    // ──────────────────────────────────────────────────
    public function create(string $riskId): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;
        $risk   = Database::fetchOne("SELECT id FROM risks WHERE id = ?", [$riskId]);
        if (!$risk) {
            http_response_code(404);
            return;
        }

        $title       = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $strategy    = Security::sanitizeInput($_POST['strategy'] ?? 'mitigate');
        $status      = Security::sanitizeInput($_POST['status'] ?? 'draft');
        $targetScore = !empty($_POST['target_score']) ? (int)$_POST['target_score'] : null;
        $ownerId     = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $startDate   = Security::sanitizeInput($_POST['start_date'] ?? '');
        $targetDate  = Security::sanitizeInput($_POST['target_date'] ?? '');
        $description = trim(Security::sanitizeInput($_POST['description'] ?? ''));

        if (!$title) {
            $_SESSION['flash_error'] = 'Plan title is required.';
            header("Location: /risk/{$riskId}/treatment/create");
            return;
        }

        $allowedStrategies = ['mitigate', 'transfer', 'accept', 'avoid'];
        if (!in_array($strategy, $allowedStrategies, true)) {
            $strategy = 'mitigate';
        }

        $allowedStatuses = ['draft', 'active', 'completed', 'cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        // Generate treatment plan code from next sequential ID
        $maxRow   = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM treatment_plans");
        $planCode = 'TRT-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $planId = Database::insert('treatment_plans', [
            'plan_code'    => $planCode,
            'risk_id'      => $riskId,
            'title'        => $title,
            'strategy'     => $strategy,
            'status'       => $status,
            'target_score' => $targetScore,
            'owner_id'     => $ownerId,
            'start_date'   => $startDate ?: null,
            'target_date'  => $targetDate ?: null,
            'description'  => $description ?: null,
            'created_by'   => Auth::id(),
        ]);

        // Insert milestones
        $stepTitles = (array)($_POST['step_title'] ?? []);
        $stepDescs  = (array)($_POST['step_desc'] ?? []);
        $stepDues   = (array)($_POST['step_due'] ?? []);

        foreach ($stepTitles as $i => $stitle) {
            $stitle = trim(Security::sanitizeInput($stitle));
            if (!$stitle) continue;
            Database::insert('treatment_milestones', [
                'plan_id'     => $planId,
                'title'       => $stitle,
                'description' => trim(Security::sanitizeInput($stepDescs[$i] ?? '')) ?: null,
                'due_date'    => ($stepDues[$i] ?? '') !== '' ? $stepDues[$i] : null,
                'sort_order'  => $i,
            ]);
        }

        Auth::log('treatment_plan_created', 'treatment_plans', $planId, ['plan_code' => $planCode, 'risk_id' => $riskId, 'title' => $title]);
        $_SESSION['flash_success'] = "Treatment plan {$planCode} created.";
        header("Location: /risk/{$riskId}");
    }

    // ──────────────────────────────────────────────────
    // GET /treatment/{id}
    // ──────────────────────────────────────────────────
    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $plan = Database::fetchOne(
            "SELECT tp.*, r.title AS risk_title, r.id AS risk_id,
                    u.name AS owner_name
             FROM treatment_plans tp
             JOIN risks r ON r.id = tp.risk_id
             LEFT JOIN users u ON u.id = tp.owner_id
             WHERE tp.id = ?",
            [$id]
        );
        if (!$plan) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $milestones = Database::fetchAll(
            "SELECT tm.*, u.name AS completed_by_name
             FROM treatment_milestones tm
             LEFT JOIN users u ON u.id = tm.completed_by
             WHERE tm.plan_id = ?
             ORDER BY tm.sort_order, tm.id",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $totalMilestones     = count($milestones);
        $completedMilestones = 0;
        foreach ($milestones as $m) {
            if ($m['completed_at'] !== null) {
                $completedMilestones++;
            }
        }
        $progressPct = $totalMilestones > 0
            ? (int)round(($completedMilestones / $totalMilestones) * 100)
            : 0;

        $pageTitle    = 'Treatment Plan: ' . $plan['title'];
        $activeModule = 'treatment_plans';
        $breadcrumbs  = [
            ['Treatment Plans', '/treatment'],
            [$plan['title'], null],
        ];
        ob_start();
        require AEGIS_ROOT . '/views/treatment/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ──────────────────────────────────────────────────
    // POST /treatment/{id}/update
    // ──────────────────────────────────────────────────
    public function update(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id   = (int)$id;
        $plan = Database::fetchOne("SELECT id FROM treatment_plans WHERE id = ?", [$id]);
        if (!$plan) {
            http_response_code(404);
            return;
        }

        $title       = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $strategy    = Security::sanitizeInput($_POST['strategy'] ?? 'mitigate');
        $status      = Security::sanitizeInput($_POST['status'] ?? 'draft');
        $targetScore = !empty($_POST['target_score']) ? (int)$_POST['target_score'] : null;
        $ownerId     = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $startDate   = Security::sanitizeInput($_POST['start_date'] ?? '');
        $targetDate  = Security::sanitizeInput($_POST['target_date'] ?? '');
        $description = trim(Security::sanitizeInput($_POST['description'] ?? ''));

        if (!$title) {
            $_SESSION['flash_error'] = 'Plan title is required.';
            header("Location: /treatment/{$id}");
            return;
        }

        $allowedStrategies = ['mitigate', 'transfer', 'accept', 'avoid'];
        if (!in_array($strategy, $allowedStrategies, true)) {
            $strategy = 'mitigate';
        }

        $allowedStatuses = ['draft', 'active', 'completed', 'cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        Database::query(
            "UPDATE treatment_plans
             SET title=?, strategy=?, status=?, target_score=?, owner_id=?,
                 start_date=?, target_date=?, description=?, updated_at=NOW()
             WHERE id=?",
            [
                $title, $strategy, $status, $targetScore, $ownerId,
                $startDate ?: null, $targetDate ?: null,
                $description ?: null, $id,
            ]
        );

        Auth::log('treatment_plan_updated', 'treatment_plans', $id, [
            'title'    => $title,
            'strategy' => $strategy,
            'status'   => $status,
        ]);
        $_SESSION['flash_success'] = 'Treatment plan updated.';
        header("Location: /treatment/{$id}");
    }

    // ──────────────────────────────────────────────────
    // POST /treatment/milestone/{id}/complete
    // ──────────────────────────────────────────────────
    public function completeMilestone(string $id): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
            return;
        }

        $id = (int)$id;
        $milestone = Database::fetchOne(
            "SELECT tm.*, tp.id AS plan_id
             FROM treatment_milestones tm
             JOIN treatment_plans tp ON tp.id = tm.plan_id
             WHERE tm.id = ?",
            [$id]
        );
        if (!$milestone) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            return;
        }

        $planId = (int)$milestone['plan_id'];

        if ($milestone['completed_at'] === null) {
            // Mark complete
            Database::query(
                "UPDATE treatment_milestones SET completed_at=NOW(), completed_by=? WHERE id=?",
                [Auth::id(), $id]
            );
        } else {
            // Toggle back to incomplete
            Database::query(
                "UPDATE treatment_milestones SET completed_at=NULL, completed_by=NULL WHERE id=?",
                [$id]
            );
        }

        // Recalculate progress
        $totals = Database::fetchOne(
            "SELECT COUNT(*) AS total, COUNT(completed_at) AS completed
             FROM treatment_milestones WHERE plan_id = ?",
            [$planId]
        );

        header('Content-Type: application/json');
        echo json_encode([
            'ok'       => true,
            'progress' => (int)($totals['completed'] ?? 0),
            'total'    => (int)($totals['total'] ?? 0),
        ]);
    }

    // ──────────────────────────────────────────────────
    // POST /treatment/{planId}/milestone/add
    // ──────────────────────────────────────────────────
    public function addMilestone(string $planId): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $planId = (int)$planId;
        $plan   = Database::fetchOne("SELECT id FROM treatment_plans WHERE id = ?", [$planId]);
        if (!$plan) {
            http_response_code(404);
            return;
        }

        $title       = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $description = trim(Security::sanitizeInput($_POST['description'] ?? ''));
        $dueDate     = Security::sanitizeInput($_POST['due_date'] ?? '');

        if (!$title) {
            $_SESSION['flash_error'] = 'Milestone title is required.';
            header("Location: /treatment/{$planId}");
            return;
        }

        $maxOrder = Database::fetchOne(
            "SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM treatment_milestones WHERE plan_id = ?",
            [$planId]
        );

        Database::insert('treatment_milestones', [
            'plan_id'     => $planId,
            'title'       => $title,
            'description' => $description ?: null,
            'due_date'    => $dueDate ?: null,
            'sort_order'  => (int)($maxOrder['max_order'] ?? -1) + 1,
        ]);

        Auth::log('milestone_added', 'treatment_milestones', $planId, ['title' => $title]);
        $_SESSION['flash_success'] = 'Milestone added.';
        header("Location: /treatment/{$planId}");
    }

    // ──────────────────────────────────────────────────
    // POST /treatment/milestone/{milestoneId}/delete
    // ──────────────────────────────────────────────────
    public function deleteMilestone(string $milestoneId): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $milestoneId = (int)$milestoneId;
        $milestone   = Database::fetchOne(
            "SELECT id, plan_id, completed_at FROM treatment_milestones WHERE id = ?",
            [$milestoneId]
        );
        if (!$milestone) {
            http_response_code(404);
            return;
        }

        if ($milestone['completed_at'] !== null) {
            $_SESSION['flash_error'] = 'Cannot delete a completed milestone.';
            header("Location: /treatment/{$milestone['plan_id']}");
            return;
        }

        $planId = (int)$milestone['plan_id'];
        Database::query("DELETE FROM treatment_milestones WHERE id = ?", [$milestoneId]);
        Auth::log('milestone_deleted', 'treatment_milestones', $milestoneId);
        $_SESSION['flash_success'] = 'Milestone deleted.';
        header("Location: /treatment/{$planId}");
    }
}
