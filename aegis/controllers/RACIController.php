<?php
declare(strict_types=1);

class RACIController {

    public function index(): void {
        Auth::requirePermission('risk.view');
        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, COALESCE(s.code,'CUSTOM') AS standard_code,
                    COUNT(DISTINCT co.id) FILTER (WHERE co.level=1) AS domain_count,
                    COUNT(DISTINCT ra.id) AS assignment_count
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id
             LEFT JOIN raci_assignments ra ON ra.package_id = cp.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, s.code
             ORDER BY cp.name"
        );
        $pageTitle    = 'RACI Matrix';
        $activeModule = 'raci';
        $breadcrumbs  = [['RACI', null]];
        ob_start();
        require AEGIS_ROOT . '/views/raci/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function view(int $packageId): void {
        Auth::requirePermission('risk.view');
        $package = Database::fetchOne(
            "SELECT cp.*, COALESCE(s.code,'CUSTOM') AS standard_code
             FROM compliance_packages cp LEFT JOIN standards s ON s.id=cp.standard_id
             WHERE cp.id=?", [$packageId]
        );
        if (!$package) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $domains = Database::fetchAll(
            "SELECT id, code, title FROM compliance_objectives WHERE package_id=? AND level=1 ORDER BY sort_order, code",
            [$packageId]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");

        $assignments = Database::fetchAll(
            "SELECT objective_id, user_id, raci_role FROM raci_assignments WHERE package_id=?", [$packageId]
        );
        $assignMap = [];
        foreach ($assignments as $a) {
            $assignMap[$a['objective_id']][$a['user_id']][$a['raci_role']] = true;
        }

        $pageTitle    = 'RACI — ' . Security::h($package['name']);
        $activeModule = 'raci';
        $breadcrumbs  = [['RACI', '/raci'], [$package['name'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/raci/matrix.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function save(int $packageId): void {
        Auth::requirePermission('risk.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        Database::query("DELETE FROM raci_assignments WHERE package_id=?", [$packageId]);

        $raciData = $_POST['raci'] ?? [];
        foreach ($raciData as $domainId => $users) {
            foreach ($users as $userId => $roles) {
                foreach ((array)$roles as $role) {
                    if (!in_array($role, ['responsible','accountable','consulted','informed'], true)) continue;
                    try {
                        Database::insert('raci_assignments', [
                            'package_id'   => $packageId,
                            'objective_id' => (int)$domainId,
                            'user_id'      => (int)$userId,
                            'raci_role'    => $role,
                        ]);
                    } catch (Throwable) {}
                }
            }
        }

        Auth::log('raci_saved', 'compliance_packages', $packageId, []);
        $_SESSION['flash_success'] = 'RACI matrix saved.';
        header("Location: /raci/{$packageId}");
    }

    public function responsibilityMatrix(int $packageId): void {
        Auth::requirePermission('risk.view');
        $package = Database::fetchOne(
            "SELECT cp.*, COALESCE(s.code,'CUSTOM') AS standard_code
             FROM compliance_packages cp LEFT JOIN standards s ON s.id=cp.standard_id
             WHERE cp.id=?", [$packageId]
        );
        if (!$package) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $controls = Database::fetchAll(
            "SELECT co.id, co.code, co.title,
                    sr.responsibility, sr.provider_name, sr.customer_notes, sr.provider_notes
             FROM compliance_objectives co
             LEFT JOIN shared_responsibility sr ON sr.objective_id=co.id AND sr.package_id=?
             WHERE co.package_id=? AND co.level=2
             ORDER BY co.sort_order, co.code",
            [$packageId, $packageId]
        );

        $pageTitle    = 'Shared Responsibility — ' . Security::h($package['name']);
        $activeModule = 'raci';
        $breadcrumbs  = [['RACI', '/raci'], [$package['name'], '/raci/' . $packageId], ['Responsibility', null]];
        ob_start();
        require AEGIS_ROOT . '/views/raci/responsibility.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveResponsibility(int $packageId): void {
        Auth::requirePermission('risk.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $data = $_POST['srm'] ?? [];
        foreach ($data as $objectiveId => $fields) {
            $resp = in_array($fields['responsibility'] ?? '', ['customer','provider','shared'], true)
                ? $fields['responsibility'] : 'customer';
            $existing = Database::fetchOne(
                "SELECT id FROM shared_responsibility WHERE package_id=? AND objective_id=?",
                [$packageId, (int)$objectiveId]
            );
            if ($existing) {
                Database::query(
                    "UPDATE shared_responsibility SET responsibility=?, provider_name=?, customer_notes=?, provider_notes=? WHERE id=?",
                    [$resp, Security::sanitizeInput($fields['provider_name'] ?? ''),
                     Security::sanitizeInput($fields['customer_notes'] ?? ''),
                     Security::sanitizeInput($fields['provider_notes'] ?? ''), $existing['id']]
                );
            } else {
                Database::insert('shared_responsibility', [
                    'package_id'     => $packageId,
                    'objective_id'   => (int)$objectiveId,
                    'responsibility' => $resp,
                    'provider_name'  => Security::sanitizeInput($fields['provider_name'] ?? ''),
                    'customer_notes' => Security::sanitizeInput($fields['customer_notes'] ?? ''),
                    'provider_notes' => Security::sanitizeInput($fields['provider_notes'] ?? ''),
                ]);
            }
        }

        $_SESSION['flash_success'] = 'Responsibility matrix saved.';
        header("Location: /raci/{$packageId}/responsibility");
    }
}
