<?php
class MetricsController {
    public function index(): void {
        Auth::requireAuth();

        // Compliance trend — % compliant per package
        $complianceByPackage = Database::fetchAll(
            "SELECT cp.name,
               COUNT(co.id) AS total,
               COUNT(ci.id) FILTER (WHERE ci.status = 'compliant')     AS compliant,
               COUNT(ci.id) FILTER (WHERE ci.status = 'non_compliant') AS non_compliant,
               COUNT(ci.id) FILTER (WHERE ci.status = 'partial')       AS partial,
               COUNT(ci.id) FILTER (WHERE ci.status = 'not_started')   AS not_started,
               COUNT(ci.id) FILTER (WHERE ci.status = 'not_applicable')AS not_applicable
             FROM compliance_packages cp
             LEFT JOIN compliance_objectives co ON co.package_id = cp.id AND co.level = 2
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE cp.is_active = TRUE
             GROUP BY cp.id, cp.name
             ORDER BY cp.imported_at ASC"
        );

        // Risk by category
        $riskByCategory = Database::fetchAll(
            "SELECT rc.name, rc.color,
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE r.inherent_score > 14) AS critical,
               COUNT(*) FILTER (WHERE r.inherent_score BETWEEN 10 AND 14) AS high,
               COUNT(*) FILTER (WHERE r.inherent_score BETWEEN 5 AND 9)  AS medium,
               COUNT(*) FILTER (WHERE r.inherent_score <= 4)             AS low
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             WHERE r.status != 'closed'
             GROUP BY rc.id, rc.name, rc.color
             ORDER BY total DESC"
        );

        // Risk status breakdown
        $riskStatus = Database::fetchAll(
            "SELECT status, COUNT(*) AS count FROM risks GROUP BY status ORDER BY count DESC"
        );

        // Risk over time (last 12 months)
        $riskTrend = Database::fetchAll(
            "SELECT TO_CHAR(DATE_TRUNC('month', created_at), 'Mon YYYY') AS month,
               DATE_TRUNC('month', created_at) AS month_date,
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE inherent_score > 14) AS critical
             FROM risks
             WHERE created_at >= NOW() - INTERVAL '12 months'
             GROUP BY DATE_TRUNC('month', created_at)
             ORDER BY month_date ASC"
        );

        // Policy status
        $policyStatus = Database::fetchAll(
            "SELECT status, COUNT(*) AS count FROM policies GROUP BY status ORDER BY count DESC"
        );

        // Audit completion rate
        $auditStats = Database::fetchAll(
            "SELECT audit_type,
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE status = 'completed') AS completed,
               ROUND(AVG(score) FILTER (WHERE score IS NOT NULL), 1) AS avg_score
             FROM audits
             GROUP BY audit_type ORDER BY total DESC"
        );

        // Audit scores over time
        $auditTrend = Database::fetchAll(
            "SELECT TO_CHAR(completed_date, 'Mon YYYY') AS month,
               DATE_TRUNC('month', completed_date) AS month_date,
               ROUND(AVG(score), 1) AS avg_score, COUNT(*) AS count
             FROM audits
             WHERE status = 'completed' AND completed_date IS NOT NULL
               AND completed_date >= NOW() - INTERVAL '12 months'
             GROUP BY DATE_TRUNC('month', completed_date)
             ORDER BY month_date ASC"
        );

        // Controls by status across all packages
        $controlStatus = Database::fetchAll(
            "SELECT COALESCE(ci.status, 'not_started') AS status, COUNT(*) AS count
             FROM compliance_objectives co
             LEFT JOIN control_implementations ci ON ci.objective_id = co.id
             WHERE co.level = 2
             GROUP BY ci.status ORDER BY count DESC"
        );

        // Top 5 open risks by score
        $topRisks = Database::fetchAll(
            "SELECT r.risk_id, r.title, r.inherent_score, r.residual_score,
               rc.name AS category, rc.color
             FROM risks r
             LEFT JOIN risk_categories rc ON r.category_id = rc.id
             WHERE r.status = 'open'
             ORDER BY r.inherent_score DESC LIMIT 5"
        );

        // Overall KPIs
        $kpi = [
            'total_risks'      => Database::fetchOne("SELECT COUNT(*) AS c FROM risks")['c'] ?? 0,
            'open_risks'       => Database::fetchOne("SELECT COUNT(*) AS c FROM risks WHERE status='open'")['c'] ?? 0,
            'critical_risks'   => Database::fetchOne("SELECT COUNT(*) AS c FROM risks WHERE inherent_score>14 AND status='open'")['c'] ?? 0,
            'total_controls'   => Database::fetchOne("SELECT COUNT(*) AS c FROM compliance_objectives WHERE level=2")['c'] ?? 0,
            'compliant'        => Database::fetchOne("SELECT COUNT(*) AS c FROM control_implementations WHERE status='compliant'")['c'] ?? 0,
            'total_audits'     => Database::fetchOne("SELECT COUNT(*) AS c FROM audits")['c'] ?? 0,
            'completed_audits' => Database::fetchOne("SELECT COUNT(*) AS c FROM audits WHERE status='completed'")['c'] ?? 0,
            'total_policies'   => Database::fetchOne("SELECT COUNT(*) AS c FROM policies")['c'] ?? 0,
            'published_policies'=> Database::fetchOne("SELECT COUNT(*) AS c FROM policies WHERE status='published'")['c'] ?? 0,
        ];
        $kpi['compliance_pct'] = $kpi['total_controls']
            ? round($kpi['compliant'] / $kpi['total_controls'] * 100) : 0;
        $kpi['audit_completion'] = $kpi['total_audits']
            ? round($kpi['completed_audits'] / $kpi['total_audits'] * 100) : 0;

        require AEGIS_ROOT . '/views/metrics/index.php';
    }
}
