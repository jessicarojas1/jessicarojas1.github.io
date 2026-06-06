<?php
declare(strict_types=1);

class ODPController {

    public function index(): void {
        Auth::requirePermission('ssp.view');
        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, COALESCE(s.code,'CUSTOM') AS standard_code,
                    COUNT(odp.id) AS odp_count
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id
             LEFT JOIN odp_entries odp ON odp.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, s.code
             ORDER BY cp.name"
        );
        $pageTitle    = 'ODP Center';
        $activeModule = 'odp';
        $breadcrumbs  = [['ODP Center', null]];
        ob_start();
        require AEGIS_ROOT . '/views/odp/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function packageView(int $packageId): void {
        Auth::requirePermission('ssp.view');
        $package = Database::fetchOne(
            "SELECT cp.*, COALESCE(s.code,'CUSTOM') AS standard_code
             FROM compliance_packages cp LEFT JOIN standards s ON s.id=cp.standard_id
             WHERE cp.id=?", [$packageId]
        );
        if (!$package) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $controls = Database::fetchAll(
            "SELECT co.id, co.code, co.title,
                    json_agg(json_build_object(
                        'id', odp.id,
                        'parameter_name', odp.parameter_name,
                        'parameter_value', odp.parameter_value,
                        'notes', odp.notes,
                        'updated_at', odp.updated_at
                    ) ORDER BY odp.parameter_name) FILTER (WHERE odp.id IS NOT NULL) AS odps
             FROM compliance_objectives co
             LEFT JOIN odp_entries odp ON odp.objective_id = co.id
             WHERE co.package_id = ? AND co.level = 2
             GROUP BY co.id
             ORDER BY co.sort_order, co.code",
            [$packageId]
        );
        foreach ($controls as &$ctrl) {
            $ctrl['odps'] = $ctrl['odps'] ? json_decode($ctrl['odps'], true) : [];
        }
        unset($ctrl);

        $pageTitle    = 'ODP — ' . Security::h($package['name']);
        $activeModule = 'odp';
        $breadcrumbs  = [['ODP Center', '/odp'], [$package['name'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/odp/package.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function save(): void {
        Auth::requirePermission('ssp.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $objectiveId  = (int)($_POST['objective_id'] ?? 0);
        $paramName    = trim(Security::sanitizeInput($_POST['parameter_name'] ?? ''));
        $paramValue   = Security::sanitizeInput($_POST['parameter_value'] ?? '');
        $notes        = Security::sanitizeInput($_POST['notes'] ?? '');
        $packageId    = (int)($_POST['package_id'] ?? 0);

        if (!$objectiveId || !$paramName) {
            $_SESSION['flash_error'] = 'Objective and parameter name are required.';
            $ref = $_SERVER['HTTP_REFERER'] ?? '/odp';
            $parsed = parse_url($ref);
            $safePath = $parsed['path'] ?? '/odp';
            if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $safePath)) $safePath = '/odp';
            header('Location: ' . $safePath);
            return;
        }

        $existing = Database::fetchOne(
            "SELECT id FROM odp_entries WHERE objective_id=? AND parameter_name=?",
            [$objectiveId, $paramName]
        );
        if ($existing) {
            Database::query(
                "UPDATE odp_entries SET parameter_value=?, notes=?, updated_by=?, updated_at=NOW() WHERE id=?",
                [$paramValue, $notes, Auth::id(), $existing['id']]
            );
        } else {
            Database::insert('odp_entries', [
                'objective_id'   => $objectiveId,
                'parameter_name' => $paramName,
                'parameter_value'=> $paramValue,
                'notes'          => $notes,
                'updated_by'     => Auth::id(),
            ]);
        }

        $_SESSION['flash_success'] = 'ODP entry saved.';
        header('Location: /odp/package/' . $packageId);
    }
}
