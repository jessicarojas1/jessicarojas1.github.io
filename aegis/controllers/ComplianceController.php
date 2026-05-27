<?php
class ComplianceController {
    public function index(): void {
        Auth::requireAuth();

        $packages = Database::fetchAll(
            "SELECT cp.*, COALESCE(s.name, cp.name) as standard_name,
               COALESCE(s.code, 'CUSTOM') as standard_code,
               COALESCE(s.category, 'custom') as standard_category,
               COUNT(co.id) FILTER (WHERE co.level = 2) as control_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'compliant') as compliant_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'partial') as partial_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant') as non_compliant_count
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, s.name, s.code, s.category
             ORDER BY cp.imported_at ASC"
        );

        require AEGIS_ROOT . '/views/compliance/index.php';
    }

    // ─── Manual Package Creation ───────────────────────────────────────────────

    public function createForm(): void {
        Auth::requirePermission('compliance.write');
        $standards   = Database::fetchAll("SELECT * FROM standards WHERE is_active = TRUE ORDER BY name");
        $pageTitle   = 'Create Package';
        $activeModule = 'compliance';
        $breadcrumbs = [['Compliance','/compliance'],['Create Package',null]];
        require AEGIS_ROOT . '/views/compliance/create.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name        = Security::sanitizeInput($_POST['name'] ?? '');
        $version     = Security::sanitizeInput($_POST['version'] ?? '1.0');
        $description = Security::sanitizeInput($_POST['description'] ?? '');
        $standardId  = (int)($_POST['standard_id'] ?? 0) ?: null;

        if (!$name) {
            $_SESSION['flash_error'] = 'Package name is required.';
            header('Location: /compliance/create'); return;
        }

        $pkgId = Database::insert('compliance_packages', [
            'standard_id' => $standardId,
            'name'        => $name,
            'version'     => $version ?: '1.0',
            'description' => $description,
            'imported_by' => Auth::id(),
            'imported_at' => date('Y-m-d H:i:s'),
        ]);
        Auth::log('create_package', 'compliance_packages', $pkgId);
        header('Location: /compliance/' . $pkgId . '?created=1');
    }

    public function updatePackage(string $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id = (int)$id;
        $name        = Security::sanitizeInput($_POST['name'] ?? '');
        $version     = Security::sanitizeInput($_POST['version'] ?? '1.0');
        $description = Security::sanitizeInput($_POST['description'] ?? '');
        $standardId  = (int)($_POST['standard_id'] ?? 0) ?: null;
        if (!$name) {
            $_SESSION['flash_error'] = 'Package name is required.';
            header('Location: /compliance/' . $id); return;
        }
        Database::query(
            "UPDATE compliance_packages SET name=?, version=?, description=?, standard_id=? WHERE id=?",
            [$name, $version ?: '1.0', $description, $standardId, $id]
        );
        Auth::log('update_package', 'compliance_packages', $id);
        $_SESSION['flash_success'] = 'Package updated.';
        header('Location: /compliance/' . $id);
    }

    public function deletePackage(string $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id = (int)$id;
        Database::query("UPDATE audits SET package_id = NULL WHERE package_id = ?", [$id]);
        Database::query("DELETE FROM audit_schedules WHERE package_id = ?", [$id]);
        Database::query("DELETE FROM compliance_packages WHERE id = ?", [$id]);
        Auth::log('delete_package', 'compliance_packages', $id);
        $_SESSION['flash_success'] = 'Package deleted.';
        header('Location: /compliance');
    }

    // ─── Domain Management ─────────────────────────────────────────────────────

    public function addDomain(string $pkgId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)$pkgId;
        $code  = Security::sanitizeInput($_POST['code'] ?? '');
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        if (!$code || !$title) {
            $_SESSION['flash_error'] = 'Domain code and title are required.';
            header('Location: /compliance/' . $pkgId); return;
        }
        $sort = (int)(Database::fetchOne(
            "SELECT COALESCE(MAX(sort_order),0)+1 as s FROM compliance_objectives WHERE package_id=? AND level=1",
            [$pkgId]
        )['s'] ?? 0);
        Database::insert('compliance_objectives', [
            'package_id' => $pkgId, 'code' => $code, 'title' => $title,
            'level' => 1, 'sort_order' => $sort,
        ]);
        header('Location: /compliance/' . $pkgId . '#domains');
    }

    public function updateDomain(string $pkgId, string $domainId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)$pkgId; $domainId = (int)$domainId;
        $code  = Security::sanitizeInput($_POST['code'] ?? '');
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        if (!$code || !$title) {
            $_SESSION['flash_error'] = 'Domain code and title are required.';
            header('Location: /compliance/' . $pkgId); return;
        }
        Database::query(
            "UPDATE compliance_objectives SET code=?, title=? WHERE id=? AND package_id=? AND level=1",
            [$code, $title, $domainId, $pkgId]
        );
        header('Location: /compliance/' . $pkgId);
    }

    public function deleteDomain(string $pkgId, string $domainId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)$pkgId; $domainId = (int)$domainId;
        // child controls cascade via ON DELETE CASCADE on parent_id
        Database::query("DELETE FROM compliance_objectives WHERE id=? AND package_id=?", [$domainId, $pkgId]);
        $this->syncCount($pkgId);
        header('Location: /compliance/' . $pkgId);
    }

    // ─── Control Management ────────────────────────────────────────────────────

    public function addControl(string $pkgId, string $domainId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)$pkgId; $domainId = (int)$domainId;
        $code  = Security::sanitizeInput($_POST['code'] ?? '');
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        $desc  = Security::sanitizeInput($_POST['description'] ?? '');
        if (!$code || !$title) {
            $_SESSION['flash_error'] = 'Control code and title are required.';
            header('Location: /compliance/' . $pkgId); return;
        }
        $sort = (int)(Database::fetchOne(
            "SELECT COALESCE(MAX(sort_order),0)+1 as s FROM compliance_objectives WHERE parent_id=?",
            [$domainId]
        )['s'] ?? 0);
        Database::insert('compliance_objectives', [
            'package_id' => $pkgId, 'parent_id' => $domainId,
            'code' => $code, 'title' => $title, 'description' => $desc,
            'level' => 2, 'sort_order' => $sort,
        ]);
        $this->syncCount($pkgId);
        header('Location: /compliance/' . $pkgId);
    }

    public function updateControl(string $pkgId, string $ctrlId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)$pkgId; $ctrlId = (int)$ctrlId;
        $code  = Security::sanitizeInput($_POST['code'] ?? '');
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        $desc  = Security::sanitizeInput($_POST['description'] ?? '');
        if (!$code || !$title) {
            $_SESSION['flash_error'] = 'Control code and title are required.';
            header('Location: /compliance/' . $pkgId); return;
        }
        Database::query(
            "UPDATE compliance_objectives SET code=?, title=?, description=? WHERE id=? AND package_id=?",
            [$code, $title, $desc, $ctrlId, $pkgId]
        );
        header('Location: /compliance/' . $pkgId);
    }

    public function deleteControl(string $pkgId, string $ctrlId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)$pkgId; $ctrlId = (int)$ctrlId;
        Database::query("DELETE FROM compliance_objectives WHERE id=? AND package_id=? AND level=2", [$ctrlId, $pkgId]);
        $this->syncCount($pkgId);
        header('Location: /compliance/' . $pkgId);
    }

    private function syncCount(int $pkgId): void {
        Database::query(
            "UPDATE compliance_packages SET objectives_count = (SELECT COUNT(*) FROM compliance_objectives WHERE package_id=? AND level=2) WHERE id=?",
            [$pkgId, $pkgId]
        );
    }

    public function viewPackage(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $package = Database::fetchOne(
            "SELECT cp.*, COALESCE(s.name, cp.name) as standard_name,
               COALESCE(s.code, 'CUSTOM') as standard_code,
               s.description as standard_desc, s.authority, s.url as standard_url
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             WHERE cp.id = ?", [$id]
        );
        if (!$package) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $domains = Database::fetchAll(
            "SELECT co.*,
               COUNT(child.id) as child_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'compliant') as compliant_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'partial') as partial_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant') as non_compliant_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'not_started' OR ci.id IS NULL) as not_started_count
             FROM compliance_objectives co
             LEFT JOIN compliance_objectives child ON child.parent_id = co.id
             LEFT JOIN control_implementations ci ON ci.objective_id = child.id
             WHERE co.package_id = ? AND co.level = 1
             GROUP BY co.id ORDER BY co.sort_order", [$id]
        );

        $filter   = $_GET['status'] ?? '';
        $search   = Security::sanitizeInput($_GET['q'] ?? '');
        $users    = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        require AEGIS_ROOT . '/views/compliance/package.php';
    }

    public function viewObjective(string $pkgId, string $objId): void {
        Auth::requireAuth();
        $pkgId = (int)$pkgId;
        $objId = (int)$objId;

        $objective = Database::fetchOne(
            "SELECT co.*, p.name as package_name, p.id as package_id,
               parent.code as parent_code, parent.title as parent_title
             FROM compliance_objectives co
             JOIN compliance_packages p ON p.id = co.package_id
             LEFT JOIN compliance_objectives parent ON parent.id = co.parent_id
             WHERE co.id = ? AND co.package_id = ?", [$objId, $pkgId]
        );
        if (!$objective) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $implementation = Database::fetchOne(
            "SELECT ci.*, u.name as assigned_name, r.name as reviewer_name
             FROM control_implementations ci
             LEFT JOIN users u ON u.id = ci.assigned_to
             LEFT JOIN users r ON r.id = ci.reviewed_by
             WHERE ci.objective_id = ?", [$objId]
        );

        $children = Database::fetchAll(
            "SELECT co.*, ci.status as impl_status
             FROM compliance_objectives co
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE co.parent_id = ? ORDER BY co.sort_order", [$objId]
        );

        $mappedPolicies = Database::fetchAll(
            "SELECT p.id, p.title, p.status, p.version
             FROM policies p JOIN policy_mappings pm ON pm.policy_id = p.id
             WHERE pm.objective_id = ?", [$objId]
        );

        $auditFindings = Database::fetchAll(
            "SELECT ai.*, a.name as audit_name, a.completed_date
             FROM audit_items ai JOIN audits a ON a.id = ai.audit_id
             WHERE ai.objective_id = ? ORDER BY a.completed_date DESC LIMIT 5", [$objId]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        require AEGIS_ROOT . '/views/compliance/objective.php';
    }

    public function updateObjective(string $pkgId, string $objId): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); echo 'CSRF error'; return;
        }

        $objId = (int)$objId;
        $status = in_array($_POST['status'] ?? '', ['not_started','compliant','partial','non_compliant','not_applicable'])
            ? $_POST['status'] : 'not_started';
        $notes    = Security::sanitizeInput($_POST['implementation_notes'] ?? '');
        $evidence = Security::sanitizeInput($_POST['evidence'] ?? '');
        $dueDate  = $_POST['due_date'] ? Security::sanitizeInput($_POST['due_date']) : null;
        $assignTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

        $existing = Database::fetchOne("SELECT id FROM control_implementations WHERE objective_id = ?", [$objId]);
        if ($existing) {
            Database::query(
                "UPDATE control_implementations SET status=?, implementation_notes=?, evidence=?, due_date=?, assigned_to=?, last_reviewed=NOW(), reviewed_by=?, updated_at=NOW() WHERE objective_id=?",
                [$status, $notes, $evidence, $dueDate, $assignTo, Auth::id(), $objId]
            );
        } else {
            Database::query(
                "INSERT INTO control_implementations (objective_id, status, implementation_notes, evidence, due_date, assigned_to, last_reviewed, reviewed_by) VALUES (?,?,?,?,?,?,NOW(),?)",
                [$objId, $status, $notes, $evidence, $dueDate, $assignTo, Auth::id()]
            );
        }

        Auth::log('update_control', 'compliance_objectives', $objId, ['status' => $status]);
        header('Location: /compliance/' . (int)$pkgId . '/objective/' . $objId . '?saved=1');
    }

    public function aiSuggestions(string $pkgId): void {
        Auth::requireAuth();
        header('Content-Type: application/json');
        $pkgId = (int)$pkgId;
        $pkg = Database::fetchOne(
            "SELECT cp.*, s.name AS standard_name FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.id = ?",
            [$pkgId]
        );
        if (!$pkg) { http_response_code(404); echo json_encode(['error' => 'Package not found']); return; }
        $suggestions = AIAdvisor::suggestControlGaps($pkgId);
        $narrative   = !empty($suggestions) ? AIAdvisor::generateNarrative($pkgId) : '';
        echo json_encode([
            'ai_enabled'  => !empty($suggestions),
            'suggestions' => $suggestions,
            'narrative'   => $narrative,
        ]);
    }

    public function importForm(): void {
        Auth::requirePermission('compliance.write');
        $standards = Database::fetchAll("SELECT * FROM standards ORDER BY name");
        require AEGIS_ROOT . '/views/compliance/import.php';
    }

    public function import(): void {
        Auth::requirePermission('compliance.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $importType = $_POST['import_type'] ?? 'json';
        $errors = [];

        if (!empty($_FILES['package_file']['tmp_name'])) {
            $file = $_FILES['package_file'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

            if ($file['size'] > 20 * 1024 * 1024) {
                $errors[] = 'File too large (max 20MB).';
            } elseif ($importType === 'json' && in_array($mime, ['application/json', 'text/plain'])) {
                $json = file_get_contents($file['tmp_name']);
                $data = json_decode($json, true);
                if (!$data) { $errors[] = 'Invalid JSON file.'; }
                else { $this->processJsonImport($data); header('Location: /compliance?imported=1'); exit; }
            } elseif ($importType === 'csv' && in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])) {
                $result = $this->processCsvImport($file['tmp_name']);
                if ($result !== true) { $errors[] = $result; }
                else { header('Location: /compliance?imported=1'); exit; }
            } elseif ($importType === 'pdf' && in_array($mime, ['application/pdf'])) {
                $result = $this->processPdfImport($file['tmp_name'], $file['name']);
                if ($result !== true) { $errors[] = $result; }
                else { header('Location: /compliance?imported=1'); exit; }
            } else {
                $errors[] = 'File type does not match selected import format.';
            }
        } else {
            $errors[] = 'No file uploaded.';
        }

        if ($errors) {
            $_SESSION['import_errors'] = $errors;
            header('Location: /compliance/import'); exit;
        }

        header('Location: /compliance'); exit;
    }

    public function downloadCsvTemplate(): void {
        Auth::requirePermission('compliance.write');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="compliance_package_template.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['package_name', 'package_version', 'package_description', 'domain_code', 'domain_title', 'control_code', 'control_title', 'control_description']);
        // Example rows
        fputcsv($out, ['My Compliance Framework', '1.0', 'Internal security controls', 'D1', 'Access Control', 'D1.1', 'User Access Management', 'Ensure all user accounts are reviewed quarterly']);
        fputcsv($out, ['My Compliance Framework', '1.0', 'Internal security controls', 'D1', 'Access Control', 'D1.2', 'Privileged Access', 'Privileged access must require MFA and be logged']);
        fputcsv($out, ['My Compliance Framework', '1.0', 'Internal security controls', 'D2', 'Risk Management', 'D2.1', 'Risk Assessment', 'Conduct annual risk assessments for all critical systems']);
        fclose($out);
        exit;
    }

    public function clearAll(): void {
        Auth::requirePermission('admin');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        // Nullify package_id on audits/schedules (no CASCADE), then cascade-delete packages
        Database::query("UPDATE audits SET package_id = NULL WHERE package_id IS NOT NULL");
        Database::query("DELETE FROM audit_schedules WHERE package_id IS NOT NULL");
        Database::query("DELETE FROM compliance_packages");
        header('Location: /compliance?cleared=1'); exit;
    }

    private function processCsvImport(string $tmpPath): bool|string {
        $handle = fopen($tmpPath, 'r');
        if (!$handle) return 'Could not read CSV file.';

        $headers = fgetcsv($handle);
        if (!$headers) return 'CSV file is empty.';
        $headers = array_map('trim', $headers);

        $required = ['package_name', 'domain_code', 'domain_title', 'control_code', 'control_title'];
        foreach ($required as $r) {
            if (!in_array($r, $headers)) return "CSV missing required column: $r";
        }

        $idx = array_flip($headers);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($required)) continue;
            $rows[] = $row;
        }
        fclose($handle);
        if (!$rows) return 'CSV has no data rows.';

        // Use first row for package-level info
        $first = $rows[0];
        $pkgName    = trim($first[$idx['package_name']] ?? 'Imported Package');
        $pkgVersion = trim($first[$idx['package_version'] ?? -1] ?? '1.0');
        $pkgDesc    = trim($first[$idx['package_description'] ?? -1] ?? '');
        $standardId = (int)($_POST['standard_id'] ?? 0) ?: null;

        $pkgId = Database::insert('compliance_packages', [
            'standard_id'  => $standardId,
            'name'         => $pkgName,
            'version'      => $pkgVersion,
            'description'  => $pkgDesc,
            'imported_by'  => Auth::id(),
            'imported_at'  => date('Y-m-d H:i:s'),
        ]);

        // Group by domain
        $domains = [];
        foreach ($rows as $row) {
            $dc = trim($row[$idx['domain_code']]);
            $dt = trim($row[$idx['domain_title']]);
            $cc = trim($row[$idx['control_code']]);
            $ct = trim($row[$idx['control_title']]);
            $cd = trim($row[$idx['control_description'] ?? -1] ?? '');
            if (!$dc || !$cc) continue;
            if (!isset($domains[$dc])) $domains[$dc] = ['title' => $dt, 'controls' => []];
            $domains[$dc]['controls'][] = ['code' => $cc, 'title' => $ct, 'description' => $cd];
        }

        $domainSort = 0;
        foreach ($domains as $dCode => $domain) {
            $domainId = Database::insert('compliance_objectives', [
                'package_id' => $pkgId,
                'code'       => $dCode,
                'title'      => $domain['title'],
                'level'      => 1,
                'sort_order' => $domainSort++,
            ]);
            $ctrlSort = 0;
            foreach ($domain['controls'] as $ctrl) {
                Database::insert('compliance_objectives', [
                    'package_id' => $pkgId,
                    'parent_id'  => $domainId,
                    'code'       => $ctrl['code'],
                    'title'      => $ctrl['title'],
                    'description'=> $ctrl['description'],
                    'level'      => 2,
                    'sort_order' => $ctrlSort++,
                ]);
            }
        }
        return true;
    }

    private function processPdfImport(string $tmpPath, string $originalName): bool|string {
        if (!shell_exec('which pdftotext')) {
            return 'PDF import requires poppler-utils on the server. Please use CSV or JSON import instead.';
        }

        $textFile = sys_get_temp_dir() . '/' . uniqid('pdf_') . '.txt';
        $escaped  = escapeshellarg($tmpPath);
        $escapedOut = escapeshellarg($textFile);
        exec("pdftotext -layout $escaped $escapedOut 2>/dev/null", $out, $code);
        if ($code !== 0 || !file_exists($textFile)) return 'Could not extract text from PDF.';

        $text = file_get_contents($textFile);
        unlink($textFile);
        if (!$text) return 'PDF appears to be empty or image-only (no selectable text).';

        // Derive package name from filename
        $pkgName = preg_replace('/\.(pdf)$/i', '', $originalName);
        $pkgName = preg_replace('/[-_]+/', ' ', $pkgName);
        $pkgName = trim(ucwords($pkgName));
        $standardId = (int)($_POST['standard_id'] ?? 0) ?: null;

        $pkgId = Database::insert('compliance_packages', [
            'standard_id'  => $standardId,
            'name'         => $pkgName,
            'version'      => '1.0',
            'description'  => 'Imported from PDF: ' . $originalName,
            'imported_by'  => Auth::id(),
            'imported_at'  => date('Y-m-d H:i:s'),
        ]);

        // Parse controls — matches patterns like: A.5.1, CC6.1, 5.1.1, PR.AC-1, D1.1
        $lines   = explode("\n", $text);
        $domains = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line || strlen($line) < 4) continue;

            // Domain-level heading: short numbered section with no lowercase body (e.g. "5.1 Access Control")
            if (preg_match('/^([A-Z]{1,3}[\.\-]?\d{1,2}(?:\.\d{1,2})?)\s{2,}(.{4,80})$/', $line, $m) ||
                preg_match('/^(\d{1,2}(?:\.\d{1,2})?)\s{2,}([A-Z][A-Za-z\s&\/\-]{4,60})$/', $line, $m)) {
                $code  = trim($m[1]);
                $title = trim($m[2]);
                // Decide if it looks like a domain (shorter code) or control
                $depth = substr_count($code, '.') + substr_count($code, '-');
                if ($depth <= 1) {
                    $current = $code;
                    if (!isset($domains[$code])) $domains[$code] = ['title' => $title, 'controls' => []];
                } else {
                    if (!$current) { $current = 'GEN'; $domains['GEN'] = ['title' => 'General', 'controls' => []]; }
                    $domains[$current]['controls'][] = ['code' => $code, 'title' => $title, 'description' => ''];
                }
            }
        }

        // If no structured controls found, create one placeholder domain
        if (!$domains) {
            $domains['GEN'] = ['title' => 'General', 'controls' => [
                ['code' => 'GEN.1', 'title' => 'Review PDF and populate controls manually', 'description' => 'The PDF could not be auto-parsed. Please edit this package to add controls.']
            ]];
        }

        $domainSort = 0;
        foreach ($domains as $dCode => $domain) {
            if (!$domain['controls']) continue; // skip domains with no controls
            $domainId = Database::insert('compliance_objectives', [
                'package_id' => $pkgId,
                'code'       => $dCode,
                'title'      => $domain['title'],
                'level'      => 1,
                'sort_order' => $domainSort++,
            ]);
            $ctrlSort = 0;
            foreach ($domain['controls'] as $ctrl) {
                Database::insert('compliance_objectives', [
                    'package_id' => $pkgId,
                    'parent_id'  => $domainId,
                    'code'       => $ctrl['code'],
                    'title'      => $ctrl['title'],
                    'description'=> $ctrl['description'],
                    'level'      => 2,
                    'sort_order' => $ctrlSort++,
                ]);
            }
        }
        return true;
    }

    public function scorecard(string $pkgId): void {
        Auth::requireAuth();
        $pkgId = (int)$pkgId;
        $package = Database::fetchOne("SELECT cp.*, s.name as standard_name, s.code as standard_code FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id WHERE cp.id = ?", [$pkgId]);
        if (!$package) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        // All controls (level 2) grouped by domain (level 1)
        $domains = Database::fetchAll("SELECT * FROM compliance_objectives WHERE package_id = ? AND level = 1 ORDER BY sort_order", [$pkgId]);
        $controls = Database::fetchAll(
            "SELECT co.*, ci.status, ci.due_date, ci.completion_date, ci.notes,
                    u.name as assigned_name
             FROM compliance_objectives co
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             LEFT JOIN users u ON u.id = ci.assigned_to
             WHERE co.package_id = ? AND co.level = 2
             ORDER BY co.sort_order", [$pkgId]
        );
        $byDomain = [];
        foreach ($controls as $c) {
            $byDomain[(int)($c['parent_id'] ?? 0)][] = $c;
        }

        $total = count($controls);
        $compliant = count(array_filter($controls, fn($c) => $c['status'] === 'compliant'));
        $nonCompliant = count(array_filter($controls, fn($c) => $c['status'] === 'non_compliant'));
        $partial = count(array_filter($controls, fn($c) => $c['status'] === 'partial'));
        $notApplicable = count(array_filter($controls, fn($c) => $c['status'] === 'not_applicable'));
        $notAssessed = $total - $compliant - $nonCompliant - $partial - $notApplicable;
        $pct = ($total - $notApplicable) > 0 ? round($compliant / ($total - $notApplicable) * 100) : 0;

        $pageTitle = $package['name'] . ' — Scorecard';
        $activeModule = 'compliance';
        $breadcrumbs = [['Compliance','/compliance'],[$package['name'],'/compliance/'.$pkgId],['Scorecard',null]];
        require AEGIS_ROOT . '/views/compliance/scorecard.php';
    }

    private function processJsonImport(array $data): void {
        $standardId = (int)($_POST['standard_id'] ?? 0);
        $name = Security::sanitizeInput($data['name'] ?? 'Imported Package');
        $version = Security::sanitizeInput($data['version'] ?? '1.0');
        $description = Security::sanitizeInput($data['description'] ?? '');

        // If the JSON carries a standard definition, upsert it
        if (!$standardId && !empty($data['standard']['code'])) {
            $std = $data['standard'];
            $existing = Database::fetchOne("SELECT id FROM standards WHERE code = ?", [$std['code']]);
            if ($existing) {
                $standardId = $existing['id'];
            } else {
                Database::query(
                    "INSERT INTO standards (code, name, authority, category, is_builtin, is_active)
                     VALUES (?,?,?,?,FALSE,TRUE)",
                    [$std['code'], $std['name'] ?? $std['code'], $std['authority'] ?? '', $std['category'] ?? '']
                );
                $standardId = Database::fetchOne("SELECT id FROM standards WHERE code = ?", [$std['code']])['id'];
            }
        }

        $pkgId = Database::insert('compliance_packages', [
            'standard_id'  => $standardId ?: null,
            'name'         => $name,
            'version'      => $version,
            'description'  => $description,
            'imported_by'  => Auth::id(),
            'imported_at'  => date('Y-m-d H:i:s'),
        ]);

        $controlCount = 0;

        // 2-level (domains → controls)
        if (!empty($data['domains'])) {
            $domainSort = 0;
            foreach ($data['domains'] as $domain) {
                Database::query(
                    "INSERT INTO compliance_objectives (package_id, code, title, description, level, sort_order)
                     VALUES (?,?,?,?,1,?)",
                    [$pkgId, $domain['code'] ?? '', $domain['title'] ?? '', $domain['description'] ?? '', $domainSort++]
                );
                $domainRow = Database::fetchOne(
                    "SELECT id FROM compliance_objectives WHERE package_id = ? AND code = ? AND level = 1 ORDER BY id DESC LIMIT 1",
                    [$pkgId, $domain['code'] ?? '']
                );
                $domainId   = $domainRow['id'];
                $ctrlSort   = 0;
                foreach ($domain['controls'] ?? [] as $ctrl) {
                    Database::query(
                        "INSERT INTO compliance_objectives (package_id, parent_id, code, title, description, level, sort_order)
                         VALUES (?,?,?,?,?,2,?)",
                        [$pkgId, $domainId, $ctrl['code'] ?? '', $ctrl['title'] ?? '', $ctrl['description'] ?? '', $ctrlSort++]
                    );
                    $controlCount++;
                }
            }
        } else {
            // Flat 1-level import (legacy format)
            $sort = 0;
            foreach ($data['objectives'] ?? $data['controls'] ?? [] as $item) {
                Database::query(
                    "INSERT INTO compliance_objectives (package_id, code, title, description, category, level, sort_order) VALUES (?,?,?,?,?,1,?)",
                    [$pkgId, $item['code'] ?? '', $item['title'] ?? $item['name'] ?? '', $item['description'] ?? '', $item['category'] ?? '', $sort++]
                );
                $controlCount++;
            }
        }

        $total = Database::fetchOne("SELECT COUNT(*) as c FROM compliance_objectives WHERE package_id = ?", [$pkgId])['c'];
        Database::query("UPDATE compliance_packages SET objectives_count = ? WHERE id = ?", [$total, $pkgId]);
        Auth::log('import_package', 'compliance_packages', $pkgId);
    }

    public function testControl(string $objId): void {
        Auth::requirePermission('audit.write');
        $objId = (int)$objId;
        $obj = Database::fetchOne(
            "SELECT co.*, cp.id as package_id, cp.name as package_name, s.name as standard_name
             FROM compliance_objectives co
             JOIN compliance_packages cp ON cp.id = co.package_id
             JOIN standards s ON s.id = cp.standard_id
             WHERE co.id=?", [$objId]
        );
        if (!$obj) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $history = Database::fetchAll(
            "SELECT ct.*, u.name as tester_name FROM control_tests ct
             LEFT JOIN users u ON u.id = ct.tester_id
             WHERE ct.objective_id=? ORDER BY ct.test_date DESC LIMIT 10", [$objId]
        );
        $pageTitle    = 'Test Control: ' . $obj['code'];
        $activeModule = 'compliance';
        $breadcrumbs  = [
            ['Compliance', '/compliance'],
            [$obj['package_name'], "/compliance/{$obj['package_id']}"],
            ['Test Control', null],
        ];
        ob_start();
        require AEGIS_ROOT . '/views/compliance/test_control.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveTest(string $objId): void {
        Auth::requirePermission('audit.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $objId = (int)$objId;
        $obj = Database::fetchOne("SELECT id, package_id FROM compliance_objectives WHERE id=?", [$objId]);
        if (!$obj) { http_response_code(404); return; }
        $validResults = ['pass','fail','partial','not_tested'];
        $result = in_array($_POST['result'] ?? '', $validResults, true) ? $_POST['result'] : 'not_tested';
        $effectiveness = isset($_POST['effectiveness']) ? max(0, min(100, (int)$_POST['effectiveness'])) : null;
        $testDate = Security::sanitizeInput($_POST['test_date'] ?? date('Y-m-d'));
        $nextDate = Security::sanitizeInput($_POST['next_test_date'] ?? '');
        $id = Database::insert('control_tests', [
            'objective_id'   => $objId,
            'package_id'     => $obj['package_id'],
            'test_date'      => $testDate,
            'tester_id'      => Auth::id(),
            'result'         => $result,
            'effectiveness'  => $effectiveness,
            'method'         => Security::sanitizeInput($_POST['method'] ?? ''),
            'findings'       => Security::sanitizeInput($_POST['findings'] ?? ''),
            'evidence_refs'  => Security::sanitizeInput($_POST['evidence_refs'] ?? ''),
            'next_test_date' => $nextDate ?: null,
        ]);
        // Update the effectiveness on the control_implementation too
        Database::query(
            "UPDATE control_implementations SET notes = COALESCE(notes,'') || '' WHERE objective_id=?",
            [$objId]
        );
        Auth::log('control_tested', 'control_tests', $id, ['result'=>$result,'effectiveness'=>$effectiveness]);
        $_SESSION['flash_success'] = 'Test result recorded.';
        header("Location: /compliance/control/{$objId}/test");
    }

    public function gapAnalysis(): void {
        Auth::requireAuth();
        // Per-package compliance stats
        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, s.name as standard_name, s.code as standard_code,
                    COUNT(co.id) FILTER (WHERE co.level=2) as total_controls,
                    COUNT(ci.id) FILTER (WHERE ci.status='implemented' AND co.level=2) as implemented,
                    COUNT(ci.id) FILTER (WHERE ci.status='in_progress' AND co.level=2) as in_progress,
                    COUNT(co.id) FILTER (WHERE (ci.status IS NULL OR ci.status='not_started') AND co.level=2) as not_started,
                    COUNT(co.id) FILTER (WHERE ci.due_date < CURRENT_DATE AND ci.status != 'implemented' AND co.level=2) as overdue
             FROM compliance_packages cp
             JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, cp.name, s.name, s.code
             ORDER BY s.code, cp.name"
        );
        // Top gaps — controls not started or overdue across all packages
        $gaps = Database::fetchAll(
            "SELECT co.code, co.title, co.description, cp.name as package_name, s.code as standard_code,
                    ci.status, ci.due_date, u.name as assigned_name
             FROM compliance_objectives co
             JOIN compliance_packages cp ON cp.id = co.package_id
             JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             LEFT JOIN users u ON u.id = ci.assigned_to
             WHERE co.level = 2 AND cp.is_active = TRUE
               AND (ci.status IS NULL OR ci.status IN ('not_started') OR (ci.due_date < CURRENT_DATE AND ci.status != 'implemented'))
             ORDER BY ci.due_date ASC NULLS LAST, co.code ASC
             LIMIT 100"
        );
        // Cross-framework: controls that appear in multiple packages with gaps
        $crossFramework = Database::fetchAll(
            "SELECT co.title, COUNT(DISTINCT cp.id) as framework_count,
                    STRING_AGG(DISTINCT s.code, ', ' ORDER BY s.code) as frameworks,
                    COUNT(CASE WHEN ci.status='implemented' THEN 1 END) as implemented_in
             FROM compliance_objectives co
             JOIN compliance_packages cp ON cp.id = co.package_id
             JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE co.level=2 AND cp.is_active=TRUE
             GROUP BY co.title HAVING COUNT(DISTINCT cp.id) > 1
             ORDER BY framework_count DESC, co.title
             LIMIT 20"
        );
        $pageTitle    = 'Compliance Gap Analysis';
        $activeModule = 'compliance_gap';
        $breadcrumbs  = [['Compliance', '/compliance'], ['Gap Analysis', null]];
        ob_start();
        require AEGIS_ROOT . '/views/compliance/gap_analysis.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function testingDashboard(): void {
        Auth::requireAuth();
        // Controls by result
        $summary = Database::fetchAll(
            "SELECT result, COUNT(*) as cnt FROM control_tests
             WHERE id IN (SELECT MAX(id) FROM control_tests GROUP BY objective_id)
             GROUP BY result"
        );
        // Recently tested
        $recent = Database::fetchAll(
            "SELECT ct.*, co.code, co.title, cp.name as package_name, u.name as tester_name
             FROM control_tests ct
             JOIN compliance_objectives co ON co.id = ct.objective_id
             JOIN compliance_packages cp ON cp.id = ct.package_id
             LEFT JOIN users u ON u.id = ct.tester_id
             ORDER BY ct.test_date DESC LIMIT 20"
        );
        // Overdue next tests
        $overdue = Database::fetchAll(
            "SELECT ct.*, co.code, co.title, cp.name as package_name
             FROM control_tests ct
             JOIN compliance_objectives co ON co.id = ct.objective_id
             JOIN compliance_packages cp ON cp.id = ct.package_id
             WHERE ct.next_test_date < CURRENT_DATE
               AND ct.id IN (SELECT MAX(id) FROM control_tests GROUP BY objective_id)
             ORDER BY ct.next_test_date ASC"
        );
        $pageTitle    = 'Control Testing Dashboard';
        $activeModule = 'control_testing';
        $breadcrumbs  = [['Compliance', '/compliance'], ['Control Testing', null]];
        ob_start();
        require AEGIS_ROOT . '/views/compliance/testing_dashboard.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }
}
