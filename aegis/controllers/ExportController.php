<?php
declare(strict_types=1);

class ExportController {

    private static array $exportTypes = [
        'risks'       => ['label' => 'Risk Register',         'perm' => 'risk.read'],
        'policies'    => ['label' => 'Policies',              'perm' => 'policy.read'],
        'audits'      => ['label' => 'Audits',                'perm' => 'audit.read'],
        'incidents'   => ['label' => 'Incidents',             'perm' => 'incident.read'],
        'vendors'     => ['label' => 'Vendors',               'perm' => 'vendor.read'],
        'controls'    => ['label' => 'Control Implementations','perm' => 'compliance.read'],
        'assets'      => ['label' => 'Asset Inventory',       'perm' => 'read'],
        'activity_log'=> ['label' => 'Activity Log',          'perm' => 'admin'],
    ];

    public function index(): void {
        Auth::requireAuth();
        $pageTitle    = 'Export Data';
        $activeModule = 'export';
        $breadcrumbs  = [['Export', null]];
        $exportTypes  = self::$exportTypes;
        require AEGIS_ROOT . '/views/export/index.php';
    }

    public function download(): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $type   = Security::sanitizeInput($_POST['type'] ?? '');
        $format = in_array($_POST['format'] ?? '', ['csv', 'json']) ? $_POST['format'] : 'csv';

        if (!array_key_exists($type, self::$exportTypes)) {
            http_response_code(400); echo 'Invalid export type.'; return;
        }

        $meta = self::$exportTypes[$type];
        if ($meta['perm'] === 'admin') {
            Auth::requireAdmin();
        } else {
            Auth::requirePermission($meta['perm']);
        }

        $data = self::fetchData($type);
        Auth::log('export_data', $type, 0, ['format' => $format, 'rows' => count($data)]);

        $filename = $type . '-' . date('Y-m-d');
        if ($format === 'json') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
            $out = fopen('php://output', 'w');
            if ($data) {
                fputcsv($out, array_keys($data[0]));
                foreach ($data as $row) fputcsv($out, $row);
            }
            fclose($out);
        }
        exit;
    }

    public function downloadAll(): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $selected = $_POST['types'] ?? [];
        if (!is_array($selected) || empty($selected)) {
            $_SESSION['flash_error'] = 'Select at least one data type to export.';
            header('Location: /export'); return;
        }

        if (!class_exists('ZipArchive')) {
            $_SESSION['flash_error'] = 'ZipArchive extension not available.';
            header('Location: /export'); return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'aegis_export_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $_SESSION['flash_error'] = 'Could not create ZIP.';
            header('Location: /export'); return;
        }

        $exported = [];
        foreach ($selected as $type) {
            $type = Security::sanitizeInput($type);
            if (!array_key_exists($type, self::$exportTypes)) continue;
            $meta = self::$exportTypes[$type];
            if ($meta['perm'] === 'admin' && Auth::role() !== 'admin') continue;
            if ($meta['perm'] !== 'admin' && !Auth::can($meta['perm'])) continue;

            $data = self::fetchData($type);
            if (!$data) {
                $zip->addFromString("{$type}.csv", "No data available.\n");
                $exported[] = $type;
                continue;
            }

            ob_start();
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($data[0]));
            foreach ($data as $row) fputcsv($out, $row);
            fclose($out);
            $csv = ob_get_clean();

            $zip->addFromString("{$type}.csv", "\xEF\xBB\xBF" . $csv);
            $exported[] = $type;
        }

        // Add manifest
        $manifest = "AEGIS GRC — Data Export\n";
        $manifest .= "Exported: " . date('Y-m-d H:i:s') . " UTC\n";
        $manifest .= "Exported by: " . (Auth::user()['name'] ?? '') . "\n\n";
        $manifest .= "Files included:\n";
        foreach ($exported as $t) { $manifest .= "  - {$t}.csv\n"; }
        $zip->addFromString('README.txt', $manifest);
        $zip->close();

        Auth::log('export_data_all', 'export', 0, ['types' => $exported]);

        $filename = 'aegis-export-' . date('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: private, no-cache');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    private static function fetchData(string $type): array {
        return match ($type) {
            'risks' => Database::fetchAll(
                "SELECT r.risk_id, r.title, r.description, rc.name as category,
                        r.likelihood, r.impact, r.inherent_score,
                        r.residual_likelihood, r.residual_impact, r.residual_score,
                        r.treatment_type, r.status, r.target_date,
                        u.name as owner, r.created_at
                 FROM risks r
                 LEFT JOIN risk_categories rc ON rc.id = r.category_id
                 LEFT JOIN users u ON u.id = r.owner_id
                 ORDER BY r.inherent_score DESC"
            ),
            'policies' => Database::fetchAll(
                "SELECT p.title, p.version, p.status, p.effective_date,
                        p.next_review_date, p.review_frequency, p.document_type,
                        u.name as owner, p.created_at
                 FROM policies p LEFT JOIN users u ON u.id = p.owner_id
                 ORDER BY p.title"
            ),
            'audits' => Database::fetchAll(
                "SELECT a.name, a.audit_type, a.status, a.scheduled_date,
                        a.start_date, a.completed_date, a.score,
                        cp.name as framework, u.name as auditor, a.created_at
                 FROM audits a
                 LEFT JOIN compliance_packages cp ON cp.id = a.package_id
                 LEFT JOIN users u ON u.id = a.auditor_id
                 ORDER BY a.scheduled_date DESC"
            ),
            'incidents' => Database::fetchAll(
                "SELECT i.title, i.severity, i.status, i.incident_type,
                        i.description, i.resolution, i.created_at, i.resolved_at,
                        u.name as owner
                 FROM incidents i LEFT JOIN users u ON u.id = i.owner_id
                 ORDER BY i.created_at DESC"
            ),
            'vendors' => Database::fetchAll(
                "SELECT v.name, v.vendor_type, v.risk_tier, v.status,
                        v.contact_name, v.contact_email,
                        v.contract_start, v.contract_end, v.created_at
                 FROM vendors v ORDER BY v.name"
            ),
            'controls' => Database::fetchAll(
                "SELECT co.code, co.title, cp.name as framework,
                        ci.status, ci.due_date, ci.completion_date,
                        ci.notes, u.name as assigned_to
                 FROM control_implementations ci
                 JOIN compliance_objectives co ON co.id = ci.objective_id
                 JOIN compliance_packages cp ON cp.id = co.package_id
                 LEFT JOIN users u ON u.id = ci.assigned_to
                 ORDER BY cp.name, co.sort_order"
            ),
            'assets' => (function() {
                try {
                    return Database::fetchAll(
                        "SELECT a.name, a.asset_type, a.criticality, a.status,
                                a.ip_address, a.owner, a.location, a.created_at
                         FROM assets a ORDER BY a.criticality DESC, a.name"
                    );
                } catch (Throwable) { return []; }
            })(),
            'activity_log' => Database::fetchAll(
                "SELECT al.action, al.entity_type, al.entity_id,
                        u.name as user_name, u.email,
                        al.ip_address, al.created_at
                 FROM activity_log al LEFT JOIN users u ON u.id = al.user_id
                 ORDER BY al.created_at DESC LIMIT 50000"
            ),
            default => [],
        };
    }
}
