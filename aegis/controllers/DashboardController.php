<?php
class DashboardController {
    public function index(): void {
        Auth::requireAuth();

        // Org-wide counts — user-independent and tenant-scoped, so safely cached
        // for a short TTL (Cache is tenant-namespaced; per-user queries below are
        // deliberately left uncached). See TECH_DEBT.md TD-6.
        $stats = Cache::remember('dash:stats:core', Cache::DEFAULT_TTL, fn() => [
            'packages'      => Database::fetchOne("SELECT COUNT(*) as c FROM compliance_packages WHERE is_active = TRUE")['c'] ?? 0,
            'controls'      => Database::fetchOne("SELECT COUNT(*) as c FROM compliance_objectives WHERE level = 2")['c'] ?? 0,
            'compliant'     => Database::fetchOne("SELECT COUNT(*) as c FROM control_implementations WHERE status = 'compliant'")['c'] ?? 0,
            'open_risks'    => Database::fetchOne("SELECT COUNT(*) as c FROM risks WHERE status = 'open'")['c'] ?? 0,
            'critical_risks'=> Database::fetchOne("SELECT COUNT(*) as c FROM risks WHERE status = 'open' AND inherent_score >= 20")['c'] ?? 0,
            'policies'      => Database::fetchOne("SELECT COUNT(*) as c FROM policies")['c'] ?? 0,
            'audits_due'    => Database::fetchOne("SELECT COUNT(*) as c FROM audits WHERE status != 'completed' AND scheduled_date <= CURRENT_DATE + INTERVAL '30 days'")['c'] ?? 0,
            'reviews_due'   => Database::fetchOne("SELECT COUNT(*) as c FROM policies WHERE next_review_date <= CURRENT_DATE + INTERVAL '30 days' AND status = 'published'")['c'] ?? 0,
        ]);
        // approval_requests lives in the enterprise phase1 migration — guard in case not yet applied
        // (per-user: depends on Auth::id()/role() — NOT cached under the tenant key)
        try {
            $stats['pending_approvals'] = (int)(Database::fetchOne(
                "SELECT COUNT(*) as c FROM approval_requests ar
                 JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
                 WHERE ar.status = 'pending' AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')",
                [Auth::id(), Auth::role(), Auth::role()]
            )['c'] ?? 0);
        } catch (Throwable) {
            $stats['pending_approvals'] = 0;
        }

        // New module stats — safe try/catch for environments running older migrations
        $moduleQueries = [
            'active_bcp'      => ["SELECT COUNT(*) as c FROM bcp_plans WHERE status = 'active'", []],
            'total_assets'    => ["SELECT COUNT(*) as c FROM assets WHERE status = 'active'", []],
            'critical_assets' => ["SELECT COUNT(*) as c FROM assets WHERE criticality = 'critical' AND status = 'active'", []],
            'pending_questionnaires' => ["SELECT COUNT(*) as c FROM questionnaire_assignments WHERE status = 'pending' AND assigned_to = ?", [Auth::id()]],
            'webhook_failures_24h'   => ["SELECT COUNT(*) as c FROM webhook_deliveries WHERE status = 'failed' AND created_at >= NOW() - INTERVAL '24 hours'", []],
        ];
        foreach ($moduleQueries as $key => [$sql, $params]) {
            try { $stats[$key] = (int)(Database::fetchOne($sql, $params)['c'] ?? 0); }
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

        // Heavy org-wide GROUP BY aggregates — cached per tenant for a short TTL.
        $riskDistribution = Cache::remember('dash:risk_distribution', Cache::DEFAULT_TTL, fn() => Database::fetchAll(
            "SELECT
               CASE WHEN inherent_score <= 4 THEN 'Low'
                    WHEN inherent_score <= 9 THEN 'Medium'
                    WHEN inherent_score <= 14 THEN 'High'
                    ELSE 'Critical' END as level,
               COUNT(*) as count
             FROM risks WHERE status = 'open'
             GROUP BY level"
        ));

        $complianceByPackage = Cache::remember('dash:compliance_by_package', Cache::DEFAULT_TTL, fn() => Database::fetchAll(
            "SELECT cp.name, cp.id,
               COUNT(co.id) as total,
               COUNT(ci.id) FILTER (WHERE ci.status = 'compliant') as compliant
             FROM compliance_packages cp
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, cp.name
             ORDER BY cp.imported_at ASC"
        ));

        $alerts = Database::fetchAll(
            "SELECT * FROM alerts WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC LIMIT 10",
            [Auth::id()]
        );

        // Due-items widget — policies and audits bucketed by urgency
        $dueBuckets = ['expired' => [], 'overdue' => [], 'due7' => [], 'due30' => []];
        try {
            $dueItems = Database::fetchAll(
                "SELECT due_date, item_type, url, name, owner FROM (
                   SELECT p.next_review_date AS due_date,
                          'Policy Review'    AS item_type,
                          '/policy/' || p.id AS url,
                          p.title            AS name,
                          u.name             AS owner
                   FROM policies p
                   LEFT JOIN users u ON p.owner_id = u.id
                   WHERE p.status = 'published'
                     AND p.next_review_date IS NOT NULL
                     AND p.next_review_date <= CURRENT_DATE + INTERVAL '30 days'
                   UNION ALL
                   SELECT a.scheduled_date   AS due_date,
                          'Audit'            AS item_type,
                          '/audit/' || a.id  AS url,
                          a.name             AS name,
                          u.name             AS owner
                   FROM audits a
                   LEFT JOIN users u ON a.auditor_id = u.id
                   WHERE a.status != 'completed'
                     AND a.scheduled_date IS NOT NULL
                     AND a.scheduled_date <= CURRENT_DATE + INTERVAL '30 days'
                 ) t
                 ORDER BY due_date ASC"
            );
            $today = new DateTimeImmutable('today');
            foreach ($dueItems as $item) {
                $d = new DateTimeImmutable($item['due_date']);
                $diff = (int)$today->diff($d)->format('%r%a');
                if ($diff < -30)     $dueBuckets['expired'][] = $item;
                elseif ($diff < 0)   $dueBuckets['overdue'][] = $item;
                elseif ($diff <= 7)  $dueBuckets['due7'][]    = $item;
                else                 $dueBuckets['due30'][]   = $item;
            }
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
