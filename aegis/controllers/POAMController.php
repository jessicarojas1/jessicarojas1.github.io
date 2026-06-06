<?php
declare(strict_types=1);

class POAMController {

    // ──────────────────────────────────────────────────
    // GET /poam
    // ──────────────────────────────────────────────────
    public function index(): void {
        Auth::requirePermission('compliance.view');

        $items = Database::fetchAll(
            "SELECT pi.*, cp.name AS package_name,
                    u.name AS owner_name,
                    COUNT(pm.id) AS total_milestones,
                    COUNT(pm.id) FILTER (WHERE pm.is_complete = TRUE) AS completed_milestones
             FROM poam_items pi
             LEFT JOIN compliance_packages cp ON cp.id = pi.package_id
             LEFT JOIN users u ON u.id = pi.owner_id
             LEFT JOIN poam_milestones pm ON pm.poam_id = pi.id
             GROUP BY pi.id, cp.name, u.name
             ORDER BY
               CASE pi.status
                 WHEN 'open'        THEN 1
                 WHEN 'in_progress' THEN 2
                 WHEN 'closed'      THEN 3
                 WHEN 'cancelled'   THEN 4
                 ELSE 5
               END,
               pi.scheduled_completion ASC NULLS LAST"
        );

        $packages = Database::fetchAll(
            "SELECT id, name FROM compliance_packages WHERE is_active = TRUE ORDER BY name"
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = 'POA&M Items';
        $activeModule = 'poam';
        $breadcrumbs  = [['POA&M', null]];
        ob_start();
        require AEGIS_ROOT . '/views/poam/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ──────────────────────────────────────────────────
    // POST /poam/generate
    // ──────────────────────────────────────────────────
    public function generate(): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $packageId = (int)($_POST['package_id'] ?? 0);
        if (!$packageId) {
            $_SESSION['flash_error'] = 'Please select a package.';
            header('Location: /poam');
            return;
        }

        $package = Database::fetchOne("SELECT id, name FROM compliance_packages WHERE id = ?", [$packageId]);
        if (!$package) {
            $_SESSION['flash_error'] = 'Package not found.';
            header('Location: /poam');
            return;
        }

        // Get non-compliant/partial controls for this package (level 2 only)
        $controls = Database::fetchAll(
            "SELECT co.id, co.code, co.title, ci.status
             FROM compliance_objectives co
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE co.package_id = ? AND co.level = 2
               AND (ci.status IS NULL OR ci.status IN ('non_compliant', 'partial'))",
            [$packageId]
        );

        if (empty($controls)) {
            $_SESSION['flash_error'] = 'No non-compliant or partial controls found for this package.';
            header('Location: /poam');
            return;
        }

        // Get current max POAM number
        $maxRow = Database::fetchOne(
            "SELECT MAX(CAST(SUBSTRING(poam_number FROM 6) AS INTEGER)) AS max_num
             FROM poam_items WHERE poam_number ~ '^POAM-[0-9]+$'"
        );
        $nextNum = (int)($maxRow['max_num'] ?? 0) + 1;

        $created = 0;
        foreach ($controls as $ctrl) {
            // Skip if already has a POA&M for this objective
            $existing = Database::fetchOne(
                "SELECT id FROM poam_items WHERE objective_id = ?",
                [$ctrl['id']]
            );
            if ($existing) {
                continue;
            }

            $poamNumber = 'POAM-' . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
            $nextNum++;

            Database::insert('poam_items', [
                'poam_number'          => $poamNumber,
                'title'                => $ctrl['code'] . ': ' . $ctrl['title'],
                'weakness_description' => 'Control status: ' . ($ctrl['status'] ?? 'not_assessed'),
                'package_id'           => $packageId,
                'objective_id'         => $ctrl['id'],
                'status'               => 'open',
                'created_by'           => Auth::id(),
            ]);
            $created++;
        }

        Auth::log('poam_generate', 'compliance_packages', $packageId, ['created' => $created]);

        if ($created === 0) {
            $_SESSION['flash_error'] = 'All non-compliant controls already have POA&M items.';
        } else {
            $_SESSION['flash_success'] = $created . ' POA&M item(s) generated for ' . $package['name'] . '.';
        }
        header('Location: /poam');
    }

    // ──────────────────────────────────────────────────
    // POST /poam/create  — manual single-item create
    // ──────────────────────────────────────────────────
    public function create(): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $title = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /poam'); return;
        }

