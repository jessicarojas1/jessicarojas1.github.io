<?php
declare(strict_types=1);

class VendorController {

    // ------------------------------------------------------------------ index
    public function index(): void {
        Auth::requirePermission('vendor.view');

        $tierFilter   = Security::sanitizeInput($_GET['risk_tier'] ?? '');
        $statusFilter = Security::sanitizeInput($_GET['status'] ?? '');
        $search       = Security::sanitizeInput($_GET['search'] ?? '');

        $validTiers    = ['critical', 'high', 'medium', 'low'];
        $validStatuses = ['active', 'inactive', 'under_review', 'terminated'];

        $where  = ['1=1'];
        $params = [];

        if ($tierFilter && in_array($tierFilter, $validTiers, true)) {
            $where[]  = 'v.risk_tier = ?';
            $params[] = $tierFilter;
        }

        if ($statusFilter && in_array($statusFilter, $validStatuses, true)) {
            $where[]  = 'v.status = ?';
            $params[] = $statusFilter;
        }

        if ($search) {
            $where[]  = '(v.name ILIKE ? OR v.vendor_code ILIKE ? OR v.category ILIKE ? OR v.primary_contact ILIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSQL = implode(' AND ', $where);

        // Server-side pagination (TD-5).
        $vendorTotal = (int) (Database::fetchOne("SELECT COUNT(*) AS c FROM vendors v WHERE {$whereSQL}", $params)['c'] ?? 0);
        $pagination = Pagination::build($vendorTotal);

        $vendors = Database::fetchAll(
            "SELECT v.*
             FROM vendors v
             WHERE {$whereSQL}
             ORDER BY
               CASE v.risk_tier WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END,
               v.name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$pagination['perPage'], $pagination['offset']])
        );

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*)                                        AS total,
               COUNT(*) FILTER (WHERE status = 'active')      AS active_count,
               COUNT(*) FILTER (WHERE risk_tier = 'critical') AS critical_count,
               COUNT(*) FILTER (WHERE data_access = TRUE)     AS data_access_count
             FROM vendors"
        );

        require AEGIS_ROOT . '/views/vendor/index.php';
    }

    // ------------------------------------------------------------ createForm
    public function createForm(): void {
        Auth::requirePermission('vendor.create');
        require AEGIS_ROOT . '/views/vendor/create.php';
    }

    // --------------------------------------------------------------- create
    public function create(): void {
        Auth::requirePermission('vendor.create');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $name           = Security::sanitizeInput($_POST['name'] ?? '');
        $category       = Security::sanitizeInput($_POST['category'] ?? '');
        $rawWebsite     = Security::sanitizeInput($_POST['website'] ?? '');
        // Validate website URL — only http/https allowed
        $website        = '';
        if ($rawWebsite) {
            $scheme = strtolower(parse_url($rawWebsite, PHP_URL_SCHEME) ?? '');
            $website = in_array($scheme, ['http', 'https']) ? $rawWebsite : '';
        }
        $description    = Security::sanitizeInput($_POST['description'] ?? '');
        $riskTier       = Security::sanitizeInput($_POST['risk_tier'] ?? 'medium');
        $status         = Security::sanitizeInput($_POST['status'] ?? 'active');
        $country        = Security::sanitizeInput($_POST['country'] ?? '');
        $primaryContact = Security::sanitizeInput($_POST['primary_contact'] ?? '');
        $contactEmail   = Security::sanitizeInput($_POST['contact_email'] ?? '');
        $contractStart  = Security::sanitizeInput($_POST['contract_start'] ?? '');
        $contractEnd    = Security::sanitizeInput($_POST['contract_end'] ?? '');
        $dataAccess      = isset($_POST['data_access']);
        $criticalService = isset($_POST['critical_service']);

        if (!$name) {
            $_SESSION['flash_error'] = 'Vendor name is required.';
            header('Location: /vendor/create');
            return;
        }

        $validTiers    = ['critical', 'high', 'medium', 'low'];
        $validStatuses = ['active', 'inactive', 'under_review', 'terminated'];
        $validCategories = ['Cloud Provider', 'SaaS', 'Hardware', 'Professional Services', 'Financial', 'Legal', 'Other'];

        if (!in_array($riskTier, $validTiers, true))       { $riskTier = 'medium'; }
        if (!in_array($status, $validStatuses, true))      { $status   = 'active'; }
        if ($category && !in_array($category, $validCategories, true)) { $category = 'Other'; }

        // Generate vendor code: VND-0001 style
        $maxRow     = Database::fetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM vendors");
        $vendorCode = 'VND-' . str_pad((string)(((int)$maxRow['max_id']) + 1), 4, '0', STR_PAD_LEFT);

        $vendorId = Database::insert('vendors', [
            'vendor_code'     => $vendorCode,
            'name'            => $name,
            'category'        => $category ?: null,
            'website'         => $website ?: null,
            'primary_contact' => $primaryContact ?: null,
            'contact_email'   => $contactEmail ?: null,
            'risk_tier'       => $riskTier,
            'status'          => $status,
            'country'         => $country ?: null,
            'description'     => $description ?: null,
            'contract_start'  => $contractStart ?: null,
            'contract_end'    => $contractEnd ?: null,
            'data_access'     => $dataAccess,
            'critical_service' => $criticalService,
        ]);

        Auth::log('create_vendor', 'vendors', $vendorId, ['name' => $name, 'risk_tier' => $riskTier]);

        $_SESSION['flash_success'] = 'Vendor ' . $vendorCode . ' created successfully.';
        header('Location: /vendor/' . $vendorId);
    }

    // ------------------------------------------------------------------ view
    public function view(string $id): void {
        Auth::requirePermission('vendor.view');
        $id = (int)$id;

        $vendor = Database::fetchOne(
            "SELECT * FROM vendors WHERE id = ?",
            [$id]
        );

        if (!$vendor) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $assessments = Database::fetchAll(
            "SELECT va.*, u.name AS assessed_by_name
             FROM vendor_assessments va
             LEFT JOIN users u ON u.id = va.assessed_by
             WHERE va.vendor_id = ?
             ORDER BY va.created_at DESC",
            [$id]
        );

        $certifications = Database::fetchAll(
            "SELECT vc.*, u.name AS owner_name
             FROM vendor_certifications vc
             LEFT JOIN users u ON u.id = vc.owner_id
             WHERE vc.vendor_id = ?
             ORDER BY vc.expiry_date ASC NULLS LAST, vc.created_at DESC",
            [$id]
        );

        $users = Database::fetchAll(
            "SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name"
        );

        require AEGIS_ROOT . '/views/vendor/view.php';
    }

    // --------------------------------------------------------------- update
    public function update(string $id): void {
        Auth::requirePermission('vendor.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;

        $name           = Security::sanitizeInput($_POST['name'] ?? '');
        $category       = Security::sanitizeInput($_POST['category'] ?? '');
        $rawWebsite     = Security::sanitizeInput($_POST['website'] ?? '');
        // Validate website URL — only http/https allowed
        $website        = '';
        if ($rawWebsite) {
            $scheme = strtolower(parse_url($rawWebsite, PHP_URL_SCHEME) ?? '');
            $website = in_array($scheme, ['http', 'https']) ? $rawWebsite : '';
        }
        $description    = Security::sanitizeInput($_POST['description'] ?? '');
        $riskTier       = Security::sanitizeInput($_POST['risk_tier'] ?? 'medium');
        $status         = Security::sanitizeInput($_POST['status'] ?? 'active');
        $country        = Security::sanitizeInput($_POST['country'] ?? '');
        $primaryContact = Security::sanitizeInput($_POST['primary_contact'] ?? '');
        $contactEmail   = Security::sanitizeInput($_POST['contact_email'] ?? '');
        $contractStart  = Security::sanitizeInput($_POST['contract_start'] ?? '');
        $contractEnd    = Security::sanitizeInput($_POST['contract_end'] ?? '');
        $dataAccess      = isset($_POST['data_access']);
        $criticalService = isset($_POST['critical_service']);

        if (!$name) {
            $_SESSION['flash_error'] = 'Vendor name is required.';
            header('Location: /vendor/' . $id . '?tab=edit');
            return;
        }

        $validTiers    = ['critical', 'high', 'medium', 'low'];
        $validStatuses = ['active', 'inactive', 'under_review', 'terminated'];
        $validCategories = ['Cloud Provider', 'SaaS', 'Hardware', 'Professional Services', 'Financial', 'Legal', 'Other'];

        if (!in_array($riskTier, $validTiers, true))       { $riskTier = 'medium'; }
        if (!in_array($status, $validStatuses, true))      { $status   = 'active'; }
        if ($category && !in_array($category, $validCategories, true)) { $category = 'Other'; }

        Database::update('vendors', [
            'name'            => $name,
            'category'        => $category ?: null,
            'website'         => $website ?: null,
            'primary_contact' => $primaryContact ?: null,
            'contact_email'   => $contactEmail ?: null,
            'risk_tier'       => $riskTier,
            'status'          => $status,
            'country'         => $country ?: null,
            'description'     => $description ?: null,
            'contract_start'  => $contractStart ?: null,
            'contract_end'    => $contractEnd ?: null,
            'data_access'     => $dataAccess,
            'critical_service' => $criticalService,
        ], 'id = ?', [$id]);

        Auth::log('update_vendor', 'vendors', $id, ['status' => $status, 'risk_tier' => $riskTier]);

        $_SESSION['flash_success'] = 'Vendor updated successfully.';
        header('Location: /vendor/' . $id . '?saved=1');
    }

    // -------------------------------------------------------- addAssessment
    public function addAssessment(string $id): void {
        Auth::requirePermission('vendor.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $vendorId = (int)$id;

        $vendor = Database::fetchOne("SELECT id FROM vendors WHERE id = ?", [$vendorId]);
        if (!$vendor) {
            http_response_code(404);
            return;
        }

        $validTypes = ['security', 'privacy', 'business_continuity', 'financial', 'operational'];
        $assessmentType = Security::sanitizeInput($_POST['assessment_type'] ?? 'security');
        if (!in_array($assessmentType, $validTypes, true)) {
            $assessmentType = 'security';
        }

        $scheduledDate = Security::sanitizeInput($_POST['scheduled_date'] ?? '');
        $assessedById  = !empty($_POST['assessed_by']) ? (int)$_POST['assessed_by'] : Auth::id();

        if (!$scheduledDate) {
            $_SESSION['flash_error'] = 'Scheduled date is required.';
            header('Location: /vendor/' . $vendorId);
            return;
        }

        $assessId = Database::insert('vendor_assessments', [
            'vendor_id'       => $vendorId,
            'assessment_type' => $assessmentType,
            'status'          => 'planned',
            'assessed_by'     => $assessedById,
            'scheduled_date'  => $scheduledDate,
        ]);

        Auth::log('create_vendor_assessment', 'vendor_assessments', $assessId, [
            'vendor_id'       => $vendorId,
            'assessment_type' => $assessmentType,
        ]);

        $_SESSION['flash_success'] = 'Assessment scheduled successfully.';
        header('Location: /vendor/' . $vendorId . '?assess_added=1');
    }

    // ----------------------------------------------------- addCertification
    public function addCertification(string $id): void {
        Auth::requirePermission('vendor.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $vendorId = (int)$id;
        $vendor = Database::fetchOne("SELECT id FROM vendors WHERE id = ?", [$vendorId]);
        if (!$vendor) { http_response_code(404); return; }

        $certType = Security::sanitizeInput($_POST['certification_type'] ?? '');
        if ($certType === '') {
            $_SESSION['flash_error'] = 'Certification type is required.';
            header('Location: /vendor/' . $vendorId); return;
        }

        $validStatuses = ['active', 'expired', 'revoked', 'pending'];
        $status = Security::sanitizeInput($_POST['status'] ?? 'active');
        if (!in_array($status, $validStatuses, true)) { $status = 'active'; }

        $issued = Security::sanitizeInput($_POST['issued_date'] ?? '');
        $expiry = Security::sanitizeInput($_POST['expiry_date'] ?? '');

        $certId = Database::insert('vendor_certifications', [
            'vendor_id'          => $vendorId,
            'certification_type' => $certType,
            'certificate_number' => Security::sanitizeInput($_POST['certificate_number'] ?? '') ?: null,
            'issuer'             => Security::sanitizeInput($_POST['issuer'] ?? '') ?: null,
            'issued_date'        => $issued ?: null,
            'expiry_date'        => $expiry ?: null,
            'status'             => $status,
            'notes'              => Security::sanitizeInput($_POST['notes'] ?? '') ?: null,
            'owner_id'           => !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null,
            'created_by'         => Auth::id(),
        ]);

        Auth::log('create_vendor_certification', 'vendor_certifications', $certId, [
            'vendor_id'          => $vendorId,
            'certification_type' => $certType,
        ]);

        $_SESSION['flash_success'] = 'Certification added.';
        header('Location: /vendor/' . $vendorId . '?cert_added=1');
    }

    // -------------------------------------------------- deleteCertification
    public function deleteCertification(string $vendorId, string $certId): void {
        Auth::requirePermission('vendor.edit');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $vendorId = (int)$vendorId;
        $certId   = (int)$certId;

        $cert = Database::fetchOne(
            "SELECT id FROM vendor_certifications WHERE id = ? AND vendor_id = ?",
            [$certId, $vendorId]
        );
        if (!$cert) { $_SESSION['flash_error'] = 'Certification not found.'; header('Location: /vendor/' . $vendorId); return; }

        Database::query("DELETE FROM vendor_certifications WHERE id = ?", [$certId]);
        Auth::log('delete_vendor_certification', 'vendor_certifications', $certId, ['vendor_id' => $vendorId]);

        $_SESSION['flash_success'] = 'Certification removed.';
        header('Location: /vendor/' . $vendorId);
    }

    // ------------------------------------------- generatePortalLink
    public function generatePortalLink(string $id): void {
        Auth::requirePermission('vendor.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'CSRF error';
            return;
        }

        $id = (int)$id;
        $vendor = Database::fetchOne("SELECT id, name FROM vendors WHERE id = ?", [$id]);
        if (!$vendor) {
            http_response_code(404);
            return;
        }

        $defaultQuestions = [
            ['id'=>1,'text'=>'Describe your information security management system (ISMS) and any certifications held (ISO 27001, SOC 2, etc.).','required'=>true],
            ['id'=>2,'text'=>'Do you have a dedicated security team or CISO? If yes, describe their responsibilities.','required'=>true],
            ['id'=>3,'text'=>'How do you handle and protect customer/client data? Describe encryption practices.','required'=>true],
            ['id'=>4,'text'=>'Describe your incident response and breach notification procedures.','required'=>true],
            ['id'=>5,'text'=>'Do you conduct regular penetration testing or security audits? How often?','required'=>false],
            ['id'=>6,'text'=>'How do you manage access controls and privileged access management (PAM)?','required'=>false],
            ['id'=>7,'text'=>'Describe your business continuity and disaster recovery plans.','required'=>false],
            ['id'=>8,'text'=>'What third-party subprocessors do you use and how are they managed?','required'=>false],
            ['id'=>9,'text'=>'Have you had any security incidents in the past 24 months? If yes, describe.','required'=>false],
            ['id'=>10,'text'=>'Provide your data retention and deletion policies.','required'=>false],
        ];

        $token    = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        Database::query(
            "INSERT INTO vendor_portal_tokens (vendor_id, token_hash, title, questions, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $id,
                $tokenHash,
                'Vendor Self-Assessment',
                json_encode($defaultQuestions),
                $expiresAt,
                Auth::id(),
            ]
        );

        Auth::log('generate_portal_link', 'vendors', $id, ['vendor_name' => $vendor['name']]);

        $portalUrl = rtrim($_ENV['APP_URL'] ?? '', '/') . '/vendor/portal/' . $token;
        $_SESSION['portal_link'] = $portalUrl;
        header('Location: /vendor/' . $id . '?portal=1');
    }

    // ------------------------------------------- portalView (PUBLIC — no auth)
    public function portalView(string $token): void {
        // Sanitize token — only hex chars allowed
        $token = preg_replace('/[^a-f0-9]/i', '', $token);
        $tokenHash = hash('sha256', $token);

        $rec = Database::fetchOne(
            "SELECT * FROM vendor_portal_tokens WHERE token_hash = ? AND expires_at > NOW()",
            [$tokenHash]
        );

        if (!$rec) {
            http_response_code(404);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Link Invalid or Expired</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc}
            .box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.1);max-width:400px}
            h2{color:#dc2626;margin-top:0}p{color:#64748b}</style></head>
            <body><div class="box"><h2>Link Invalid or Expired</h2>
            <p>This assessment link is no longer valid. Please contact the organization that sent you this link to request a new one.</p></div></body></html>';
            return;
        }

        if (!empty($rec['used_at'])) {
            http_response_code(410);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Assessment Already Submitted</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc}
            .box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.1);max-width:400px}
            h2{color:#059669;margin-top:0}p{color:#64748b}</style></head>
            <body><div class="box"><h2>Assessment Already Submitted</h2>
            <p>This assessment has already been completed. Thank you for your response. Please contact the organization if you need to make changes.</p></div></body></html>';
            return;
        }

        $vendor    = Database::fetchOne("SELECT name FROM vendors WHERE id = ?", [$rec['vendor_id']]);
        $questions = json_decode($rec['questions'], true) ?? [];
        require AEGIS_ROOT . '/views/vendor/portal.php';
    }

    // ------------------------------------------- portalSubmit (PUBLIC — no auth)
    public function portalSubmit(string $token): void {
        // Sanitize token — only hex chars allowed
        $token = preg_replace('/[^a-f0-9]/i', '', $token);
        $tokenHash = hash('sha256', $token);

        $rec = Database::fetchOne(
            "SELECT * FROM vendor_portal_tokens WHERE token_hash = ? AND expires_at > NOW()",
            [$tokenHash]
        );

        if (!$rec || !empty($rec['used_at'])) {
            http_response_code(410);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Submission Error</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f8fafc}
            .box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.1);max-width:400px}
            h2{color:#dc2626;margin-top:0}p{color:#64748b}</style></head>
            <body><div class="box"><h2>Submission Error</h2>
            <p>This assessment link is no longer valid or has already been submitted.</p></div></body></html>';
            return;
        }

        // Validate CSRF: HMAC-SHA256 of portal token with app secret
        $expectedCsrf  = hash_hmac('sha256', $token, $_ENV['JWT_SECRET'] ?? '');
        $submittedCsrf = $_POST['csrf_token'] ?? '';
        if (!hash_equals($expectedCsrf, $submittedCsrf)) {
            http_response_code(403);
            echo 'Security validation failed. Please go back and try again.';
            return;
        }

        $questions = json_decode($rec['questions'], true) ?? [];
        $rawAnswers = $_POST['answers'] ?? [];

        // Validate required questions
        $errors = [];
        foreach ($questions as $q) {
            if (!empty($q['required'])) {
                $ans = trim($rawAnswers[$q['id']] ?? '');
                if ($ans === '') {
                    $errors[] = 'Question ' . $q['id'] . ' is required.';
                }
            }
        }

        if ($errors) {
            http_response_code(422);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Validation Error</title>
            <style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:0 20px}
            .err{background:#fee2e2;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:16px}
            a{color:#4f46e5}</style></head><body>
            <div class="err"><strong>Please fix the following:</strong><ul>';
            foreach ($errors as $e) {
                echo '<li>' . htmlspecialchars($e, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</li>';
            }
            echo '</ul></div><a href="javascript:history.back()">← Go back</a></body></html>';
            return;
        }

        // Build answers array
        $answers = [];
        foreach ($questions as $q) {
            $answers[(int)$q['id']] = trim($rawAnswers[$q['id']] ?? '');
        }

        // Save response
        Database::query(
            "UPDATE vendor_portal_tokens SET response = ?, used_at = NOW() WHERE token_hash = ?",
            [json_encode($answers), $tokenHash]
        );

        // Optionally create a vendor_assessment record (best-effort)
        try {
            Database::insert('vendor_assessments', [
                'vendor_id'       => $rec['vendor_id'],
                'assessment_type' => 'security',
                'status'          => 'completed',
                'scheduled_date'  => date('Y-m-d'),
                'completed_date'  => date('Y-m-d'),
                'findings'        => 'Submitted via vendor portal self-assessment.',
            ]);
        } catch (Throwable) {
            // Non-fatal — assessment record is optional
        }

        // Success page
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Assessment Submitted</title>
        <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
        <style>
          body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:0}
          .header{background:#0f172a;color:white;padding:20px 40px;display:flex;align-items:center;gap:12px}
          .header h1{margin:0;font-size:18px}
          .content{max-width:560px;margin:60px auto;padding:0 20px;text-align:center}
          .icon{font-size:56px;color:#059669;display:block;margin-bottom:16px}
          h2{font-size:24px;margin-bottom:12px}
          p{color:#64748b;line-height:1.6}
          .footer{text-align:center;color:#94a3b8;font-size:13px;padding:20px}
        </style></head><body>
        <div class="header">
          <i class="bi bi-shield-fill-check" style="font-size:24px;color:#818cf8"></i>
          <div><h1>AEGIS GRC — Vendor Security Assessment</h1></div>
        </div>
        <div class="content">
          <i class="bi bi-check-circle-fill icon"></i>
          <h2>Assessment Submitted Successfully</h2>
          <p>Thank you for completing the vendor security assessment. Your responses have been securely recorded and will be reviewed by our team.</p>
          <p style="margin-top:24px;font-size:13px;color:#94a3b8">You may now close this window.</p>
        </div>
        <div class="footer">Powered by AEGIS GRC</div>
        </body></html>';
    }

    // --------------------------------------------------- contracts
    public function contracts(): void {
        Auth::requirePermission('vendor.contracts');
        // Expiring soon (within 60 days)
        $expiring = Database::fetchAll(
            "SELECT vc.*, v.name as vendor_name FROM vendor_contracts vc
             JOIN vendors v ON v.id = vc.vendor_id
             WHERE vc.status='active' AND vc.end_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '60 days'
             ORDER BY vc.end_date ASC"
        );
        $contracts = Database::fetchAll(
            "SELECT vc.*, v.name as vendor_name, u.name as owner_name
             FROM vendor_contracts vc
             JOIN vendors v ON v.id = vc.vendor_id
             LEFT JOIN users u ON u.id = vc.owner_id
             ORDER BY vc.end_date ASC NULLS LAST, v.name ASC"
        );
        $pageTitle    = 'Vendor Contracts';
        $activeModule = 'vendor_contracts';
        $breadcrumbs  = [['Vendors', '/vendor'], ['Contracts', null]];
        ob_start();
        require AEGIS_ROOT . '/views/vendor/contracts.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // --------------------------------------------------- createContract
    public function createContract(string $vendorId): void {
        Auth::requirePermission('vendor.contracts');
        $vendorId = (int)$vendorId;
        $vendor = Database::fetchOne("SELECT id, name FROM vendors WHERE id=?", [$vendorId]);
        if (!$vendor) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $pageTitle    = 'New Contract — ' . $vendor['name'];
        $activeModule = 'vendor_contracts';
        $breadcrumbs  = [['Vendors', '/vendor'], [$vendor['name'], "/vendor/{$vendorId}"], ['New Contract', null]];
        ob_start();
        require AEGIS_ROOT . '/views/vendor/contract_create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // --------------------------------------------------- saveContract
    public function saveContract(string $vendorId): void {
        Auth::requirePermission('vendor.contracts');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $vendorId = (int)$vendorId;
        $vendor = Database::fetchOne("SELECT id FROM vendors WHERE id=?", [$vendorId]);
        if (!$vendor) { http_response_code(404); return; }
        $title  = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        $start  = Security::sanitizeInput($_POST['start_date'] ?? '');
        if (!$title || !$start) {
            $_SESSION['flash_error'] = 'Title and start date are required.';
            header("Location: /vendor/{$vendorId}/contract/create"); return;
        }
        $validStatuses = ['draft','active','expired','terminated'];
        $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : 'active';
        $value  = is_numeric($_POST['value'] ?? '') ? (float)$_POST['value'] : null;
        $id = Database::insert('vendor_contracts', [
            'vendor_id'           => $vendorId,
            'title'               => $title,
            'contract_number'     => Security::sanitizeInput($_POST['contract_number'] ?? ''),
            'status'              => $status,
            'value'               => $value,
            'currency'            => strtoupper(substr(Security::sanitizeInput($_POST['currency'] ?? 'USD'), 0, 3)),
            'start_date'          => $start,
            'end_date'            => Security::sanitizeInput($_POST['end_date'] ?? '') ?: null,
            'auto_renewal'        => !empty($_POST['auto_renewal']),
            'renewal_notice_days' => (int)($_POST['renewal_notice_days'] ?? 30),
            'description'         => Security::sanitizeInput($_POST['description'] ?? ''),
            'owner_id'            => (int)($_POST['owner_id'] ?? 0) ?: null,
            'created_by'          => Auth::id(),
        ]);
        Auth::log('contract_created', 'vendor_contracts', $id, ['title'=>$title,'vendor_id'=>$vendorId]);
        $_SESSION['flash_success'] = 'Contract saved.';
        header("Location: /vendor/{$vendorId}");
    }

    // --------------------------------------------------- updateContract
    public function updateContract(string $id): void {
        Auth::requirePermission('vendor.contracts');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id  = (int)$id;
        $contract = Database::fetchOne("SELECT * FROM vendor_contracts WHERE id=?", [$id]);
        if (!$contract) { http_response_code(404); return; }
        $validStatuses = ['draft','active','expired','terminated'];
        $status = in_array($_POST['status'] ?? '', $validStatuses, true) ? $_POST['status'] : $contract['status'];
        $value  = is_numeric($_POST['value'] ?? '') ? (float)$_POST['value'] : null;
        Database::query(
            "UPDATE vendor_contracts SET title=?,contract_number=?,status=?,value=?,currency=?,start_date=?,end_date=?,
             auto_renewal=?,renewal_notice_days=?,description=?,owner_id=?,updated_at=NOW() WHERE id=?",
            [
                trim(Security::sanitizeInput($_POST['title'] ?? $contract['title'])),
                Security::sanitizeInput($_POST['contract_number'] ?? ''),
                $status, $value,
                strtoupper(substr(Security::sanitizeInput($_POST['currency'] ?? 'USD'), 0, 3)),
                Security::sanitizeInput($_POST['start_date'] ?? $contract['start_date']),
                Security::sanitizeInput($_POST['end_date'] ?? '') ?: null,
                !empty($_POST['auto_renewal']),
                (int)($_POST['renewal_notice_days'] ?? 30),
                Security::sanitizeInput($_POST['description'] ?? ''),
                (int)($_POST['owner_id'] ?? 0) ?: null,
                $id,
            ]
        );
        Auth::log('contract_updated', 'vendor_contracts', $id, ['status'=>$status]);
        $_SESSION['flash_success'] = 'Contract updated.';
        header("Location: /vendor/{$contract['vendor_id']}");
    }

    // --------------------------------------------------- updateAssessment
    public function updateAssessment(string $vendorId, string $assessId): void {
        Auth::requirePermission('vendor.assess');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $vendorId = (int)$vendorId;
        $assessId = (int)$assessId;

        $validStatuses  = ['planned', 'in_progress', 'completed', 'overdue'];
        $validRatings   = ['critical', 'high', 'medium', 'low', 'acceptable'];

        $status          = Security::sanitizeInput($_POST['status'] ?? 'planned');
        $overallScore    = !empty($_POST['overall_score']) ? max(0, min(100, (int)$_POST['overall_score'])) : null;
        $riskRating      = Security::sanitizeInput($_POST['risk_rating'] ?? '');
        $findings        = Security::sanitizeInput($_POST['findings'] ?? '');
        $recommendations = Security::sanitizeInput($_POST['recommendations'] ?? '');
        $completedDate   = Security::sanitizeInput($_POST['completed_date'] ?? '');
        $nextAssessDate  = Security::sanitizeInput($_POST['next_assessment_date'] ?? '');

        if (!in_array($status, $validStatuses, true))  { $status = 'planned'; }
        if ($riskRating && !in_array($riskRating, $validRatings, true)) { $riskRating = ''; }

        $data = [
            'status'               => $status,
            'overall_score'        => $overallScore,
            'risk_rating'          => $riskRating ?: null,
            'findings'             => $findings ?: null,
            'recommendations'      => $recommendations ?: null,
            'completed_date'       => $completedDate ?: null,
            'next_assessment_date' => $nextAssessDate ?: null,
        ];

        if ($status === 'completed' && !$completedDate) {
            $data['completed_date'] = date('Y-m-d');
        }

        Database::update('vendor_assessments', $data, 'id = ? AND vendor_id = ?', [$assessId, $vendorId]);

        Auth::log('update_vendor_assessment', 'vendor_assessments', $assessId, [
            'status'     => $status,
            'risk_rating' => $riskRating,
        ]);

        $_SESSION['flash_success'] = 'Assessment updated.';
        header('Location: /vendor/' . $vendorId . '?assess_saved=1');
    }
}
