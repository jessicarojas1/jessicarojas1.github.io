<?php
class AuditController {
    public function index(): void {
        Auth::requireAuth();

        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $where  = $status ? "WHERE a.status = ?" : "WHERE 1=1";
        $params = $status ? [$status] : [];

        $audits = Database::fetchAll(
            "SELECT a.*, cp.name as package_name, u.name as auditor_name, u2.name as created_by_name
             FROM audits a
             LEFT JOIN compliance_packages cp ON a.package_id = cp.id
             LEFT JOIN users u ON a.auditor_id = u.id
             LEFT JOIN users u2 ON a.created_by = u2.id
             {$where} ORDER BY a.scheduled_date DESC",
            $params
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) FILTER (WHERE status = 'planned') as planned,
               COUNT(*) FILTER (WHERE status = 'in_progress') as in_progress,
               COUNT(*) FILTER (WHERE status = 'completed') as completed,
               COUNT(*) FILTER (WHERE status = 'overdue') as overdue
             FROM audits"
        );

        require AEGIS_ROOT . '/views/audit/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('audit.write');
        $packages = Database::fetchAll("SELECT cp.id, cp.name, s.code FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.is_active = TRUE ORDER BY cp.name");
        $users    = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/audit/create.php';
    }

    public function create(): void {
        Auth::requirePermission('audit.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $name        = Security::sanitizeInput($_POST['name'] ?? '');
        $description = Security::sanitizeInput($_POST['description'] ?? '');
        $packageId   = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
        $auditType   = in_array($_POST['audit_type'] ?? '', ['internal','external','gap','follow_up']) ? $_POST['audit_type'] : 'internal';
        $frequency   = in_array($_POST['frequency'] ?? '', ['one_time','monthly','quarterly','biannual','annual']) ? $_POST['frequency'] : 'annual';
        $scheduledDate = Security::sanitizeInput($_POST['scheduled_date'] ?? '');
        $auditorId   = !empty($_POST['auditor_id']) ? (int)$_POST['auditor_id'] : null;

        if (!$name || !$scheduledDate) {
            $_SESSION['audit_error'] = 'Name and scheduled date are required.';
            header('Location: /audit/create'); return;
        }

        $auditId = Database::insert('audits', [
            'name'           => $name,
            'description'    => $description,
            'package_id'     => $packageId,
            'audit_type'     => $auditType,
            'frequency'      => $frequency,
            'status'         => 'planned',
            'scheduled_date' => $scheduledDate,
            'auditor_id'     => $auditorId,
            'created_by'     => Auth::id(),
        ]);

        if ($packageId) {
            $objectives = Database::fetchAll(
                "SELECT id FROM compliance_objectives WHERE package_id = ? AND level = 2",
                [$packageId]
            );
            foreach ($objectives as $obj) {
                Database::query(
                    "INSERT INTO audit_items (audit_id, objective_id) VALUES (?,?)",
                    [$auditId, $obj['id']]
                );
            }

            if ($frequency !== 'one_time') {
                $nextDue = match($frequency) {
                    'monthly'   => date('Y-m-d', strtotime($scheduledDate . ' +1 month')),
                    'quarterly' => date('Y-m-d', strtotime($scheduledDate . ' +3 months')),
                    'biannual'  => date('Y-m-d', strtotime($scheduledDate . ' +6 months')),
                    default     => date('Y-m-d', strtotime($scheduledDate . ' +1 year')),
                };
                Database::insert('audit_schedules', [
                    'package_id'       => $packageId,
                    'frequency'        => $frequency,
                    'next_due_date'    => $nextDue,
                    'assigned_auditor' => $auditorId,
                ]);
            }
        }

        Auth::log('create_audit', 'audits', $auditId);
        header('Location: /audit/' . $auditId);
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $audit = Database::fetchOne(
            "SELECT a.*, cp.name as package_name, s.name as standard_name,
               u.name as auditor_name, u2.name as created_by_name
             FROM audits a
             LEFT JOIN compliance_packages cp ON a.package_id = cp.id
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN users u ON a.auditor_id = u.id
             LEFT JOIN users u2 ON a.created_by = u2.id
             WHERE a.id = ?", [$id]
        );
        if (!$audit) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $items = Database::fetchAll(
            "SELECT ai.*, co.code, co.title, co.category,
               parent.code as domain_code, parent.title as domain_title
             FROM audit_items ai
             JOIN compliance_objectives co ON co.id = ai.objective_id
             LEFT JOIN compliance_objectives parent ON parent.id = co.parent_id
             WHERE ai.audit_id = ? ORDER BY co.sort_order",
            [$id]
        );

        $summary = Database::fetchOne(
            "SELECT
               COUNT(*) as total,
               COUNT(*) FILTER (WHERE status = 'compliant') as compliant,
               COUNT(*) FILTER (WHERE status = 'non_compliant') as non_compliant,
               COUNT(*) FILTER (WHERE status = 'partial') as partial,
               COUNT(*) FILTER (WHERE status = 'not_assessed') as not_assessed
             FROM audit_items WHERE audit_id = ?", [$id]
        );

        $groupedByDomain = [];
        foreach ($items as $item) {
            $domain = $item['domain_title'] ?? 'General';
            $groupedByDomain[$domain][] = $item;
        }

        require AEGIS_ROOT . '/views/audit/view.php';
    }

    public function updateItem(string $auditId, string $itemId): void {
        Auth::requirePermission('audit.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $status   = in_array($_POST['status'] ?? '', ['not_assessed','compliant','non_compliant','partial','not_applicable']) ? $_POST['status'] : 'not_assessed';
        $finding  = Security::sanitizeInput($_POST['finding'] ?? '');
        $evidence = Security::sanitizeInput($_POST['evidence'] ?? '');
        $riskLevel = in_array($_POST['risk_level'] ?? '', ['low','medium','high','critical','']) ? $_POST['risk_level'] : null;
        $remediation = Security::sanitizeInput($_POST['remediation'] ?? '');

        Database::query(
            "UPDATE audit_items SET status=?, finding=?, evidence=?, risk_level=?, remediation=?, updated_at=NOW() WHERE id=? AND audit_id=?",
            [$status, $finding, $evidence, $riskLevel ?: null, $remediation, (int)$itemId, (int)$auditId]
        );

        Auth::log('update_audit_item', 'audit_items', (int)$itemId, ['status' => $status]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'status' => $status]);
    }

    public function update(string $id): void {
        Auth::requirePermission('audit.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        $status = in_array($_POST['status'] ?? '', ['planned','in_progress','completed','overdue','cancelled']) ? $_POST['status'] : 'planned';
        $notes  = Security::sanitizeInput($_POST['notes'] ?? '');

        Database::query("UPDATE audits SET status=?, notes=?, updated_at=NOW() WHERE id=?", [$status, $notes, $id]);

        if ($status === 'in_progress') {
            Database::query("UPDATE audits SET start_date = COALESCE(start_date, CURRENT_DATE) WHERE id=?", [$id]);
        }

        header('Location: /audit/' . $id . '?updated=1');
    }

    public function complete(string $id): void {
        Auth::requirePermission('audit.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id = (int)$id;
        $items = Database::fetchAll("SELECT status FROM audit_items WHERE audit_id = ?", [$id]);
        $total = count($items);
        $compliant = count(array_filter($items, fn($i) => $i['status'] === 'compliant'));
        $score = $total > 0 ? round(($compliant / $total) * 100, 2) : 0;

        Database::query(
            "UPDATE audits SET status='completed', completed_date=CURRENT_DATE, score=?, updated_at=NOW() WHERE id=?",
            [$score, $id]
        );

        Auth::log('complete_audit', 'audits', $id, ['score' => $score]);
        header('Location: /audit/' . $id . '?completed=1');
    }

    public function editForm(string $id): void {
        Auth::requirePermission('audit.write');
        $audit = Database::fetchOne("SELECT * FROM audits WHERE id = ?", [(int)$id]);
        if (!$audit) { http_response_code(404); return; }
        $packages = Database::fetchAll("SELECT cp.id, cp.name FROM compliance_packages cp WHERE is_active = TRUE");
        $users    = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/audit/create.php';
    }
}