        $maxRow = Database::fetchOne(
            "SELECT MAX(CAST(SUBSTRING(poam_number FROM 6) AS INTEGER)) AS max_num
             FROM poam_items WHERE poam_number ~ '^POAM-[0-9]+$'"
        );
        $poamNumber = 'POAM-' . str_pad((string)((int)($maxRow['max_num'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);

        $id = Database::insert('poam_items', [
            'poam_number'           => $poamNumber,
            'title'                 => $title,
            'weakness_description'  => Security::sanitizeInput($_POST['weakness_description'] ?? ''),
            'resource_requirements' => Security::sanitizeInput($_POST['required_resources']   ?? ''),
            'package_id'            => !empty($_POST['package_id'])  ? (int)$_POST['package_id']  : null,
            'owner_id'              => !empty($_POST['owner_id'])    ? (int)$_POST['owner_id']    : null,
            'status'                => 'open',
            'scheduled_completion'  => !empty($_POST['scheduled_completion']) ? $_POST['scheduled_completion'] : null,
            'created_by'            => Auth::id(),
        ]);

        Auth::log('poam_manual_create', 'poam_items', $id, ['poam_number' => $poamNumber]);
        $_SESSION['flash_success'] = "{$poamNumber} created successfully.";
        header('Location: /poam/' . $id);
    }

    // ──────────────────────────────────────────────────
    // POST /poam/import  — CSV bulk import
    // ──────────────────────────────────────────────────
    public function importCsv(): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $file = $_FILES['csv_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'No file uploaded or upload error.';
            header('Location: /poam'); return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $_SESSION['flash_error'] = 'Only CSV files are accepted.';
            header('Location: /poam'); return;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'], true)) {
            $_SESSION['flash_error'] = 'Invalid file type. Only CSV files are accepted.';
            header('Location: /poam'); return;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            $_SESSION['flash_error'] = 'File too large (max 5MB).';
            header('Location: /poam'); return;
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $_SESSION['flash_error'] = 'Could not read file.';
            header('Location: /poam'); return;
        }

        $headers = array_map('trim', fgetcsv($handle) ?: []);
        if (!in_array('title', $headers)) {
            fclose($handle);
            $_SESSION['flash_error'] = "CSV must include a 'title' column.";
            header('Location: /poam'); return;
        }

        // Build package name → id map
        $pkgRows = Database::fetchAll("SELECT id, name FROM compliance_packages");
        $pkgMap  = array_column($pkgRows, 'id', 'name');

        // Build user email → id map
        $userRows = Database::fetchAll("SELECT id, email FROM users WHERE is_active = TRUE");
        $userMap  = array_column($userRows, 'id', 'email');

        $maxRow  = Database::fetchOne(
            "SELECT MAX(CAST(SUBSTRING(poam_number FROM 6) AS INTEGER)) AS max_num
             FROM poam_items WHERE poam_number ~ '^POAM-[0-9]+$'"
        );
        $nextNum = (int)($maxRow['max_num'] ?? 0) + 1;

        $imported = 0;
        $errors   = [];
        $line     = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count($row) !== count($headers)) {
                $errors[] = "Row {$line}: column count mismatch."; continue;
            }
            $data = array_combine($headers, $row);

            $title = trim($data['title'] ?? '');
            if (!$title) { $errors[] = "Row {$line}: title is required."; continue; }

            $pkgId  = null;
            if (!empty($data['package'])) {
                $pkgId = $pkgMap[trim($data['package'])] ?? null;
            }
            $ownerId = null;
            if (!empty($data['owner_email'])) {
                $ownerId = $userMap[trim($data['owner_email'])] ?? null;
            }

            $scheduled = null;
            if (!empty($data['scheduled_completion'])) {
                $ts = strtotime($data['scheduled_completion']);
                if ($ts) $scheduled = date('Y-m-d', $ts);
            }

            $poamNumber = 'POAM-' . str_pad((string)$nextNum++, 4, '0', STR_PAD_LEFT);
            Database::insert('poam_items', [
                'poam_number'           => $poamNumber,
                'title'                 => Security::sanitizeInput($title),
                'weakness_description'  => Security::sanitizeInput($data['weakness_description'] ?? ''),
                'resource_requirements' => Security::sanitizeInput($data['required_resources']   ?? ''),
                'package_id'            => $pkgId,
                'owner_id'              => $ownerId,
                'status'                => 'open',
                'scheduled_completion'  => $scheduled,
                'created_by'            => Auth::id(),
            ]);
            $imported++;
        }
        fclose($handle);

