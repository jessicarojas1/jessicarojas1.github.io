<?php
declare(strict_types=1);

class IncidentController {

    public function index(): void {
        Auth::requirePermission('incident.view');

        $severity = Security::sanitizeInput($_GET['severity'] ?? '');
        $status   = Security::sanitizeInput($_GET['status']   ?? '');
        $search   = Security::sanitizeInput($_GET['search']   ?? '');

        $where  = ['1=1'];
        $params = [];

        if ($severity && in_array($severity, ['critical','high','medium','low'])) {
            $where[] = 'i.severity = ?';
            $params[] = $severity;
        }
        if ($status && in_array($status, ['open','investigating','contained','resolved','closed'])) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if ($search) {
            $where[] = '(i.title ILIKE ? OR i.incident_number ILIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $whereSQL = implode(' AND ', $where);

        $incidents = Database::fetchAll(
            "SELECT i.*,
                    u1.name AS reported_by_name,
                    u2.name AS assigned_to_name
             FROM incidents i
             LEFT JOIN users u1 ON i.reported_by = u1.id
             LEFT JOIN users u2 ON i.assigned_to  = u2.id
             WHERE {$whereSQL}
             ORDER BY
               CASE i.severity
                 WHEN 'critical' THEN 1
                 WHEN 'high'     THEN 2
                 WHEN 'medium'   THEN 3
                 WHEN 'low'      THEN 4
                 ELSE 5
               END,
               i.created_at DESC",
            $params
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status = 'open')          AS open,
               COUNT(*) FILTER (WHERE status = 'investigating')  AS investigating,
               COUNT(*) FILTER (WHERE status = 'contained')      AS contained,
               COUNT(*) FILTER (WHERE status IN ('resolved','closed')) AS resolved
             FROM incidents"
        );

        require AEGIS_ROOT . '/views/incident/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('incident.create');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/incident/create.php';
    }

    public function create(): void {
        Auth::requirePermission('incident.create');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title             = Security::sanitizeInput($_POST['title']              ?? '');
        $description       = Security::sanitizeInput($_POST['description']        ?? '');
        $severity          = Security::sanitizeInput($_POST['severity']            ?? 'medium');
        $category          = Security::sanitizeInput($_POST['category']            ?? '');
        $affectedSystems   = Security::sanitizeInput($_POST['affected_systems']   ?? '');
        $impactDescription = Security::sanitizeInput($_POST['impact_description'] ?? '');
        $assignedTo        = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $detectedAt        = Security::sanitizeInput($_POST['detected_at']        ?? '');

        if (!$title) {
            $_SESSION['flash_error'] = 'Incident title is required.';
            header('Location: /incident/create');
            return;
        }

        if (!in_array($severity, ['critical','high','medium','low'])) {
            $severity = 'medium';
        }
        if (!in_array($category, ['Data Breach','System Outage','Unauthorized Access','Malware','Physical','Policy Violation','Other',''])) {
            $category = 'Other';
        }

        // Generate incident number from next ID
        $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM incidents");
        $nextId = ((int)$maxRow['max_id']) + 1;
        $incidentNumber = 'INC-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);

        $detectedAtValue = $detectedAt ? str_replace('T', ' ', $detectedAt) : date('Y-m-d H:i:s');

        $id = Database::insert('incidents', [
            'incident_number'    => $incidentNumber,
            'title'              => $title,
            'description'        => $description,
            'severity'           => $severity,
            'category'           => $category ?: null,
            'status'             => 'open',
            'reported_by'        => Auth::id(),
            'assigned_to'        => $assignedTo,
            'affected_systems'   => $affectedSystems ?: null,
            'impact_description' => $impactDescription ?: null,
            'detected_at'        => $detectedAtValue,
        ]);

        Auth::log('create_incident', 'incidents', $id, [
            'incident_number' => $incidentNumber,
            'severity'        => $severity,
        ]);

        $_SESSION['flash_success'] = "Incident {$incidentNumber} created successfully.";
        header('Location: /incident/' . $id);
    }

