#!/usr/bin/env php
<?php
/**
 * AEGIS GRC — Notification cron script.
 * Sends 5 categories of email notifications to users based on their preferences.
 *
 * Add to crontab:
 *   0 * * * * php /var/www/aegis/scripts/send_notifications.php >> /var/log/aegis-notifications.log 2>&1
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('AEGIS_ROOT', dirname(__DIR__));

// ── Load environment variables ────────────────────────────────────────────────
foreach (['.env.local', '.env'] as $envFile) {
    $envPath = AEGIS_ROOT . '/' . $envFile;
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
        break;
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';
require_once AEGIS_ROOT . '/src/Auth.php';
require_once AEGIS_ROOT . '/src/Mailer.php';

// ── Self-bootstrap notification tables ───────────────────────────────────────
Database::query("
    CREATE TABLE IF NOT EXISTS notification_log (
        id SERIAL PRIMARY KEY,
        user_id INTEGER,
        notification_type VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100),
        entity_id INTEGER,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
", []);

Database::query("
    CREATE INDEX IF NOT EXISTS idx_notif_log
        ON notification_log(notification_type, entity_id, sent_at)
", []);

Database::query("
    CREATE TABLE IF NOT EXISTS user_notification_prefs (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        notification_type VARCHAR(100) NOT NULL,
        enabled BOOLEAN NOT NULL DEFAULT TRUE,
        UNIQUE(user_id, notification_type)
    )
", []);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Check whether the user has a given notification type enabled.
 * Defaults to TRUE if no preference row exists.
 */
function notifEnabled(int $userId, string $type): bool {
    $row = Database::fetchOne(
        "SELECT enabled FROM user_notification_prefs WHERE user_id = ? AND notification_type = ?",
        [$userId, $type]
    );
    if ($row === null) {
        return true; // default: enabled
    }
    return (bool) $row['enabled'];
}

/**
 * Check whether we already sent this notification today (for daily cadence).
 * For pending_approval and open_incident_aging the window is 24 h / 48 h
 * respectively — those pass a custom $withinSeconds argument.
 */
function alreadyNotified(int $userId, string $notifType, string $entityType, int $entityId, int $withinSeconds = 86400): bool {
    $row = Database::fetchOne(
        "SELECT id FROM notification_log
          WHERE user_id = ?
            AND notification_type = ?
            AND entity_type = ?
            AND entity_id = ?
            AND sent_at >= NOW() - INTERVAL '1 second' * ?
          LIMIT 1",
        [$userId, $notifType, $entityType, $entityId, $withinSeconds]
    );
    return $row !== null;
}

/**
 * Record that we sent a notification.
 */
function logNotification(int $userId, string $notifType, string $entityType, int $entityId): void {
    Database::query(
        "INSERT INTO notification_log (user_id, notification_type, entity_type, entity_id, sent_at)
         VALUES (?, ?, ?, ?, NOW())",
        [$userId, $notifType, $entityType, $entityId]
    );
}

/**
 * Wrap content in a standard AEGIS HTML email shell.
 */
function emailShell(string $title, string $innerHtml): string {
    $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
    $date   = date('F j, Y');
    return <<<HTML
<html>
<body style="font-family:Arial,sans-serif;color:#1f2937;max-width:680px;margin:0 auto;padding:0">
  <div style="background:#1e3a5f;color:#fff;padding:24px;border-radius:8px 8px 0 0">
    <h1 style="margin:0;font-size:20px">AEGIS GRC</h1>
    <p style="margin:4px 0 0;opacity:.8;font-size:13px">{$date}</p>
  </div>
  <div style="padding:24px;background:#f9fafb;border:1px solid #e5e7eb;border-top:none">
    <h2 style="margin-top:0;font-size:18px;color:#1e3a5f">{$title}</h2>
    {$innerHtml}
  </div>
  <div style="padding:14px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb">
    AEGIS GRC &mdash; <a href="{$appUrl}" style="color:#6366f1">Open Dashboard</a>
  </div>
</body>
</html>
HTML;
}

