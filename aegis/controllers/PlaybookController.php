<?php
declare(strict_types=1);

class PlaybookController {

    public function index(): void {
        Auth::requireAuth();
        $playbooks = Database::fetchAll(
            "SELECT p.*, u.name as creator_name,
                    COUNT(DISTINCT ps.id) as step_count,
                    COUNT(DISTINCT ipr.id) as run_count
             FROM playbooks p
             LEFT JOIN users u ON u.id = p.created_by
             LEFT JOIN playbook_steps ps ON ps.playbook_id = p.id
             LEFT JOIN incident_playbook_runs ipr ON ipr.playbook_id = p.id
             GROUP BY p.id, u.name ORDER BY p.title"
        );
        $pageTitle    = 'Playbooks';
        $activeModule = 'playbooks';
        $breadcrumbs  = [['Playbooks', null]];
        ob_start();
        require AEGIS_ROOT . '/views/playbook/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('incident.write');
        $pageTitle    = 'New Playbook';
        $activeModule = 'playbooks';
        $breadcrumbs  = [['Playbooks', '/playbooks'], ['New', null]];
        ob_start();
        require AEGIS_ROOT . '/views/playbook/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('incident.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $title    = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $category = Security::sanitizeInput($_POST['category'] ?? 'general');
        $severity = Security::sanitizeInput($_POST['severity_filter'] ?? '');
        $desc     = trim(Security::sanitizeInput($_POST['description'] ?? ''));
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /playbooks/create'); return;
        }
        $id = Database::insert('playbooks', [
            'title'           => $title,
            'category'        => $category,
            'severity_filter' => $severity ?: null,
            'description'     => $desc,
            'created_by'      => Auth::id(),
        ]);
        $steps      = (array)($_POST['step_title'] ?? []);
        $stepDescs  = (array)($_POST['step_desc'] ?? []);
        $stepRoles  = (array)($_POST['step_role'] ?? []);
        $stepMins   = (array)($_POST['step_minutes'] ?? []);
        foreach ($steps as $i => $stitle) {
            $stitle = trim(Security::sanitizeInput($stitle));
            if (!$stitle) continue;
            Database::insert('playbook_steps', [
                'playbook_id' => $id,
                'step_number' => $i + 1,
                'title'       => $stitle,
                'description' => Security::sanitizeInput($stepDescs[$i] ?? ''),
                'owner_role'  => Security::sanitizeInput($stepRoles[$i] ?? ''),
                'due_minutes' => ($stepMins[$i] ?? '') !== '' ? (int)$stepMins[$i] : null,
                'sort_order'  => $i,
            ]);
        }
        Auth::log('playbook_created', 'playbooks', $id, ['title'=>$title]);
        $_SESSION['flash_success'] = 'Playbook created.';
        header("Location: /playbooks/{$id}");
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;
        $playbook = Database::fetchOne(
            "SELECT p.*, u.name as creator_name FROM playbooks p
             LEFT JOIN users u ON u.id = p.created_by WHERE p.id=?", [$id]
        );
        if (!$playbook) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $steps = Database::fetchAll(
            "SELECT * FROM playbook_steps WHERE playbook_id=? ORDER BY sort_order, step_number", [$id]
        );
        $runs = Database::fetchAll(
            "SELECT ipr.*, i.title as incident_title, u.name as started_by_name
             FROM incident_playbook_runs ipr
             JOIN incidents i ON i.id = ipr.incident_id
             LEFT JOIN users u ON u.id = ipr.started_by
             WHERE ipr.playbook_id=? ORDER BY ipr.started_at DESC LIMIT 10", [$id]
        );
        $pageTitle    = $playbook['title'];
        $activeModule = 'playbooks';
        $breadcrumbs  = [['Playbooks', '/playbooks'], [$playbook['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/playbook/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // Attach a playbook to an incident and start a run
    public function startRun(string $incidentId): void {
        Auth::requirePermission('incident.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $incidentId = (int)$incidentId;
        $playbookId = (int)($_POST['playbook_id'] ?? 0);
        $incident = Database::fetchOne("SELECT id FROM incidents WHERE id=?", [$incidentId]);
        $playbook = Database::fetchOne("SELECT id FROM playbooks WHERE id=? AND is_active=TRUE", [$playbookId]);
        if (!$incident || !$playbook) { http_response_code(404); return; }
        try {
            Database::insert('incident_playbook_runs', [
                'incident_id' => $incidentId,
                'playbook_id' => $playbookId,
                'started_by'  => Auth::id(),
            ]);
            Auth::log('playbook_run_started', 'incident_playbook_runs', $incidentId, ['playbook_id'=>$playbookId]);
            $_SESSION['flash_success'] = 'Playbook started.';
        } catch (Throwable) {
            $_SESSION['flash_error'] = 'Playbook already attached to this incident.';
        }
        header("Location: /incident/{$incidentId}");
    }

    // Complete a step in a run
    public function completeStep(string $runId): void {
        Auth::requirePermission('incident.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $runId  = (int)$runId;
        $stepId = (int)($_POST['step_id'] ?? 0);
        $notes  = trim(Security::sanitizeInput($_POST['notes'] ?? ''));
        $run = Database::fetchOne(
            "SELECT ipr.*, i.id as incident_id FROM incident_playbook_runs ipr
             JOIN incidents i ON i.id = ipr.incident_id WHERE ipr.id=?", [$runId]
        );
        if (!$run) { http_response_code(404); return; }
        Database::query(
            "INSERT INTO playbook_step_completions (run_id, step_id, completed_by, notes)
             VALUES (?,?,?,?) ON CONFLICT (run_id, step_id) DO UPDATE SET notes=EXCLUDED.notes",
            [$runId, $stepId, Auth::id(), $notes]
        );
        // Check if all steps complete — if so mark run complete
        $totalSteps = Database::fetchOne(
            "SELECT COUNT(*) as c FROM playbook_steps WHERE playbook_id=?",
            [$run['playbook_id']]
        )['c'] ?? 0;
        $doneSteps = Database::fetchOne(
            "SELECT COUNT(*) as c FROM playbook_step_completions WHERE run_id=?", [$runId]
        )['c'] ?? 0;
        if ($totalSteps > 0 && (int)$doneSteps >= (int)$totalSteps) {
            Database::query("UPDATE incident_playbook_runs SET completed_at=NOW() WHERE id=?", [$runId]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'done' => (int)$doneSteps, 'total' => (int)$totalSteps]);
    }

    public function toggle(string $id): void {
        Auth::requirePermission('incident.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id = (int)$id;
        Database::query("UPDATE playbooks SET is_active = NOT is_active WHERE id=?", [$id]);
        header('Location: /playbooks');
    }
}
