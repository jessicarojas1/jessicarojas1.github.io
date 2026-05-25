<?php
class DashboardController {
    public function index(): void {
        Auth::requireAuth();

        $stats = [
            'packages'      => Database::fetchOne("SELECT COUNT(*) as c FROM compliance_packages WHERE is_active = TRUE")['c'] ?? 0,
            'controls'      => Database::fetchOne("SELECT COUNT(*) as c FROM compliance_objectives WHERE level = 2")['c'] ?? 0,
            'compliant'     => Database::fetchOne("SELECT COUNT(*) as c FROM control_implementations WHERE status = 'compliant'")['c'] ?? 0,
            'open_risks'    => Database::fetchOne("SELECT COUNT(*) as c FROM risks WHERE status = 'open'")['c'] ?? 0,
            'critical_risks'=> Database::fetchOne("SELECT COUNT(*) as c FROM risks WHERE status = 'open' AND inherent_score >= 20")['c'] ?? 0,
            'policies'      => Database::fetchOne("SELECT COUNT(*) as c FROM policies")['c'] ?? 0,
            'audits_due'    => Database::fetchOne("SELECT COUNT(*) as c FROM audits WHERE status != 'completed' AND scheduled_date <= CURRENT_DATE + INTERVAL '30 days'")['c'] ?? 0,
            'reviews_due'   => Database::fetchOne("SELECT COUNT(*) as c FROM policies WHERE next_review_date <= CURRENT_DATE + INTERVAL '30 days' AND status = 'published'")['c'] ?? 0,
            'pending_approvals' => Database::fetchOne(
                "SELECT COUNT(*) as c FROM approval_requests ar
                 JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
                 WHERE ar.status = 'pending' AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')",
                [Auth::id(), Auth::role(), Auth::role()]
            )['c'] ?? 0,
        ];

        // New module stats — safe try/catch for environments running older migrations
        foreach ([
            'open_incidents'  => "SELECT COUNT(*) as c FROM incidents WHERE status NOT IN ('resolved','closed')",
            'open_changes'    => "SELECT COUNT(*) as c FROM change_requests WHERE status NOT IN ('implemented','closed','rejected')",
            'active_bcp'      => "SELECT COUNT(*) as c FROM bcp_plans WHERE status = 'active'",
            'total_assets'    => "SELECT COUNT(*) as c FROM assets WHERE status = 'active'",
            'critical_assets' => "SELECT COUNT(*) as c FROM assets WHERE criticality = 'critical' AND status = 'active'",
            'pending_questionnaires' => "SELECT COUNT(*) as c FROM questionnaire_assignments WHERE status = 'pending' AND assigned_to = " . (int)Auth::id(),
            'webhook_failures_24h'   => "SELECT COUNT(*) as c FROM webhook_deliveries WHERE status = 'failed' AND created_at >= NOW() - INTERVAL '24 hours'",
        ] as $key => $sql) {
            try { $stats[$key] = (int)(Database::fetchOne($sql)['c'] ?? 0); }
            catch (Throwable) { $stats[$key] = 0; }
        }

        $totalControls = (int)($stats['controls'] ?: 1);
        $stats['compliance_pct'] = round(($stats['compliant'] / $totalControls) * 100);

        // GRC score from latest daily snapshot
        try {
            $snap = Database::fetchOne("SELECT grc_score FROM metrics_snapshots ORDER BY snapshot_date DESC LIMIT 1");
            $stats['grc_score'] = (int)($snap['grc_score'] ?? $stats['compliance_pct']);
        } catch (Throwable) {
            $stats['grc_score'] = $stats['compliance_pct'];
        }

        $recentRisks = Database::fetchAll(
            "SELECT r.*, rc.name as category_name, rc.color as category_color, u.name as owner_name
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             LEFT JOIN users u ON r.owner_id = u.id
             ORDER BY r.created_at DESC LIMIT 5"
        );

        $upcomingAudits = Database::fetchAll(
            "SELECT a.*, cp.name as package_name, u.name as auditor_name
             FROM audits a
             LEFT JOIN compliance_packages cp ON a.package_id = cp.id
             LEFT JOIN users u ON a.auditor_id = u.id
             WHERE a.status != 'completed'
             ORDER BY a.scheduled_date ASC LIMIT 5"
        );

        $policyReviews = Database::fetchAll(
            "SELECT p.id, p.title, p.next_review_date, p.status, u.name as owner_name
             FROM policies p
             LEFT JOIN users u ON p.owner_id = u.id
             WHERE p.next_review_date IS NOT NULL AND p.status = 'published'
             ORDER BY p.next_review_date ASC LIMIT 5"
        );

        $riskMatrix = Database::fetchOne("SELECT * FROM risk_matrix_config WHERE is_active = TRUE ORDER BY id LIMIT 1");

        $riskDistribution = Database::fetchAll(
            "SELECT
               CASE WHEN inherent_score <= 4 THEN 'Low'
                    WHEN inherent_score <= 9 THEN 'Medium'
                    WHEN inherent_score <= 14 THEN 'High'
                    ELSE 'Critical' END as level,
               COUNT(*) as count
             FROM risks WHERE status = 'open'
             GROUP BY level"
        );

        $complianceByPackage = Database::fetchAll(
            "SELECT cp.name, cp.id,
               COUNT(co.id) as total,
               COUNT(ci.id) FILTER (WHERE ci.status = 'compliant') as compliant
             FROM compliance_packages cp
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, cp.name
             ORDER BY cp.imported_at ASC"
        );

        $alerts = Database::fetchAll(
            "SELECT * FROM alerts WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC LIMIT 10",
            [Auth::id()]
        );

        $activityLog = Database::fetchAll(
            "SELECT al.*, u.name as user_name FROM activity_log al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC LIMIT 10"
        );

        $recentChanges = [];
        $openIncidents = [];
        try {
            $recentChanges = Database::fetchAll(
                "SELECT cr.id, cr.title, cr.status, cr.change_type, cr.risk_level, u.name as submitter_name
                 FROM change_requests cr LEFT JOIN users u ON u.id = cr.submitter_id
                 WHERE cr.status NOT IN ('implemented','closed','rejected')
                 ORDER BY cr.created_at DESC LIMIT 5"
            );
            $openIncidents = Database::fetchAll(
                "SELECT id, title, severity, status, created_at FROM incidents
                 WHERE status NOT IN ('resolved','closed')
                 ORDER BY created_at DESC LIMIT 5"
            );
        } catch (Throwable) {}

        require AEGIS_ROOT . '/views/dashboard/index.php';
    }

    public function markAlertRead(string $id): void {
        Auth::requireAuth();
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!Security::validateCsrf($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
            return;
        }
        Database::query("UPDATE alerts SET is_read = TRUE, read_at = NOW() WHERE id = ? AND user_id = ?", [(int)$id, Auth::id()]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }
}