// ── Counters ──────────────────────────────────────────────────────────────────
$sentOverdue   = 0;
$sentPolicy    = 0;
$sentApprovals = 0;
$sentRisks     = 0;
$sentIncidents = 0;

// ═══════════════════════════════════════════════════════════════════════════════
// 1. OVERDUE CONTROLS
// ═══════════════════════════════════════════════════════════════════════════════
$overdueRows = Database::fetchAll(
    "SELECT co.id, co.code, co.title, cp.name AS package_name,
            ci.due_date, u.id AS user_id, u.email, u.name AS user_name
     FROM control_implementations ci
     JOIN compliance_objectives co ON co.id = ci.objective_id
     JOIN compliance_packages   cp ON cp.id = co.package_id
     JOIN users u ON u.id = ci.assigned_to
     WHERE ci.status NOT IN ('compliant','not_applicable')
       AND ci.due_date < CURRENT_DATE
       AND u.is_active = TRUE",
    []
);

// Group by user
$overdueByUser = [];
foreach ($overdueRows as $row) {
    $overdueByUser[$row['user_id']][] = $row;
}

foreach ($overdueByUser as $userId => $controls) {
    if (!notifEnabled((int) $userId, 'overdue_controls')) {
        continue;
    }

    $email    = $controls[0]['email'];
    $userName = $controls[0]['user_name'];
    $count    = count($controls);

    // One email per user per day — use user_id as entity_id, 'user' as entity_type
    if (alreadyNotified((int) $userId, 'overdue_controls', 'user', (int) $userId, 86400)) {
        continue;
    }

    $tableRows = '';
    foreach ($controls as $c) {
        $daysOverdue = (int) floor((time() - strtotime($c['due_date'])) / 86400);
        $tableRows .= sprintf(
            '<tr>
               <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-family:monospace;font-size:13px">%s</td>
               <td style="padding:8px;border-bottom:1px solid #e5e7eb">%s</td>
               <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">%s</td>
               <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#ef4444;font-weight:600">%d day%s</td>
             </tr>',
            htmlspecialchars($c['code'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($c['package_name'], ENT_QUOTES, 'UTF-8'),
            $daysOverdue,
            $daysOverdue === 1 ? '' : 's'
        );
    }

    $controlWord = $count === 1 ? 'control' : 'controls';
    $inner = <<<HTML
<p style="margin-top:0">Hi {$userName}, you have <strong>{$count} overdue compliance {$controlWord}</strong> that require your attention:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px">
  <thead>
    <tr style="background:#f3f4f6">
      <th style="padding:8px;text-align:left">Code</th>
      <th style="padding:8px;text-align:left">Title</th>
      <th style="padding:8px;text-align:left">Package</th>
      <th style="padding:8px;text-align:left">Overdue</th>
    </tr>
  </thead>
  <tbody>{$tableRows}</tbody>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to update the status of these controls.</p>
HTML;

    $subject = "Overdue: {$count} compliance control" . ($count === 1 ? '' : 's') . " require attention";
    $body    = emailShell('Overdue Compliance Controls', $inner);

    if (Mailer::sendFromSettings($email, $userName, $subject, $body)) {
        logNotification((int) $userId, 'overdue_controls', 'user', (int) $userId);
        $sentOverdue++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. POLICY REVIEW DUE
// ═══════════════════════════════════════════════════════════════════════════════
$policyRows = Database::fetchAll(
    "SELECT p.id, p.title, p.next_review_date,
            u.id AS user_id, u.email, u.name AS user_name
     FROM policies p
     JOIN users u ON u.id = p.owner_id
     WHERE p.status = 'published'
       AND p.next_review_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '7 days'
       AND u.is_active = TRUE",
    []
);

// Group by user
$policyByUser = [];
foreach ($policyRows as $row) {
    $policyByUser[$row['user_id']][] = $row;
}

foreach ($policyByUser as $userId => $policies) {
    if (!notifEnabled((int) $userId, 'policy_review_due')) {
        continue;
    }

    $email    = $policies[0]['email'];
    $userName = $policies[0]['user_name'];

    foreach ($policies as $policy) {
        if (alreadyNotified((int) $userId, 'policy_review_due', 'policy', (int) $policy['id'], 86400)) {
            continue;
        }

        $dueDate      = date('M j, Y', strtotime($policy['next_review_date']));
        $daysUntilDue = (int) ceil((strtotime($policy['next_review_date']) - time()) / 86400);
        $urgency      = $daysUntilDue <= 1
            ? '<span style="color:#ef4444;font-weight:600">due today</span>'
            : "<span style=\"color:#f59e0b;font-weight:600\">due in {$daysUntilDue} days ({$dueDate})</span>";

        $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>The following policy is {$urgency} for review:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Policy</th>
    <th style="padding:8px;text-align:left">Review Date</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$policy['title']}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$dueDate}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to review and update this policy.</p>
HTML;

        $subject = "Policy review due: {$policy['title']}";
        $body    = emailShell('Policy Review Reminder', $inner);

        if (Mailer::sendFromSettings($email, $userName, $subject, $body)) {
            logNotification((int) $userId, 'policy_review_due', 'policy', (int) $policy['id']);
            $sentPolicy++;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. PENDING APPROVAL REMINDERS
// ═══════════════════════════════════════════════════════════════════════════════
$approvalRows = Database::fetchAll(
    "SELECT ar.id, ar.entity_type, ar.entity_id, at.name AS template_name,
            ars.label AS step_label, ars.due_at,
            u.id AS user_id, u.email, u.name AS user_name
     FROM approval_requests ar
     JOIN approval_templates at ON ar.template_id = at.id
     JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
     JOIN users u ON (u.id = ars.required_user_id OR u.role = ars.required_role)
     WHERE ar.status = 'pending'
       AND ar.created_at < NOW() - INTERVAL '4 hours'
       AND u.is_active = TRUE",
    []
);

foreach ($approvalRows as $row) {
    $userId = (int) $row['user_id'];

    if (!notifEnabled($userId, 'pending_approval')) {
        continue;
    }

    // Remind once per approval per 24 h
    if (alreadyNotified($userId, 'pending_approval', 'approval_request', (int) $row['id'], 86400)) {
        continue;
    }

    $dueAt   = $row['due_at'] ? date('M j, Y g:ia', strtotime($row['due_at'])) : 'No deadline set';
    $inner   = <<<HTML
<p style="margin-top:0">Hi {$row['user_name']},</p>
<p>An approval request is waiting for your action:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Workflow</th>
    <th style="padding:8px;text-align:left">Step</th>
    <th style="padding:8px;text-align:left">Due</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$row['template_name']}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$row['step_label']}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$dueAt}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to review and approve or reject this request.</p>
HTML;

    $subject = "Action required: Approval pending \u{2014} {$row['template_name']}";
    $body    = emailShell('Approval Action Required', $inner);

    if (Mailer::sendFromSettings($row['email'], $row['user_name'], $subject, $body)) {
        logNotification($userId, 'pending_approval', 'approval_request', (int) $row['id']);
        $sentApprovals++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. NEW RISK ASSIGNED
// ═══════════════════════════════════════════════════════════════════════════════
$riskRows = Database::fetchAll(
    "SELECT r.id, r.risk_id, r.title, r.inherent_score,
            u.id AS user_id, u.email, u.name AS user_name
     FROM risks r
     JOIN users u ON u.id = r.owner_id
     WHERE r.created_at >= NOW() - INTERVAL '2 hours'
       AND r.status = 'open'
       AND u.is_active = TRUE",
    []
);

foreach ($riskRows as $row) {
    $userId = (int) $row['user_id'];

    if (!notifEnabled($userId, 'new_risk_assigned')) {
        continue;
    }

    // Send once per risk (per day — so if cron runs again within the hour it's idempotent)
    if (alreadyNotified($userId, 'new_risk_assigned', 'risk', (int) $row['id'], 86400)) {
        continue;
    }

    $score      = (int) $row['inherent_score'];
    $scoreColor = $score >= 15 ? '#ef4444' : ($score >= 8 ? '#f59e0b' : '#22c55e');
    $riskIdStr  = htmlspecialchars($row['risk_id'] ?? "#{$row['id']}", ENT_QUOTES, 'UTF-8');
    $riskTitle  = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');

    $inner = <<<HTML
<p style="margin-top:0">Hi {$row['user_name']},</p>
<p>A new risk has been assigned to you:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Risk ID</th>
    <th style="padding:8px;text-align:left">Title</th>
    <th style="padding:8px;text-align:left">Inherent Score</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-family:monospace">{$riskIdStr}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$riskTitle}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:700;color:{$scoreColor}">{$score}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to review this risk and plan your response.</p>
HTML;

    $subject = "Risk assigned: {$row['title']}";
    $body    = emailShell('New Risk Assigned', $inner);

    if (Mailer::sendFromSettings($row['email'], $row['user_name'], $subject, $body)) {
        logNotification($userId, 'new_risk_assigned', 'risk', (int) $row['id']);
        $sentRisks++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 5. OPEN INCIDENT AGING
// ═══════════════════════════════════════════════════════════════════════════════
$incidentRows = Database::fetchAll(
    "SELECT i.id, i.title, i.severity, i.created_at,
            u.id AS user_id, u.email, u.name AS user_name
     FROM incidents i
     JOIN users u ON u.id = i.owner_id
     WHERE i.status NOT IN ('resolved','closed')
       AND i.created_at < NOW() - INTERVAL '48 hours'
       AND u.is_active = TRUE",
    []
);

foreach ($incidentRows as $row) {
    $userId = (int) $row['user_id'];

    if (!notifEnabled($userId, 'open_incident_aging')) {
        continue;
    }

    // Remind once per incident per 48 h
    if (alreadyNotified($userId, 'open_incident_aging', 'incident', (int) $row['id'], 172800)) {
        continue;
    }

    $ageHours    = (int) floor((time() - strtotime($row['created_at'])) / 3600);
    $ageDays     = (int) floor($ageHours / 24);
    $ageStr      = $ageDays > 0 ? "{$ageDays} day" . ($ageDays === 1 ? '' : 's') : "{$ageHours} hour" . ($ageHours === 1 ? '' : 's');
    $sevColor    = match ($row['severity']) {
        'critical' => '#7f1d1d',
        'high'     => '#ef4444',
        'medium'   => '#f59e0b',
        default    => '#22c55e',
    };
    $sevLabel    = htmlspecialchars(ucfirst($row['severity']), ENT_QUOTES, 'UTF-8');
    $incTitle    = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');

    $inner = <<<HTML
<p style="margin-top:0">Hi {$row['user_name']},</p>
<p>The following incident has been open and unresolved for <strong>{$ageStr}</strong>:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Incident</th>
    <th style="padding:8px;text-align:left">Severity</th>
    <th style="padding:8px;text-align:left">Age</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$incTitle}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:700;color:{$sevColor}">{$sevLabel}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$ageStr}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to update or resolve this incident.</p>
HTML;

    $subject = "Unresolved incident aging: {$row['title']} ({$row['severity']})";
    $body    = emailShell('Aging Incident Alert', $inner);

    if (Mailer::sendFromSettings($row['email'], $row['user_name'], $subject, $body)) {
        logNotification($userId, 'open_incident_aging', 'incident', (int) $row['id']);
        $sentIncidents++;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Notifications: {$sentOverdue} overdue, {$sentPolicy} reviews, "
   . "{$sentApprovals} approvals, {$sentRisks} risk assignments, {$sentIncidents} aging incidents\n";
