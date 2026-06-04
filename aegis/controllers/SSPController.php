<?php
declare(strict_types=1);

class SSPController {

    public function index(): void {
        Auth::requireAuth();
        $plans = Database::fetchAll(
            "SELECT sp.*, u.name AS created_by_name,
                    COUNT(DISTINCT spkg.package_id) AS package_count
             FROM ssp_plans sp
             LEFT JOIN users u ON u.id = sp.created_by
             LEFT JOIN ssp_packages spkg ON spkg.ssp_id = sp.id
             GROUP BY sp.id, u.name
             ORDER BY sp.updated_at DESC"
        );
        $pageTitle    = 'System Security Plans';
        $activeModule = 'ssp';
        $breadcrumbs  = [['SSP', null]];
        ob_start();
        require AEGIS_ROOT . '/views/ssp/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('compliance.write');
        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, cp.version,
                    COALESCE(s.code,'CUSTOM') AS standard_code,
                    COALESCE(s.name,cp.name)  AS standard_name,
                    COUNT(co.id) FILTER (WHERE co.level=2) AS control_count
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, s.code, s.name
             ORDER BY cp.name"
        );
        $assets = Database::fetchAll(
            "SELECT id, name, asset_type FROM assets ORDER BY name"
        );
        $pageTitle    = 'New System Security Plan';
        $activeModule = 'ssp';
        $breadcrumbs  = [['SSP', '/ssp'], ['New Plan', null]];
        ob_start();
        require AEGIS_ROOT . '/views/ssp/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /ssp/create'); return;
        }

        $packageIds = array_filter(array_map('intval', (array)($_POST['package_ids'] ?? [])));
        if (empty($packageIds)) {
            $_SESSION['flash_error'] = 'Select at least one compliance package.';
            header('Location: /ssp/create'); return;
        }

        $validStatuses = ['operational','under_development','major_modification','other'];
        $validTypes    = ['major_application','general_support_system','minor_application'];
        $validImpacts  = ['low','moderate','high'];

        [$naFilename, $naData] = $this->handleFileUpload('network_arch_file', 10);
        [$dfFilename, $dfData] = $this->handleFileUpload('data_flow_file', 10);

        $id = Database::insert('ssp_plans', [
            'title'                  => $title,
            'system_name'            => Security::sanitizeInput($_POST['system_name']           ?? ''),
            'system_description'     => Security::sanitizeInput($_POST['system_description']    ?? ''),
            'system_owner'           => Security::sanitizeInput($_POST['system_owner']          ?? ''),
            'system_owner_email'     => Security::sanitizeInput($_POST['system_owner_email']    ?? ''),
            'information_owner'      => Security::sanitizeInput($_POST['information_owner']     ?? ''),
            'authorizing_official'   => Security::sanitizeInput($_POST['authorizing_official']  ?? ''),
            'authorization_boundary' => Security::sanitizeInput($_POST['authorization_boundary']?? ''),
            'network_architecture'   => Security::sanitizeInput($_POST['network_architecture']  ?? ''),
            'data_flow'              => Security::sanitizeInput($_POST['data_flow']             ?? ''),
            'network_arch_filename'  => $naFilename,
            'network_arch_data'      => $naData,
            'data_flow_filename'     => $dfFilename,
            'data_flow_data'         => $dfData,
            'operational_status'     => in_array($_POST['operational_status'] ?? '', $validStatuses, true) ? $_POST['operational_status'] : 'operational',
            'system_type'            => in_array($_POST['system_type'] ?? '', $validTypes, true) ? $_POST['system_type'] : 'major_application',
            'confidentiality_impact' => in_array($_POST['confidentiality_impact'] ?? '', $validImpacts, true) ? $_POST['confidentiality_impact'] : 'moderate',
            'integrity_impact'       => in_array($_POST['integrity_impact'] ?? '', $validImpacts, true) ? $_POST['integrity_impact'] : 'moderate',
            'availability_impact'    => in_array($_POST['availability_impact'] ?? '', $validImpacts, true) ? $_POST['availability_impact'] : 'moderate',
            'authorization_date'     => $_POST['authorization_date']  ?: null,
            'next_review_date'       => $_POST['next_review_date']    ?: null,
            'created_by'             => Auth::id(),
        ]);

        foreach ($packageIds as $pkgId) {
            try {
                Database::insert('ssp_packages', ['ssp_id' => $id, 'package_id' => $pkgId]);
            } catch (Throwable) {}
        }

        Auth::log('ssp_created', 'ssp_plans', $id, ['title' => $title]);
        $_SESSION['flash_success'] = 'System Security Plan created.';
        header("Location: /ssp/{$id}");
    }

    public function view(int $id): void {
        Auth::requireAuth();
        $plan = $this->getPlan($id);
        if (!$plan) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $linkedPackages = Database::fetchAll(
            "SELECT cp.id, cp.name, cp.version,
                    COALESCE(s.code,'CUSTOM') AS standard_code,
                    COALESCE(s.name,cp.name)  AS standard_name,
                    COUNT(co.id) FILTER (WHERE co.level=2) AS control_count,
                    SUM(CASE WHEN ci.status='compliant' THEN 1 ELSE 0 END) AS compliant_count
             FROM ssp_packages spkg
             JOIN compliance_packages cp ON cp.id = spkg.package_id
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE spkg.ssp_id = ?
             GROUP BY cp.id, s.code, s.name",
            [$id]
        );

        $allPackages = Database::fetchAll(
            "SELECT cp.id, cp.name, COALESCE(s.code,'CUSTOM') AS standard_code
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             WHERE cp.is_active=TRUE
             AND cp.id NOT IN (SELECT package_id FROM ssp_packages WHERE ssp_id=?)
             ORDER BY cp.name", [$id]
        );

        $pageTitle    = Security::h($plan['title']);
        $activeModule = 'ssp';
        $breadcrumbs  = [['SSP', '/ssp'], [$plan['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/ssp/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function generate(int $id): void {
        Auth::requireAuth();
        $plan = $this->getPlan($id);
        if (!$plan) { http_response_code(404); return; }

        // Load all domains + controls for every linked package
        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, cp.version,
                    COALESCE(s.code,'CUSTOM') AS standard_code,
                    COALESCE(s.name,cp.name)  AS standard_name
             FROM ssp_packages spkg
             JOIN compliance_packages cp ON cp.id = spkg.package_id
             LEFT JOIN standards s ON s.id = cp.standard_id
             WHERE spkg.ssp_id = ?
             ORDER BY cp.name", [$id]
        );

        $sections = [];
        foreach ($packages as $pkg) {
            $domains = Database::fetchAll(
                "SELECT co.id, co.code, co.title
                 FROM compliance_objectives co
                 WHERE co.package_id = ? AND co.level = 1
                 ORDER BY co.sort_order, co.code",
                [$pkg['id']]
            );
            foreach ($domains as &$domain) {
                $domain['controls'] = Database::fetchAll(
                    "SELECT co.id, co.code, co.title, co.description,
                            ci.status, ci.implementation_notes, ci.evidence,
                            ci.assigned_to, u.name AS assignee_name,
                            scs.implementation_statement, scs.responsible_roles, scs.objective_responses
                     FROM compliance_objectives co
                     LEFT JOIN control_implementations ci ON ci.objective_id = co.id
                     LEFT JOIN users u ON u.id = ci.assigned_to
                     LEFT JOIN ssp_control_statements scs
                           ON scs.objective_id = co.id AND scs.ssp_id = ?
                     WHERE co.parent_id = ?
                     ORDER BY co.sort_order, co.code",
                    [$id, $domain['id']]
                );
            }
            unset($domain);
            $sections[] = ['package' => $pkg, 'domains' => $domains];
        }

        $org = Database::fetchOne("SELECT value FROM settings WHERE key='org_name'");
        $orgName = $org['value'] ?? 'Organization';

        // Render standalone printable document
        require AEGIS_ROOT . '/views/ssp/document.php';
    }

    public function saveStatement(int $id, int $objectiveId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        // Upsert SSP control statement
        $existing = Database::fetchOne(
            "SELECT id FROM ssp_control_statements WHERE ssp_id=? AND objective_id=?",
            [$id, $objectiveId]
        );
        if ($existing) {
            Database::query(
                "UPDATE ssp_control_statements
                 SET implementation_statement=?, responsible_roles=?, objective_responses=?
                 WHERE ssp_id=? AND objective_id=?",
                [
                    Security::sanitizeInput($_POST['implementation_statement'] ?? ''),
                    Security::sanitizeInput($_POST['responsible_roles'] ?? ''),
                    Security::sanitizeInput($_POST['objective_responses'] ?? ''),
                    $id, $objectiveId,
                ]
            );
        } else {
            Database::insert('ssp_control_statements', [
                'ssp_id'                  => $id,
                'objective_id'            => $objectiveId,
                'implementation_statement'=> Security::sanitizeInput($_POST['implementation_statement'] ?? ''),
                'responsible_roles'       => Security::sanitizeInput($_POST['responsible_roles'] ?? ''),
                'objective_responses'     => Security::sanitizeInput($_POST['objective_responses'] ?? ''),
            ]);
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'csrf' => Security::generateCsrfToken()]);
    }

    public function addPackage(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $pkgId = (int)($_POST['package_id'] ?? 0);
        if ($pkgId) {
            try { Database::insert('ssp_packages', ['ssp_id' => $id, 'package_id' => $pkgId]); } catch (Throwable) {}
        }
        header("Location: /ssp/{$id}");
    }

    public function removePackage(int $id, int $pkgId): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM ssp_packages WHERE ssp_id=? AND package_id=?", [$id, $pkgId]);
        header("Location: /ssp/{$id}");
    }

    public function update(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $validStatuses = ['operational','under_development','major_modification','other'];
        $validTypes    = ['major_application','general_support_system','minor_application'];
        $validImpacts  = ['low','moderate','high'];

        [$naFilename, $naData] = $this->handleFileUpload('network_arch_file', 10);
        [$dfFilename, $dfData] = $this->handleFileUpload('data_flow_file', 10);

        $fileUpdates = '';
        $fileParams  = [];
        if ($naFilename !== null) {
            $fileUpdates .= ', network_arch_filename=?, network_arch_data=?';
            $fileParams[] = $naFilename; $fileParams[] = $naData;
        }
        if ($dfFilename !== null) {
            $fileUpdates .= ', data_flow_filename=?, data_flow_data=?';
            $fileParams[] = $dfFilename; $fileParams[] = $dfData;
        }

        Database::query(
            "UPDATE ssp_plans SET
               title=?, system_name=?, system_description=?, system_owner=?,
               system_owner_email=?, information_owner=?, authorizing_official=?,
               authorization_boundary=?, network_architecture=?, data_flow=?,
               operational_status=?, system_type=?,
               confidentiality_impact=?, integrity_impact=?, availability_impact=?,
               authorization_date=?, next_review_date=?{$fileUpdates}, updated_at=NOW()
             WHERE id=?",
            [
                Security::sanitizeInput($_POST['title']                  ?? ''),
                Security::sanitizeInput($_POST['system_name']            ?? ''),
                Security::sanitizeInput($_POST['system_description']     ?? ''),
                Security::sanitizeInput($_POST['system_owner']           ?? ''),
                Security::sanitizeInput($_POST['system_owner_email']     ?? ''),
                Security::sanitizeInput($_POST['information_owner']      ?? ''),
                Security::sanitizeInput($_POST['authorizing_official']   ?? ''),
                Security::sanitizeInput($_POST['authorization_boundary'] ?? ''),
                Security::sanitizeInput($_POST['network_architecture']   ?? ''),
                Security::sanitizeInput($_POST['data_flow']              ?? ''),
                in_array($_POST['operational_status'] ?? '', $validStatuses, true) ? $_POST['operational_status'] : 'operational',
                in_array($_POST['system_type']        ?? '', $validTypes,    true) ? $_POST['system_type']        : 'major_application',
                in_array($_POST['confidentiality_impact'] ?? '', $validImpacts, true) ? $_POST['confidentiality_impact'] : 'moderate',
                in_array($_POST['integrity_impact']   ?? '', $validImpacts, true) ? $_POST['integrity_impact']   : 'moderate',
                in_array($_POST['availability_impact']?? '', $validImpacts, true) ? $_POST['availability_impact']: 'moderate',
                $_POST['authorization_date'] ?: null,
                $_POST['next_review_date']   ?: null,
                ...$fileParams,
                $id,
            ]
        );
        $_SESSION['flash_success'] = 'Plan updated.';
        header("Location: /ssp/{$id}");
    }

    public function delete(int $id): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("DELETE FROM ssp_plans WHERE id=?", [$id]);
        Auth::log('ssp_deleted', 'ssp_plans', $id, []);
        $_SESSION['flash_success'] = 'SSP deleted.';
        header('Location: /ssp');
    }

    public function downloadNetworkArch(int $id): void {
        Auth::requireAuth();
        $plan = $this->getPlan($id);
        if (!$plan || empty($plan['network_arch_data'])) { http_response_code(404); return; }
        $this->serveFile($plan['network_arch_filename'] ?? 'network_architecture', $plan['network_arch_data']);
    }

    public function downloadDataFlow(int $id): void {
        Auth::requireAuth();
        $plan = $this->getPlan($id);
        if (!$plan || empty($plan['data_flow_data'])) { http_response_code(404); return; }
        $this->serveFile($plan['data_flow_filename'] ?? 'data_flow', $plan['data_flow_data']);
    }

    private function serveFile(string $filename, string $base64Data): void {
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'vsdx' => 'application/vnd.ms-visio.drawing',
            default => 'application/octet-stream',
        };
        $data = base64_decode($base64Data);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    /** Returns [filename, base64data] or [null, null] if no file uploaded. */
    private function handleFileUpload(string $fieldName, int $maxMB = 10): array {
        $file = $_FILES[$fieldName] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
        if ($file['error'] !== UPLOAD_ERR_OK) return [null, null];
        if ($file['size'] > $maxMB * 1024 * 1024) return [null, null];

        $allowed = ['pdf','png','jpg','jpeg','gif','svg','vsdx','docx','pptx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) return [null, null];

        $data = file_get_contents($file['tmp_name']);
        if ($data === false) return [null, null];

        return [basename($file['name']), base64_encode($data)];
    }

    private function getPlan(int $id): ?array {
        return Database::fetchOne(
            "SELECT sp.*, u.name AS created_by_name
             FROM ssp_plans sp LEFT JOIN users u ON u.id = sp.created_by
             WHERE sp.id = ?", [$id]
        ) ?: null;
    }
}
