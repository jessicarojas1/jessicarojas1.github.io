#!/usr/bin/env php
<?php
/**
 * AEGIS Workflow Automation Executor
 * Evaluates all active workflows and fires actions for matching triggers.
 *
 * Supported triggers:
 *   risk_score_threshold      — risk inherent_score >= configured min_score
 *   audit_overdue             — audit past scheduled_date and still 'planned'
 *   policy_review_due         — policy next_review_date within N days
 *   incident_created_severity — new incident with severity in configured list
 *   vendor_assessment_overdue — vendor assessment past due and not completed
 *   control_non_compliant     — control_implementation.status = 'non_compliant'
 *   scheduled                 — fires once per day/week/month (cron frequency)
 *
 * Supported actions:
 *   create_alert  — in-app notification for specified roles
 *   send_email    — email to specified addresses or roles
 *   create_issue  — auto-create a remediation issue
 *
 * Add to crontab:
 *   * /5 * * * * php /var/www/aegis/scripts/run_workflows.php >> /var/log/aegis-workflows.log 2>&1
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('AEGIS_ROOT', dirname(__DIR__));
define('AEGIS_WORKER', true);

foreach (['.env.local', '.env'] as $envFile) {
    if (file_exists(AEGIS_ROOT . '/' . $envFile)) {
        foreach (file(AEGIS_ROOT . '/' . $envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';
require_once AEGIS_ROOT . '/src/Mailer.php';

$now = date('Y-m-d H:i:s');
$log = static function(string $msg) use ($now): void {
    echo "[{$now}] {$msg}\n";
};

// ── Load active workflows ────────────────────────────────────────────────────

$workflows = Database::fetchAll(
    "SELECT * FROM workflows WHERE is_active = TRUE ORDER BY id"
);

$log("Evaluating " . count($workflows) . " active workflow(s).");

foreach ($workflows as $wf) {
    $triggerType   = $wf['trigger_type'];
    $triggerConfig = json_decode($wf['trigger_config'], true) ?? [];
    $actions       = json_decode($wf['actions'], true) ?? [];
    $cooldown      = (int)($wf['cooldown_seconds'] ?? 3600);

    // Cooldown: skip if triggered less than cooldown seconds ago
    if ($wf['last_triggered_at']) {
        $lastTriggered = strtotime($wf['last_triggered_at']);
        if (time() - $lastTriggered < $cooldown) {
            continue;
        }
    }

    $matches = evaluateTrigger($triggerType, $triggerConfig);

    if (empty($matches)) continue;

    $log("Workflow [{$wf['id']}] '{$wf['name']}' triggered ({$triggerType}) — " . count($matches) . " match(es).");

    $actionsTaken = [];
    foreach ($matches as $entity) {
        foreach ($actions as $action) {
            $result = executeAction($action, $entity, $wf);
            $actionsTaken[] = $result;
        }
    }

    // Record execution
    Database::query(
        "INSERT INTO workflow_executions (workflow_id, triggered_at, trigger_data, actions_taken, status)
         VALUES (?, NOW(), ?, ?, 'success')",
        [$wf['id'], json_encode($matches), json_encode($actionsTaken)]
    );

    Database::query(
        "UPDATE workflows SET last_triggered_at = NOW() WHERE id = ?",
        [$wf['id']]
    );
}

$log("Done.");

// ─────────────────────────────────────────────────────────────────────────────
// Trigger evaluators
// ─────────────────────────────────────────────────────────────────────────────

function evaluateTrigger(string $type, array $cfg): array {
    return match ($type) {
        'risk_score_threshold'       => triggerRiskScore($cfg),
        'audit_overdue'              => triggerAuditOverdue($cfg),
        'policy_review_due'          => triggerPolicyReview($cfg),
        'incident_created_severity'  => triggerIncidentSeverity($cfg),
        'vendor_assessment_overdue'  => triggerVendorAssessmentOverdue($cfg),
        'control_non_compliant'      => triggerControlNonCompliant($cfg),
        'scheduled'                  => triggerScheduled($cfg),
        default                      => [],
    };
}

function triggerRiskScore(array $cfg): array {
    $min = (int)($cfg['min_score'] ?? 15);
    $statuses = $cfg['statuses'] ?? ['open'];
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    return Database::fetchAll(
        "SELECT r.id, r.title, r.inherent_score, r.status, u.email as owner_email, u.name as owner_name
         FROM risks r LEFT JOIN users u ON r.owner_id = u.id
         WHERE r.inherent_score >= ? AND r.status IN ({$placeholders})",
        array_merge([$min], $statuses)
    );
}

function triggerAuditOverdue(array $cfg): array {
    $graceDays = (int)($cfg['grace_days'] ?? 0);
    return Database::fetchAll(
        "SELECT a.id, a.name as title, a.scheduled_date, a.status, u.email as auditor_email, u.name as auditor_name
         FROM audits a LEFT JOIN users u ON a.auditor_id = u.id
         WHERE a.status = 'planned'
           AND a.scheduled_date IS NOT NULL
           AND a.scheduled_date < CURRENT_DATE - INTERVAL '{$graceDays} days'"
    );
}

function triggerPolicyReview(array $cfg): array {
    $daysAhead = (int)($cfg['days_ahead'] ?? 30);
    return Database::fetchAll(
        "SELECT p.id, p.title, p.next_review_date, u.email as owner_email, u.name as owner_name
         FROM policies p LEFT JOIN users u ON p.owner_id = u.id
         WHERE p.status = 'published'
           AND p.next_review_date IS NOT NULL
           AND p.next_review_date <= CURRENT_DATE + INTERVAL '{$daysAhead} days'
           AND p.next_review_date >= CURRENT_DATE"
    );
}

function triggerIncidentSeverity(array $cfg): array {
    $severities = $cfg['severities'] ?? ['critical', 'high'];
    $ageMinutes = (int)($cfg['age_minutes'] ?? 5);
    $placeholders = implode(',', array_fill(0, count($severities), '?'));
    return Database::fetchAll(
        "SELECT i.id, i.title, i.severity, i.status, u.email as owner_email
         FROM incidents i LEFT JOIN users u ON i.assigned_to = u.id
         WHERE i.severity IN ({$placeholders})
           AND i.created_at >= NOW() - INTERVAL '{$ageMinutes} minutes'",
        $severities
    );
}

function triggerVendorAssessmentOverdue(array $cfg): array {
    $graceDays = (int)($cfg['grace_days'] ?? 0);
    return Database::fetchAll(
        "SELECT va.id, v.name as title, va.scheduled_date, va.assessment_type
         FROM vendor_assessments va JOIN vendors v ON va.vendor_id = v.id
         WHERE va.status IN ('planned','in_progress')
           AND va.scheduled_date < CURRENT_DATE - INTERVAL '{$graceDays} days'"
    );
}

function triggerControlNonCompliant(array $cfg): array {
    $packageId = isset($cfg['package_id']) ? (int)$cfg['package_id'] : null;
    $sql = "SELECT ci.id, co.title, co.code, cp.name as package_name, u.email as assignee_email
            FROM control_implementations ci
            JOIN compliance_objectives co ON ci.objective_id = co.id
            JOIN compliance_packages cp ON co.package_id = cp.id
            LEFT JOIN users u ON ci.assigned_to = u.id
            WHERE ci.status = 'non_compliant'";
    $params = [];
    if ($packageId) { $sql .= " AND co.package_id = ?"; $params[] = $packageId; }
    return Database::fetchAll($sql, $params);
}

function triggerScheduled(array $cfg): array {
    // Returns a synthetic single-entity so actions fire once per run
    $freq = $cfg['frequency'] ?? 'daily';
    $lastCol = Database::fetchOne("SELECT MAX(triggered_at) as t FROM workflow_executions we
        JOIN workflows w ON we.workflow_id = w.id WHERE w.trigger_type = 'scheduled'");
    $last = $lastCol['t'] ? strtotime($lastCol['t']) : 0;
    $threshold = match ($freq) {
        'hourly'  => 3600,
        'daily'   => 86400,
        'weekly'  => 86400 * 7,
        'monthly' => 86400 * 30,
        default   => 86400,
    };
    if (time() - $last < $threshold) return [];
    return [['id' => 0, 'title' => "Scheduled ({$freq})"]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Action executors
// ─────────────────────────────────────────────────────────────────────────────

function executeAction(array $action, array $entity, array $workflow): array {
    $type   = $action['type'] ?? '';
    $config = $action['config'] ?? [];

    return match ($type) {
        'create_alert' => actionCreateAlert($config, $entity, $workflow),
        'send_email'   => actionSendEmail($config, $entity, $workflow),
        'create_issue' => actionCreateIssue($config, $entity, $workflow),
        default        => ['type' => $type, 'status' => 'unknown_action'],
    };
}

function actionCreateAlert(array $cfg, array $entity, array $wf): array {
    $title   = str_replace('{title}', $entity['title'] ?? '', $cfg['title'] ?? $wf['name']);
    $message = str_replace('{title}', $entity['title'] ?? '', $cfg['message'] ?? '');
    $severity = $cfg['severity'] ?? 'warning';
    $roles   = $cfg['roles'] ?? ['admin', 'manager'];

    $users = Database::fetchAll(
        "SELECT id FROM users WHERE role = ANY(?) AND is_active = TRUE",
        ['{' . implode(',', $roles) . '}']
    );

    foreach ($users as $u) {
        Database::query(
            "INSERT INTO alerts (type, title, message, severity, user_id, related_type, related_id)
             VALUES ('workflow', ?, ?, ?, ?, ?, ?)",
            [$title, $message, $severity, $u['id'], $wf['trigger_type'], $entity['id'] ?? null]
        );
    }
    return ['type' => 'create_alert', 'status' => 'sent', 'recipients' => count($users)];
}

function actionSendEmail(array $cfg, array $entity, array $wf): array {
    $subject = str_replace('{title}', $entity['title'] ?? '', $cfg['subject'] ?? $wf['name']);
    $body    = str_replace('{title}', $entity['title'] ?? '', $cfg['body'] ?? '');
    $sent    = 0;

    $recipients = $cfg['emails'] ?? [];

    // Add users by role
    if (!empty($cfg['roles'])) {
        $roleUsers = Database::fetchAll(
            "SELECT email, name FROM users WHERE role = ANY(?) AND is_active = TRUE",
            ['{' . implode(',', $cfg['roles']) . '}']
        );
        foreach ($roleUsers as $ru) {
            $recipients[] = $ru['email'];
        }
    }

    // Add entity owner/assignee email
    if (!empty($entity['owner_email'])) $recipients[] = $entity['owner_email'];
    if (!empty($entity['auditor_email'])) $recipients[] = $entity['auditor_email'];
    if (!empty($entity['assignee_email'])) $recipients[] = $entity['assignee_email'];

    $smtpHost = $_ENV['SMTP_HOST'] ?? '';
    $smtpUser = $_ENV['SMTP_USER'] ?? '';
    $smtpPass = $_ENV['SMTP_PASS'] ?? '';
    $fromAddr = $_ENV['SMTP_FROM'] ?? $smtpUser;

    if (!$smtpHost || !$smtpUser) {
        return ['type' => 'send_email', 'status' => 'smtp_not_configured'];
    }

    foreach (array_unique($recipients) as $to) {
        $ok = Mailer::send($to, '', $subject, "<p>{$body}</p>", $body,
            $smtpHost, (int)($_ENV['SMTP_PORT'] ?? 587), $smtpUser, $smtpPass, $fromAddr);
        if ($ok) $sent++;
    }

    return ['type' => 'send_email', 'status' => 'sent', 'recipients' => $sent];
}

function actionCreateIssue(array $cfg, array $entity, array $wf): array {
    $title   = str_replace('{title}', $entity['title'] ?? '', $cfg['title'] ?? "Workflow: {$wf['name']}");
    $desc    = str_replace('{title}', $entity['title'] ?? '', $cfg['description'] ?? '');
    $severity = $cfg['severity'] ?? 'medium';

    // Avoid duplicate issues for the same entity from the same workflow
    $existing = Database::fetchOne(
        "SELECT id FROM issues WHERE title = ? AND source_type = ? AND source_id = ? AND status NOT IN ('resolved','closed','wont_fix')",
        [$title, 'workflow', $wf['id']]
    );
    if ($existing) {
        return ['type' => 'create_issue', 'status' => 'already_exists', 'issue_id' => $existing['id']];
    }

    Database::query(
        "INSERT INTO issues (title, description, severity, status, source_type, source_id, created_at)
         VALUES (?, ?, ?, 'open', 'workflow', ?, NOW())",
        [$title, $desc, $severity, $wf['id']]
    );
    $issue = Database::fetchOne("SELECT id FROM issues WHERE title = ? AND source_type = 'workflow' AND source_id = ? ORDER BY id DESC LIMIT 1",
        [$title, $wf['id']]);
    return ['type' => 'create_issue', 'status' => 'created', 'issue_id' => $issue['id'] ?? null];
}
