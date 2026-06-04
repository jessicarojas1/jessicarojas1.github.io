<?php
class MetricsController {

    public function index(): void {
        Auth::requireAuth();

        // Current snapshot (latest)
        $snapshot = Database::fetchOne(
            "SELECT * FROM metrics_snapshots ORDER BY snapshot_date DESC LIMIT 1"
        );

        // Live KPI fallback — computed directly when no snapshot data exists
        $live = [];
        try {
            $ciStats = Database::fetchOne(
                "SELECT COUNT(*) as total,
                        COUNT(*) FILTER (WHERE status='compliant') as compliant,
                        COUNT(*) FILTER (WHERE status='partial') as partial
                 FROM control_implementations"
            );
            $livePolicies = Database::fetchOne(
                "SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE status='approved') as approved FROM policies"
            );
            $liveRisks = Database::fetchOne(
                "SELECT COUNT(*) as total,
                        COUNT(*) FILTER (WHERE inherent_score >= 15) as critical,
                        COUNT(*) FILTER (WHERE inherent_score BETWEEN 10 AND 14) as high
                 FROM risks WHERE status NOT IN ('closed','transferred')"
            );
            $liveIncidents = Database::fetchOne(
                "SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE status IN ('open','investigating')) as open
                 FROM incidents"
            );
            $liveAudits = Database::fetchOne(
                "SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE status='completed') as completed FROM audits"
            );

            $ciTotal      = (int)($ciStats['total'] ?? 0);
            $compPct      = $ciTotal > 0 ? round($ciStats['compliant'] / $ciTotal * 100, 1) : 0;
            $rTotal       = (int)($liveRisks['total'] ?? 0);
            $riskHealth   = $rTotal > 0
                ? round((1 - ($liveRisks['critical'] + $liveRisks['high']) / $rTotal) * 100, 1)
                : 100;
            $pTotal       = (int)($livePolicies['total'] ?? 0);
            $policyHealth = $pTotal > 0 ? round($livePolicies['approved'] / $pTotal * 100, 1) : 0;
            $grcScore     = $ciTotal > 0 ? round(($compPct + $riskHealth + $policyHealth) / 3, 1) : 0;

            $live = [
                'grc_score'      => $snapshot ? (float)$snapshot['grc_score']      : $grcScore,
                'compliance_pct' => $snapshot ? (float)$snapshot['compliance_pct'] : $compPct,
                'risk_health'    => $snapshot ? (float)$snapshot['risk_health']     : $riskHealth,
                'policy_health'  => $snapshot ? (float)$snapshot['policy_health']   : $policyHealth,
                'open_risks'     => $rTotal,
                'open_incidents' => (int)($liveIncidents['open'] ?? 0),
                'total_controls' => $ciTotal,
                'total_policies' => $pTotal,
                'total_audits'   => (int)($liveAudits['total'] ?? 0),
            ];
        } catch (Throwable) {
            $live = [];
        }

        // 90-day trend data for chart
        $trend = Database::fetchAll(
            "SELECT snapshot_date, grc_score, compliance_pct, risk_health, policy_health, audit_health,
                    open_risks, open_incidents, open_issues
             FROM metrics_snapshots
             WHERE snapshot_date >= CURRENT_DATE - INTERVAL '90 days'
             ORDER BY snapshot_date ASC"
        );

        // Per-framework compliance
        $frameworks = Database::fetchAll(
            "SELECT cp.name,
                    COUNT(co.id) as total_controls,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'compliant')     as compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'partial')       as partial,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant') as non_compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'not_applicable') as na
             FROM compliance_packages cp
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, cp.name ORDER BY cp.name"
        );

        // Risk distribution by score band
        $riskDist = Database::fetchAll(
            "SELECT
               COUNT(*) FILTER (WHERE inherent_score <= 4)               as low,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 5 AND 9)   as medium,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 10 AND 14) as high,
               COUNT(*) FILTER (WHERE inherent_score >= 15)             as critical
             FROM risks WHERE status NOT IN ('closed','transferred')"
        );

        // Policy review status
        $policyStatus = Database::fetchAll(
            "SELECT status, COUNT(*) as count FROM policies GROUP BY status ORDER BY status"
        );

        // Incident trend (last 12 months monthly)
        $incidentTrend = Database::fetchAll(
            "SELECT TO_CHAR(created_at, 'YYYY-MM') as month, COUNT(*) as count,
                    COUNT(*) FILTER (WHERE severity IN ('critical','high')) as critical_high
             FROM incidents
             WHERE created_at >= CURRENT_DATE - INTERVAL '12 months'
             GROUP BY TO_CHAR(created_at, 'YYYY-MM') ORDER BY 1"
        );

        // Scheduled reports
        $reportSchedules = Database::fetchAll(
            "SELECT * FROM report_schedules ORDER BY name"
        );

        $pageTitle    = 'Metrics & Trends';
        $activeModule = 'metrics';
        $breadcrumbs  = [['Metrics', null]];
        ob_start();
        require AEGIS_ROOT . '/views/metrics/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveSchedule(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $name       = Security::sanitizeInput($_POST['name'] ?? '');
        $reportType = in_array($_POST['report_type'] ?? '', ['compliance','executive','risk','audit'])
            ? $_POST['report_type'] : 'executive';
        $frequency  = in_array($_POST['frequency'] ?? '', ['daily','weekly','monthly','quarterly'])
            ? $_POST['frequency'] : 'weekly';
        $recipients = array_filter(array_map('trim', explode("\n", $_POST['recipients'] ?? '')));
        $isActive   = !empty($_POST['is_active']) ? 1 : 0;
        $dayOfWeek  = !empty($_POST['day_of_week'])  ? (int)$_POST['day_of_week']  : null;
        $dayOfMonth = !empty($_POST['day_of_month']) ? (int)$_POST['day_of_month'] : null;

        if (!$name || empty($recipients)) {
            $_SESSION['flash_error'] = 'Name and at least one recipient are required.';
            header('Location: /metrics'); exit;
        }

        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        if ($id) {
            Database::query(
                "UPDATE report_schedules SET name=?,report_type=?,frequency=?,recipients=?,
                 is_active=?,day_of_week=?,day_of_month=? WHERE id=?",
                [$name, $reportType, $frequency, json_encode(array_values($recipients)),
                 $isActive, $dayOfWeek, $dayOfMonth, $id]
            );
        } else {
            Database::query(
                "INSERT INTO report_schedules (name,report_type,frequency,recipients,is_active,day_of_week,day_of_month,created_by)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$name, $reportType, $frequency, json_encode(array_values($recipients)),
                 $isActive, $dayOfWeek, $dayOfMonth, Auth::id()]
            );
        }

        Auth::log('save_report_schedule', 'report_schedules', $id);
        $_SESSION['flash_success'] = 'Report schedule saved.';
        header('Location: /metrics'); exit;
    }

    public function deleteSchedule(string $id): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        Database::query("DELETE FROM report_schedules WHERE id = ?", [(int)$id]);
        Auth::log('delete_report_schedule', 'report_schedules', (int)$id);
        $_SESSION['flash_success'] = 'Schedule deleted.';
        header('Location: /metrics'); exit;
    }
}
