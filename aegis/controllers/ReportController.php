<?php
declare(strict_types=1);

class ReportController {

    public function index(): void {
        Auth::requirePermission('report.view');
        $pageTitle    = 'Reports';
        $activeModule = 'report';
        $breadcrumbs  = [['Reports', null]];
        require AEGIS_ROOT . '/views/report/index.php';
    }

    public function compliance(): void {
        Auth::requirePermission('report.view');

        $packages = Database::fetchAll(
            "SELECT cp.id, cp.name, cp.objectives_count,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'compliant')      AS compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'partial')        AS partial,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant')  AS non_compliant,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'not_started')    AS not_started,
                    COUNT(ci.id) FILTER (WHERE ci.status = 'not_applicable') AS not_applicable
             FROM compliance_packages cp
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, cp.name, cp.objectives_count
             ORDER BY cp.name"
        );

        $nonCompliantItems = Database::fetchAll(
            "SELECT co.code, co.title, cp.name AS package_name,
                    u.name AS assigned_to_name, ci.due_date, ci.status
             FROM control_implementations ci
             JOIN compliance_objectives co ON co.id = ci.objective_id
             JOIN compliance_packages cp ON cp.id = co.package_id
             LEFT JOIN users u ON u.id = ci.assigned_to
             WHERE ci.status = 'non_compliant'
             ORDER BY co.code
             LIMIT 50"
        );

        $recentChanges = Database::fetchAll(
            "SELECT al.action, al.entity_type, al.entity_id, al.created_at,
                    u.name AS user_name
             FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.entity_type IN ('control','compliance')
             ORDER BY al.created_at DESC
             LIMIT 10"
        );

        $totalControls   = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM control_implementations")['c'] ?? 0);
        $compliantCount  = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM control_implementations WHERE status = 'compliant'")['c'] ?? 0);
        $overallPct      = $totalControls > 0 ? round($compliantCount / $totalControls * 100) : 0;

        $generatedAt = date('Y-m-d H:i:s');
        $generatedBy = Auth::user()['name'];
        $orgName     = Database::fetchOne("SELECT value FROM settings WHERE key = 'org_name'")['value'] ?? 'Organization';

        $pageTitle    = 'Compliance Status Report';
        $activeModule = 'report';
        $breadcrumbs  = [['Reports', '/report'], ['Compliance', null]];
        require AEGIS_ROOT . '/views/report/compliance.php';
    }

    public function executive(): void {
        Auth::requirePermission('report.view');

        // Compliance health
        $totalCtrl      = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM control_implementations")['c'] ?? 0);
        $compliantCtrl  = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM control_implementations WHERE status = 'compliant'")['c'] ?? 0);
        $compliancePct  = $totalCtrl > 0 ? round($compliantCtrl / $totalCtrl * 100) : 0;

        // Risk health (fewer high/critical = better)
        $totalRisks     = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM risks WHERE status NOT IN ('closed','accepted')")['c'] ?? 0);
        $critRisks      = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM risks WHERE inherent_score >= 20 AND status NOT IN ('closed','accepted')")['c'] ?? 0);
        $highRisks      = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM risks WHERE inherent_score BETWEEN 15 AND 19 AND status NOT IN ('closed','accepted')")['c'] ?? 0);
        $riskHealth     = $totalRisks > 0 ? max(0, round(100 - (($critRisks * 20 + $highRisks * 10) / $totalRisks * 100))) : 100;

        // Policy health
        $totalPolicies   = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM policies")['c'] ?? 0);
        $publishedPols   = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM policies WHERE status = 'published'")['c'] ?? 0);
        $policyHealth    = $totalPolicies > 0 ? round($publishedPols / $totalPolicies * 100) : 0;

        // Audit health (last 90 days)
        $totalAudits    = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM audits WHERE scheduled_date >= NOW() - INTERVAL '90 days'")['c'] ?? 0);
        $completedAudits = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM audits WHERE status = 'completed' AND scheduled_date >= NOW() - INTERVAL '90 days'")['c'] ?? 0);
        $auditHealth    = $totalAudits > 0 ? round($completedAudits / $totalAudits * 100) : 100;

        $grcScore = (int)round($compliancePct * 0.4 + $riskHealth * 0.3 + $policyHealth * 0.2 + $auditHealth * 0.1);

        // Incidents (may not exist yet)
        $openIncidents = 0;
        try {
            $openIncidents = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM incidents WHERE status NOT IN ('resolved','closed')")['c'] ?? 0);
        } catch (Exception) {}

        $upcomingAudits = (int)(Database::fetchOne(
            "SELECT COUNT(*) AS c FROM audits WHERE status IN ('planned','in_progress') AND scheduled_date <= NOW() + INTERVAL '30 days'"
        )['c'] ?? 0);

        $topRisks = Database::fetchAll(
            "SELECT r.title, r.inherent_score, r.status, rc.name AS category_name
             FROM risks r LEFT JOIN risk_categories rc ON rc.id = r.category_id
             WHERE r.status NOT IN ('closed')
             ORDER BY r.inherent_score DESC LIMIT 5"
        );

        $reviewsDue = Database::fetchAll(
            "SELECT p.title, p.next_review_date, u.name AS owner_name
             FROM policies p LEFT JOIN users u ON u.id = p.owner_id
             WHERE p.status = 'published' AND p.next_review_date IS NOT NULL
               AND p.next_review_date <= NOW() + INTERVAL '30 days'
             ORDER BY p.next_review_date ASC LIMIT 5"
        );

        $generatedAt  = date('Y-m-d H:i:s');
        $generatedBy  = Auth::user()['name'];
        $orgName      = Database::fetchOne("SELECT value FROM settings WHERE key = 'org_name'")['value'] ?? 'Organization';

        $pageTitle    = 'Executive Summary Report';
        $activeModule = 'report';
        $breadcrumbs  = [['Reports', '/report'], ['Executive Summary', null]];
        require AEGIS_ROOT . '/views/report/executive.php';
    }

