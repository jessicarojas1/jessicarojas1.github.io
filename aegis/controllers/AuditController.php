<?php
class AuditController {
    public function index(): void {
        Auth::requireAuth();

        $validStatuses = ['planned', 'in_progress', 'completed', 'overdue', 'cancelled'];
        $status = Security::sanitizeInput($_GET['status'] ?? '');
        if ($status && !in_array($status, $validStatuses, true)) $status = '';
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
        $packages = Database::fetchAll("SELECT cp.id, cp.name, s.code FROM compliance_packages cp LEFT JOIN standards s ON s.id = cp.standard_id WHERE cp.is_active = TRUE ORDER BY cp.name");
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

        // Generate audit number from next sequential ID
        $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM audits");
        $auditNumber = 'AUD-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $auditId = Database::insert('audits', [
            'audit_number'   => $auditNumber,
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

        Auth::log('create_audit', 'audits', $auditId, ['audit_number' => $auditNumber]);
        $_SESSION['flash_success'] = "Audit {$auditNumber} created successfully.";
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

        // Handle evidence file uploads
        if (!empty($_FILES['evidence_file']['name'][0])) {
            $uploadDir  = AEGIS_ROOT . '/uploads/evidence';
            $allowedMime = ['image/jpeg','image/png','image/gif','image/webp','application/pdf',
                            'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain','text/csv'];
            $allowedExt  = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt','csv'];

            $uploadCount = count(array_filter($_FILES['evidence_file']['error'], fn($e) => $e === UPLOAD_ERR_OK));
            if ($uploadCount > 10) {
                $_SESSION['flash_error'] = 'Maximum 10 evidence files per submission.';
                header("Location: /audits/{$auditId}/items/{$itemId}"); exit;
            }

            foreach ($_FILES['evidence_file']['name'] as $i => $origName) {
                if ($_FILES['evidence_file']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmpPath  = $_FILES['evidence_file']['tmp_name'][$i];
                $fileSize = $_FILES['evidence_file']['size'][$i];
                if ($fileSize > 20 * 1024 * 1024) continue; // 20MB limit

                $finfo    = new \finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($tmpPath);
                if (!in_array($detectedMime, $allowedMime, true)) continue;

                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) continue;

                $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . '/' . $storedName)) {
                    Database::insert('evidence_files', [
                        'entity_type'   => 'audit_item',
                        'entity_id'     => (int)$itemId,
                        'original_name' => basename($origName),
                        'stored_name'   => 'evidence/' . $storedName,
                        'mime_type'     => $detectedMime,
                        'file_size'     => $fileSize,
                        'file_hash'     => hash_file('sha256', $uploadDir . '/' . $storedName),
                        'uploaded_by'   => Auth::id(),
                    ]);
                }
            }
        }

        // Return existing evidence files list with response
        $files = Database::fetchAll(
            "SELECT id, original_name, file_size, mime_type FROM evidence_files WHERE entity_type='audit_item' AND entity_id=? ORDER BY created_at DESC",
            [(int)$itemId]
        );

        Auth::log('update_audit_item', 'audit_items', (int)$itemId, ['status' => $status]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'status' => $status, 'files' => $files]);
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

    public function exportPackage(string $id): void {
        Auth::requirePermission('audit.read');
        $id = (int)$id;

        $audit = Database::fetchOne(
            "SELECT a.*, cp.name as package_name, s.name as standard_name,
               u.name as auditor_name
             FROM audits a
             LEFT JOIN compliance_packages cp ON a.package_id = cp.id
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN users u ON a.auditor_id = u.id
             WHERE a.id = ?", [$id]
        );
        if (!$audit) { http_response_code(404); echo 'Audit not found.'; return; }

        $items = Database::fetchAll(
            "SELECT ai.*, co.id as obj_id, co.code, co.title, co.category
             FROM audit_items ai
             JOIN compliance_objectives co ON co.id = ai.objective_id
             WHERE ai.audit_id = ? ORDER BY co.sort_order",
            [$id]
        );

        // Collect evidence: directly on audit + on each control objective
        $objIds = array_column($items, 'obj_id');
        $auditEvidence = Database::fetchAll(
            "SELECT ef.*, u.name as uploaded_by_name
             FROM evidence_files ef LEFT JOIN users u ON u.id = ef.uploaded_by
             WHERE ef.entity_type = 'audit' AND ef.entity_id = ?",
            [$id]
        );
        $controlEvidence = [];
        if ($objIds) {
            $placeholders = implode(',', array_fill(0, count($objIds), '?'));
            $controlEvidence = Database::fetchAll(
                "SELECT ef.*, ef.entity_id as objective_id, u.name as uploaded_by_name
                 FROM evidence_files ef LEFT JOIN users u ON u.id = ef.uploaded_by
                 WHERE ef.entity_type = 'control' AND ef.entity_id IN ({$placeholders})",
                $objIds
            );
        }

        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo 'ZipArchive extension not available on this server.';
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'aegis_audit_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500); echo 'Could not create ZIP archive.'; return;
        }

        $uploadDir = AEGIS_ROOT . '/uploads';
        $addedFiles = [];

        // Add audit-level evidence files
        foreach ($auditEvidence as $ef) {
            $storedName = basename($ef['stored_name']);
            if (!preg_match('/^[0-9a-f]+\.[a-z0-9]+$/i', $storedName)) continue;
            $path = $uploadDir . '/' . $storedName;
            if (!file_exists($path)) continue;
            $zipPath = 'audit-evidence/' . $ef['original_name'];
            $zipPath = $this->uniqueZipPath($zipPath, $addedFiles);
            $zip->addFile($path, $zipPath);
            $addedFiles[] = $zipPath;
        }

        // Add control-level evidence files grouped by control code
        $codeMap = array_column($items, 'code', 'obj_id');
        foreach ($controlEvidence as $ef) {
            $storedName = basename($ef['stored_name']);
            if (!preg_match('/^[0-9a-f]+\.[a-z0-9]+$/i', $storedName)) continue;
            $path = $uploadDir . '/' . $storedName;
            if (!file_exists($path)) continue;
            $code = preg_replace('/[^A-Za-z0-9._-]/', '_', $codeMap[$ef['objective_id']] ?? 'unknown');
            $zipPath = "controls/{$code}/{$ef['original_name']}";
            $zipPath = $this->uniqueZipPath($zipPath, $addedFiles);
            $zip->addFile($path, $zipPath);
            $addedFiles[] = $zipPath;
        }

        // Build findings manifest CSV
        $csv = "Control Code,Title,Category,Status,Finding,Evidence Notes,Risk Level,Remediation\n";
        foreach ($items as $item) {
            $csv .= implode(',', array_map(
                fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"',
                [$item['code'], $item['title'], $item['category'],
                 $item['status'], $item['finding'], $item['evidence'],
                 $item['risk_level'], $item['remediation']]
            )) . "\n";
        }
        $zip->addFromString('findings.csv', $csv);

        // Build audit summary text
        $total     = count($items);
        $compliant = count(array_filter($items, fn($i) => $i['status'] === 'compliant'));
        $score     = $total > 0 ? round(($compliant / $total) * 100, 1) : 0;
        $summary   = "AEGIS GRC — Audit Evidence Package\n";
        $summary  .= str_repeat('=', 60) . "\n";
        $summary  .= "Audit:         {$audit['name']}\n";
        $summary  .= "Framework:     {$audit['package_name']} ({$audit['standard_name']})\n";
        $summary  .= "Auditor:       {$audit['auditor_name']}\n";
        $summary  .= "Scheduled:     {$audit['scheduled_date']}\n";
        $summary  .= "Status:        {$audit['status']}\n";
        $summary  .= "Score:         {$score}% ({$compliant}/{$total} controls compliant)\n";
        $summary  .= "Exported:      " . date('Y-m-d H:i:s') . " UTC\n";
        $summary  .= "Exported by:   " . (Auth::user()['name'] ?? '') . "\n\n";
        $summary  .= "Evidence Files:\n";
        foreach ($addedFiles as $f) { $summary .= "  - {$f}\n"; }
        $zip->addFromString('README.txt', $summary);

        $zip->close();

        Auth::log('export_audit_package', 'audits', $id);

        $filename = 'audit-' . $id . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($audit['name'])) . '-evidence.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: private, no-cache');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    public function itemEvidence(string $auditId, string $itemId): void {
        Auth::requirePermission('audit.read');
        header('Content-Type: application/json');
        $files = Database::fetchAll(
            "SELECT id, original_name, file_size, mime_type, created_at FROM evidence_files WHERE entity_type='audit_item' AND entity_id=? ORDER BY created_at DESC",
            [(int)$itemId]
        );
        echo json_encode($files);
    }

    private function uniqueZipPath(string $path, array $existing): string {
        if (!in_array($path, $existing)) return $path;
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir  = pathinfo($path, PATHINFO_DIRNAME);
        $i = 1;
        do {
            $candidate = ($dir !== '.' ? $dir . '/' : '') . $base . "_{$i}." . $ext;
            $i++;
        } while (in_array($candidate, $existing));
        return $candidate;
    }
}
