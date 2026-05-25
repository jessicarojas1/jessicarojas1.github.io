#!/usr/bin/env php
<?php
/**
 * Captures a daily GRC metrics snapshot for trend analysis.
 * Run once per day via cron:
 *   0 1 * * * php /var/www/aegis/scripts/capture_metrics_snapshot.php >> /var/log/aegis-metrics.log 2>&1
 */
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('AEGIS_ROOT', dirname(__DIR__));
foreach (['.env.local', '.env'] as $f) {
    if (file_exists(AEGIS_ROOT . '/' . $f)) {
        foreach (file(AEGIS_ROOT . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}
require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

$today = date('Y-m-d');

// Avoid duplicate snapshots
$existing = Database::fetchOne("SELECT id FROM metrics_snapshots WHERE snapshot_date = ?", [$today]);
if ($existing) {
    echo "[{$today}] Snapshot already captured for today — skipping.\n";
    exit(0);
}

// Compliance %
$compRow = Database::fetchOne(
    "SELECT COUNT(*) FILTER (WHERE ci.status = 'compliant') as compliant,
            COUNT(*) FILTER (WHERE co.level = 2) as total
     FROM compliance_objectives co
     LEFT JOIN control_implementations ci ON ci.objective_id = co.id
     JOIN compliance_packages cp ON co.package_id = cp.id WHERE cp.is_active = TRUE AND co.level = 2"
);
$compPct = $compRow['total'] > 0 ? round(($compRow['compliant'] / $compRow['total']) * 100, 2) : 0;

// Risk health (fewer critical/high = better)
$riskRow = Database::fetchOne(
    "SELECT COUNT(*) as total,
            COUNT(*) FILTER (WHERE inherent_score >= 15) as critical_high
     FROM risks WHERE status NOT IN ('closed','transferred')"
);
$riskHealth = $riskRow['total'] > 0
    ? round((1 - $riskRow['critical_high'] / $riskRow['total']) * 100, 2)
    : 100;

// Policy health
$policyRow = Database::fetchOne(
    "SELECT COUNT(*) as total, COUNT(*) FILTER (WHERE status = 'published') as published FROM policies"
);
$policyHealth = $policyRow['total'] > 0
    ? round(($policyRow['published'] / $policyRow['total']) * 100, 2)
    : 100;

// Audit health (% completed in last 90 days)
$auditRow = Database::fetchOne(
    "SELECT COUNT(*) as total,
            COUNT(*) FILTER (WHERE status = 'completed' AND completed_date >= CURRENT_DATE - INTERVAL '90 days') as completed
     FROM audits WHERE scheduled_date >= CURRENT_DATE - INTERVAL '90 days'"
);
$auditHealth = $auditRow['total'] > 0
    ? round(($auditRow['completed'] / $auditRow['total']) * 100, 2)
    : 100;

// GRC score (weighted)
$grcScore = round(($compPct * 0.40) + ($riskHealth * 0.30) + ($policyHealth * 0.20) + ($auditHealth * 0.10), 2);

// Open incidents
$incRow = Database::fetchOne(
    "SELECT COUNT(*) as open,
            COUNT(*) FILTER (WHERE severity IN ('critical','high')) as critical
     FROM incidents WHERE status NOT IN ('resolved','closed')"
);

// Open issues
$issueRow = Database::fetchOne("SELECT COUNT(*) as open FROM issues WHERE status NOT IN ('resolved','closed','wont_fix')");

// Overdue reviews (policies past next_review_date)
$overdueRow = Database::fetchOne(
    "SELECT COUNT(*) as c FROM policies WHERE status = 'published' AND next_review_date < CURRENT_DATE"
);

// Vendor count
$vendorRow = Database::fetchOne("SELECT COUNT(*) as c FROM vendors WHERE status = 'active'");

// Active audits
$activeAuditRow = Database::fetchOne("SELECT COUNT(*) as c FROM audits WHERE status = 'in_progress'");

$details = [
    'compliant_controls' => $compRow['compliant'],
    'total_controls'     => $compRow['total'],
    'total_risks'        => $riskRow['total'],
    'published_policies' => $policyRow['published'],
    'total_policies'     => $policyRow['total'],
];

Database::query(
    "INSERT INTO metrics_snapshots
     (snapshot_date, compliance_pct, risk_health, policy_health, audit_health, grc_score,
      open_risks, critical_risks, open_incidents, critical_incidents, open_issues,
      overdue_reviews, vendor_count, active_audits, details)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT (snapshot_date) DO UPDATE SET
       compliance_pct = EXCLUDED.compliance_pct, risk_health = EXCLUDED.risk_health,
       policy_health = EXCLUDED.policy_health, audit_health = EXCLUDED.audit_health,
       grc_score = EXCLUDED.grc_score, open_risks = EXCLUDED.open_risks,
       critical_risks = EXCLUDED.critical_risks, open_incidents = EXCLUDED.open_incidents,
       critical_incidents = EXCLUDED.critical_incidents, open_issues = EXCLUDED.open_issues,
       overdue_reviews = EXCLUDED.overdue_reviews, vendor_count = EXCLUDED.vendor_count,
       active_audits = EXCLUDED.active_audits, details = EXCLUDED.details",
    [
        $today, $compPct, $riskHealth, $policyHealth, $auditHealth, $grcScore,
        $riskRow['total'], $riskRow['critical_high'],
        $incRow['open'], $incRow['critical'],
        $issueRow['open'], $overdueRow['c'],
        $vendorRow['c'], $activeAuditRow['c'],
        json_encode($details),
    ]
);

echo "[{$today}] Snapshot captured — GRC Score: {$grcScore} | Compliance: {$compPct}% | Risk Health: {$riskHealth}%\n";
