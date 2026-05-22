<?php
class DashboardController {
    public function index(): void {
        Auth::requireAuth();

        $stats = [
            'packages'     => Database::fetchOne("SELECT COUNT(*) as c FROM compliance_packages WHERE is_active = TRUE")['c'] ?? 0,
            'controls'     => Database::fetchOne("SELECT COUNT(*) as c FROM compliance_objectives WHERE level = 2")['c'] ?? 0,
            'compliant'    => Database::fetchOne("SELECT COUNT(*) as c FROM control_implementations WHERE status = 'compliant'")['c'] ?? 0,
            'open_risks'   => Database::fetchOne("SELECT COUNT(*) as c FROM risks WHERE status = 'open'")['c'] ?? 0,
            'policies'     => Database::fetchOne("SELECT COUNT(*) as c FROM policies")['c'] ?? 0,
            'audits_due'   => Database::fetchOne("SELECT COUNT(*) as c FROM audits WHERE status != 'completed' AND scheduled_date <= CURRENT_DATE + INTERVAL '30 days'")['c'] ?? 0,
            'reviews_due'  => Database::fetchOne("SELECT COUNT(*) as c FROM policies WHERE next_review_date <= CURRENT_DATE + INTERVAL '30 days' AND status = 'published'")['c'] ?? 0,
        ];

        $totalControls = (int)($stats['controls'] ?: 1);
        $stats['compliance_pct'] = round(($stats['compliant'] / $totalControls) * 100);

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

        // Due items widget — covers all overdue + due in 30 days across every module
        $dueItems = Database::fetchAll(
            "SELECT 'Policy Review' AS item_type, p.id, p.title AS name,
                    p.next_review_date AS due_date, u.name AS owner,
                    '/policy/' || p.id AS url
             FROM policies p
             LEFT JOIN users u ON p.owner_id = u.id
             WHERE p.next_review_date IS NOT NULL
               AND p.next_review_date <= CURRENT_DATE + INTERVAL '30 days'
               AND p.status NOT IN ('archived')

             UNION ALL

             SELECT 'Risk Review', r.id, r.title, r.review_date, u.name,
                    '/risk/' || r.id
             FROM risks r
             LEFT JOIN users u ON r.owner_id = u.id
             WHERE r.review_date IS NOT NULL
               AND r.review_date <= CURRENT_DATE + INTERVAL '30 days'
               AND r.status = 'open'

             UNION ALL

             SELECT 'Audit', a.id, a.name, a.scheduled_date, u.name,
                    '/audit/' || a.id
             FROM audits a
             LEFT JOIN users u ON a.auditor_id = u.id
             WHERE a.scheduled_date IS NOT NULL
               AND a.scheduled_date <= CURRENT_DATE + INTERVAL '30 days'
               AND a.status NOT IN ('completed')

             UNION ALL

             SELECT 'Control', ci.id,
                    co.code || ': ' || LEFT(co.title, 55),
                    ci.due_date, u.name,
                    '/compliance/' || co.package_id || '/objective/' || co.id
             FROM control_implementations ci
             JOIN compliance_objectives co ON ci.objective_id = co.id
             LEFT JOIN users u ON ci.assigned_to = u.id
             WHERE ci.due_date IS NOT NULL
               AND ci.due_date <= CURRENT_DATE + INTERVAL '30 days'
               AND ci.status NOT IN ('compliant','not_applicable')

             ORDER BY due_date ASC"
        );

        // Bucket by date distance
        $today   = new DateTimeImmutable('today');
        $d7      = new DateTimeImmutable('+7 days');
        $d30     = new DateTimeImmutable('+30 days');
        $expired = new DateTimeImmutable('-60 days');

        $dueBuckets = ['expired' => [], 'overdue' => [], 'due7' => [], 'due30' => []];
        foreach ($dueItems as $item) {
            $date = new DateTimeImmutable($item['due_date']);
            if ($date < $expired)      $dueBuckets['expired'][] = $item;
            elseif ($date < $today)    $dueBuckets['overdue'][] = $item;
            elseif ($date <= $d7)      $dueBuckets['due7'][]    = $item;
            else                       $dueBuckets['due30'][]   = $item;
        }

        require AEGIS_ROOT . '/views/dashboard/index.php';
    }

    public function markAlertRead(string $id): void {
        Auth::requireAuth();
        Database::query("UPDATE alerts SET is_read = TRUE, read_at = NOW() WHERE id = ? AND user_id = ?", [(int)$id, Auth::id()]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }
}
