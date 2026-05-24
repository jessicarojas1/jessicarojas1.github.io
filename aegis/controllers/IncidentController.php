<?php
declare(strict_types=1);

class IncidentController {

    public function index(): void {
        Auth::requireAuth();

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
        Auth::requirePermission('incident.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/incident/create.php';
    }

    public function create(): void {
        Auth::requirePermission('incident.write');

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
        Auth::requireAuth();
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

        require AEGIS_ROOT . '/views/incident/view.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('incident.write');

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
        Auth::requirePermission('incident.write');

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
}
