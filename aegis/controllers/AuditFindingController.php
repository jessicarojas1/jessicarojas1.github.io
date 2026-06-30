<?php
declare(strict_types=1);

class AuditFindingController {

    private const VALID_SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const VALID_STATUSES   = ['open', 'in_progress', 'resolved', 'risk_accepted', 'closed'];
    private const VALID_SOURCES    = ['external_audit', 'pentest', 'certification', 'assessment', 'regulatory', 'other'];

    public function index(): void {
        Auth::requirePermission('audit.findings');

        $findings = Database::fetchAll(
            "SELECT af.*,
                    u.name  AS owner_name,
                    co.code AS control_code,
                    cp.name AS package_name,
                    a.name  AS linked_audit_name
             FROM audit_findings af
             LEFT JOIN users u  ON u.id  = af.owner_id
             LEFT JOIN compliance_objectives co ON co.id = af.objective_id
             LEFT JOIN compliance_packages   cp ON cp.id = af.package_id
             LEFT JOIN audits a ON a.id = af.audit_id
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
        $audits   = Database::fetchAll("SELECT id, name FROM audits ORDER BY scheduled_date DESC, name");

        $pageTitle    = 'External Audit Findings';
        $activeModule = 'audit_findings';
        $breadcrumbs  = [['External Audit Findings', null]];
        ob_start();
        require AEGIS_ROOT . '/views/audit_findings/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('audit.findings');
        header('Location: /audit-findings');
    }

    public function create(): void {
        Auth::requirePermission('audit.findings');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $title       = Security::sanitizeInput($_POST['title']        ?? '');
        $description = Security::sanitizeInput($_POST['description']  ?? '');
        $severity    = Security::sanitizeInput($_POST['severity']     ?? 'medium');
        $status      = Security::sanitizeInput($_POST['status']       ?? 'open');
        $source      = Security::sanitizeInput($_POST['source']       ?? 'external_audit');
        $auditId     = !empty($_POST['audit_id'])     ? (int)$_POST['audit_id']     : null;
        $auditName   = Security::sanitizeInput($_POST['audit_name']   ?? '');
        $auditorName = Security::sanitizeInput($_POST['auditor_name'] ?? '');
        $deadline    = Security::sanitizeInput($_POST['deadline']     ?? '');
        $ownerId     = !empty($_POST['owner_id'])    ? (int)$_POST['owner_id']    : null;
        $packageId   = !empty($_POST['package_id'])  ? (int)$_POST['package_id']  : null;
        $objectiveId = !empty($_POST['objective_id']) ? (int)$_POST['objective_id'] : null;

        // If linked to a specific audit, pull name from DB
        if ($auditId && !$auditName) {
            $auditRow  = Database::fetchOne("SELECT name FROM audits WHERE id = ?", [$auditId]);
            $auditName = $auditRow['name'] ?? '';
        }

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
            'audit_id'       => $auditId,
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
        Auth::requirePermission('audit.findings');
        $id = (int)$id;

        $finding = Database::fetchOne(
            "SELECT af.*,
                    u.name  AS owner_name,
                    co.code AS control_code,
                    co.title AS control_title,
                    cp.name AS package_name,
                    cb.name AS created_by_name,
                    a.name  AS linked_audit_name,
                    a.id    AS linked_audit_id
             FROM audit_findings af
             LEFT JOIN users u    ON u.id    = af.owner_id
             LEFT JOIN users cb   ON cb.id   = af.created_by
             LEFT JOIN compliance_objectives co ON co.id = af.objective_id
             LEFT JOIN compliance_packages   cp ON cp.id = af.package_id
             LEFT JOIN audits a ON a.id = af.audit_id
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

        // Phase 2: risks linked to this finding (traceability) + risks available to link.
        $linkedRisks = Database::fetchAll(
            "SELECT frl.id AS link_id, frl.relationship_type,
                    r.id, r.risk_id AS risk_code, r.title, r.status, r.inherent_score
             FROM finding_risk_links frl
             JOIN risks r ON r.id = frl.risk_id
             WHERE frl.finding_id = ?
             ORDER BY r.inherent_score DESC NULLS LAST",
            [$id]
        );
        $availableRisks = Database::fetchAll(
            "SELECT id, risk_id AS risk_code, title FROM risks
             WHERE id NOT IN (SELECT risk_id FROM finding_risk_links WHERE finding_id = ?)
             ORDER BY risk_id NULLS LAST, id LIMIT 1000",
            [$id]
        );
        $canLinkRisk = Auth::can('risk.view');

        $pageTitle    = 'Finding: ' . $finding['finding_number'];
        $activeModule = 'audit_findings';
        $breadcrumbs  = [['External Audit Findings', '/audit-findings'], [$finding['finding_number'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/audit_findings/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('audit.findings');

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
        $auditId     = !empty($_POST['audit_id'])   ? (int)$_POST['audit_id']   : null;
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
            'audit_id'       => $auditId,
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
        Auth::requirePermission('audit.findings');

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
        Auth::requirePermission('audit.findings');

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
        Auth::requirePermission('audit.findings');

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

    /** Relationship types between a finding and a risk. */
    private const RISK_REL_TYPES = ['causes', 'indicates', 'mitigated_by', 'related'];

    /** Link a risk to a finding (Phase 2 traceability). */
    public function linkRisk(string $id): void {
        Auth::requirePermission('audit.findings');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id     = (int)$id;
        $riskId = (int)($_POST['risk_id'] ?? 0);
        $rel    = in_array($_POST['relationship_type'] ?? '', self::RISK_REL_TYPES, true) ? $_POST['relationship_type'] : 'related';
        $back   = "/audit-findings/{$id}";

        // Both ends must exist (RLS scopes these to the tenant).
        $finding = Database::fetchOne("SELECT id FROM audit_findings WHERE id = ?", [$id]);
        $risk    = Database::fetchOne("SELECT id FROM risks WHERE id = ?", [$riskId]);
        if (!$finding || !$risk) {
            $_SESSION['flash_error'] = 'Finding or risk not found.';
            header("Location: {$back}"); return;
        }
        $dup = Database::fetchOne("SELECT id FROM finding_risk_links WHERE finding_id = ? AND risk_id = ?", [$id, $riskId]);
        if ($dup) {
            $_SESSION['flash_error'] = 'That risk is already linked to this finding.';
            header("Location: {$back}"); return;
        }
        $linkId = Database::insert('finding_risk_links', [
            'finding_id'        => $id,
            'risk_id'           => $riskId,
            'relationship_type' => $rel,
            'created_by'        => Auth::id(),
        ]);
        Auth::log('link_finding_risk', 'finding_risk_links', $linkId,
            ['finding_id' => $id, 'risk_id' => $riskId, 'relationship_type' => $rel]);
        $_SESSION['flash_success'] = 'Risk linked to finding.';
        header("Location: {$back}");
    }

    /** Remove a finding↔risk link. */
    public function unlinkRisk(string $id): void {
        Auth::requirePermission('audit.findings');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id     = (int)$id;
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId > 0) {
            // Scope the delete to this finding (and RLS to the tenant).
            Database::query("DELETE FROM finding_risk_links WHERE id = ? AND finding_id = ?", [$linkId, $id]);
            Auth::log('unlink_finding_risk', 'finding_risk_links', $linkId, ['finding_id' => $id]);
            $_SESSION['flash_success'] = 'Risk unlinked.';
        }
        header("Location: /audit-findings/{$id}");
    }
}
