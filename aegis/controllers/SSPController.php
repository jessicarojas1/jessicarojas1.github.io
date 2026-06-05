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

        $format = $_GET['format'] ?? '';

        if ($format === 'pdf') {
            // Print-optimized HTML — browser opens print dialog automatically
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $plan['title']);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: inline; filename="SSP-' . $safeName . '.html"');
            $this->outputSspDocument($plan, $sections, $orgName, 'pdf');
            return;
        }

        if ($format === 'word') {
            // Word-compatible HTML download
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $plan['title']);
            header('Content-Type: application/msword');
            header('Content-Disposition: attachment; filename="SSP-' . $safeName . '.doc"');
            $this->outputSspDocument($plan, $sections, $orgName, 'word');
            return;
        }

        // Default: render standalone interactive document
        require AEGIS_ROOT . '/views/ssp/document.php';
    }

    /**
     * Output a self-contained SSP HTML document suitable for PDF printing or Word import.
     */
    private function outputSspDocument(array $plan, array $sections, string $orgName, string $mode): void
    {
        $isPdf  = $mode === 'pdf';
        $isWord = $mode === 'word';

        $statusLabels = [
            'compliant'      => 'Compliant',
            'partial'        => 'Partial',
            'non_compliant'  => 'Non-Compliant',
            'not_applicable' => 'N/A',
            'default'        => 'Not Assessed',
        ];
        $typeLabels = [
            'major_application'      => 'Major Application',
            'general_support_system' => 'General Support System',
            'minor_application'      => 'Minor Application',
        ];

        $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $printScript = $isPdf ? '
<script>
window.addEventListener("load", function() {
  window.print();
  window.addEventListener("afterprint", function() { window.close(); });
});
</script>' : '';

        $wordMeta = $isWord ? '
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml>' : '';

        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
' . $wordMeta . '
<title>' . $h($plan['title']) . ' — System Security Plan</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #1a1a2e; background: #fff; margin: 40px 60px; font-size: 12pt; line-height: 1.5; }
  h1 { font-size: 22pt; margin: 0 0 8px; }
  h2 { font-size: 16pt; margin: 0 0 6px; color: #1e3a8a; }
  h3 { font-size: 12pt; margin: 0 0 4px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-size: 10pt; }
  th { background: #f0f4ff; font-weight: bold; }
  .cover { text-align: center; padding: 60px 0 40px; border-bottom: 3px solid #1e3a8a; margin-bottom: 40px; page-break-after: always; }
  .cover-org { font-size: 11pt; font-weight: bold; letter-spacing: 0.1em; text-transform: uppercase; color: #1e3a8a; margin-bottom: 12px; }
  .cover-title { font-size: 24pt; font-weight: bold; margin-bottom: 8px; }
  .cover-subtitle { font-size: 14pt; color: #555; margin-bottom: 32px; }
  .section-header { background: #1e3a8a; color: #fff; padding: 12px 16px; margin: 32px 0 16px; page-break-before: always; }
  .section-header h2 { color: #fff; margin: 0; }
  .domain-header { background: #eff6ff; border-left: 4px solid #1e3a8a; padding: 8px 14px; margin: 20px 0 10px; }
  .control-block { border: 1px solid #ddd; border-radius: 4px; margin-bottom: 18px; page-break-inside: avoid; }
  .control-head { background: #f9fafb; padding: 8px 14px; border-bottom: 1px solid #ddd; display: flex; align-items: center; gap: 10px; }
  .control-code { background: #1e3a8a; color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 9pt; font-family: monospace; white-space: nowrap; }
  .control-body { padding: 12px 14px; }
  .field-label { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #666; margin-bottom: 4px; margin-top: 10px; }
  .field-content { font-size: 10pt; white-space: pre-wrap; }
  .field-empty { color: #999; font-style: italic; font-size: 10pt; }
  .status-compliant { color: #065f46; font-weight: bold; }
  .status-partial { color: #92400e; font-weight: bold; }
  .status-non_compliant { color: #991b1b; font-weight: bold; }
  .status-not_applicable { color: #6b7280; }
  @media print {
    body { margin: 0; }
    .section-header { page-break-before: always; }
    .control-block { page-break-inside: avoid; }
  }
</style>
' . $printScript . '
</head>
<body>

<!-- Cover Page -->
<div class="cover">
  <div class="cover-org">' . $h($orgName) . '</div>
  <div class="cover-title">System Security Plan</div>
  <div class="cover-subtitle">' . $h($plan['title']) . '</div>
  <table style="display:inline-table;width:auto;min-width:360px;text-align:left;margin:0 auto;">
';
        if ($plan['system_name']) echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">System Name</td><td style="border:none;padding:5px 8px;">' . $h($plan['system_name']) . '</td></tr>' . "\n";
        echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">System Owner</td><td style="border:none;padding:5px 8px;">' . $h($plan['system_owner'] ?: '—') . '</td></tr>' . "\n";
        if ($plan['system_owner_email']) echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Owner Email</td><td style="border:none;padding:5px 8px;">' . $h($plan['system_owner_email']) . '</td></tr>' . "\n";
        echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Information Owner</td><td style="border:none;padding:5px 8px;">' . $h($plan['information_owner'] ?: '—') . '</td></tr>' . "\n";
        echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Auth. Official</td><td style="border:none;padding:5px 8px;">' . $h($plan['authorizing_official'] ?: '—') . '</td></tr>' . "\n";
        echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">System Type</td><td style="border:none;padding:5px 8px;">' . $h($typeLabels[$plan['system_type']] ?? $plan['system_type']) . '</td></tr>' . "\n";
        echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Impact (C/I/A)</td><td style="border:none;padding:5px 8px;">' . $h(ucfirst($plan['confidentiality_impact']) . ' / ' . ucfirst($plan['integrity_impact']) . ' / ' . ucfirst($plan['availability_impact'])) . '</td></tr>' . "\n";
        if ($plan['authorization_date']) echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Auth. Date</td><td style="border:none;padding:5px 8px;">' . $h(date('F j, Y', strtotime($plan['authorization_date']))) . '</td></tr>' . "\n";
        if ($plan['next_review_date'])   echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Next Review</td><td style="border:none;padding:5px 8px;">' . $h(date('F j, Y', strtotime($plan['next_review_date']))) . '</td></tr>' . "\n";
        echo '    <tr><td style="color:#666;width:160px;font-weight:bold;border:none;padding:5px 8px;">Date Generated</td><td style="border:none;padding:5px 8px;">' . $h(date('F j, Y')) . '</td></tr>' . "\n";
        echo '  </table>
';

        if ($plan['system_description']) {
            echo '<p style="max-width:600px;margin:24px auto 0;font-size:11pt;color:#444;text-align:left;"><strong>System Description:</strong> ' . $h($plan['system_description']) . '</p>' . "\n";
        }

        echo '</div>

<!-- System Details -->
<div style="margin-bottom:40px;">
  <h2 style="border-bottom:2px solid #1e3a8a;padding-bottom:6px;margin-bottom:16px;">System Details</h2>
';
        if ($plan['authorization_boundary']) {
            echo '<p><strong>Authorization Boundary:</strong><br>' . nl2br($h($plan['authorization_boundary'])) . '</p>' . "\n";
        }
        if ($plan['network_architecture']) {
            echo '<p><strong>Network Architecture:</strong><br>' . nl2br($h($plan['network_architecture'])) . '</p>' . "\n";
        }
        if ($plan['data_flow']) {
            echo '<p><strong>Data Flow:</strong><br>' . nl2br($h($plan['data_flow'])) . '</p>' . "\n";
        }
        echo '</div>

';
        // Sections
        foreach ($sections as $sec) {
            $pkg = $sec['package'];
            echo '<div class="section-header">
  <h2>' . $h($pkg['standard_name']) . '</h2>
  <div style="font-size:10pt;opacity:0.85;">' . $h($pkg['name']);
            if ($pkg['version']) echo ' · Version ' . $h($pkg['version']);
            echo '</div>
</div>

';
            if (empty($sec['domains'])) {
                echo '<p style="color:#999;font-style:italic;">No domains found in this package.</p>' . "\n";
            }

            foreach ($sec['domains'] as $domain) {
                echo '<div class="domain-header"><h3>' . $h($domain['code']) . ' — ' . $h($domain['title']) . '</h3></div>' . "\n";

                if (empty($domain['controls'])) {
                    echo '<p style="color:#999;font-style:italic;padding-left:14px;">No controls in this domain.</p>' . "\n";
                }

                foreach ($domain['controls'] as $ctrl) {
                    $statusKey   = $ctrl['status'] ?: 'default';
                    $statusLabel = $statusLabels[$statusKey] ?? 'Not Assessed';
                    echo '<div class="control-block">
  <div class="control-head">
    <span class="control-code">' . $h($ctrl['code']) . '</span>
    <strong style="flex:1;font-size:10pt;">' . $h($ctrl['title']) . '</strong>
    <span class="status-' . $h($statusKey) . '">' . $h($statusLabel) . '</span>
  </div>
  <div class="control-body">';

                    if ($ctrl['description']) {
                        echo "\n    <div class=\"field-label\">Control Description</div><div class=\"field-content\">" . nl2br($h($ctrl['description'])) . '</div>';
                    }

                    echo "\n    <div class=\"field-label\">Implementation Notes (from Compliance)</div>";
                    echo $ctrl['implementation_notes']
                        ? '<div class="field-content">' . nl2br($h($ctrl['implementation_notes'])) . '</div>'
                        : '<div class="field-empty">No implementation notes recorded.</div>';

                    if ($ctrl['assignee_name']) {
                        echo "\n    <div style=\"font-size:9pt;color:#666;margin-top:4px;\">Control Owner: " . $h($ctrl['assignee_name']) . '</div>';
                    }

                    echo "\n    <div class=\"field-label\">SSP Implementation Statement</div>";
                    echo ($ctrl['implementation_statement'] ?? '')
                        ? '<div class="field-content">' . nl2br($h($ctrl['implementation_statement'])) . '</div>'
                        : '<div class="field-empty">Not documented.</div>';

                    echo "\n    <div class=\"field-label\">Objective-Level Responses</div>";
                    echo ($ctrl['objective_responses'] ?? '')
                        ? '<div class="field-content">' . nl2br($h($ctrl['objective_responses'])) . '</div>'
                        : '<div class="field-empty">Not documented.</div>';

                    echo "\n    <div class=\"field-label\">Responsible Roles</div>";
                    echo ($ctrl['responsible_roles'] ?? '')
                        ? '<div class="field-content">' . $h($ctrl['responsible_roles']) . '</div>'
                        : '<div class="field-empty">Not documented.</div>';

                    echo '
  </div>
</div>
';
                }
            }
        }

        echo '
</body>
</html>';
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
        // Force download as octet-stream to prevent browser execution of SVG/HTML
        header('Content-Type: application/octet-stream');
        $safeName = rawurlencode(preg_replace('/[^\w.\-]/', '_', $filename));
        header("Content-Disposition: attachment; filename=\"{$safeName}\"; filename*=UTF-8''{$safeName}");
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

        $allowed = ['pdf','png','jpg','jpeg','gif','vsdx','docx','pptx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) return [null, null];

        // Validate actual MIME type (not just extension)
        $allowedMimes = [
            'application/pdf', 'image/png', 'image/jpeg', 'image/gif',
            'application/vnd.ms-visio.drawing',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        $detectedMime = mime_content_type($file['tmp_name']);
        if (!in_array($detectedMime, $allowedMimes)) return [null, null];

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
