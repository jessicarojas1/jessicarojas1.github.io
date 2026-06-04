<?php
declare(strict_types=1);

class AuditFindingController {

    private const VALID_SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const VALID_STATUSES   = ['open', 'in_progress', 'resolved', 'risk_accepted', 'closed'];
    private const VALID_SOURCES    = ['external_audit', 'pentest', 'certification', 'assessment', 'regulatory', 'other'];

    public function index(): void {
        Auth::requireAuth();

        $findings = Database::fetchAll(
            "SELECT af.*,
                    u.name  AS owner_name,
                    co.code AS control_code,
                    cp.name AS package_name
             FROM audit_findings af
             LEFT JOIN users u  ON u.id  = af.owner_id
             LEFT JOIN compliance_objectives co ON co.id = af.objective_id
             LEFT JOIN compliance_packages   cp ON cp.id = af.package_id
             ORDER BY
               CASE af.severity
                 WHEN 'critical' THEN 1
                 WHEN 'high'     THEN 2
                 WHEN 'medium'   THEN 3
                 WHEN 'low'      THEN 4
                 ELSE 5
               END,
               af.created_at DESC"
        );

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status NOT IN ('closed','resolved','risk_accepted')) AS open_count,
               COUNT(*) FILTER (WHERE severity IN ('critical','high') AND status NOT IN ('closed','resolved','risk_accepted')) AS critical_high,
               COUNT(*) FILTER (WHERE deadline < CURRENT_DATE AND status NOT IN ('closed','resolved','risk_accepted')) AS overdue
             FROM audit_findings"
        );

        $users    = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $packages = Database::fetchAll("SELECT id, name FROM compliance_packages ORDER BY name");

        $pageTitle    = 'External Audit Findings';
        $activeModule = 'audit_findings';
        $breadcrumbs  = [['External Audit Findings', null]];
        ob_start();
        require AEGIS_ROOT . '/views/audit_findings/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requireAuth();
        header('Location: /audit-findings');
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title       = Security::sanitizeInput($_POST['title']        ?? '');
        $description = Security::sanitizeInput($_POST['description']  ?? '');
        $severity    = Security::sanitizeInput($_POST['severity']     ?? 'medium');
        $status      = Security::sanitizeInput($_POST['status']       ?? 'open');
        $source      = Security::sanitizeInput($_POST['source']       ?? 'external_audit');
        $auditName   = Security::sanitizeInput($_POST['audit_name']   ?? '');
        $auditorName = Security::sanitizeInput($_POST['auditor_name'] ?? '');
        $deadline    = Security::sanitizeInput($_POST['deadline']     ?? '');
        $ownerId     = !empty($_POST['owner_id'])    ? (int)$_POST['owner_id']    : null;
        $packageId   = !empty($_POST['package_id'])  ? (int)$_POST['package_id']  : null;
        $objectiveId = !empty($_POST['objective_id']) ? (int)$_POST['objective_id'] : null;

        if (!$title) {
            $_SESSION['flash_error'] = 'Finding title is required.';
            header('Location: /audit-findings');
            return;
        }

        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            $severity = 'medium';
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $status = 'open';
        }
        if (!in_array($source, self::VALID_SOURCES, true)) {
            $source = 'external_audit';
        }

        // Auto-generate finding number
        $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM audit_findings");
        $nextId = ((int)($maxRow['max_id'] ?? 0)) + 1;
        $findingNumber = 'FIND-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);

        $id = Database::insert('audit_findings', [
            'finding_number' => $findingNumber,
            'title'          => $title,
            'description'    => $description ?: null,
            'severity'       => $severity,
            'status'         => $status,
            'source'         => $source,
            'audit_name'     => $auditName   ?: null,
            'auditor_name'   => $auditorName ?: null,
            'deadline'       => $deadline    ?: null,
            'owner_id'       => $ownerId,
            'package_id'     => $packageId,
            'objective_id'   => $objectiveId,
            'created_by'     => Auth::id(),
        ]);

        Auth::log('created', 'audit_findings', $id, [
            'finding_number' => $findingNumber,
            'severity'       => $severity,
        ]);

        $_SESSION['flash_success'] = "Finding {$findingNumber} created successfully.";
        header('Location: /audit-findings/' . $id);
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $finding = Database::fetchOne(
            "SELECT af.*,
                    u.name  AS owner_name,
                    co.code AS control_code,
                    co.title AS control_title,
                    cp.name AS package_name,
                    cb.name AS created_by_name
             FROM audit_findings af
             LEFT JOIN users u    ON u.id    = af.owner_id
             LEFT JOIN users cb   ON cb.id   = af.created_by
             LEFT JOIN compliance_objectives co ON co.id = af.objective_id
             LEFT JOIN compliance_packages   cp ON cp.id = af.package_id
             WHERE af.id = ?",
            [$id]
        );

        if (!$finding) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $updates = Database::fetchAll(
            "SELECT fu.*, u.name AS user_name
             FROM finding_updates fu
             LEFT JOIN users u ON u.id = fu.user_id
             WHERE fu.finding_id = ?
             ORDER BY fu.created_at ASC",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = 'Finding: ' . $finding['finding_number'];
        $activeModule = 'audit_findings';
        $breadcrumbs  = [['External Audit Findings', '/audit-findings'], [$finding['finding_number'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/audit_findings/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id          = (int)$id;
        $title       = Security::sanitizeInput($_POST['title']         ?? '');
        $description = Security::sanitizeInput($_POST['description']   ?? '');
        $severity    = Security::sanitizeInput($_POST['severity']      ?? 'medium');
        $status      = Security::sanitizeInput($_POST['status']        ?? 'open');
        $source      = Security::sanitizeInput($_POST['source']        ?? 'external_audit');
        $auditName   = Security::sanitizeInput($_POST['audit_name']    ?? '');
        $auditorName = Security::sanitizeInput($_POST['auditor_name']  ?? '');
        $deadline    = Security::sanitizeInput($_POST['deadline']      ?? '');
        $responseNotes = Security::sanitizeInput($_POST['response_notes'] ?? '');
        $ownerId     = !empty($_POST['owner_id'])   ? (int)$_POST['owner_id']   : null;
        $packageId   = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
        $objectiveId = !empty($_POST['objective_id']) ? (int)$_POST['objective_id'] : null;

        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            $severity = 'medium';
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $status = 'open';
        }
        if (!in_array($source, self::VALID_SOURCES, true)) {
            $source = 'external_audit';
        }

        $data = [
            'title'          => $title,
            'description'    => $description ?: null,
            'severity'       => $severity,
            'status'         => $status,
            'source'         => $source,
            'audit_name'     => $auditName   ?: null,
            'auditor_name'   => $auditorName ?: null,
            'deadline'       => $deadline    ?: null,
            'response_notes' => $responseNotes ?: null,
            'owner_id'       => $ownerId,
            'package_id'     => $packageId,
            'objective_id'   => $objectiveId,
        ];

        if ($status === 'closed') {
            $data['closed_at'] = date('Y-m-d H:i:s');
        }

        Database::update('audit_findings', $data, 'id = ?', [$id]);

        Auth::log('updated', 'audit_findings', $id, ['status' => $status, 'severity' => $severity]);

        $_SESSION['flash_success'] = 'Finding updated successfully.';
        header('Location: /audit-findings/' . $id);
    }

    public function addUpdate(string $id): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id      = (int)$id;
        $content = Security::sanitizeInput($_POST['content'] ?? '');

        if (!$content) {
            $_SESSION['flash_error'] = 'Update content cannot be empty.';
            header('Location: /audit-findings/' . $id);
            return;
        }

        Database::insert('finding_updates', [
            'finding_id' => $id,
            'user_id'    => Auth::id(),
            'content'    => $content,
        ]);

        Database::query(
            "UPDATE audit_findings SET updated_at = NOW() WHERE id = ?",
            [$id]
        );

        Auth::log('added_update', 'audit_findings', $id, []);

        $_SESSION['flash_success'] = 'Update added successfully.';
        header('Location: /audit-findings/' . $id);
    }

    public function close(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;

        Database::query(
            "UPDATE audit_findings SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$id]
        );

        Database::insert('finding_updates', [
            'finding_id' => $id,
            'user_id'    => Auth::id(),
            'content'    => 'Finding closed.',
        ]);

        Auth::log('closed', 'audit_findings', $id, ['status' => 'closed']);

        $_SESSION['flash_success'] = 'Finding closed successfully.';
        header('Location: /audit-findings/' . $id);
    }

    public function delete(string $id): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;

        Database::query("DELETE FROM audit_findings WHERE id = ?", [$id]);

        Auth::log('deleted', 'audit_findings', $id, []);

        $_SESSION['flash_success'] = 'Finding deleted.';
        header('Location: /audit-findings');
    }
}