    public function view(string $id): void {
        Auth::requirePermission('incident.view');
        $id = (int)$id;

        $incident = Database::fetchOne(
            "SELECT i.*,
                    u1.name AS reported_by_name,
                    u2.name AS assigned_to_name
             FROM incidents i
             LEFT JOIN users u1 ON i.reported_by = u1.id
             LEFT JOIN users u2 ON i.assigned_to  = u2.id
             WHERE i.id = ?",
            [$id]
        );

        if (!$incident) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $updates = Database::fetchAll(
            "SELECT iu.*, u.name AS user_name
             FROM incident_updates iu
             LEFT JOIN users u ON iu.user_id = u.id
             WHERE iu.incident_id = ?
             ORDER BY iu.created_at ASC",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        // Playbook runs for this incident
        $playbookRuns = Database::fetchAll(
            "SELECT ipr.*, p.title as playbook_title,
                    u.name as started_by_name,
                    COUNT(ps.id) as total_steps,
                    COUNT(psc.id) as done_steps
             FROM incident_playbook_runs ipr
             JOIN playbooks p ON p.id = ipr.playbook_id
             LEFT JOIN users u ON u.id = ipr.started_by
             LEFT JOIN playbook_steps ps ON ps.playbook_id = p.id
             LEFT JOIN playbook_step_completions psc ON psc.run_id = ipr.id AND psc.step_id = ps.id
             WHERE ipr.incident_id = ?
             GROUP BY ipr.id, p.title, u.name
             ORDER BY ipr.started_at ASC",
            [$id]
        );

        // For each run load its steps with completion state
        $playbookRunSteps = [];
        foreach ($playbookRuns as $run) {
            $playbookRunSteps[$run['id']] = Database::fetchAll(
                "SELECT ps.*,
                        psc.id as completion_id,
                        psc.completed_by,
                        psc.completed_at,
                        psc.notes as completion_notes,
                        cu.name as completed_by_name
                 FROM playbook_steps ps
                 LEFT JOIN playbook_step_completions psc ON psc.step_id = ps.id AND psc.run_id = ?
                 LEFT JOIN users cu ON cu.id = psc.completed_by
                 WHERE ps.playbook_id = ?
                 ORDER BY ps.sort_order, ps.step_number",
                [$run['id'], $run['playbook_id']]
            );
        }

        // Active playbooks available to start (not already running)
        $attachedPlaybookIds = array_column($playbookRuns, 'playbook_id');
        $availablePlaybooks = Database::fetchAll(
            "SELECT id, title, category, severity_filter FROM playbooks WHERE is_active = TRUE ORDER BY title"
        );
        // Filter out already-attached ones in PHP to avoid parameter binding complexity
        $availablePlaybooks = array_values(array_filter(
            $availablePlaybooks,
            fn($p) => !in_array((int)$p['id'], array_map('intval', $attachedPlaybookIds))
        ));

        require AEGIS_ROOT . '/views/incident/view.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('incident.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id                = (int)$id;
        $title             = Security::sanitizeInput($_POST['title']              ?? '');
        $description       = Security::sanitizeInput($_POST['description']        ?? '');
        $severity          = Security::sanitizeInput($_POST['severity']            ?? 'medium');
        $category          = Security::sanitizeInput($_POST['category']            ?? '');
        $affectedSystems   = Security::sanitizeInput($_POST['affected_systems']   ?? '');
        $impactDescription = Security::sanitizeInput($_POST['impact_description'] ?? '');
        $rootCause         = Security::sanitizeInput($_POST['root_cause']         ?? '');
        $lessonsLearned    = Security::sanitizeInput($_POST['lessons_learned']    ?? '');
        $assignedTo        = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $status            = Security::sanitizeInput($_POST['status']              ?? 'open');

        if (!in_array($severity, ['critical','high','medium','low'])) {
            $severity = 'medium';
        }
        if (!in_array($status, ['open','investigating','contained','resolved','closed'])) {
            $status = 'open';
        }

        $data = [
            'title'              => $title,
            'description'        => $description,
            'severity'           => $severity,
            'category'           => $category ?: null,
            'status'             => $status,
            'assigned_to'        => $assignedTo,
            'affected_systems'   => $affectedSystems ?: null,
            'impact_description' => $impactDescription ?: null,
            'root_cause'         => $rootCause ?: null,
            'lessons_learned'    => $lessonsLearned ?: null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        if ($status === 'contained') {
            $data['contained_at'] = date('Y-m-d H:i:s');
        }
        if (in_array($status, ['resolved','closed'])) {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }

        Database::update('incidents', $data, 'id = ?', [$id]);

        Auth::log('update_incident', 'incidents', $id, ['status' => $status, 'severity' => $severity]);

        $_SESSION['flash_success'] = 'Incident updated successfully.';
        header('Location: /incident/' . $id);
    }

    public function addUpdate(string $id): void {
        Auth::requirePermission('incident.edit');

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
            header('Location: /incident/' . $id);
            return;
        }

        if (!in_array($updateType, ['comment','status_change','assignment','containment','resolution'])) {
            $updateType = 'comment';
        }

        Database::insert('incident_updates', [
            'incident_id' => $id,
            'user_id'     => Auth::id(),
            'content'     => $content,
            'update_type' => $updateType,
        ]);

        if ($updateType === 'status_change' && $newStatus && in_array($newStatus, ['open','investigating','contained','resolved','closed'])) {
            $updateData = [
                'status'     => $newStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($newStatus === 'contained') {
                $updateData['contained_at'] = date('Y-m-d H:i:s');
            }
            if (in_array($newStatus, ['resolved','closed'])) {
                $updateData['resolved_at'] = date('Y-m-d H:i:s');
            }
            Database::update('incidents', $updateData, 'id = ?', [$id]);
        } else {
            Database::update('incidents', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        }

        Auth::log('add_incident_update', 'incidents', $id, ['update_type' => $updateType]);

        $_SESSION['flash_success'] = 'Update added successfully.';
        header('Location: /incident/' . $id);
    }

    public function close(string $id): void {
        Auth::requirePermission('incident.close');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;

        Database::update('incidents', [
            'status'      => 'closed',
            'resolved_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        Database::insert('incident_updates', [
            'incident_id' => $id,
            'user_id'     => Auth::id(),
            'content'     => 'Incident closed.',
            'update_type' => 'resolution',
        ]);

        Auth::log('close_incident', 'incidents', $id, ['status' => 'closed']);

        $_SESSION['flash_success'] = 'Incident closed successfully.';
        header('Location: /incident/' . $id);
    }

    public function acknowledge(string $id): void {
        Auth::requirePermission('incident.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id       = (int)$id;
        $incident = Database::fetchOne("SELECT id, severity FROM incidents WHERE id=?", [$id]);
        if (!$incident) { http_response_code(404); return; }
        // Check not already acknowledged
        $existing = Database::fetchOne(
            "SELECT id FROM incident_sla_events WHERE incident_id=? AND event_type='acknowledged'", [$id]
        );
        if (!$existing) {
            Database::insert('incident_sla_events', [
                'incident_id' => $id,
                'event_type'  => 'acknowledged',
                'recorded_by' => Auth::id(),
                'notes'       => Security::sanitizeInput($_POST['notes'] ?? ''),
            ]);
            Auth::log('incident_acknowledged', 'incidents', $id, []);
            $_SESSION['flash_success'] = 'Incident acknowledged.';
        } else {
            $_SESSION['flash_error'] = 'Already acknowledged.';
        }
        header("Location: /incident/{$id}");
    }

    public function slaReport(): void {
        Auth::requirePermission('incident.view');
        // Active incidents with SLA status
        $incidents = Database::fetchAll(
            "SELECT i.*, isp.acknowledge_hours, isp.resolve_hours,
                    ack_evt.occurred_at as acknowledged_at,
                    res_evt.occurred_at as resolved_at
             FROM incidents i
             LEFT JOIN incident_sla_policies isp ON isp.severity = i.severity
             LEFT JOIN incident_sla_events ack_evt ON ack_evt.incident_id=i.id AND ack_evt.event_type='acknowledged'
             LEFT JOIN incident_sla_events res_evt ON res_evt.incident_id=i.id AND res_evt.event_type='resolved'
             WHERE i.status != 'closed'
             ORDER BY i.created_at DESC"
        );
        // Add computed SLA fields
        foreach ($incidents as &$inc) {
            $inc['ack_sla_status']     = self::slaStatus($inc['created_at'], $inc['acknowledged_at'], $inc['acknowledge_hours']);
            $inc['resolve_sla_status'] = self::slaStatus($inc['created_at'], $inc['resolved_at'], $inc['resolve_hours']);
            $inc['age_hours']          = round((time() - strtotime($inc['created_at'])) / 3600, 1);
        }
        unset($inc);
        $pageTitle    = 'SLA Report';
        $activeModule = 'incident_sla';
        $breadcrumbs  = [['Incidents', '/incident'], ['SLA Report', null]];
        ob_start();
        require AEGIS_ROOT . '/views/incident/sla_report.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    private static function slaStatus(?string $startedAt, ?string $eventAt, ?int $hoursAllowed): string {
        if (!$startedAt || !$hoursAllowed) return 'n/a';
        if ($eventAt) return 'met'; // Completed within SLA (assume met if done)
        $elapsed = (time() - strtotime($startedAt)) / 3600;
        if ($elapsed > $hoursAllowed) return 'breached';
        if ($elapsed > $hoursAllowed * 0.75) return 'at_risk';
        return 'on_track';
    }
}
