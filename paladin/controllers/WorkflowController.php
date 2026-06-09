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
        $states = Database::fetchAll("SELECT * FROM wf_states WHERE template_id=? ORDER BY sort_order, id", [$id]);
        $transitions = Database::fetchAll("SELECT * FROM wf_transitions WHERE template_id=? ORDER BY id", [$id]);
        $assignedSpaces = Database::fetchAll(
            "SELECT s.id, s.space_key, s.name FROM workflow_space_assignments wsa JOIN spaces s ON s.id=wsa.space_id
             WHERE wsa.template_id=? ORDER BY s.name", [$id]
        );
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
        $states = Database::fetchAll("SELECT * FROM wf_states WHERE template_id=? ORDER BY sort_order, id", [$id]);
        $transitions = Database::fetchAll("SELECT * FROM wf_transitions WHERE template_id=? ORDER BY id", [$id]);
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $assigned = array_column(Database::fetchAll("SELECT space_id FROM workflow_space_assignments WHERE template_id=?", [$id]), 'space_id');
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

    // ── Stateful workflow: states ────────────────────────────────────────────
    private const STATE_KINDS = ['initial','inprogress','review','approved','rejected','final'];

    public function addState(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM workflow_templates WHERE id=?", [$id])) { http_response_code(404); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'State name is required.'; header('Location: /workflows/' . $id . '/edit'); return; }
        $kind = in_array($_POST['kind'] ?? '', self::STATE_KINDS, true) ? $_POST['kind'] : 'inprogress';
        $isInitial = !empty($_POST['is_initial']);
        $max = Database::fetchOne("SELECT COALESCE(MAX(sort_order),0) m FROM wf_states WHERE template_id=?", [$id]);
        if ($isInitial) Database::query("UPDATE wf_states SET is_initial=FALSE WHERE template_id=?", [$id]); // only one initial
        Database::insert('wf_states', [
            'template_id' => $id, 'name' => $name,
            'color' => Branding::sanitizeColor($_POST['color'] ?? '') ?: '#64748b',
            'kind' => $kind, 'is_initial' => $isInitial ? 't' : 'f', 'sort_order' => (int)$max['m'] + 1,
        ]);
        Auth::log('add_wf_state', 'workflow_templates', $id);
        $_SESSION['flash_success'] = 'State added.';
        header('Location: /workflows/' . $id . '/edit');
    }

    public function updateState(int $id, int $sid): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM wf_states WHERE id=? AND template_id=?", [$sid, $id])) { http_response_code(404); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') { $_SESSION['flash_error'] = 'State name is required.'; header('Location: /workflows/' . $id . '/edit'); return; }
        $kind = in_array($_POST['kind'] ?? '', self::STATE_KINDS, true) ? $_POST['kind'] : 'inprogress';
        $isInitial = !empty($_POST['is_initial']);
        if ($isInitial) Database::query("UPDATE wf_states SET is_initial=FALSE WHERE template_id=?", [$id]);
        Database::query("UPDATE wf_states SET name=?, color=?, kind=?, is_initial=? WHERE id=?",
            [$name, Branding::sanitizeColor($_POST['color'] ?? '') ?: '#64748b', $kind, $isInitial ? 't' : 'f', $sid]);
        Auth::log('update_wf_state', 'workflow_templates', $id, ['state' => $sid]);
        $_SESSION['flash_success'] = 'State updated.';
        header('Location: /workflows/' . $id . '/edit');
    }

    public function deleteState(int $id, int $sid): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM wf_states WHERE id=? AND template_id=?", [$sid, $id])) { http_response_code(404); return; }
        Database::query("DELETE FROM wf_states WHERE id=?", [$sid]); // transitions cascade
        Auth::log('delete_wf_state', 'workflow_templates', $id, ['state' => $sid]);
        $_SESSION['flash_success'] = 'State removed.';
        header('Location: /workflows/' . $id . '/edit');
    }

    // ── Stateful workflow: transitions ───────────────────────────────────────
    public function addTransition(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM workflow_templates WHERE id=?", [$id])) { http_response_code(404); return; }
        $from = (int)($_POST['from_state_id'] ?? 0);
        $to   = (int)($_POST['to_state_id'] ?? 0);
        $okFrom = Database::fetchOne("SELECT id FROM wf_states WHERE id=? AND template_id=?", [$from, $id]);
        $okTo   = Database::fetchOne("SELECT id FROM wf_states WHERE id=? AND template_id=?", [$to, $id]);
        if (!$okFrom || !$okTo) { $_SESSION['flash_error'] = 'Pick valid from/to states.'; header('Location: /workflows/' . $id . '/edit'); return; }
        Database::insert('wf_transitions', [
            'template_id' => $id, 'from_state_id' => $from, 'to_state_id' => $to,
            'action_label' => Security::sanitizeInput($_POST['action_label'] ?? '') ?: 'Submit',
            'approver_role' => Security::sanitizeInput($_POST['approver_role'] ?? '') ?: null,
            'approver_user_id' => !empty($_POST['approver_user_id']) ? (int)$_POST['approver_user_id'] : null,
        ]);
        Auth::log('add_wf_transition', 'workflow_templates', $id);
        $_SESSION['flash_success'] = 'Transition added.';
        header('Location: /workflows/' . $id . '/edit');
    }

    public function deleteTransition(int $id, int $tid): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM wf_transitions WHERE id=? AND template_id=?", [$tid, $id])) { http_response_code(404); return; }
        Database::query("DELETE FROM wf_transitions WHERE id=?", [$tid]);
        Auth::log('delete_wf_transition', 'workflow_templates', $id, ['transition' => $tid]);
        $_SESSION['flash_success'] = 'Transition removed.';
        header('Location: /workflows/' . $id . '/edit');
    }

    // ── Stateful workflow: space assignment ──────────────────────────────────
    public function assignSpace(int $id): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        if (!Database::fetchOne("SELECT id FROM workflow_templates WHERE id=?", [$id])) { http_response_code(404); return; }
        $sid = (int)($_POST['space_id'] ?? 0);
        if ($sid && Database::fetchOne("SELECT id FROM spaces WHERE id=?", [$sid])) {
            try { Database::insert('workflow_space_assignments', ['template_id' => $id, 'space_id' => $sid]); } catch (Throwable) {}
            Auth::log('assign_workflow_space', 'workflow_templates', $id, ['space' => $sid]);
        }
        header('Location: /workflows/' . $id . '/edit');
    }

    public function unassignSpace(int $id, int $sid): void {
        Auth::requirePermission('workflow.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM workflow_space_assignments WHERE template_id=? AND space_id=?", [$id, $sid]);
        Auth::log('unassign_workflow_space', 'workflow_templates', $id, ['space' => $sid]);
        header('Location: /workflows/' . $id . '/edit');
    }
}
