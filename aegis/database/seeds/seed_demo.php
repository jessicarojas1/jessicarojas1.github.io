#!/usr/bin/env php
<?php
/**
 * Seeds comprehensive demo operational data for executive presentations.
 * Creates realistic data across all AEGIS modules:
 *   users, risk categories, risks, vendors, incidents, policies,
 *   audits, assets, KRIs, POA&Ms, issues, and compliance control
 *   implementations (requires frameworks already seeded).
 *
 * Usage:
 *   php database/seeds/seed_demo.php
 *   php database/seeds/seed_demo.php --force    # drop and re-seed
 *
 * Safe to run after seed_frameworks.php.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('AEGIS_ROOT', dirname(__DIR__, 2));
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

$force = in_array('--force', $argv ?? [], true);

// Check if demo data already seeded (sentinel: demo CISO user)
$sentinel = Database::fetchOne("SELECT id FROM users WHERE email = ?", ['sarah.chen@acmedemo.com']);
if ($sentinel && !$force) {
    echo "Demo data already seeded (user sarah.chen@acmedemo.com exists). Use --force to re-seed.\n";
    exit(0);
}
if ($sentinel && $force) {
    echo "Force mode: removing existing demo data...\n";
    // Remove in FK-safe order
    Database::query("DELETE FROM kri_values WHERE kri_id IN (SELECT id FROM kris WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com'))");
    Database::query("DELETE FROM kris WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM poam_milestones WHERE poam_id IN (SELECT id FROM poam_items WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com'))");
    Database::query("DELETE FROM poam_items WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM asset_risk_links WHERE asset_id IN (SELECT id FROM assets WHERE owner_id IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com'))");
    Database::query("DELETE FROM assets WHERE owner_id IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM incident_updates WHERE incident_id IN (SELECT id FROM incidents WHERE reported_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com'))");
    Database::query("DELETE FROM incidents WHERE reported_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com') OR title LIKE '[DEMO]%'");
    Database::query("DELETE FROM vendor_assessments WHERE vendor_id IN (SELECT id FROM vendors WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com'))");
    Database::query("DELETE FROM vendors WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM issues WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM risk_treatments WHERE owner_id IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM risks WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM policies WHERE owner_id IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM audits WHERE created_by IN (SELECT id FROM users WHERE email LIKE '%@acmedemo.com')");
    Database::query("DELETE FROM risk_categories WHERE name IN ('Technology','Compliance','Financial','Operational','Strategic','Reputational','Third-Party','People & Culture')");
    Database::query("DELETE FROM users WHERE email LIKE '%@acmedemo.com'");
    echo "Existing demo data removed.\n\n";
}

echo "Seeding demo data for AEGIS GRC...\n\n";

// ─────────────────────────────────────────────────────────
// 1. USERS
// ─────────────────────────────────────────────────────────
// Password for all demo users: Demo@Aegis2024!
$demoPasswordHash = password_hash('Demo@Aegis2024!', PASSWORD_ARGON2ID, [
    'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2,
]);

$demoUsers = [
    ['sarah.chen@acmedemo.com',   'Sarah Chen',         'admin',        'Information Security',     'CISO'],
    ['marcus.jones@acmedemo.com', 'Marcus Jones',       'manager',      'Risk Management',           'Chief Risk Officer'],
    ['priya.patel@acmedemo.com',  'Priya Patel',        'manager',      'Compliance',                'VP of Compliance'],
    ['james.okafor@acmedemo.com', 'James Okafor',       'auditor',      'Internal Audit',            'Senior Auditor'],
    ['diana.rossi@acmedemo.com',  'Diana Rossi',        'viewer',       'IT Operations',             'IT Manager'],
];

$userIds = [];
foreach ($demoUsers as [$email, $name, $role, $dept, $title]) {
    $existing = Database::fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        $userIds[$email] = $existing['id'];
        continue;
    }
    Database::query(
        "INSERT INTO users (name, email, password_hash, role, department, job_title, is_active, email_verified_at)
         VALUES (?, ?, ?, ?, ?, ?, TRUE, NOW())",
        [$name, $email, $demoPasswordHash, $role, $dept, $title]
    );
    $row = Database::fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
    $userIds[$email] = $row['id'];
    echo "  User: {$name} ({$role})\n";
}

$cisoId    = $userIds['sarah.chen@acmedemo.com'];
$croId     = $userIds['marcus.jones@acmedemo.com'];
$vpCompId  = $userIds['priya.patel@acmedemo.com'];
$auditorId = $userIds['james.okafor@acmedemo.com'];
$itMgrId   = $userIds['diana.rossi@acmedemo.com'];

echo "  [5 demo users seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 2. RISK CATEGORIES
// ─────────────────────────────────────────────────────────
$categoryDefs = [
    ['Technology',      'IT systems, infrastructure, cybersecurity, and data risks',   '#3b82f6'],
    ['Compliance',      'Regulatory, legal, and contractual obligation risks',          '#8b5cf6'],
    ['Financial',       'Financial loss, fraud, and economic exposure risks',           '#f59e0b'],
    ['Operational',     'Business process, supply chain, and continuity risks',        '#ef4444'],
    ['Strategic',       'Market, competitive, and strategic direction risks',           '#6366f1'],
    ['Reputational',    'Brand, public perception, and stakeholder trust risks',        '#ec4899'],
    ['Third-Party',     'Vendor, supplier, and partner dependency risks',               '#14b8a6'],
    ['People & Culture','Talent, workforce, and organizational culture risks',          '#f97316'],
];

$catIds = [];
foreach ($categoryDefs as $i => [$name, $desc, $color]) {
    $existing = Database::fetchOne("SELECT id FROM risk_categories WHERE name = ?", [$name]);
    if ($existing) {
        $catIds[$name] = $existing['id'];
        continue;
    }
    Database::query(
        "INSERT INTO risk_categories (name, description, color, sort_order) VALUES (?,?,?,?)",
        [$name, $desc, $color, $i]
    );
    $row = Database::fetchOne("SELECT id FROM risk_categories WHERE name = ?", [$name]);
    $catIds[$name] = $row['id'];
}
echo "  [Risk categories: " . count($catIds) . " ready]\n\n";

// ─────────────────────────────────────────────────────────
// 3. RISKS (20 realistic enterprise risks)
// ─────────────────────────────────────────────────────────
$today = date('Y-m-d');
$risks = [
    [
        'title'       => 'Ransomware Attack on Core Infrastructure',
        'risk_id'     => 'RSK-001',
        'category'    => 'Technology',
        'likelihood'  => 4, 'impact' => 5,
        'res_like'    => 2, 'res_impact' => 4,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Threat actors may deploy ransomware encrypting critical systems and demanding payment, resulting in operational shutdown and data loss.',
        'source'      => 'technology',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+30 days')),
        'fin_min'     => 500000, 'fin_likely' => 2500000, 'fin_max' => 10000000,
        'proximity'   => 'short_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Third-Party Data Breach via Cloud Provider',
        'risk_id'     => 'RSK-002',
        'category'    => 'Technology',
        'likelihood'  => 3, 'impact' => 5,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Cloud provider security incident could expose customer PII and confidential business data, triggering GDPR/HIPAA breach notification obligations.',
        'source'      => 'technology',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+45 days')),
        'fin_min'     => 250000, 'fin_likely' => 1000000, 'fin_max' => 5000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'SOC 2 Type II Audit Failure',
        'risk_id'     => 'RSK-003',
        'category'    => 'Compliance',
        'likelihood'  => 2, 'impact' => 4,
        'res_like'    => 1, 'res_impact' => 3,
        'status'      => 'monitoring',
        'treatment'   => 'mitigate',
        'desc'        => 'Failure to satisfy SOC 2 Type II trust service criteria could result in loss of enterprise customer contracts and reputational damage.',
        'source'      => 'compliance',
        'owner'       => $vpCompId,
        'review'      => date('Y-m-d', strtotime('+60 days')),
        'fin_min'     => 100000, 'fin_likely' => 750000, 'fin_max' => 3000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'HIPAA Privacy Rule Violation',
        'risk_id'     => 'RSK-004',
        'category'    => 'Compliance',
        'likelihood'  => 2, 'impact' => 5,
        'res_like'    => 1, 'res_impact' => 4,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Unauthorized disclosure of Protected Health Information (PHI) could trigger OCR investigation, civil monetary penalties, and class-action litigation.',
        'source'      => 'compliance',
        'owner'       => $vpCompId,
        'review'      => date('Y-m-d', strtotime('+21 days')),
        'fin_min'     => 200000, 'fin_likely' => 1500000, 'fin_max' => 20000000,
        'proximity'   => 'short_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Key Person Dependency — CISO Role',
        'risk_id'     => 'RSK-005',
        'category'    => 'People & Culture',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Critical security programs and institutional knowledge concentrated in single CISO role. Departure would disrupt ongoing security initiatives and audit readiness.',
        'source'      => 'people',
        'owner'       => $croId,
        'review'      => date('Y-m-d', strtotime('+90 days')),
        'fin_min'     => 50000, 'fin_likely' => 300000, 'fin_max' => 1000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Unpatched Vulnerabilities in Production Systems',
        'risk_id'     => 'RSK-006',
        'category'    => 'Technology',
        'likelihood'  => 4, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Production servers running software with known CVEs. Exploitation could enable lateral movement, privilege escalation, or data exfiltration.',
        'source'      => 'operational',
        'owner'       => $itMgrId,
        'review'      => date('Y-m-d', strtotime('+14 days')),
        'fin_min'     => 100000, 'fin_likely' => 800000, 'fin_max' => 4000000,
        'proximity'   => 'immediate',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Supply Chain Software Compromise (SolarWinds-type)',
        'risk_id'     => 'RSK-007',
        'category'    => 'Third-Party',
        'likelihood'  => 2, 'impact' => 5,
        'res_like'    => 2, 'res_impact' => 4,
        'status'      => 'monitoring',
        'treatment'   => 'mitigate',
        'desc'        => 'Nation-state or sophisticated threat actor compromises a trusted software vendor, using their build pipeline to distribute malware into our environment via routine updates.',
        'source'      => 'external',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+60 days')),
        'fin_min'     => 1000000, 'fin_likely' => 5000000, 'fin_max' => 50000000,
        'proximity'   => 'long_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Business Email Compromise (BEC) / Wire Fraud',
        'risk_id'     => 'RSK-008',
        'category'    => 'Financial',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 2,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Attackers impersonate executives via spoofed email to redirect wire transfers or authorize fraudulent payments to attacker-controlled accounts.',
        'source'      => 'financial',
        'owner'       => $croId,
        'review'      => date('Y-m-d', strtotime('+30 days')),
        'fin_min'     => 50000, 'fin_likely' => 500000, 'fin_max' => 2000000,
        'proximity'   => 'short_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Inadequate Disaster Recovery / RTO Breach',
        'risk_id'     => 'RSK-009',
        'category'    => 'Operational',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Disaster recovery procedures not tested in over 18 months. Actual Recovery Time Objective (RTO) likely exceeds contractual SLA commitments of 4 hours.',
        'source'      => 'operational',
        'owner'       => $itMgrId,
        'review'      => date('Y-m-d', strtotime('+45 days')),
        'fin_min'     => 75000, 'fin_likely' => 400000, 'fin_max' => 2000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'pending_review',
    ],
    [
        'title'       => 'Privileged Access Misuse / Insider Threat',
        'risk_id'     => 'RSK-010',
        'category'    => 'People & Culture',
        'likelihood'  => 2, 'impact' => 5,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'monitoring',
        'treatment'   => 'mitigate',
        'desc'        => 'Privileged users (admins, DBAs) with excessive access could intentionally or inadvertently exfiltrate sensitive data, sabotage systems, or bypass controls.',
        'source'      => 'people',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+60 days')),
        'fin_min'     => 100000, 'fin_likely' => 2000000, 'fin_max' => 15000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'GDPR Non-Compliance — Cross-Border Data Transfer',
        'risk_id'     => 'RSK-011',
        'category'    => 'Compliance',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'EU customer data transferred to US-based systems without adequate Standard Contractual Clauses (SCCs) or Binding Corporate Rules in place.',
        'source'      => 'compliance',
        'owner'       => $vpCompId,
        'review'      => date('Y-m-d', strtotime('+30 days')),
        'fin_min'     => 200000, 'fin_likely' => 4000000, 'fin_max' => 20000000,
        'proximity'   => 'short_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Critical Vendor Single-Point-of-Failure',
        'risk_id'     => 'RSK-012',
        'category'    => 'Third-Party',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'transfer',
        'desc'        => 'Core payment processing reliant on single vendor with no contractual SLA for uptime. Vendor outage would halt all revenue-generating transactions.',
        'source'      => 'operational',
        'owner'       => $croId,
        'review'      => date('Y-m-d', strtotime('+45 days')),
        'fin_min'     => 200000, 'fin_likely' => 1500000, 'fin_max' => 8000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Phishing / Social Engineering Campaign',
        'risk_id'     => 'RSK-013',
        'category'    => 'Technology',
        'likelihood'  => 5, 'impact' => 3,
        'res_like'    => 3, 'res_impact' => 2,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Targeted spear-phishing emails targeting finance and executive teams. Credential compromise could enable account takeover and further lateral movement.',
        'source'      => 'external',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+30 days')),
        'fin_min'     => 25000, 'fin_likely' => 200000, 'fin_max' => 1000000,
        'proximity'   => 'immediate',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'AI / ML Model Bias in Decision-Making',
        'risk_id'     => 'RSK-014',
        'category'    => 'Strategic',
        'likelihood'  => 2, 'impact' => 3,
        'res_like'    => 2, 'res_impact' => 2,
        'status'      => 'accepted',
        'treatment'   => 'accept',
        'desc'        => 'AI models used for credit scoring and fraud detection may exhibit demographic bias leading to regulatory scrutiny under fair lending laws.',
        'source'      => 'strategic',
        'owner'       => $croId,
        'review'      => date('Y-m-d', strtotime('+90 days')),
        'fin_min'     => 50000, 'fin_likely' => 500000, 'fin_max' => 5000000,
        'proximity'   => 'long_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Insufficient Logging and Audit Trail Gaps',
        'risk_id'     => 'RSK-015',
        'category'    => 'Compliance',
        'likelihood'  => 3, 'impact' => 3,
        'res_like'    => 2, 'res_impact' => 2,
        'status'      => 'monitoring',
        'treatment'   => 'mitigate',
        'desc'        => 'Audit log retention below regulatory minimums in several systems. Gaps in log coverage would impede forensic investigation and regulatory reporting.',
        'source'      => 'compliance',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+60 days')),
        'fin_min'     => null, 'fin_likely' => 150000, 'fin_max' => 500000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Legacy System End-of-Life / Unsupported Software',
        'risk_id'     => 'RSK-016',
        'category'    => 'Technology',
        'likelihood'  => 4, 'impact' => 3,
        'res_like'    => 3, 'res_impact' => 2,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'Multiple production systems running end-of-life OS and middleware no longer receiving security patches, creating an expanding attack surface.',
        'source'      => 'technology',
        'owner'       => $itMgrId,
        'review'      => date('Y-m-d', strtotime('+30 days')),
        'fin_min'     => 100000, 'fin_likely' => 600000, 'fin_max' => 3000000,
        'proximity'   => 'short_term',
        'assessment'  => 'pending_review',
    ],
    [
        'title'       => 'Market Downturn Impact on Revenue Projections',
        'risk_id'     => 'RSK-017',
        'category'    => 'Financial',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 3, 'res_impact' => 3,
        'status'      => 'monitoring',
        'treatment'   => 'accept',
        'desc'        => 'Macroeconomic recession could reduce enterprise customer renewals by 20-30%, materially impacting ARR targets and cash flow projections for FY2025.',
        'source'      => 'financial',
        'owner'       => $croId,
        'review'      => date('Y-m-d', strtotime('+90 days')),
        'fin_min'     => 500000, 'fin_likely' => 2000000, 'fin_max' => 8000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'DDoS Attack on Customer-Facing Services',
        'risk_id'     => 'RSK-018',
        'category'    => 'Technology',
        'likelihood'  => 3, 'impact' => 3,
        'res_like'    => 2, 'res_impact' => 2,
        'status'      => 'monitoring',
        'treatment'   => 'mitigate',
        'desc'        => 'Volumetric DDoS attacks could overwhelm CDN capacity and cause customer-facing portal downtime, violating SLA uptime commitments.',
        'source'      => 'external',
        'owner'       => $itMgrId,
        'review'      => date('Y-m-d', strtotime('+60 days')),
        'fin_min'     => 25000, 'fin_likely' => 200000, 'fin_max' => 1000000,
        'proximity'   => 'medium_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'Talent Retention — Engineering & Security Teams',
        'risk_id'     => 'RSK-019',
        'category'    => 'People & Culture',
        'likelihood'  => 4, 'impact' => 3,
        'res_like'    => 3, 'res_impact' => 2,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'High attrition in engineering and security roles (35% annually) driven by market compensation pressure. Loss of institutional knowledge threatens product roadmap delivery.',
        'source'      => 'people',
        'owner'       => $croId,
        'review'      => date('Y-m-d', strtotime('+45 days')),
        'fin_min'     => 100000, 'fin_likely' => 500000, 'fin_max' => 2000000,
        'proximity'   => 'short_term',
        'assessment'  => 'approved',
    ],
    [
        'title'       => 'API Security — Unauthenticated Endpoint Exposure',
        'risk_id'     => 'RSK-020',
        'category'    => 'Technology',
        'likelihood'  => 3, 'impact' => 4,
        'res_like'    => 2, 'res_impact' => 3,
        'status'      => 'open',
        'treatment'   => 'mitigate',
        'desc'        => 'External API endpoints found during penetration test lacking rate limiting and proper authentication. Exploitation could enable mass data scraping or account enumeration.',
        'source'      => 'technology',
        'owner'       => $cisoId,
        'review'      => date('Y-m-d', strtotime('+21 days')),
        'fin_min'     => 50000, 'fin_likely' => 400000, 'fin_max' => 2000000,
        'proximity'   => 'short_term',
        'assessment'  => 'approved',
    ],
];

$riskIds = [];
foreach ($risks as $r) {
    $catId = $catIds[$r['category']] ?? null;
    $iScore = $r['likelihood'] * $r['impact'];
    $rScore = $r['res_like'] * $r['res_impact'];
    Database::query(
        "INSERT INTO risks (title, risk_id, description, category_id, likelihood, impact, inherent_score,
            residual_likelihood, residual_impact, residual_score, status, treatment_type,
            owner_id, review_date, identified_date, created_by, financial_min, financial_likely,
            financial_max, financial_currency, risk_source, proximity, assessment_status, confidence,
            velocity)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $r['title'], $r['risk_id'], $r['desc'], $catId,
            $r['likelihood'], $r['impact'], $iScore,
            $r['res_like'], $r['res_impact'], $rScore,
            $r['status'], $r['treatment'],
            $r['owner'], $r['review'], date('Y-m-d', strtotime('-' . rand(30, 365) . ' days')),
            $r['owner'],
            $r['fin_min'], $r['fin_likely'], $r['fin_max'], 'USD',
            $r['source'], $r['proximity'], $r['assessment'], 'medium',
            rand(2, 4),
        ]
    );
    $rRow = Database::fetchOne("SELECT id FROM risks WHERE risk_id = ?", [$r['risk_id']]);
    $riskIds[$r['risk_id']] = $rRow['id'];
}
echo "  [" . count($risks) . " risks seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 4. RISK TREATMENTS for critical/high risks
// ─────────────────────────────────────────────────────────
$treatments = [
    ['RSK-001', 'mitigate',  'Deploy EDR on all endpoints and implement immutable backup solution with air-gap isolation tested quarterly.', 150000, 'high',   date('Y-m-d', strtotime('+90 days')),  'in_progress'],
    ['RSK-001', 'mitigate',  'Implement network segmentation to contain blast radius; test incident response playbook bi-annually.',        75000,  'medium', date('Y-m-d', strtotime('+60 days')),  'planned'],
    ['RSK-006', 'mitigate',  'Enforce 14-day critical patch SLA; deploy automated patch compliance reporting to CISO dashboard.',           50000,  'medium', date('Y-m-d', strtotime('+30 days')),  'in_progress'],
    ['RSK-009', 'mitigate',  'Conduct full DR tabletop exercise; update runbooks; achieve 2-hour RTO for Tier-1 systems.',                  80000,  'high',   date('Y-m-d', strtotime('+45 days')),  'planned'],
    ['RSK-013', 'mitigate',  'Monthly phishing simulations; mandatory security awareness training for all staff; deploy MFA everywhere.',   30000,  'low',    date('Y-m-d', strtotime('+30 days')),  'completed'],
    ['RSK-020', 'mitigate',  'Engage penetration testing firm for full API security audit; implement API gateway with rate limiting.',       60000,  'high',   date('Y-m-d', strtotime('+21 days')),  'in_progress'],
];

foreach ($treatments as [$riskId, $type, $desc, $cost, $effort, $due, $status]) {
    $rid = $riskIds[$riskId] ?? null;
    if (!$rid) continue;
    Database::query(
        "INSERT INTO risk_treatments (risk_id, treatment_type, description, cost_estimate, effort, due_date, status, owner_id)
         VALUES (?,?,?,?,?,?,?,?)",
        [$rid, $type, $desc, $cost, $effort, $due, $status, $cisoId]
    );
}
echo "  [Risk treatments seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 5. VENDORS
// ─────────────────────────────────────────────────────────
$vendors = [
    ['Amazon Web Services',   'cloud',          'active',       'critical', 'aws-security@amazon.com',   '+1-206-266-1000', 'aws.amazon.com',          'Primary cloud infrastructure provider. Hosts all production workloads.'],
    ['Okta Inc.',             'saas',           'active',       'high',     'support@okta.com',           '+1-888-722-7871', 'okta.com',                'Identity and Access Management (IAM) and Single Sign-On (SSO) platform.'],
    ['Crowdstrike Holdings',  'security',       'active',       'high',     'support@crowdstrike.com',    '+1-888-512-8906', 'crowdstrike.com',          'Endpoint Detection and Response (EDR) and threat intelligence.'],
    ['Salesforce Inc.',       'saas',           'active',       'medium',   'legal@salesforce.com',       '+1-415-901-7000', 'salesforce.com',           'CRM platform holding customer contact data and deal pipeline.'],
    ['Qualys Inc.',           'security',       'active',       'medium',   'support@qualys.com',         '+1-800-745-4355', 'qualys.com',               'Vulnerability management and compliance scanning platform.'],
    ['DocuSign Inc.',         'saas',           'active',       'low',      'privacy@docusign.com',       '+1-800-379-9973', 'docusign.com',             'Electronic signature and contract lifecycle management.'],
    ['Legacy Payroll Co.',    'payroll',        'under_review', 'high',     'compliance@legacypayroll.co','',                'legacypayroll.co',         'Payroll processing vendor. Currently under review due to recent SOC 2 findings.'],
    ['DataStream Analytics',  'analytics',      'inactive',     'medium',   'dpo@datastream.io',          '',                'datastream.io',            'Former analytics vendor. Contract expired. Ensure data deletion confirmed.'],
];

$vendorIds = [];
foreach ($vendors as [$name, $cat, $status, $rating, $email, $phone, $web, $desc]) {
    Database::query(
        "INSERT INTO vendors (name, category, status, risk_rating, contact_email, contact_phone, website, description, owner_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$name, $cat, $status, $rating, $email, $phone, $web, $desc, $croId, $croId]
    );
    $vRow = Database::fetchOne("SELECT id FROM vendors WHERE name = ? ORDER BY id DESC LIMIT 1", [$name]);
    $vendorIds[$name] = $vRow['id'];
}
echo "  [" . count($vendors) . " vendors seeded]\n\n";

// Vendor assessments
$assessments = [
    ['Amazon Web Services',  'security',     'completed', date('Y-m-d', strtotime('-90 days')),  date('Y-m-d', strtotime('-60 days')),  88,  'Strong security posture. Minor findings in IAM policy scope.',  'Review S3 bucket policies; enable GuardDuty in all regions.'],
    ['Okta Inc.',            'security',     'completed', date('Y-m-d', strtotime('-120 days')), date('Y-m-d', strtotime('-90 days')),  92,  'Excellent MFA enforcement and SIEM integration.',               'Confirm session timeout aligns with our policy.'],
    ['Crowdstrike Holdings', 'security',     'completed', date('Y-m-d', strtotime('-60 days')),  date('Y-m-d', strtotime('-30 days')),  95,  'Top-tier EDR. Full telemetry coverage confirmed.',              'Ensure threat intelligence feeds synced to SIEM.'],
    ['Salesforce Inc.',      'privacy',      'completed', date('Y-m-d', strtotime('-180 days')), date('Y-m-d', strtotime('-150 days')), 78,  'Adequate data controls. DPA reviewed and signed.',              'Update data residency settings for EU customers.'],
    ['Qualys Inc.',          'security',     'completed', date('Y-m-d', strtotime('-45 days')),  date('Y-m-d', strtotime('-15 days')),  85,  'Scan coverage 94%. Credentialed scans enabled on all segments.','Extend scanning schedule to cover cloud assets.'],
    ['Legacy Payroll Co.',   'security',     'in_progress',date('Y-m-d', strtotime('-14 days')), null,                                 null,'Open findings from last SOC 2 audit under review.',            'Obtain updated SOC 2 Type II report; resolve critical findings within 30 days.'],
];

foreach ($assessments as [$vname, $type, $status, $scheduled, $completed, $score, $findings, $recs]) {
    $vid = $vendorIds[$vname] ?? null;
    if (!$vid) continue;
    Database::query(
        "INSERT INTO vendor_assessments (vendor_id, assessment_type, status, assessed_by, scheduled_date, completed_date, score, findings, recommendations)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$vid, $type, $status, $auditorId, $scheduled, $completed, $score, $findings, $recs]
    );
}
echo "  [Vendor assessments seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 6. INCIDENTS
// ─────────────────────────────────────────────────────────
$incidents = [
    [
        'number'   => 'INC-2024-001',
        'title'    => 'Phishing Campaign Targeting Finance Team',
        'severity' => 'high',
        'category' => 'Social Engineering',
        'status'   => 'resolved',
        'desc'     => 'Targeted spear-phishing emails sent to 12 finance team members impersonating CFO. Three users clicked malicious link. Credential reset performed immediately.',
        'systems'  => 'Office 365, Finance ERP',
        'impact'   => 'Three employee credentials compromised. No funds transferred. Contained within 4 hours.',
        'detected' => date('Y-m-d H:i:s', strtotime('-45 days')),
        'resolved' => date('Y-m-d H:i:s', strtotime('-44 days')),
        'reporter' => $cisoId,
        'assignee' => $cisoId,
    ],
    [
        'number'   => 'INC-2024-002',
        'title'    => 'Unauthorized Access to S3 Bucket (Misconfiguration)',
        'severity' => 'critical',
        'category' => 'Data Exposure',
        'status'   => 'closed',
        'desc'     => 'Misconfigured S3 bucket inadvertently made public for 72 hours. Bucket contained non-production test data with anonymized records. No PII confirmed exposed.',
        'systems'  => 'AWS S3, Development environment',
        'impact'   => 'Potential exposure of 45,000 anonymized test records. No production data affected. No evidence of external access in CloudTrail logs.',
        'detected' => date('Y-m-d H:i:s', strtotime('-120 days')),
        'resolved' => date('Y-m-d H:i:s', strtotime('-117 days')),
        'reporter' => $itMgrId,
        'assignee' => $cisoId,
    ],
    [
        'number'   => 'INC-2024-003',
        'title'    => 'Malware Detection on Employee Workstation',
        'severity' => 'medium',
        'category' => 'Malware',
        'status'   => 'resolved',
        'desc'     => 'EDR flagged trojan dropper on senior developer workstation. Isolation and remediation completed. Threat assessed as opportunistic, not targeted.',
        'systems'  => 'Developer workstation, corporate VPN',
        'impact'   => 'Single workstation isolated for 6 hours. Code repository access temporarily suspended pending forensic review. No data exfiltration detected.',
        'detected' => date('Y-m-d H:i:s', strtotime('-30 days')),
        'resolved' => date('Y-m-d H:i:s', strtotime('-29 days')),
        'reporter' => $cisoId,
        'assignee' => $itMgrId,
    ],
    [
        'number'   => 'INC-2024-004',
        'title'    => 'Vendor API Credential Exposure in Public Repository',
        'severity' => 'high',
        'category' => 'Credential Exposure',
        'status'   => 'resolved',
        'desc'     => 'Active vendor API keys accidentally committed to public GitHub repository. Keys rotated within 90 minutes of detection. No evidence of unauthorized API usage.',
        'systems'  => 'GitHub, Vendor API gateway',
        'impact'   => 'Keys exposed for approximately 3 hours. Vendor confirmed no unauthorized calls logged during exposure window.',
        'detected' => date('Y-m-d H:i:s', strtotime('-60 days')),
        'resolved' => date('Y-m-d H:i:s', strtotime('-60 days +2 hours')),
        'reporter' => $cisoId,
        'assignee' => $cisoId,
    ],
    [
        'number'   => 'INC-2024-005',
        'title'    => 'Elevated Failed Login Attempts — Admin Portal',
        'severity' => 'medium',
        'category' => 'Brute Force',
        'status'   => 'investigating',
        'desc'     => 'SIEM detected 2,847 failed login attempts against admin portal from 3 distinct IP ranges over 6 hours. Rate limiting engaged. IPs blocked at firewall.',
        'systems'  => 'Admin portal, WAF, SIEM',
        'impact'   => 'No successful authentication. Account lockouts triggered for 4 valid accounts (cleared after verification). Service availability unaffected.',
        'detected' => date('Y-m-d H:i:s', strtotime('-5 days')),
        'resolved' => null,
        'reporter' => $itMgrId,
        'assignee' => $cisoId,
    ],
    [
        'number'   => 'INC-2024-006',
        'title'    => 'Insider Data Download — Departing Employee',
        'severity' => 'high',
        'category' => 'Insider Threat',
        'status'   => 'investigating',
        'desc'     => 'DLP alert triggered on departing sales executive downloading 4.2 GB of customer contact data to personal Dropbox 48 hours before departure. HR and Legal engaged.',
        'systems'  => 'Salesforce CRM, DLP platform, corporate email',
        'impact'   => 'Potential exfiltration of 15,000 customer records. Legal hold applied. Forensic investigation ongoing. Law enforcement notification under review.',
        'detected' => date('Y-m-d H:i:s', strtotime('-7 days')),
        'resolved' => null,
        'reporter' => $cisoId,
        'assignee' => $cisoId,
    ],
];

$incidentIds = [];
foreach ($incidents as $inc) {
    Database::query(
        "INSERT INTO incidents (incident_number, title, description, severity, category, status,
            reported_by, assigned_to, affected_systems, impact_description, detected_at, resolved_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $inc['number'], $inc['title'], $inc['desc'], $inc['severity'], $inc['category'],
            $inc['status'], $inc['reporter'], $inc['assignee'], $inc['systems'],
            $inc['impact'], $inc['detected'], $inc['resolved'],
        ]
    );
    $iRow = Database::fetchOne("SELECT id FROM incidents WHERE incident_number = ?", [$inc['number']]);
    $incidentIds[$inc['number']] = $iRow['id'];
}

// Add incident updates
Database::query(
    "INSERT INTO incident_updates (incident_id, user_id, content) VALUES (?,?,?)",
    [$incidentIds['INC-2024-001'], $cisoId, 'Phishing emails traced to threat actor group APT-Finance-42. IOCs shared with industry ISAC. All affected credentials rotated. MFA enforced for finance department.']
);
Database::query(
    "INSERT INTO incident_updates (incident_id, user_id, content) VALUES (?,?,?)",
    [$incidentIds['INC-2024-006'], $cisoId, 'Legal hold issued. External forensics firm engaged. Departure suspended pending investigation. Preliminary review confirms customer data included in download.']
);
Database::query(
    "INSERT INTO incident_updates (incident_id, user_id, content) VALUES (?,?,?)",
    [$incidentIds['INC-2024-005'], $itMgrId, 'Attack pattern consistent with credential stuffing. Implementing CAPTCHA challenge after 5 failed attempts. Threat intel team monitoring for related infrastructure.']
);
echo "  [" . count($incidents) . " incidents seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 7. POLICIES
// ─────────────────────────────────────────────────────────
$policies = [
    ['POL-IS-001', 'Information Security Policy',          'information_security', '3.2', 'approved',
     'This policy establishes the foundation for protecting ACME Corp information assets, including customer data, intellectual property, and operational systems. All employees, contractors, and third parties with access to company systems are subject to this policy.',
     $cisoId,  date('Y-m-d', strtotime('-30 days')),  date('Y-m-d', strtotime('+335 days'))],
    ['POL-AC-002', 'Access Control and Identity Management Policy', 'access_control', '2.1', 'approved',
     'Governs user access lifecycle including provisioning, modification, and deprovisioning. Requires MFA for all privileged access. Enforces least-privilege principle across all systems.',
     $cisoId,  date('Y-m-d', strtotime('-60 days')),  date('Y-m-d', strtotime('+305 days'))],
    ['POL-DR-003', 'Business Continuity and Disaster Recovery Policy', 'operational', '1.4', 'approved',
     'Defines requirements for maintaining business operations during disruptive events. Establishes RTO of 4 hours and RPO of 1 hour for Tier-1 critical systems.',
     $itMgrId, date('Y-m-d', strtotime('-45 days')),  date('Y-m-d', strtotime('+320 days'))],
    ['POL-VM-004', 'Vulnerability Management Policy',      'information_security', '2.0', 'approved',
     'Establishes patch management cadence: critical CVEs patched within 24 hours, high within 7 days, medium within 30 days. Requires authenticated scanning monthly and after significant changes.',
     $cisoId,  date('Y-m-d', strtotime('-90 days')),  date('Y-m-d', strtotime('+275 days'))],
    ['POL-VP-005', 'Vendor and Third-Party Risk Policy',   'vendor_management', '1.2', 'approved',
     'All vendors with access to company data or systems require security assessments prior to onboarding. Annual reassessment required for critical-tier vendors. SOC 2 Type II or equivalent required.',
     $croId,   date('Y-m-d', strtotime('-15 days')),  date('Y-m-d', strtotime('+350 days'))],
    ['POL-DP-006', 'Data Classification and Handling Policy', 'data_governance', '2.3', 'approved',
     'Defines four data classification levels: Public, Internal, Confidential, and Restricted. Specifies handling, storage, transmission, and disposal requirements for each level.',
     $vpCompId, date('Y-m-d', strtotime('-120 days')), date('Y-m-d', strtotime('+245 days'))],
    ['POL-IR-007', 'Incident Response Policy',            'incident_management', '1.5', 'approved',
     'Establishes incident classification matrix, escalation procedures, and communication protocols. Requires breach notification within 72 hours per GDPR and applicable regulations.',
     $cisoId,  date('Y-m-d', strtotime('-200 days')), date('Y-m-d', strtotime('+165 days'))],
    ['POL-SA-008', 'Security Awareness and Training Policy', 'human_resources', '1.1', 'draft',
     'Mandates annual security awareness training for all employees, quarterly phishing simulations, and role-specific training for privileged users and developers.',
     $cisoId,  null, date('Y-m-d', strtotime('+30 days'))],
    ['POL-CR-009', 'Cryptography and Key Management Policy', 'information_security', '1.0', 'under_review',
     'Specifies approved cryptographic algorithms (AES-256, RSA-4096, ECDSA P-384), key rotation schedules, and Hardware Security Module (HSM) requirements for key storage.',
     $cisoId,  null, date('Y-m-d', strtotime('+15 days'))],
    ['POL-PV-010', 'Privacy and Data Protection Policy',  'privacy', '2.0', 'approved',
     'Implements GDPR Article 5 data processing principles, defines lawful bases for processing personal data, establishes data subject rights procedures, and appoints Data Protection Officer responsibilities.',
     $vpCompId, date('Y-m-d', strtotime('-180 days')), date('Y-m-d', strtotime('+185 days'))],
];

foreach ($policies as [$num, $title, $cat, $ver, $status, $content, $owner, $approved, $nextReview]) {
    Database::query(
        "INSERT INTO policies (title, policy_number, content, version, status, category, owner_id, approver_id,
            review_frequency, next_review_date, approved_at, published_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $title, $num, $content, $ver, $status, $cat, $owner, $cisoId,
            'annual', $nextReview,
            $status === 'approved' ? $approved : null,
            $status === 'approved' ? $approved : null,
        ]
    );
}
echo "  [" . count($policies) . " policies seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 8. AUDITS
// ─────────────────────────────────────────────────────────
// Get a package ID (SOC 2 preferred)
$soc2Pkg = Database::fetchOne("SELECT id FROM compliance_packages WHERE name LIKE '%SOC%' OR name LIKE '%SOC2%' LIMIT 1");
$nistPkg = Database::fetchOne("SELECT id FROM compliance_packages WHERE name LIKE '%NIST%' LIMIT 1");
$hipaaPkg = Database::fetchOne("SELECT id FROM compliance_packages WHERE name LIKE '%HIPAA%' LIMIT 1");

$audits = [
    [
        'name'       => 'SOC 2 Type II Readiness Assessment — FY2024',
        'desc'       => 'Comprehensive internal readiness assessment evaluating maturity against SOC 2 Trust Service Criteria (TSC) prior to external audit. Focus on CC6, CC7, and A1 criteria.',
        'pkg_id'     => $soc2Pkg['id'] ?? null,
        'type'       => 'internal',
        'status'     => 'completed',
        'scheduled'  => date('Y-m-d', strtotime('-90 days')),
        'start'      => date('Y-m-d', strtotime('-85 days')),
        'completed'  => date('Y-m-d', strtotime('-60 days')),
        'score'      => 82.5,
        'auditor'    => $auditorId,
        'frequency'  => 'annual',
        'notes'      => 'Assessment revealed 14 control gaps. High-priority items addressed prior to external audit engagement. Overall readiness rated GOOD.',
    ],
    [
        'name'       => 'NIST 800-53 Controls Audit — Q3 2024',
        'desc'       => 'Quarterly compliance audit against NIST SP 800-53 Rev 5 control baseline. Scope includes all FedRAMP-impacting controls across cloud and on-premises environments.',
        'pkg_id'     => $nistPkg['id'] ?? null,
        'type'       => 'internal',
        'status'     => 'in_progress',
        'scheduled'  => date('Y-m-d', strtotime('-14 days')),
        'start'      => date('Y-m-d', strtotime('-7 days')),
        'completed'  => null,
        'score'      => null,
        'auditor'    => $auditorId,
        'frequency'  => 'quarterly',
        'notes'      => 'Currently in evidence collection phase. Estimated completion in 3 weeks.',
    ],
    [
        'name'       => 'HIPAA Security Rule Gap Assessment — FY2024',
        'desc'       => 'Annual HIPAA Security Rule assessment covering Administrative, Physical, and Technical Safeguards. Required for PHI systems supporting healthcare client accounts.',
        'pkg_id'     => $hipaaPkg['id'] ?? null,
        'type'       => 'external',
        'status'     => 'planned',
        'scheduled'  => date('Y-m-d', strtotime('+30 days')),
        'start'      => null,
        'completed'  => null,
        'score'      => null,
        'auditor'    => $auditorId,
        'frequency'  => 'annual',
        'notes'      => 'External auditor engagement letter signed. Pre-assessment questionnaire submitted.',
    ],
];

$auditIds = [];
foreach ($audits as $a) {
    Database::query(
        "INSERT INTO audits (name, description, package_id, audit_type, status, scheduled_date, start_date,
            completed_date, score, auditor_id, created_by, notes, frequency)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $a['name'], $a['desc'], $a['pkg_id'], $a['type'], $a['status'],
            $a['scheduled'], $a['start'], $a['completed'], $a['score'],
            $a['auditor'], $auditorId, $a['notes'], $a['frequency'],
        ]
    );
    $auRow = Database::fetchOne("SELECT id FROM audits WHERE name = ? ORDER BY id DESC LIMIT 1", [$a['name']]);
    $auditIds[] = $auRow['id'];
}
echo "  [" . count($audits) . " audits seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 9. ASSETS
// ─────────────────────────────────────────────────────────
$assets = [
    ['prod-web-01',       'server',      'critical',    'confidential', 'active',     '10.0.1.10',  'prod-web-01.acmedemo.internal',  'AWS',      'EC2 m5.2xlarge', $cisoId, 'us-east-1a',      ['web', 'production', 'internet-facing'],  'Primary web application server running customer portal.'],
    ['prod-db-primary',   'database',    'critical',    'restricted',   'active',     '10.0.2.10',  'prod-db-01.acmedemo.internal',   'AWS RDS',  'PostgreSQL 15',  $itMgrId,'us-east-1a',      ['database', 'production', 'pii'],         'Primary PostgreSQL database containing customer PII and transaction data.'],
    ['prod-db-replica',   'database',    'critical',    'restricted',   'active',     '10.0.2.11',  'prod-db-02.acmedemo.internal',   'AWS RDS',  'PostgreSQL 15',  $itMgrId,'us-east-1b',      ['database', 'production', 'replica'],     'Read replica for reporting and failover. Cross-AZ for HA.'],
    ['corp-ldap-01',      'server',      'high',        'confidential', 'active',     '10.1.1.5',   'ldap.acmedemo.internal',         'Dell',     'Windows Server 2022', $itMgrId,'On-Premise DC', ['ldap', 'directory', 'identity'],        'Active Directory domain controller. Source of truth for user identities.'],
    ['fileserver-01',     'server',      'high',        'confidential', 'active',     '10.1.2.20',  'files.acmedemo.internal',        'Dell',     'Windows Server 2019', $itMgrId,'On-Premise',  ['fileshare', 'documents'],               'Corporate file server. Holds financial records and HR documents.'],
    ['dev-k8s-cluster',   'cloud',       'medium',      'internal',     'active',     null,          'k8s.dev.acmedemo.internal',      'AWS EKS',  'Kubernetes 1.29',$cisoId,'us-east-1',       ['kubernetes', 'development'],            'Development Kubernetes cluster for engineering teams.'],
    ['salesforce-crm',    'saas',        'high',        'confidential', 'active',     null,          null,                             'Salesforce', 'Enterprise',   $croId, 'Salesforce Cloud', ['crm', 'saas', 'customer-data'],         'Salesforce CRM platform — holds 95,000 customer contact records.'],
    ['okta-iam',          'saas',        'critical',    'restricted',   'active',     null,          null,                             'Okta',   'Workforce Identity',$cisoId,'Okta Cloud',      ['iam', 'sso', 'mfa'],                    'Identity provider for SSO and MFA. Governs access to all SaaS platforms.'],
    ['github-enterprise', 'saas',        'high',        'confidential', 'active',     null,          null,                             'GitHub', 'Enterprise',     $cisoId, 'GitHub Cloud',    ['scm', 'code', 'secrets'],               'Source code management. Contains proprietary application code and CI/CD pipelines.'],
    ['corp-vpn-gw',       'network',     'critical',    'confidential', 'active',     '203.0.113.1', 'vpn.acmedemo.com',              'Palo Alto','GlobalProtect',  $itMgrId,'On-Premise',     ['vpn', 'network', 'remote-access'],      'Corporate VPN gateway. Remote access entry point for all employees.'],
    ['waf-cloudflare',    'network',     'high',        'internal',     'active',     null,          null,                             'Cloudflare','WAF Enterprise',$itMgrId,'Cloudflare',    ['waf', 'ddos', 'cdn'],                   'Web Application Firewall and DDoS mitigation. Protects all public-facing services.'],
    ['devlaptop-pool',    'workstation', 'medium',      'internal',     'active',     null,          null,                             'Apple',  'macOS 14',        $itMgrId,'Corporate',       ['endpoint', 'macos'],                    'Fleet of 45 developer MacBook Pro devices. MDM enrolled.'],
    ['backup-vault',      'server',      'critical',    'restricted',   'active',     '10.0.3.5',   'backup.acmedemo.internal',       'Veeam',  'Veeam v12',       $itMgrId,'On-Premise DC',  ['backup', 'recovery'],                   'Immutable backup vault with 90-day retention. Air-gap network segment.'],
    ['legacy-erp-01',     'application', 'high',        'confidential', 'maintenance','10.1.3.50',  'erp.acmedemo.internal',          'SAP',    'SAP ECC 6.0',     $itMgrId,'On-Premise',     ['erp', 'legacy', 'eol'],                 'Legacy ERP system. EOL software on SAP ECC 6.0. Migration to S/4HANA planned Q2 2025.'],
    ['siem-platform',     'application', 'high',        'internal',     'active',     '10.0.4.10',  'siem.acmedemo.internal',         'Splunk', 'Enterprise 9.2', $cisoId, 'On-Premise',     ['siem', 'security', 'logging'],          'SIEM platform ingesting 50GB/day of security logs across all environments.'],
];

$assetIds = [];
foreach ($assets as [$name, $type, $crit, $class, $status, $ip, $hostname, $vendor, $version, $owner, $location, $tags, $desc]) {
    Database::query(
        "INSERT INTO assets (name, asset_type, criticality, classification, status, ip_address, hostname, vendor, version,
            owner_id, location, tags, description, last_scanned, last_reviewed)
         VALUES (?,?,?,?,?,?::inet,?,?,?,?,?,?::jsonb,?,?,?)",
        [
            $name, $type, $crit, $class, $status,
            $ip, $hostname, $vendor, $version,
            $owner, $location, json_encode($tags), $desc,
            date('Y-m-d', strtotime('-' . rand(1, 30) . ' days')),
            date('Y-m-d', strtotime('-' . rand(7, 60) . ' days')),
        ]
    );
    $aRow = Database::fetchOne("SELECT id FROM assets WHERE name = ? ORDER BY id DESC LIMIT 1", [$name]);
    $assetIds[$name] = $aRow['id'];
}
echo "  [" . count($assets) . " assets seeded]\n\n";

// Asset-Risk links (critical assets linked to relevant risks)
$assetRiskLinks = [
    ['prod-db-primary',   'RSK-001'], // ransomware vs primary DB
    ['prod-db-primary',   'RSK-002'], // cloud breach vs primary DB
    ['prod-db-replica',   'RSK-001'],
    ['prod-web-01',       'RSK-001'],
    ['prod-web-01',       'RSK-006'], // unpatched vulns vs web server
    ['prod-web-01',       'RSK-018'], // DDoS vs web server
    ['prod-web-01',       'RSK-020'], // API security vs web server
    ['salesforce-crm',    'RSK-002'], // cloud breach vs CRM
    ['salesforce-crm',    'RSK-012'], // vendor SPOF vs CRM
    ['okta-iam',          'RSK-001'],
    ['okta-iam',          'RSK-010'], // privileged access vs IAM
    ['legacy-erp-01',     'RSK-016'], // EOL software
    ['legacy-erp-01',     'RSK-006'],
    ['corp-vpn-gw',       'RSK-006'],
    ['corp-vpn-gw',       'RSK-013'], // phishing vs VPN
    ['github-enterprise', 'RSK-004'], // credential exposure risk
    ['backup-vault',      'RSK-001'],
    ['backup-vault',      'RSK-009'], // DR / RTO risk
];

foreach ($assetRiskLinks as [$assetName, $riskId]) {
    $aid = $assetIds[$assetName] ?? null;
    $rid = $riskIds[$riskId] ?? null;
    if (!$aid || !$rid) continue;
    try {
        Database::query(
            "INSERT INTO asset_risk_links (asset_id, risk_id) VALUES (?,?) ON CONFLICT DO NOTHING",
            [$aid, $rid]
        );
    } catch (Throwable) {}
}
echo "  [Asset-risk links seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 10. KRIs (Key Risk Indicators)
// ─────────────────────────────────────────────────────────
$kris = [
    [
        'title'     => 'Mean Time to Patch Critical Vulnerabilities (days)',
        'desc'      => 'Average days to deploy patches for CVSS 9.0+ vulnerabilities across production systems. Target: ≤1 day.',
        'unit'      => 'days',
        'direction' => 'higher_worse',
        'green'     => 1, 'amber' => 3, 'red' => 7,
        'frequency' => 'monthly',
        'risk_id'   => 'RSK-006',
        'owner'     => $cisoId,
        'values'    => [
            [date('Y-m-d', strtotime('-5 months')), 4.2, 'Legacy ERP patching delayed by compatibility testing.'],
            [date('Y-m-d', strtotime('-4 months')), 3.8, 'Improvement after scheduling dedicated patch window.'],
            [date('Y-m-d', strtotime('-3 months')), 2.1, 'New automated deployment pipeline reducing lag.'],
            [date('Y-m-d', strtotime('-2 months')), 1.5, 'Process improvements taking effect.'],
            [date('Y-m-d', strtotime('-1 month')),  0.9, 'SLA met for the first time. Critical patch deployed same-day.'],
            [date('Y-m-d'),                          1.2, 'Within target. One exception for SAP ECC due to vendor dependency.'],
        ],
    ],
    [
        'title'     => 'Phishing Simulation Click Rate (%)',
        'desc'      => 'Percentage of employees who click phishing simulation links in monthly campaigns. Target: <5%.',
        'unit'      => '%',
        'direction' => 'higher_worse',
        'green'     => 5, 'amber' => 10, 'red' => 20,
        'frequency' => 'monthly',
        'risk_id'   => 'RSK-013',
        'owner'     => $cisoId,
        'values'    => [
            [date('Y-m-d', strtotime('-5 months')), 23.4, 'Baseline measurement — no prior training.'],
            [date('Y-m-d', strtotime('-4 months')), 18.7, 'Slight improvement post-all-hands security briefing.'],
            [date('Y-m-d', strtotime('-3 months')), 12.3, 'Mandatory e-learning module deployed.'],
            [date('Y-m-d', strtotime('-2 months')), 8.9,  'Role-specific training for finance team completed.'],
            [date('Y-m-d', strtotime('-1 month')),  6.2,  'Continued improvement — approaching target.'],
            [date('Y-m-d'),                          4.8,  'Target achieved. Program continuing for sustainability.'],
        ],
    ],
    [
        'title'     => 'Vendor Security Assessment Coverage (%)',
        'desc'      => 'Percentage of Tier-1 and Tier-2 vendors with completed security assessments within the past 12 months.',
        'unit'      => '%',
        'direction' => 'lower_worse',
        'green'     => 90, 'amber' => 75, 'red' => 60,
        'frequency' => 'quarterly',
        'risk_id'   => 'RSK-012',
        'owner'     => $croId,
        'values'    => [
            [date('Y-m-d', strtotime('-9 months')), 62.0, 'Starting point — significant backlog of overdue assessments.'],
            [date('Y-m-d', strtotime('-6 months')), 71.0, 'Accelerated assessment program launched.'],
            [date('Y-m-d', strtotime('-3 months')), 83.0, 'Two high-priority vendor assessments completed.'],
            [date('Y-m-d'),                          91.0, 'Target achieved. Legacy Payroll Co assessment in progress.'],
        ],
    ],
];

$kriIds = [];
foreach ($kris as $kri) {
    $linkedRisk = isset($kri['risk_id']) ? ($riskIds[$kri['risk_id']] ?? null) : null;
    Database::query(
        "INSERT INTO kris (title, description, unit, direction, threshold_green, threshold_amber, threshold_red,
            frequency, owner_id, linked_risk_id, is_active, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,TRUE,?)",
        [
            $kri['title'], $kri['desc'], $kri['unit'], $kri['direction'],
            $kri['green'], $kri['amber'], $kri['red'],
            $kri['frequency'], $kri['owner'], $linkedRisk, $kri['owner'],
        ]
    );
    $kRow = Database::fetchOne("SELECT id FROM kris WHERE title = ? ORDER BY id DESC LIMIT 1", [$kri['title']]);
    $kriId = $kRow['id'];
    $kriIds[] = $kriId;

    foreach ($kri['values'] as [$date, $value, $notes]) {
        Database::query(
            "INSERT INTO kri_values (kri_id, value, recorded_at, notes, recorded_by) VALUES (?,?,?,?,?)",
            [$kriId, $value, $date, $notes, $kri['owner']]
        );
    }
}
echo "  [" . count($kris) . " KRIs with trend data seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 11. POA&Ms (Plans of Action & Milestones)
// ─────────────────────────────────────────────────────────
$poams = [
    [
        'number'    => 'POAM-2024-001',
        'title'     => 'Implement Immutable Backup Solution with Air-Gap',
        'weakness'  => 'Current backup solution lacks immutability controls and is accessible from production network, creating risk of ransomware destroying backups.',
        'resources' => 'Estimated $150,000 CAPEX for hardware. 2 FTE engineer weeks for implementation and testing. External vendor engagement for air-gap design.',
        'due'       => date('Y-m-d', strtotime('+90 days')),
        'status'    => 'open',
        'owner'     => $itMgrId,
        'milestones'=> [
            ['Vendor selection and hardware procurement', date('Y-m-d', strtotime('+30 days')), false],
            ['Air-gap network segment deployment', date('Y-m-d', strtotime('+60 days')), false],
            ['Backup solution installation and configuration', date('Y-m-d', strtotime('+75 days')), false],
            ['Full DR test with restored backups', date('Y-m-d', strtotime('+90 days')), false],
        ],
    ],
    [
        'number'    => 'POAM-2024-002',
        'title'     => 'Remediate API Authentication Vulnerabilities',
        'weakness'  => 'Penetration test identified 3 external API endpoints lacking proper authentication and rate limiting. CVSS score: 7.5 (High).',
        'resources' => 'Estimated 3 engineer-weeks. API gateway licensing $24,000/year. External penetration retest $15,000.',
        'due'       => date('Y-m-d', strtotime('+45 days')),
        'status'    => 'in_progress',
        'owner'     => $cisoId,
        'milestones'=> [
            ['Deploy API gateway in front of all external endpoints', date('Y-m-d', strtotime('+14 days')), true],
            ['Implement OAuth 2.0 authentication on all endpoints', date('Y-m-d', strtotime('+21 days')), false],
            ['Configure rate limiting and IP allowlisting', date('Y-m-d', strtotime('+30 days')), false],
            ['Penetration retest and sign-off', date('Y-m-d', strtotime('+45 days')), false],
        ],
    ],
    [
        'number'    => 'POAM-2024-003',
        'title'     => 'Complete GDPR Data Processing Agreements',
        'weakness'  => 'Standard Contractual Clauses (SCCs) not in place for 5 vendors processing EU customer data, violating GDPR Article 46 cross-border transfer requirements.',
        'resources' => 'Estimated 40 legal hours ($20,000). Vendor engagement coordination by Compliance team (1 FTE week per vendor).',
        'due'       => date('Y-m-d', strtotime('+60 days')),
        'status'    => 'in_progress',
        'owner'     => $vpCompId,
        'milestones'=> [
            ['Inventory all EU data transfers and vendor list', date('Y-m-d', strtotime('-7 days')), true],
            ['Legal review of SCC template', date('Y-m-d', strtotime('+14 days')), false],
            ['Execute SCCs with priority Tier-1 vendors (3 vendors)', date('Y-m-d', strtotime('+35 days')), false],
            ['Execute SCCs with remaining vendors (2 vendors)', date('Y-m-d', strtotime('+60 days')), false],
        ],
    ],
    [
        'number'    => 'POAM-2024-004',
        'title'     => 'Disaster Recovery Plan Testing and Update',
        'weakness'  => 'DR plan last tested 18 months ago. Current documentation does not reflect cloud architecture. Estimated RTO exceeds contractual 4-hour commitment.',
        'resources' => 'Estimated 1 FTE month for documentation. $30,000 for DR tabletop facilitation. 8-hour maintenance window for live DR test.',
        'due'       => date('Y-m-d', strtotime('+75 days')),
        'status'    => 'open',
        'owner'     => $itMgrId,
        'milestones'=> [
            ['Update DR runbooks to reflect current architecture', date('Y-m-d', strtotime('+21 days')), false],
            ['Conduct tabletop exercise with executive team', date('Y-m-d', strtotime('+42 days')), false],
            ['Full live DR failover test — Tier-1 systems', date('Y-m-d', strtotime('+63 days')), false],
            ['Report findings and close remaining gaps', date('Y-m-d', strtotime('+75 days')), false],
        ],
    ],
    [
        'number'    => 'POAM-2024-005',
        'title'     => 'Security Awareness Program Expansion',
        'weakness'  => 'Security awareness training completion rate at 67%. Phishing click rate above 10% threshold. No role-specific training for developers and finance team.',
        'resources' => 'LMS platform license $15,000/year. 2 FTE weeks for content development. Phishing simulation platform $8,000/year.',
        'due'       => date('Y-m-d', strtotime('+30 days')),
        'status'    => 'completed',
        'owner'     => $cisoId,
        'milestones'=> [
            ['Deploy LMS platform and migrate existing content', date('Y-m-d', strtotime('-45 days')), true],
            ['Develop developer-specific secure coding training', date('Y-m-d', strtotime('-30 days')), true],
            ['Launch finance team BEC awareness module', date('Y-m-d', strtotime('-15 days')), true],
            ['Achieve 95% completion rate', date('Y-m-d', strtotime('-7 days')), true],
        ],
    ],
];

foreach ($poams as $p) {
    Database::query(
        "INSERT INTO poam_items (poam_number, title, weakness_description, resource_requirements,
            scheduled_completion, status, owner_id, created_by)
         VALUES (?,?,?,?,?,?,?,?)",
        [
            $p['number'], $p['title'], $p['weakness'], $p['resources'],
            $p['due'], $p['status'], $p['owner'], $p['owner'],
        ]
    );
    $pRow = Database::fetchOne("SELECT id FROM poam_items WHERE poam_number = ?", [$p['number']]);
    $poamId = $pRow['id'];

    foreach ($p['milestones'] as [$desc, $due, $complete]) {
        Database::query(
            "INSERT INTO poam_milestones (poam_id, description, due_date, is_complete, completed_at) VALUES (?,?,?,?,?)",
            [$poamId, $desc, $due, $complete ? 'TRUE' : 'FALSE', $complete ? date('Y-m-d') : null]
        );
    }
}
echo "  [" . count($poams) . " POA&Ms with milestones seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 12. ISSUES
// ─────────────────────────────────────────────────────────
$issues = [
    ['ISS-2024-001', 'Audit Log Retention Below Regulatory Minimum', 'high',   'in_progress',
     'Splunk log retention configured at 30 days. HIPAA requires 6 years, PCI-DSS requires 12 months. Immediate expansion required.',
     $cisoId, date('Y-m-d', strtotime('+14 days'))],
    ['ISS-2024-002', 'Missing MFA on 12 Service Accounts',           'high',   'open',
     'Discovery scan found 12 privileged service accounts without MFA enrolled. These accounts have admin rights across production systems.',
     $cisoId, date('Y-m-d', strtotime('+7 days'))],
    ['ISS-2024-003', 'Outdated Data Retention Schedule',              'medium', 'in_progress',
     'Data retention schedule last reviewed 3 years ago. Does not reflect GDPR requirements or current data inventory.',
     $vpCompId, date('Y-m-d', strtotime('+30 days'))],
    ['ISS-2024-004', 'Vendor Contract Missing Security Addendum',     'medium', 'open',
     'Contract with Legacy Payroll Co. lacks security addendum specifying breach notification timeframes and right-to-audit clauses.',
     $croId, date('Y-m-d', strtotime('+21 days'))],
    ['ISS-2024-005', 'DR Test Report Not Filed',                      'low',    'resolved',
     'Previous DR tabletop exercise report not formally documented and filed. Required for SOC 2 evidence package.',
     $auditorId, date('Y-m-d', strtotime('-30 days'))],
];

foreach ($issues as [$num, $title, $sev, $status, $desc, $assignee, $due]) {
    Database::query(
        "INSERT INTO issues (issue_number, title, description, severity, status, assigned_to, created_by, due_date, source_type)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$num, $title, $desc, $sev, $status, $assignee, $assignee, $due, 'manual']
    );
}
echo "  [" . count($issues) . " issues seeded]\n\n";

// ─────────────────────────────────────────────────────────
// 13. COMPLIANCE CONTROL IMPLEMENTATIONS
// ─────────────────────────────────────────────────────────
// Seed control implementations for the SOC 2 package if available
$soc2Controls = [];
if ($soc2Pkg) {
    $soc2Controls = Database::fetchAll(
        "SELECT id FROM compliance_objectives WHERE package_id = ? AND level = 2 ORDER BY id LIMIT 30",
        [$soc2Pkg['id']]
    );
}

$controlStatuses = ['compliant','compliant','compliant','partial','partial','not_started','not_applicable'];
$assignees = [$cisoId, $itMgrId, $vpCompId, $auditorId, $croId];

if ($soc2Controls) {
    foreach ($soc2Controls as $i => $ctrl) {
        $existing = Database::fetchOne("SELECT id FROM control_implementations WHERE objective_id = ?", [$ctrl['id']]);
        if ($existing) continue;

        $status = $controlStatuses[$i % count($controlStatuses)];
        $assignee = $assignees[$i % count($assignees)];
        $notes = match($status) {
            'compliant'       => 'Control fully implemented and tested. Evidence collected and reviewed.',
            'partial'         => 'Control partially implemented. Remediation in progress. See linked POA&M.',
            'not_started'     => 'Implementation scheduled for next quarter.',
            'not_applicable'  => 'Control not applicable to current scope. Exclusion documented.',
            default           => '',
        };
        $dueDate = $status === 'not_started' ? date('Y-m-d', strtotime('+' . rand(30, 90) . ' days')) : null;

        Database::query(
            "INSERT INTO control_implementations (objective_id, status, implementation_notes, assigned_to, due_date, last_reviewed, reviewed_by)
             VALUES (?,?,?,?,?,?,?)",
            [
                $ctrl['id'], $status, $notes, $assignee, $dueDate,
                $status === 'compliant' ? date('Y-m-d', strtotime('-' . rand(1, 60) . ' days')) : null,
                $status === 'compliant' ? $auditorId : null,
            ]
        );
    }
    echo "  [SOC 2 control implementations seeded (" . count($soc2Controls) . " controls)]\n\n";
} else {
    echo "  [SKIP: No SOC 2 package found — run seed_frameworks.php first for compliance data]\n\n";
}

// ─────────────────────────────────────────────────────────
// DONE
// ─────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════\n";
echo "  AEGIS Demo Data Seeding Complete!\n";
echo "═══════════════════════════════════════════════════\n\n";
echo "  Demo login credentials:\n";
echo "  ─────────────────────────────────────────────\n";
echo "  CISO:       sarah.chen@acmedemo.com\n";
echo "  CRO:        marcus.jones@acmedemo.com\n";
echo "  Compliance: priya.patel@acmedemo.com\n";
echo "  Auditor:    james.okafor@acmedemo.com\n";
echo "  IT Manager: diana.rossi@acmedemo.com\n";
echo "  Password:   Demo\@Aegis2024!\n\n";
echo "  Data seeded across modules:\n";
echo "  • " . count($risks)    . " risks with financial exposure data\n";
echo "  • " . count($vendors)  . " vendors with assessments\n";
echo "  • " . count($incidents). " incidents (mix of open/resolved)\n";
echo "  • " . count($policies) . " policies across categories\n";
echo "  • " . count($audits)   . " audits (completed/in-progress/planned)\n";
echo "  • " . count($assets)   . " IT assets with risk linkages\n";
echo "  • " . count($kris)     . " KRIs with 4-6 months of trend data\n";
echo "  • " . count($poams)    . " POA&Ms with milestones\n";
echo "  • " . count($issues)   . " issues\n";
echo "  • SOC 2 compliance controls partially implemented\n\n";
echo "  Tip: Run seed_frameworks.php first if compliance data is empty.\n\n";
