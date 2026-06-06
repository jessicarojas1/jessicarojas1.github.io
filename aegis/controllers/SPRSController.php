<?php
declare(strict_types=1);

class SPRSController {

    public function index(): void {
        Auth::requirePermission('compliance.view');

        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, cp.version,
                    COALESCE(s.code,'CUSTOM') AS standard_code,
                    COALESCE(s.name, cp.name) AS standard_name,
                    COUNT(co.id) FILTER (WHERE co.level=2) AS total_controls,
                    COUNT(ci.id) FILTER (WHERE ci.status='compliant') AS compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status='partial') AS partial,
                    COUNT(ci.id) FILTER (WHERE ci.status='non_compliant') AS non_compliant
             FROM compliance_packages cp
             LEFT JOIN standards s ON s.id = cp.standard_id
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, s.code, s.name
             ORDER BY cp.name"
        );

        foreach ($packages as &$pkg) {
            $total         = (int)$pkg['total_controls'];
            $compliant     = (int)$pkg['compliant'];
            $partial       = (int)$pkg['partial'];
            $nonCompliant  = (int)$pkg['non_compliant'];
            $notAssessed   = $total - $compliant - $partial - $nonCompliant;

            $pkg['not_assessed'] = $notAssessed;
            $pkg['pct']          = $total > 0 ? round($compliant / $total * 100) : 0;

            // SPRS-style score (NIST 800-171 baseline 110, each failure deducts)
            $deductions         = ($nonCompliant * 1) + ($partial * 0.5) + ($notAssessed * 1);
            $pkg['sprs_score']  = round(110 - $deductions, 1);

            // Detect NIST 800-171 packages
            $code = strtoupper($pkg['standard_code'] ?? '');
            $name = strtoupper($pkg['name'] ?? '');
            $pkg['is_nist_171'] = str_contains($code, '171') || str_contains($code, 'NIST-171')
                                || str_contains($name, '800-171') || str_contains($name, 'CMMC');
        }
        unset($pkg);

        $nistPackages  = array_filter($packages, fn($p) => $p['is_nist_171']);
        $otherPackages = array_filter($packages, fn($p) => !$p['is_nist_171']);

        $pageTitle    = 'SPRS Score';
        $activeModule = 'sprs';
        $breadcrumbs  = [['SPRS Score', null]];
        ob_start();
        require AEGIS_ROOT . '/views/sprs/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }
}
