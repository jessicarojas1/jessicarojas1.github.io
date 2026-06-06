<?php
class BCPController {
    public function index(): void {
        Auth::requirePermission('bcp.view');
        $plans = Database::fetchAll(
            "SELECT bp.*, u.name AS owner_name,
               (SELECT COUNT(*) FROM bcp_exercises be WHERE be.plan_id = bp.id) AS exercise_count,
               (SELECT COUNT(*) FROM bcp_plan_sections bs WHERE bs.plan_id = bp.id) AS section_count
             FROM bcp_plans bp LEFT JOIN users u ON u.id = bp.owner_id
             ORDER BY bp.created_at DESC"
        );
        $pageTitle    = 'Business Continuity Plans';
        $activeModule = 'bcp';
        $breadcrumbs  = [['BCP / DR', null]];
        require AEGIS_ROOT . '/views/bcp/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('bcp.edit');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $pageTitle    = 'New BCP Plan';
        $activeModule = 'bcp';
        $breadcrumbs  = [['BCP / DR', '/bcp'], ['New Plan', null]];
        require AEGIS_ROOT . '/views/bcp/create.php';
    }

    public function create(): void {
        Auth::requirePermission('bcp.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo 'CSRF error'; return;
        }
        $title        = Security::sanitizeInput(substr($_POST['title'] ?? '', 0, 255));
        $description  = Security::sanitizeInput($_POST['description'] ?? '');
        $version      = Security::sanitizeInput(substr($_POST['version'] ?? '1.0', 0, 50));
        $status       = in_array($_POST['status'] ?? '', ['draft','active','archived']) ? $_POST['status'] : 'draft';
        $rtoHours     = !empty($_POST['rto_hours']) ? (int)$_POST['rto_hours'] : null;
        $rpoHours     = !empty($_POST['rpo_hours']) ? (int)$_POST['rpo_hours'] : null;
        $ownerId      = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $nextTestDate = !empty($_POST['next_test_date']) ? Security::sanitizeInput($_POST['next_test_date']) : null;
        if (!$title) { header('Location: /bcp/create?error=missing'); return; }

        // Generate BCP plan code from next sequential ID
        $maxRow   = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM bcp_plans");
        $planCode = 'BCP-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $planId = Database::insert('bcp_plans', [
            'plan_code'      => $planCode,
            'title'          => $title,
            'description'    => $description,
            'version'        => $version,
            'status'         => $status,
            'rto_hours'      => $rtoHours,
            'rpo_hours'      => $rpoHours,
            'owner_id'       => $ownerId,
            'next_test_date' => $nextTestDate,
        ]);

        foreach ($_POST['sections'] ?? [] as $section) {
            $sType   = Security::sanitizeInput($section['section_type'] ?? 'scope');
            $sTitle  = Security::sanitizeInput(substr($section['title'] ?? '', 0, 255));
            $content = Security::sanitizeInput($section['content'] ?? '');
            if (!$sTitle) continue;
            Database::insert('bcp_plan_sections', [
                'plan_id'      => $planId,
                'section_type' => $sType,
                'title'        => $sTitle,
                'content'      => $content,
                'sort_order'   => (int)($section['sort_order'] ?? 0),
            ]);
        }
        Auth::log('create_bcp', 'bcp_plans', $planId, ['plan_code' => $planCode, 'title' => $title]);
        $_SESSION['flash_success'] = "BCP Plan {$planCode} created successfully.";
        header("Location: /bcp/{$planId}");
    }

    public function view(string $id): void {
        Auth::requirePermission('bcp.view');
        $id = (int)$id;
        $plan = Database::fetchOne(
            "SELECT bp.*, u.name AS owner_name FROM bcp_plans bp LEFT JOIN users u ON u.id = bp.owner_id WHERE bp.id = ?",
            [$id]
        );
        if (!$plan) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }
        $sections  = Database::fetchAll("SELECT * FROM bcp_plan_sections WHERE plan_id = ? ORDER BY sort_order", [$id]);
        $exercises = Database::fetchAll(
            "SELECT be.*, u.name AS created_by_name FROM bcp_exercises be LEFT JOIN users u ON u.id = be.created_by WHERE be.plan_id = ? ORDER BY be.created_at DESC",
            [$id]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $pageTitle    = Security::h($plan['title']);
        $activeModule = 'bcp';
        $breadcrumbs  = [['BCP / DR', '/bcp'], [$plan['title'], null]];
        require AEGIS_ROOT . '/views/bcp/view.php';
    }

    public function addExercise(string $id): void {
        Auth::requirePermission('bcp.exercise');
        $id = (int)$id;
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo 'CSRF error'; return;
        }
        $name           = Security::sanitizeInput(substr($_POST['name'] ?? '', 0, 255));
        $exerciseType   = in_array($_POST['exercise_type'] ?? '', ['tabletop','walkthrough','full_scale']) ? $_POST['exercise_type'] : 'tabletop';
        $scheduledDate  = !empty($_POST['scheduled_date']) ? Security::sanitizeInput($_POST['scheduled_date']) : null;
        $conductedDate  = !empty($_POST['conducted_date']) ? Security::sanitizeInput($_POST['conducted_date']) : null;
        $outcome        = in_array($_POST['outcome'] ?? '', ['passed','passed_with_findings','failed','cancelled']) ? $_POST['outcome'] : null;
        $findings       = Security::sanitizeInput($_POST['findings'] ?? '');
        $lessons        = Security::sanitizeInput($_POST['lessons_learned'] ?? '');
        if (!$name) { header("Location: /bcp/{$id}"); return; }

        Database::insert('bcp_exercises', [
            'plan_id'        => $id,
            'exercise_type'  => $exerciseType,
            'name'           => $name,
            'scheduled_date' => $scheduledDate,
            'conducted_date' => $conductedDate,
            'outcome'        => $outcome,
            'findings'       => $findings,
            'lessons_learned'=> $lessons,
            'created_by'     => Auth::id(),
        ]);
        if ($conductedDate) {
            Database::query("UPDATE bcp_plans SET last_tested = ? WHERE id = ?", [$conductedDate, $id]);
        }
        Auth::log('add_bcp_exercise', 'bcp_plans', $id, ['name' => $name]);
        header("Location: /bcp/{$id}#exercises");
    }

    public function update(string $id): void {
        Auth::requirePermission('bcp.edit');
        $id = (int)$id;
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo 'CSRF error'; return;
        }
        Database::query(
            "UPDATE bcp_plans SET title=?, description=?, version=?, status=?, rto_hours=?, rpo_hours=?, owner_id=?, next_test_date=?, updated_at=NOW() WHERE id=?",
            [
                Security::sanitizeInput(substr($_POST['title'] ?? '', 0, 255)),
                Security::sanitizeInput($_POST['description'] ?? ''),
                Security::sanitizeInput(substr($_POST['version'] ?? '1.0', 0, 50)),
                in_array($_POST['status'] ?? '', ['draft','active','archived']) ? $_POST['status'] : 'draft',
                !empty($_POST['rto_hours']) ? (int)$_POST['rto_hours'] : null,
                !empty($_POST['rpo_hours']) ? (int)$_POST['rpo_hours'] : null,
                !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null,
                !empty($_POST['next_test_date']) ? Security::sanitizeInput($_POST['next_test_date']) : null,
                $id,
            ]
        );
        Auth::log('update_bcp', 'bcp_plans', $id);
        header("Location: /bcp/{$id}?saved=1");
    }
}
