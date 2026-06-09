<?php
declare(strict_types=1);

class WorkflowController {

    public function index(): void {
        Auth::requirePermission('workflow.view');
        $templates = Database::fetchAll(
            "SELECT wt.*, u.name AS creator,
                    (SELECT COUNT(*) FROM workflow_steps ws WHERE ws.template_id=wt.id) AS step_count
             FROM workflow_templates wt LEFT JOIN users u ON u.id=wt.created_by
             ORDER BY wt.is_active DESC, wt.name"
        );
        require PALADIN_ROOT . '/views/workflows/index.php';
    }

    public function view(int $id): void {
        Auth::requirePermission('workflow.view');
        $wf = Database::fetchOne("SELECT wt.*, u.name AS creator FROM workflow_templates wt LEFT JOIN users u ON u.id=wt.created_by WHERE wt.id=?", [$id]);
        if (!$wf) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $steps = Database::fetchAll(
            "SELECT ws.*, u.name AS approver_name FROM workflow_steps ws LEFT JOIN users u ON u.id=ws.approver_user_id
             WHERE ws.template_id=? ORDER BY ws.step_number", [$id]
        );
        $usage = (int)(Database::fetchOne("SELECT COUNT(*) c FROM approval_requests WHERE template_id=?", [$id])['c'] ?? 0);
        require PALADIN_ROOT . '/views/workflows/view.php';
    }

    public function createForm(): void {
        Auth::requirePermission('workflow.manage');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/workflows/form.php';
    }

    public function create(): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Workflow name is required.'; header('Location: /workflows/create'); return; }

        $type = Security::sanitizeInput($_POST['workflow_type'] ?? 'general');
        if (!in_array($type, ['policy','procedure','process','change','record','evidence','corrective','general'], true)) $type = 'general';
        $mode = Security::sanitizeInput($_POST['approval_mode'] ?? 'sequential');
        if (!in_array($mode, ['single','sequential','parallel','consensus'], true)) $mode = 'sequential';

        $id = Database::insert('workflow_templates', [
            'name' => $name, 'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'workflow_type' => $type, 'approval_mode' => $mode, 'created_by' => Auth::id(),
        ]);