        if ($errors) {
            $_SESSION['flash_error'] = 'Import completed with errors: ' . implode('; ', array_slice($errors, 0, 3));
        } else {
            $_SESSION['flash_success'] = "Successfully imported {$imported} POA&M item(s).";
        }
        Auth::log('poam_csv_import', 'poam_items', null, ['imported' => $imported]);
        header('Location: /poam');
    }

    // ──────────────────────────────────────────────────
    // GET /poam/{id}
    // ──────────────────────────────────────────────────
    public function view(string $id): void {
        Auth::requirePermission('compliance.view');
        $id = (int)$id;

        $item = Database::fetchOne(
            "SELECT pi.*, cp.name AS package_name,
                    u.name AS owner_name,
                    cb.name AS created_by_name
             FROM poam_items pi
             LEFT JOIN compliance_packages cp ON cp.id = pi.package_id
             LEFT JOIN users u ON u.id = pi.owner_id
             LEFT JOIN users cb ON cb.id = pi.created_by
             WHERE pi.id = ?",
            [$id]
        );
        if (!$item) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $control = null;
        if ($item['objective_id']) {
            $control = Database::fetchOne(
                "SELECT co.code, co.title, ci.status
                 FROM compliance_objectives co
                 LEFT JOIN control_implementations ci ON ci.objective_id = co.id
                 WHERE co.id = ?",
                [$item['objective_id']]
            );
        }

        $milestones = Database::fetchAll(
            "SELECT * FROM poam_milestones WHERE poam_id = ? ORDER BY created_at ASC",
            [$id]
        );

        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = 'POA&M: ' . $item['poam_number'];
        $activeModule = 'poam';
        $breadcrumbs  = [['POA&M', '/poam'], [$item['poam_number'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/poam/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ──────────────────────────────────────────────────
    // POST /poam/{id}/update
    // ──────────────────────────────────────────────────
    public function update(string $id): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id   = (int)$id;
        $item = Database::fetchOne("SELECT id FROM poam_items WHERE id = ?", [$id]);
        if (!$item) {
            http_response_code(404);
            return;
        }

        $allowedStatuses = ['open', 'in_progress', 'closed', 'cancelled'];
        $status = in_array($_POST['status'] ?? '', $allowedStatuses, true)
            ? $_POST['status']
            : 'open';

        $title                = Security::sanitizeInput($_POST['title'] ?? '');
        $weaknessDescription  = Security::sanitizeInput($_POST['weakness_description'] ?? '');
        $resourceRequirements = Security::sanitizeInput($_POST['resource_requirements'] ?? '');
        $scheduledCompletion  = Security::sanitizeInput($_POST['scheduled_completion'] ?? '');
        $ownerId              = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;

        Database::query(
            "UPDATE poam_items
             SET title=?, weakness_description=?, resource_requirements=?,
                 scheduled_completion=?, status=?, owner_id=?, updated_at=NOW()
             WHERE id=?",
            [
                $title,
                $weaknessDescription ?: null,
                $resourceRequirements ?: null,
                $scheduledCompletion ?: null,
                $status,
                $ownerId,
                $id,
            ]
        );

        Auth::log('poam_update', 'poam_items', $id, ['status' => $status]);
        $_SESSION['flash_success'] = 'POA&M item updated.';
        header('Location: /poam/' . $id);
    }

    // ──────────────────────────────────────────────────
    // POST /poam/{id}/delete
    // ──────────────────────────────────────────────────
    public function delete(string $id): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;
        Database::query("DELETE FROM poam_items WHERE id = ?", [$id]);
        Auth::log('poam_delete', 'poam_items', $id, []);
        $_SESSION['flash_success'] = 'POA&M item deleted.';
        header('Location: /poam');
    }

    // ──────────────────────────────────────────────────
    // POST /poam/{id}/milestone/add
    // ──────────────────────────────────────────────────
    public function addMilestone(string $id): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id   = (int)$id;
        $item = Database::fetchOne("SELECT id FROM poam_items WHERE id = ?", [$id]);
        if (!$item) {
            http_response_code(404);
            return;
        }

        $description = Security::sanitizeInput($_POST['description'] ?? '');
        $dueDate     = Security::sanitizeInput($_POST['due_date'] ?? '');

        if (!$description) {
            $_SESSION['flash_error'] = 'Milestone description is required.';
            header('Location: /poam/' . $id);
            return;
        }

        Database::insert('poam_milestones', [
            'poam_id'     => $id,
            'description' => $description,
            'due_date'    => $dueDate ?: null,
        ]);

        Auth::log('poam_milestone_added', 'poam_milestones', $id, []);
        $_SESSION['flash_success'] = 'Milestone added.';
        header('Location: /poam/' . $id);
    }

    // ──────────────────────────────────────────────────
    // POST /poam/{id}/milestone/{milestoneId}/complete
    // ──────────────────────────────────────────────────
    public function completeMilestone(string $id, string $milestoneId): void {
        Auth::requirePermission('compliance.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id          = (int)$id;
        $milestoneId = (int)$milestoneId;

        $milestone = Database::fetchOne(
            "SELECT * FROM poam_milestones WHERE id = ? AND poam_id = ?",
            [$milestoneId, $id]
        );
        if (!$milestone) {
            http_response_code(404);
            return;
        }

        if ($milestone['is_complete']) {
            // Toggle back to incomplete
            Database::query(
                "UPDATE poam_milestones SET is_complete=FALSE, completed_at=NULL WHERE id=?",
                [$milestoneId]
            );
        } else {
            // Mark complete
            Database::query(
                "UPDATE poam_milestones SET is_complete=TRUE, completed_at=NOW() WHERE id=?",
                [$milestoneId]
            );
        }

        Auth::log('poam_milestone_complete', 'poam_milestones', $milestoneId, []);
        $_SESSION['flash_success'] = 'Milestone updated.';
        header('Location: /poam/' . $id);
    }
}