    public function board(): void {
        Auth::requirePermission('report.view');

        // Date range: default last 90 days
        $asOf = date('Y-m-d');

        // Executive summary stats
        $riskSummary = Database::fetchOne("SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE inherent_score > 14 AND status NOT IN ('closed','transferred')) AS critical,
            COUNT(*) FILTER (WHERE inherent_score BETWEEN 10 AND 14 AND status NOT IN ('closed','transferred')) AS high,
            COUNT(*) FILTER (WHERE status = 'accepted') AS accepted,
            COUNT(*) FILTER (WHERE assessment_status = 'approved') AS approved,
            COUNT(*) FILTER (WHERE review_date < CURRENT_DATE AND status NOT IN ('closed','transferred','accepted')) AS overdue_review
        FROM risks");

        // Top risks (critical + high, open)
        $topRisks = Database::fetchAll("SELECT r.*, rc.name AS category_name, u.name AS owner_name
            FROM risks r LEFT JOIN risk_categories rc ON rc.id=r.category_id LEFT JOIN users u ON u.id=r.owner_id
            WHERE r.status NOT IN ('closed','transferred') AND r.inherent_score >= 10
            ORDER BY r.inherent_score DESC LIMIT 10");

        // Compliance summary per package
        $compliance = Database::fetchAll("SELECT cp.name, s.name AS standard,
            COUNT(ci.id) AS total_controls,
            COUNT(ci.id) FILTER (WHERE ci.status='compliant') AS compliant,
            COUNT(ci.id) FILTER (WHERE ci.status='non_compliant') AS non_compliant,
            ROUND(COUNT(ci.id) FILTER (WHERE ci.status='compliant')::numeric / NULLIF(COUNT(ci.id),0) * 100) AS pct
            FROM compliance_packages cp
            LEFT JOIN standards s ON s.id = cp.standard_id
            LEFT JOIN compliance_objectives co ON co.package_id=cp.id
            LEFT JOIN control_implementations ci ON ci.objective_id=co.id
            GROUP BY cp.id, cp.name, s.name ORDER BY pct DESC");

        // Risk trend last 12 weeks
        $riskTrend = Database::fetchAll("SELECT DATE_TRUNC('week',created_at) AS week,
            ROUND(AVG(score),1) AS avg_score, COUNT(DISTINCT risk_id) AS count
            FROM risk_score_history WHERE created_at >= NOW()-INTERVAL '12 weeks'
            GROUP BY DATE_TRUNC('week',created_at) ORDER BY week");

        // Incident summary
        $incidentSummary = Database::fetchOne("SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status NOT IN ('resolved','closed')) AS open,
            COUNT(*) FILTER (WHERE severity IN ('critical','high')) AS high_severity,
            COUNT(*) FILTER (WHERE created_at >= NOW()-INTERVAL '30 days') AS last_30_days
            FROM incidents");

        // Upcoming reviews
        $upcomingReviews = Database::fetchAll("SELECT r.title, r.risk_id, r.inherent_score, r.review_date, u.name AS owner_name
            FROM risks r LEFT JOIN users u ON u.id=r.owner_id
            WHERE r.review_date BETWEEN CURRENT_DATE AND CURRENT_DATE+30 AND r.status NOT IN ('closed','transferred')
            ORDER BY r.review_date LIMIT 8");

        // Risk appetite breaches
        $appetiteBreaches = Database::fetchAll("SELECT r.title, r.risk_id, r.inherent_score, ra.max_score, ra.appetite, rc.name AS category_name
            FROM risks r JOIN risk_categories rc ON rc.id=r.category_id
            JOIN risk_appetite ra ON ra.category=rc.name
            WHERE ra.max_score IS NOT NULL AND r.inherent_score > ra.max_score
            AND r.status NOT IN ('closed','transferred') ORDER BY (r.inherent_score-ra.max_score) DESC LIMIT 6");

        // Treatment action backlog
        $treatmentBacklog = Database::fetchOne("SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status='planned') AS planned,
            COUNT(*) FILTER (WHERE status='in_progress') AS in_progress,
            COUNT(*) FILTER (WHERE due_date < CURRENT_DATE AND status NOT IN ('completed','cancelled')) AS overdue
            FROM risk_treatments");

        // KRI health
        $kriHealth = Database::fetchAll("SELECT k.title, k.unit, k.threshold_red, k.threshold_amber,
            kv.value AS latest_value, kv.recorded_at AS latest_date, r.title AS risk_title
            FROM kris k LEFT JOIN risks r ON r.id=k.linked_risk_id
            LEFT JOIN kri_values kv ON kv.id=(SELECT id FROM kri_values WHERE kri_id=k.id ORDER BY recorded_at DESC LIMIT 1)
            WHERE k.is_active=TRUE ORDER BY k.title");

        $reportDate = date('F j, Y');
        $orgName = Database::fetchOne("SELECT value FROM settings WHERE key='org_name'")['value'] ?? 'Organisation';

        $pageTitle    = 'Board Pack';
        $activeModule = 'reports';
        $breadcrumbs  = [['Reports', '/report'], ['Board Pack', null]];
        ob_start();
        require AEGIS_ROOT . '/views/report/board.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function riskDetail(): void {
        Auth::requirePermission('report.view');
        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $level  = Security::sanitizeInput($_GET['level'] ?? '');

        $where = ['r.status NOT IN (\'closed\',\'transferred\')'];
        $params = [];
        if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
        if ($level === 'critical') { $where[] = 'r.inherent_score > 14'; }
        elseif ($level === 'high') { $where[] = 'r.inherent_score BETWEEN 10 AND 14'; }

        $risks = Database::fetchAll(
            "SELECT r.*, rc.name AS category_name, u.name AS owner_name,
             (SELECT COUNT(*) FROM risk_treatments WHERE risk_id=r.id AND status NOT IN ('completed','cancelled')) AS open_treatments,
             (SELECT COUNT(*) FROM risk_control_links WHERE risk_id=r.id) AS control_count
             FROM risks r LEFT JOIN risk_categories rc ON rc.id=r.category_id LEFT JOIN users u ON u.id=r.owner_id
             WHERE " . implode(' AND ',$where) . " ORDER BY r.inherent_score DESC", $params
        );

        $pageTitle    = 'Risk Register Report';
        $activeModule = 'reports';
        $breadcrumbs  = [['Reports','/report'],['Risk Register Report',null]];
        ob_start();
        require AEGIS_ROOT . '/views/report/risk_detail.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function risk(): void {
        Auth::requirePermission('report.view');

        $risks = Database::fetchAll(
            "SELECT r.*, rc.name AS category_name, u.name AS owner_name,
                    (SELECT COUNT(*) FROM risk_treatments rt WHERE rt.risk_id = r.id) AS treatment_count
             FROM risks r
             LEFT JOIN risk_categories rc ON rc.id = r.category_id
             LEFT JOIN users u ON u.id = r.owner_id
             ORDER BY r.inherent_score DESC, r.created_at DESC"
        );

        $stats = Database::fetchOne(
            "SELECT
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE inherent_score >= 20) AS critical,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 15 AND 19) AS high,
               COUNT(*) FILTER (WHERE inherent_score BETWEEN 8 AND 14)  AS medium,
               COUNT(*) FILTER (WHERE inherent_score < 8)               AS low
             FROM risks"
        );

        $byCategory = Database::fetchAll(
            "SELECT rc.name, COUNT(r.id) AS count, AVG(r.inherent_score) AS avg_score
             FROM risk_categories rc
             LEFT JOIN risks r ON r.category_id = rc.id
             GROUP BY rc.name ORDER BY count DESC"
        );

        $openTreatments = Database::fetchAll(
            "SELECT rt.*, r.title AS risk_title, u.name AS owner_name
             FROM risk_treatments rt
             JOIN risks r ON r.id = rt.risk_id
             LEFT JOIN users u ON u.id = rt.owner_id
             WHERE rt.status NOT IN ('completed','cancelled')
             ORDER BY rt.due_date ASC NULLS LAST"
        );

        $generatedAt  = date('Y-m-d H:i:s');
        $generatedBy  = Auth::user()['name'];
        $orgName      = Database::fetchOne("SELECT value FROM settings WHERE key = 'org_name'")['value'] ?? 'Organization';

        $pageTitle    = 'Risk Register Report';
        $activeModule = 'report';
        $breadcrumbs  = [['Reports', '/report'], ['Risk Register', null]];
        require AEGIS_ROOT . '/views/report/risk.php';
    }
}
