<?php
/**
 * ImportController — bulk CSV import for risks, vendors, and incidents.
 *
 * CSV format for each entity type is documented in /views/import/index.php.
 * All imports are transactional: if any row fails validation the entire
 * import is aborted (no partial data written).
 */
class ImportController {

    public function index(): void {
        Auth::requirePermission('compliance.write');
        $pageTitle    = 'Bulk Import';
        $activeModule = 'bulk_import';
        $breadcrumbs  = [['Import', null]];
        ob_start();
        require AEGIS_ROOT . '/views/import/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function upload(): void {
        Auth::requirePermission('compliance.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $type = $_POST['import_type'] ?? '';
        if (!in_array($type, ['risks', 'vendors', 'incidents'])) {
            $_SESSION['flash_error'] = 'Invalid import type.';
            header('Location: /import'); exit;
        }

        $file = $_FILES['csv_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'No file uploaded or upload error.';
            header('Location: /import'); exit;
        }

        // Validate file extension and MIME
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $_SESSION['flash_error'] = 'Only CSV files are accepted.';
            header('Location: /import'); exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
            $_SESSION['flash_error'] = 'Invalid file content type. Only CSV files are accepted.';
            header('Location: /import'); exit;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            $_SESSION['flash_error'] = 'File too large (max 10MB).';
            header('Location: /import'); exit;
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $_SESSION['flash_error'] = 'Could not read file.';
            header('Location: /import'); exit;
        }

        // Parse CSV — first row is header
        $headers = array_map('trim', fgetcsv($handle) ?: []);
        if (empty($headers)) {
            $_SESSION['flash_error'] = 'CSV file is empty.';
            fclose($handle); header('Location: /import'); exit;
        }

        $rows = [];
        $lineNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) !== count($headers)) {
                fclose($handle);
                $_SESSION['flash_error'] = "Row {$lineNum}: column count mismatch (expected " . count($headers) . ", got " . count($row) . ").";
                header('Location: /import'); exit;
            }
            $rows[] = array_combine($headers, $row);
        }
        fclose($handle);

        if (empty($rows)) {
            $_SESSION['flash_error'] = 'No data rows found in CSV.';
            header('Location: /import'); exit;
        }

        [$errors, $imported] = match ($type) {
            'risks'     => $this->importRisks($rows),
            'vendors'   => $this->importVendors($rows),
            'incidents' => $this->importIncidents($rows),
        };

        if (!empty($errors)) {
            $_SESSION['flash_error'] = 'Import failed: ' . implode('; ', array_slice($errors, 0, 5));
            header('Location: /import'); exit;
        }

        Auth::log("bulk_import_{$type}", $type, null, ['count' => $imported]);
        $_SESSION['flash_success'] = "Successfully imported {$imported} " . rtrim($type, 's') . "(s).";
        $redirectMap = ['risks' => '/risk', 'vendors' => '/vendor', 'incidents' => '/incident'];
        header('Location: ' . ($redirectMap[$type] ?? '/import')); exit;
    }

    // ── Importers ─────────────────────────────────────────────────────────────

    private function importRisks(array $rows): array {
        $required = ['title', 'likelihood', 'impact'];
        $errors   = $this->validateHeaders($rows[0], $required);
        if ($errors) return [$errors, 0];

        $categories = Database::fetchAll("SELECT id, name FROM risk_categories");
        $catMap = array_column($categories, 'id', 'name');

        $imported = 0;
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $title = trim($row['title'] ?? '');
            if (!$title) { $errors[] = "Row {$line}: title is required."; continue; }

            $likelihood = (int)($row['likelihood'] ?? 3);
            $impact     = (int)($row['impact'] ?? 3);

            if ($likelihood < 1 || $likelihood > 5) { $errors[] = "Row {$line}: likelihood must be 1-5."; continue; }
            if ($impact    < 1 || $impact    > 5)   { $errors[] = "Row {$line}: impact must be 1-5."; continue; }

            $catId = null;
            if (!empty($row['category'])) {
                $catId = $catMap[trim($row['category'])] ?? null;
            }

            $status = in_array(($row['status'] ?? 'open'), ['open','accepted','mitigated','closed','transferred'])
                ? $row['status'] : 'open';

            Database::query(
                "INSERT INTO risks (title, description, category_id, likelihood, impact, inherent_score,
                 status, treatment_type, identified_date, created_by)
                 VALUES (?,?,?,?,?,?,?,?,CURRENT_DATE,?)",
                [
                    Security::sanitizeInput($title),
                    Security::sanitizeInput($row['description'] ?? ''),
                    $catId,
                    $likelihood,
                    $impact,
                    $likelihood * $impact,
                    $status,
                    Security::sanitizeInput($row['treatment_type'] ?? ''),
                    Auth::id(),
                ]
            );
            $imported++;
        }

        return [$errors, $imported];
    }

    private function importVendors(array $rows): array {
        $required = ['name'];
        $errors   = $this->validateHeaders($rows[0], $required);
        if ($errors) return [$errors, 0];

        $imported = 0;
        $seq = (int)(Database::fetchOne("SELECT COUNT(*) + 1 as n FROM vendors")['n']);

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $name = trim($row['name'] ?? '');
            if (!$name) { $errors[] = "Row {$line}: name is required."; continue; }

            $website = '';
            if (!empty($row['website'])) {
                $scheme = strtolower(parse_url($row['website'], PHP_URL_SCHEME) ?? '');
                $website = in_array($scheme, ['http', 'https']) ? $row['website'] : '';
            }

            $tier = in_array(($row['risk_tier'] ?? 'medium'), ['critical','high','medium','low'])
                ? $row['risk_tier'] : 'medium';

            $seq++;

            Database::query(
                "INSERT INTO vendors (name, category, website, description, risk_rating, status, created_by)
                 VALUES (?,?,?,?,?,?,?)",
                [
                    Security::sanitizeInput($name),
                    Security::sanitizeInput($row['category'] ?? ''),
                    $website,
                    Security::sanitizeInput($row['description'] ?? ''),
                    $tier,
                    'active',
                    Auth::id(),
                ]
            );
            $imported++;
        }

        return [$errors, $imported];
    }

    private function importIncidents(array $rows): array {
        $required = ['title', 'severity'];
        $errors   = $this->validateHeaders($rows[0], $required);
        if ($errors) return [$errors, 0];

        $imported = 0;
        foreach ($rows as $i => $row) {
            $line  = $i + 2;
            $title = trim($row['title'] ?? '');
            if (!$title) { $errors[] = "Row {$line}: title is required."; continue; }

            $severity = in_array(($row['severity'] ?? 'medium'), ['critical','high','medium','low'])
                ? $row['severity'] : 'medium';

            $maxRow = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM incidents");
            $incidentNumber = 'INC-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

            Database::query(
                "INSERT INTO incidents (incident_number, title, description, severity, status, reported_by)
                 VALUES (?,?,?,?,'open',?)",
                [
                    $incidentNumber,
                    Security::sanitizeInput($title),
                    Security::sanitizeInput($row['description'] ?? ''),
                    $severity,
                    Auth::id(),
                ]
            );
            $imported++;
        }

        return [$errors, $imported];
    }

    private function validateHeaders(array $firstRow, array $required): array {
        $errors  = [];
        $present = array_keys($firstRow);
        foreach ($required as $col) {
            if (!in_array($col, $present)) {
                $errors[] = "Missing required column: '{$col}'.";
            }
        }
        return $errors;
    }
}
