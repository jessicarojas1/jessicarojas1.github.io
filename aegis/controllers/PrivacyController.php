<?php
declare(strict_types=1);

class PrivacyController {

    public function index(): void {
        Auth::requirePermission('compliance.view');
        $records = Database::fetchAll(
            "SELECT pr.*, u.name AS created_by_name
             FROM privacy_records pr
             LEFT JOIN users u ON u.id = pr.created_by
             ORDER BY pr.created_at DESC"
        );
        $dsr = Database::fetchAll(
            "SELECT dsr.*, u.name AS assigned_name
             FROM data_subject_requests dsr
             LEFT JOIN users u ON u.id = dsr.assigned_to
             WHERE dsr.status IN ('open','in_progress')
             ORDER BY dsr.due_date ASC NULLS LAST, dsr.created_at DESC
             LIMIT 10"
        );
        $stats = [
            'total'    => count($records),
            'active'   => count(array_filter($records, fn($r) => $r['status'] === 'active')),
            'dpia_due' => count(array_filter($records, fn($r) => $r['dpia_required'] && !$r['dpia_completed'])),
        ];
        $pageTitle    = 'Data Privacy';
        $activeModule = 'privacy';
        $breadcrumbs  = [['Data Privacy', null]];
        require AEGIS_ROOT . '/views/privacy/index.php';
    }

    public function createForm(): void {
        Auth::requirePermission('compliance.assess');
        $pageTitle    = 'New Processing Activity';
        $activeModule = 'privacy';
        $breadcrumbs  = [['Data Privacy', '/privacy'], ['New Activity', null]];
        require AEGIS_ROOT . '/views/privacy/create.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.assess');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name = trim(Security::sanitizeInput($_POST['name'] ?? ''));
        if (!$name) {
            $_SESSION['flash_error'] = 'Name is required.';
            header('Location: /privacy/create'); return;
        }

        $validBases = ['consent','legitimate_interest','contract','legal_obligation','vital_interests','public_task'];
        $basis = in_array($_POST['legal_basis'] ?? '', $validBases, true) ? $_POST['legal_basis'] : '';

        $id = Database::insert('privacy_records', [
            'name'                    => $name,
            'description'             => Security::sanitizeInput($_POST['description']             ?? ''),
            'controller_name'         => Security::sanitizeInput($_POST['controller_name']         ?? ''),
            'processor_name'          => Security::sanitizeInput($_POST['processor_name']          ?? ''),
            'purpose'                 => Security::sanitizeInput($_POST['purpose']                 ?? ''),
            'legal_basis'             => $basis,
            'data_subject_categories' => Security::sanitizeInput($_POST['data_subject_categories'] ?? ''),
            'data_categories'         => Security::sanitizeInput($_POST['data_categories']         ?? ''),
            'recipients'              => Security::sanitizeInput($_POST['recipients']              ?? ''),
            'third_country_transfers' => Security::sanitizeInput($_POST['third_country_transfers'] ?? ''),
            'retention_period'        => Security::sanitizeInput($_POST['retention_period']        ?? ''),
            'security_measures'       => Security::sanitizeInput($_POST['security_measures']       ?? ''),
            'dpia_required'           => !empty($_POST['dpia_required']),
            'dpia_completed'          => !empty($_POST['dpia_completed']),
            'dpia_date'               => $_POST['dpia_date'] ?: null,
            'status'                  => 'active',
            'created_by'              => Auth::id(),
        ]);

        Auth::log('privacy_record_created', 'privacy_records', $id, ['name' => $name]);
        $_SESSION['flash_success'] = 'Processing activity recorded.';
        header("Location: /privacy/{$id}");
    }

    public function view(int $id): void {
        Auth::requirePermission('compliance.view');
        $record = Database::fetchOne(
            "SELECT pr.*, u.name AS created_by_name
             FROM privacy_records pr
             LEFT JOIN users u ON u.id = pr.created_by
             WHERE pr.id = ?", [$id]
        );
        if (!$record) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $pageTitle    = Security::h($record['name']);
        $activeModule = 'privacy';
        $breadcrumbs  = [['Data Privacy', '/privacy'], [$record['name'], null]];
        require AEGIS_ROOT . '/views/privacy/view.php';
    }

    public function delete(int $id): void {
        Auth::requirePermission('compliance.assess');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM privacy_records WHERE id=?", [$id]);
        Auth::log('privacy_record_deleted', 'privacy_records', $id, []);
        $_SESSION['flash_success'] = 'Record deleted.';
        header('Location: /privacy');
    }

    // ── Data Subject Requests ────────────────────────────────────────────────

    public function requests(): void {
        Auth::requirePermission('compliance.view');
        $requests = Database::fetchAll(
            "SELECT dsr.*, u.name AS assigned_name
             FROM data_subject_requests dsr
             LEFT JOIN users u ON u.id = dsr.assigned_to
             ORDER BY dsr.created_at DESC"
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = 'Data Subject Requests';
        $activeModule = 'privacy';
        $breadcrumbs  = [['Data Privacy', '/privacy'], ['Subject Requests', null]];
        require AEGIS_ROOT . '/views/privacy/requests.php';
    }

    public function createRequest(): void {
        // Writing a data-subject request is an assess-level action (matches
        // updateRequest), not a read — a view-only user must not create records.
        Auth::requirePermission('compliance.assess');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $validTypes = ['access','erasure','rectification','portability','objection','restriction'];
        $type = in_array($_POST['request_type'] ?? '', $validTypes, true) ? $_POST['request_type'] : 'access';

        Database::insert('data_subject_requests', [
            'request_type' => $type,
            'subject_name' => Security::sanitizeInput($_POST['subject_name']  ?? ''),
            'subject_email'=> Security::sanitizeInput($_POST['subject_email'] ?? ''),
            'description'  => Security::sanitizeInput($_POST['description']   ?? ''),
            'status'       => 'open',
            'due_date'     => $_POST['due_date'] ?: null,
            'assigned_to'  => (int)($_POST['assigned_to'] ?? 0) ?: null,
        ]);

        $_SESSION['flash_success'] = 'Data subject request logged.';
        header('Location: /privacy/requests');
    }

    public function updateRequest(int $id): void {
        Auth::requirePermission('compliance.assess');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $validStatuses = ['open','in_progress','completed','rejected'];
        $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : 'open';
        $completedAt = $status === 'completed' ? 'NOW()' : 'NULL';

        Database::query(
            "UPDATE data_subject_requests
             SET status=?, notes=?, assigned_to=?,
                 completed_at=CASE WHEN ?='completed' THEN NOW() ELSE NULL END,
                 updated_at=NOW()
             WHERE id=?",
            [$status, Security::sanitizeInput($_POST['notes'] ?? ''),
             (int)($_POST['assigned_to'] ?? 0) ?: null, $status, $id]
        );

        header('Location: /privacy/requests');
    }
}
