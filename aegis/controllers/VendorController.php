<?php
declare(strict_types=1);

class VendorController {

    // ------------------------------------------------------------------ index
    public function index(): void {
        Auth::requireAuth();

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

        $vendors = Database::fetchAll(
            "SELECT v.*
             FROM vendors v
             WHERE {$whereSQL}
             ORDER BY
               CASE v.risk_tier WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END,
               v.name ASC",
            $params
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
        Auth::requirePermission('vendor.write');
        require AEGIS_ROOT . '/views/vendor/create.php';
    }

    // --------------------------------------------------------------- create
    public function create(): void {
        Auth::requirePermission('vendor.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $name           = Security::sanitizeInput($_POST['name'] ?? '');
        $category       = Security::sanitizeInput($_POST['category'] ?? '');
        $website        = Security::sanitizeInput($_POST['website'] ?? '');
        $description    = Security::sanitizeInput($_POST['description'] ?? '');
        $riskTier       = Security::sanitizeInput($_POST['risk_tier'] ?? 'medium');
        $status         = Security::sanitizeInput($_POST['status'] ?? 'active');
        $country        = Security::sanitizeInput($_POST['country'] ?? '');
        $primaryContact = Security::sanitizeInput($_POST['primary_contact'] ?? '');
        $contactEmail   = Security::sanitizeInput($_POST['contact_email'] ?? '');
        $contractStart  = Security::sanitizeInput($_POST['contract_start'] ?? '');
        $contractEnd    = Security::sanitizeInput($_POST['contract_end'] ?? '');
        $dataAccess     = isset($_POST['data_access']) ? true : false;
        $criticalService = isset($_POST['critical_service']) ? true : false;

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

        // Generate vendor code: VEN-001 style
        $nextId     = (int)(Database::fetchOne("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM vendors")['next_id']);
        $vendorCode = 'VEN-' . str_pad((string)$nextId, 3, '0', STR_PAD_LEFT);

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
        Auth::requireAuth();
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

        $users = Database::fetchAll(
            "SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name"
        );

        require AEGIS_ROOT . '/views/vendor/view.php';
    }

    // --------------------------------------------------------------- update
    public function update(string $id): void {
        Auth::requirePermission('vendor.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id = (int)$id;

        $name           = Security::sanitizeInput($_POST['name'] ?? '');
        $category       = Security::sanitizeInput($_POST['category'] ?? '');
        $website        = Security::sanitizeInput($_POST['website'] ?? '');
        $description    = Security::sanitizeInput($_POST['description'] ?? '');
        $riskTier       = Security::sanitizeInput($_POST['risk_tier'] ?? 'medium');
        $status         = Security::sanitizeInput($_POST['status'] ?? 'active');
        $country        = Security::sanitizeInput($_POST['country'] ?? '');
        $primaryContact = Security::sanitizeInput($_POST['primary_contact'] ?? '');
        $contactEmail   = Security::sanitizeInput($_POST['contact_email'] ?? '');
        $contractStart  = Security::sanitizeInput($_POST['contract_start'] ?? '');
        $contractEnd    = Security::sanitizeInput($_POST['contract_end'] ?? '');
        $dataAccess     = isset($_POST['data_access']) ? true : false;
        $criticalService = isset($_POST['critical_service']) ? true : false;

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
            'updated_at'      => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        Auth::log('update_vendor', 'vendors', $id, ['status' => $status, 'risk_tier' => $riskTier]);

        $_SESSION['flash_success'] = 'Vendor updated successfully.';
        header('Location: /vendor/' . $id . '?saved=1');
    }

    // -------------------------------------------------------- addAssessment
    public function addAssessment(string $id): void {
        Auth::requirePermission('vendor.write');

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

    // --------------------------------------------------- updateAssessment
    public function updateAssessment(string $vendorId, string $assessId): void {
        Auth::requirePermission('vendor.write');

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
