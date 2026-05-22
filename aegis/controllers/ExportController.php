<?php
class ExportController {
    private static array $modules = ['risks', 'standards', 'audits', 'policies', 'controls', 'evidence'];

    public function index(): void {
        Auth::requirePermission('compliance.read');
        require AEGIS_ROOT . '/views/export/index.php';
    }

    public function downloadAll(): void {
        Auth::requirePermission('compliance.read');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'aegis_full_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        foreach (self::$modules as $module) {
            $rows    = $this->getData($module);
            $headers = $rows ? array_keys($rows[0]) : [];
            ob_start();
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) fputcsv($out, array_values($row));
            fclose($out);
            $zip->addFromString('aegis_' . $module . '.csv', ob_get_clean());
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="aegis_full_export_' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    public function download(): void {
        Auth::requirePermission('compliance.read');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $module = Security::sanitizeInput($_POST['module'] ?? '');
        $format = in_array($_POST['format'] ?? '', ['csv', 'xlsx']) ? $_POST['format'] : 'csv';

        if (!in_array($module, self::$modules)) {
            http_response_code(400); return;
        }

        $rows    = $this->getData($module);
        $headers = $rows ? array_keys($rows[0]) : [];
        $fname   = 'aegis_' . $module . '_' . date('Ymd_His');

