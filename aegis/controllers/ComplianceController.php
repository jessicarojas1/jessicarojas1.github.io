<?php
class ComplianceController {
    public function index(): void {
        Auth::requireAuth();

        $packages = Database::fetchAll(
            "SELECT cp.*, s.name as standard_name, s.code as standard_code, s.category as standard_category,
               COUNT(co.id) FILTER (WHERE co.level = 2) as control_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'compliant') as compliant_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'partial') as partial_count,
               COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant') as non_compliant_count
             FROM compliance_packages cp
             JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, s.name, s.code, s.category
             ORDER BY cp.imported_at ASC"
        );

        require AEGIS_ROOT . '/views/compliance/index.php';
    }

    public function viewPackage(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $package = Database::fetchOne(
            "SELECT cp.*, s.name as standard_name, s.code as standard_code, s.description as standard_desc,
               s.authority, s.url as standard_url
             FROM compliance_packages cp JOIN standards s ON s.id = cp.standard_id
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

        if ($importType === 'json' && !empty($_FILES['package_file']['tmp_name'])) {
            $file = $_FILES['package_file'];
            if ($file['size'] > 5 * 1024 * 1024) { $errors[] = 'File too large (max 5MB).'; }
            elseif (!in_array($file['type'], ['application/json', 'text/plain'])) { $errors[] = 'Only JSON files allowed.'; }
            else {
                $json = file_get_contents($file['tmp_name']);
                $data = json_decode($json, true);
                if (!$data) { $errors[] = 'Invalid JSON file.'; }
                else {
                    $this->processJsonImport($data);
                    header('Location: /compliance?imported=1'); exit;
                }
            }
        }

        if ($errors) {
            $_SESSION['import_errors'] = $errors;
            header('Location: /compliance/import'); exit;
        }

        header('Location: /compliance'); exit;
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
}