        $names = $_POST['step_name'] ?? [];
        $roles = $_POST['step_role'] ?? [];
        $usersS = $_POST['step_user'] ?? [];
        $slas  = $_POST['step_sla'] ?? [];
        $n = 0;
        foreach ($names as $i => $sname) {
            $sname = Security::sanitizeInput((string)$sname);
            if ($sname === '') continue;
            $n++;
            Database::insert('workflow_steps', [
                'template_id' => $id, 'step_number' => $n, 'name' => $sname,
                'approver_role' => Security::sanitizeInput((string)($roles[$i] ?? '')) ?: null,
                'approver_user_id' => !empty($usersS[$i]) ? (int)$usersS[$i] : null,
                'sla_hours' => (int)($slas[$i] ?? 72) ?: 72,
            ]);
        }
        if ($n === 0) {
            Database::insert('workflow_steps', ['template_id' => $id, 'step_number' => 1, 'name' => 'Approval', 'approver_role' => 'approver', 'sla_hours' => 72]);
        }
        Auth::log('create_workflow', 'workflow_templates', $id, ['name' => $name]);
        $_SESSION['flash_success'] = 'Workflow template created.';
        header('Location: /workflows/' . $id);
    }

    public function delete(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('workflow_templates', ['is_active' => 'f'], 'id=?', [$id]);
        Auth::log('deactivate_workflow', 'workflow_templates', $id);
        $_SESSION['flash_success'] = 'Workflow deactivated.';
        header('Location: /workflows');
    }

    public function reactivate(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('workflow_templates', ['is_active' => 't'], 'id=?', [$id]);
        Auth::log('reactivate_workflow', 'workflow_templates', $id);
        $_SESSION['flash_success'] = 'Workflow reactivated.';
        header('Location: /workflows/' . $id);
    }

    public function editForm(int $id): void {
        Auth::requirePermission('workflow.manage');
        $wf = Database::fetchOne("SELECT * FROM workflow_templates WHERE id=?", [$id]);
        if (!$wf) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $steps = Database::fetchAll(
            "SELECT ws.*, u.name AS approver_name FROM workflow_steps ws LEFT JOIN users u ON u.id=ws.approver_user_id
             WHERE ws.template_id=? ORDER BY ws.step_number", [$id]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/workflows/edit.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM workflow_templates WHERE id=?", [$id])) { http_response_code(404); return; }

        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Workflow name is required.'; header('Location: /workflows/' . $id . '/edit'); return; }
        $type = Security::sanitizeInput($_POST['workflow_type'] ?? 'general');
        if (!in_array($type, ['policy','procedure','process','change','record','evidence','corrective','general'], true)) $type = 'general';
        $mode = Security::sanitizeInput($_POST['approval_mode'] ?? 'sequential');
        if (!in_array($mode, ['single','sequential','parallel','consensus'], true)) $mode = 'sequential';

        Database::update('workflow_templates', [
            'name' => $name, 'description' => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'workflow_type' => $type, 'approval_mode' => $mode,
        ], 'id=?', [$id]);
        Auth::log('update_workflow', 'workflow_templates', $id);
        $_SESSION['flash_success'] = 'Workflow updated.';
        header('Location: /workflows/' . $id . '/edit');
    }

    /** Add a stage (step) to a workflow, appended at the end. */
    public function addStep(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM workflow_templates WHERE id=?", [$id])) { http_response_code(404); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Stage name is required.'; header('Location: /workflows/' . $id . '/edit'); return; }
        $max = Database::fetchOne("SELECT COALESCE(MAX(step_number),0) m FROM workflow_steps WHERE template_id=?", [$id]);
        Database::insert('workflow_steps', [
            'template_id' => $id, 'step_number' => (int)$max['m'] + 1, 'name' => $name,
            'approver_role' => Security::sanitizeInput($_POST['approver_role'] ?? '') ?: null,
            'approver_user_id' => !empty($_POST['approver_user_id']) ? (int)$_POST['approver_user_id'] : null,
            'sla_hours' => (int)($_POST['sla_hours'] ?? 72) ?: 72,
        ]);
        Auth::log('add_workflow_step', 'workflow_templates', $id);
        $_SESSION['flash_success'] = 'Stage added.';
        header('Location: /workflows/' . $id . '/edit');
    }

    public function updateStep(int $id, int $stepId): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $step = Database::fetchOne("SELECT id FROM workflow_steps WHERE id=? AND template_id=?", [$stepId, $id]);
        if (!$step) { http_response_code(404); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'Stage name is required.'; header('Location: /workflows/' . $id . '/edit'); return; }
        Database::query(
            "UPDATE workflow_steps SET name=?, approver_role=?, approver_user_id=?, sla_hours=? WHERE id=?",
            [
                $name,
                Security::sanitizeInput($_POST['approver_role'] ?? '') ?: null,
                !empty($_POST['approver_user_id']) ? (int)$_POST['approver_user_id'] : null,
                (int)($_POST['sla_hours'] ?? 72) ?: 72,
                $stepId,
            ]
        );
        Auth::log('update_workflow_step', 'workflow_templates', $id, ['step' => $stepId]);
        $_SESSION['flash_success'] = 'Stage updated.';
        header('Location: /workflows/' . $id . '/edit');
    }

    public function deleteStep(int $id, int $stepId): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM workflow_steps WHERE id=? AND template_id=?", [$stepId, $id])) { http_response_code(404); return; }
        Database::query("DELETE FROM workflow_steps WHERE id=?", [$stepId]);
        // Renumber remaining steps to stay contiguous
        $rows = Database::fetchAll("SELECT id FROM workflow_steps WHERE template_id=? ORDER BY step_number", [$id]);
        foreach ($rows as $i => $r) {
            Database::query("UPDATE workflow_steps SET step_number=? WHERE id=?", [$i + 1, $r['id']]);
        }
        Auth::log('delete_workflow_step', 'workflow_templates', $id, ['step' => $stepId]);
        $_SESSION['flash_success'] = 'Stage removed.';
        header('Location: /workflows/' . $id . '/edit');
    }
}