        if ($format === 'xlsx') {
            $this->sendXlsx($fname, $headers, $rows);
        } else {
            $this->sendCsv($fname, $headers, $rows);
        }
    }

    private function getData(string $module): array {
        return match($module) {
            'risks' => Database::fetchAll(
                "SELECT r.risk_id, r.title, r.description,
                        rc.name AS category, r.likelihood, r.impact,
                        r.inherent_score,
                        r.residual_likelihood, r.residual_impact, r.residual_score,
                        r.status, r.treatment_type, r.treatment_description,
                        u.name AS owner, r.review_date, r.identified_date,
                        r.created_at
                 FROM risks r
                 LEFT JOIN risk_categories rc ON r.category_id = rc.id
                 LEFT JOIN users u ON r.owner_id = u.id
                 ORDER BY r.inherent_score DESC, r.created_at DESC"
            ),
            'standards' => Database::fetchAll(
                "SELECT s.code, s.name, s.version, s.category, s.authority,
                        cp.name AS package_name,
                        COUNT(co.id) AS total_objectives,
                        COUNT(ci.id) FILTER (WHERE ci.status = 'compliant') AS compliant,
                        COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant') AS non_compliant,
                        COUNT(ci.id) FILTER (WHERE ci.status = 'partial') AS partial,
                        COUNT(ci.id) FILTER (WHERE ci.status = 'not_started') AS not_started
                 FROM standards s
                 LEFT JOIN compliance_packages cp ON cp.standard_id = s.id
                 LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
                 LEFT JOIN control_implementations ci ON ci.objective_id = co.id
                 WHERE s.is_active = TRUE
                 GROUP BY s.id, s.code, s.name, s.version, s.category, s.authority, cp.name
                 ORDER BY s.code"
            ),
            'audits' => Database::fetchAll(
                "SELECT a.name, a.audit_type, a.status, a.frequency,
                        cp.name AS package,
                        a.scheduled_date, a.start_date, a.completed_date,
                        u.name AS auditor,
                        a.score,
                        COUNT(ai.id) AS total_items,
                        COUNT(ai.id) FILTER (WHERE ai.status = 'compliant') AS compliant_items,
                        COUNT(ai.id) FILTER (WHERE ai.status = 'finding') AS findings,
                        a.notes, a.created_at
                 FROM audits a
                 LEFT JOIN compliance_packages cp ON a.package_id = cp.id
                 LEFT JOIN users u ON a.auditor_id = u.id
                 LEFT JOIN audit_items ai ON ai.audit_id = a.id
                 GROUP BY a.id, cp.name, u.name
                 ORDER BY a.created_at DESC"
            ),
            'policies' => Database::fetchAll(
                "SELECT p.policy_number, p.title, p.category, p.version, p.status,
                        u_owner.name AS owner, u_approver.name AS approver,
                        p.review_frequency, p.next_review_date,
                        p.approved_at, p.published_at,
                        COUNT(pm.id) AS mapped_controls,
                        p.created_at
                 FROM policies p
                 LEFT JOIN users u_owner    ON p.owner_id    = u_owner.id
                 LEFT JOIN users u_approver ON p.approver_id = u_approver.id
                 LEFT JOIN policy_mappings pm ON pm.policy_id = p.id
                 GROUP BY p.id, u_owner.name, u_approver.name
                 ORDER BY p.status, p.title"
            ),
            'controls' => Database::fetchAll(
                "SELECT cp.name AS package, co.code, co.title,
                        co.category, co.level,
                        COALESCE(ci.status, 'not_started') AS status,
                        ci.implementation_notes,
                        ci.evidence,
                        u.name AS assigned_to, ci.due_date, ci.last_reviewed
                 FROM compliance_objectives co
                 JOIN compliance_packages cp ON co.package_id = cp.id
                 LEFT JOIN control_implementations ci ON ci.objective_id = co.id
                 LEFT JOIN users u ON ci.assigned_to = u.id
                 WHERE co.level = 2
                 ORDER BY cp.name, co.sort_order"
            ),
            'evidence' => Database::fetchAll(
                "SELECT cp.name AS package, co.code, co.title AS control,
                        COALESCE(ci.status, 'not_started') AS status,
                        ci.implementation_notes,
                        ci.evidence,
                        ci.last_reviewed,
                        u.name AS reviewed_by
                 FROM compliance_objectives co
                 JOIN compliance_packages cp ON co.package_id = cp.id
                 LEFT JOIN control_implementations ci ON ci.objective_id = co.id
                 LEFT JOIN users u ON ci.reviewed_by = u.id
                 WHERE co.level = 2 AND (ci.evidence IS NOT NULL AND ci.evidence != '')
                 ORDER BY cp.name, co.sort_order"
            ),
            default => [],
        };
    }

    private function sendCsv(string $fname, array $headers, array $rows): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
        header('Cache-Control: no-store');

        $sanitize = static function(mixed $v): string {
            $s = (string)($v ?? '');
            return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
        };

        $out = fopen('php://output', 'w');
        fputcsv($out, array_map($sanitize, $headers));
        foreach ($rows as $row) {
            fputcsv($out, array_map($sanitize, array_values($row)));
        }
        fclose($out);
        exit;
    }

    private function sendXlsx(string $fname, array $headers, array $rows): void {
        // Pure PHP XLSX writer — no external library needed
        $strings = [];
        $strIdx  = [];

        $addStr = function(string $s) use (&$strings, &$strIdx): int {
            if (!isset($strIdx[$s])) {
                $strIdx[$s] = count($strings);
                $strings[]  = $s;
            }
            return $strIdx[$s];
        };

        // Build worksheet XML
        $wsRows = '';
        $r = 1;

        // Header row
        $wsRows .= '<row r="' . $r . '">';
        foreach ($headers as $ci => $h) {
            $col = $this->xlsxCol($ci + 1) . $r;
            $si  = $addStr((string)$h);
            $wsRows .= '<c r="' . $col . '" t="s"><v>' . $si . '</v></c>';
        }
        $wsRows .= '</row>';
        $r++;

        // Data rows
        foreach ($rows as $row) {
            $wsRows .= '<row r="' . $r . '">';
            $ci = 0;
            foreach (array_values($row) as $val) {
                $col  = $this->xlsxCol($ci + 1) . $r;
                $sval = (string)($val ?? '');
                if (is_numeric($val) && $val !== '' && $val !== null) {
                    $wsRows .= '<c r="' . $col . '"><v>' . htmlspecialchars($sval, ENT_XML1) . '</v></c>';
                } else {
                    $si = $addStr($sval);
                    $wsRows .= '<c r="' . $col . '" t="s"><v>' . $si . '</v></c>';
                }
                $ci++;
            }
            $wsRows .= '</row>';
            $r++;
        }

        $colCount = count($headers);
        $lastCol  = $this->xlsxCol($colCount) . ($r - 1);
        $wsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
               . '<sheetData>' . $wsRows . '</sheetData>'
               . '</worksheet>';

        // Shared strings
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
        foreach ($strings as $s) {
            $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
        }
        $ssXml .= '</sst>';

        // Workbook
        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
               . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
               . '</workbook>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $pkgRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // Build ZIP in memory
        $tmp = tempnam(sys_get_temp_dir(), 'aegis_xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',       $contentTypes);
        $zip->addFromString('_rels/.rels',               $pkgRels);
        $zip->addFromString('xl/workbook.xml',           $wbXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $wsXml);
        $zip->addFromString('xl/sharedStrings.xml',     $ssXml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private function xlsxCol(int $n): string {
        $col = '';
        while ($n > 0) {
            $n--;
            $col = chr(65 + ($n % 26)) . $col;
            $n   = intdiv($n, 26);
        }
        return $col;
    }
}
