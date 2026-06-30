#!/usr/bin/env php
<?php
/**
 * AEGIS GRC — Notification cron script.
 * Sends 11 categories of email notifications to users based on their preferences.
 * Supports immediate and digest (daily/weekly) delivery modes.
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

// ── Digest mode: load users who prefer non-immediate delivery ─────────────────
/** @var array<int,array{user_id:int,digest_mode:string,digest_time:string}> $digestUsers */
$digestUsers = Database::fetchAll(
    "SELECT DISTINCT user_id, digest_mode, digest_time FROM user_notification_prefs WHERE digest_mode != 'immediate'"
) ?: [];
/** @var array<int,int> $digestUserIds  key = user_id, value = user_id */
$digestUserIds = array_column($digestUsers, 'user_id', 'user_id');
/** @var array<int,string> $digestModes  key = user_id, value = digest_mode ('daily'|'weekly') */
$digestModes = array_column($digestUsers, 'digest_mode', 'user_id');

/** @var array<int,list<array{type:string,data:array<string,mixed>}>> $digestQueue */
$digestQueue = [];

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
    $unsubUrl = $appUrl . '/profile/notifications';
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
    AEGIS GRC &mdash; <a href="{$appUrl}" style="color:#6366f1">Open Dashboard</a><br>
    <span style="font-size:11px">To manage notifications visit <a href="{$unsubUrl}" style="color:#6366f1">{$unsubUrl}</a></span>
  </div>
</body>
</html>
HTML;
}

/**
 * Instead of sending immediately, store a notification in the digest queue.
 */
function collectDigest(int $userId, string $type, array $notificationData): void {
    global $digestQueue;
    $digestQueue[$userId][] = ['type' => $type, 'data' => $notificationData];
}

/**
 * Decide whether to send immediately or queue for digest.
 * Returns true if sent immediately (caller should logNotification), false if queued/skipped.
 */
function maybeSendOrQueue(
    int    $userId,
    string $notifType,
    string $email,
    string $userName,
    string $subject,
    string $htmlBody,
    array  $digestData
): bool {
    global $digestUserIds;

    if (isset($digestUserIds[$userId])) {
        collectDigest($userId, $notifType, array_merge($digestData, [
            '_email'    => $email,
            '_userName' => $userName,
            '_subject'  => $subject,
        ]));
        return false; // queued, caller should NOT logNotification yet
    }

    return Mailer::sendFromSettings($email, $userName, $subject, $htmlBody);
}

// ── Counters ──────────────────────────────────────────────────────────────────
$sentOverdue         = 0;
$sentPolicy          = 0;
$sentPolicyExpiry    = 0;
$sentApprovals       = 0;
$sentRisks           = 0;
$sentIncidents       = 0;
$sentRiskReview      = 0;
$sentTreatment       = 0;
$sentScoreWorsened   = 0;
$sentVendorAssess    = 0;
$sentDocExpiring     = 0;
$sentAssessStale     = 0;
$sentEvidenceExpiry  = 0;
$sentAcceptExpiring  = 0;
$sentKriBreach       = 0;
$sentSlaBreach       = 0;

// ═══════════════════════════════════════════════════════════════════════════════
// 1. OVERDUE CONTROLS
// ═══════════════════════════════════════════════════════════════════════════════
try {
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

        $sent = maybeSendOrQueue(
            (int) $userId, 'overdue_controls', $email, $userName, $subject, $body,
            ['count' => $count, 'controls' => $controls]
        );
        if ($sent) {
            logNotification((int) $userId, 'overdue_controls', 'user', (int) $userId);
            $sentOverdue++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[overdue_controls] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. POLICY REVIEW DUE
// ═══════════════════════════════════════════════════════════════════════════════
try {
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

            $sent = maybeSendOrQueue(
                (int) $userId, 'policy_review_due', $email, $userName, $subject, $body,
                ['policy' => $policy, 'daysUntilDue' => $daysUntilDue, 'dueDate' => $dueDate]
            );
            if ($sent) {
                logNotification((int) $userId, 'policy_review_due', 'policy', (int) $policy['id']);
                $sentPolicy++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[policy_review_due] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2b. POLICY EXPIRING (hard expiry date, distinct from review cadence)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $policyExpRows = Database::fetchAll(
        "SELECT p.id, p.title, p.expires_at,
                u.id AS user_id, u.email, u.name AS user_name
         FROM policies p
         JOIN users u ON u.id = p.owner_id
         WHERE p.status = 'published'
           AND p.expires_at IS NOT NULL
           AND p.expires_at BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
           AND u.is_active = TRUE",
        []
    );

    $policyExpByUser = [];
    foreach ($policyExpRows as $row) {
        $policyExpByUser[$row['user_id']][] = $row;
    }

    foreach ($policyExpByUser as $userId => $policies) {
        if (!notifEnabled((int) $userId, 'policy_expiring')) {
            continue;
        }
        $email    = $policies[0]['email'];
        $userName = $policies[0]['user_name'];

        foreach ($policies as $policy) {
            // Throttle: one reminder per policy per 7 days.
            if (alreadyNotified((int) $userId, 'policy_expiring', 'policy', (int) $policy['id'], 604800)) {
                continue;
            }

            $expDate   = date('M j, Y', strtotime($policy['expires_at']));
            $daysLeft  = (int) ceil((strtotime($policy['expires_at']) - time()) / 86400);
            $urgency   = $daysLeft <= 1
                ? '<span style="color:#ef4444;font-weight:600">expires today</span>'
                : "<span style=\"color:#f59e0b;font-weight:600\">expires in {$daysLeft} days ({$expDate})</span>";

            $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>The following policy {$urgency} and may need renewal or formal retirement:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Policy</th>
    <th style="padding:8px;text-align:left">Expiry Date</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$policy['title']}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$expDate}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to renew, supersede, or retire this policy.</p>
HTML;

            $subject = "Policy expiring: {$policy['title']}";
            $body    = emailShell('Policy Expiry Reminder', $inner);

            $sent = maybeSendOrQueue(
                (int) $userId, 'policy_expiring', $email, $userName, $subject, $body,
                ['policy' => $policy, 'daysLeft' => $daysLeft, 'expDate' => $expDate]
            );
            if ($sent) {
                logNotification((int) $userId, 'policy_expiring', 'policy', (int) $policy['id']);
                $sentPolicyExpiry++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[policy_expiring] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2c. RISK ACCEPTANCE EXPIRING (active acceptances nearing valid_until)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $acceptRows = Database::fetchAll(
        "SELECT ra.id, ra.valid_until,
                r.id AS risk_id, r.title AS risk_title,
                u.id AS user_id, u.email, u.name AS user_name
         FROM risk_acceptances ra
         JOIN risks r ON r.id = ra.risk_id
         JOIN users u ON u.id = r.owner_id
         WHERE ra.status = 'active'
           AND ra.valid_until BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
           AND u.is_active = TRUE",
        []
    );

    $acceptByUser = [];
    foreach ($acceptRows as $row) {
        $acceptByUser[$row['user_id']][] = $row;
    }

    foreach ($acceptByUser as $userId => $accepts) {
        if (!notifEnabled((int) $userId, 'risk_acceptance_expiring')) {
            continue;
        }
        $email    = $accepts[0]['email'];
        $userName = $accepts[0]['user_name'];

        foreach ($accepts as $accept) {
            // Throttle: one reminder per acceptance per 7 days.
            if (alreadyNotified((int) $userId, 'risk_acceptance_expiring', 'risk_acceptance', (int) $accept['id'], 604800)) {
                continue;
            }

            $expDate  = date('M j, Y', strtotime($accept['valid_until']));
            $daysLeft = (int) ceil((strtotime($accept['valid_until']) - time()) / 86400);
            $urgency  = $daysLeft <= 1
                ? '<span style="color:#ef4444;font-weight:600">expires today</span>'
                : "<span style=\"color:#f59e0b;font-weight:600\">expires in {$daysLeft} days ({$expDate})</span>";

            $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>A risk acceptance you own {$urgency} and will need renewal or the risk re-treated:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Risk</th>
    <th style="padding:8px;text-align:left">Acceptance Valid Until</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$accept['risk_title']}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$expDate}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to renew the acceptance or re-treat the risk before it lapses.</p>
HTML;

            $subject = "Risk acceptance expiring: {$accept['risk_title']}";
            $body    = emailShell('Risk Acceptance Expiry Reminder', $inner);

            $sent = maybeSendOrQueue(
                (int) $userId, 'risk_acceptance_expiring', $email, $userName, $subject, $body,
                ['acceptance' => $accept, 'daysLeft' => $daysLeft, 'expDate' => $expDate]
            );
            if ($sent) {
                logNotification((int) $userId, 'risk_acceptance_expiring', 'risk_acceptance', (int) $accept['id']);
                $sentAcceptExpiring++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[risk_acceptance_expiring] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2d. KRI BREACH (latest recorded value in the red zone)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    // A breach is the 'red' RAG band: for higher_worse the value is above amber;
    // for lower_worse it is below amber (mirrors KRIController::ragStatus()).
    $kriRows = Database::fetchAll(
        "SELECT k.id, k.title, k.unit, kv.value AS latest_value, kv.recorded_at,
                u.id AS user_id, u.email, u.name AS user_name
         FROM kris k
         JOIN users u ON u.id = k.owner_id
         LEFT JOIN LATERAL (
             SELECT value, recorded_at FROM kri_values WHERE kri_id = k.id
             ORDER BY recorded_at DESC, id DESC LIMIT 1
         ) kv ON TRUE
         WHERE k.is_active = TRUE
           AND k.owner_id IS NOT NULL
           AND u.is_active = TRUE
           AND kv.value IS NOT NULL
           AND ( (k.direction = 'higher_worse' AND kv.value > k.threshold_amber)
              OR (k.direction = 'lower_worse'  AND kv.value < k.threshold_amber) )",
        []
    );

    $kriByUser = [];
    foreach ($kriRows as $row) {
        $kriByUser[$row['user_id']][] = $row;
    }

    foreach ($kriByUser as $userId => $kris) {
        if (!notifEnabled((int) $userId, 'kri_breached')) {
            continue;
        }
        $email    = $kris[0]['email'];
        $userName = $kris[0]['user_name'];

        foreach ($kris as $kri) {
            // Throttle: one breach reminder per KRI per 7 days.
            if (alreadyNotified((int) $userId, 'kri_breached', 'kri', (int) $kri['id'], 604800)) {
                continue;
            }

            $val      = rtrim(rtrim(number_format((float) $kri['latest_value'], 4, '.', ''), '0'), '.');
            $unit     = htmlspecialchars($kri['unit'] ?? '', ENT_QUOTES, 'UTF-8');
            $kriTitle = htmlspecialchars($kri['title'], ENT_QUOTES, 'UTF-8');
            $measured = date('M j, Y', strtotime($kri['recorded_at']));

            $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>A Key Risk Indicator you own has <span style="color:#ef4444;font-weight:600">breached its red threshold</span>:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">KRI</th>
    <th style="padding:8px;text-align:left">Latest Value</th>
    <th style="padding:8px;text-align:left">Measured</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$kriTitle}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:700;color:#ef4444">{$val} {$unit}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$measured}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to review the indicator and escalate the linked risk if required.</p>
HTML;

            $subject = "KRI breach: {$kri['title']}";
            $body    = emailShell('KRI Threshold Breach', $inner);

            $sent = maybeSendOrQueue(
                (int) $userId, 'kri_breached', $email, $userName, $subject, $body,
                ['kri' => $kri, 'value' => $val]
            );
            if ($sent) {
                logNotification((int) $userId, 'kri_breached', 'kri', (int) $kri['id']);
                $sentKriBreach++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[kri_breached] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2e. INCIDENT SLA BREACH (resolution SLA passed without a 'resolved' event)
// ═══════════════════════════════════════════════════════════════════════════════
// The Incident module UI was retired (migration 032) but the SLA report
// (/incident/sla) is retained. This records a one-time 'breach' SLA event when a
// still-open incident passes its resolution SLA, and alerts the assignee/reporter
// — closing the gap where only 'acknowledged' events were ever written.
try {
    $slaRows = Database::fetchAll(
        "SELECT i.id, i.incident_number, i.title, i.severity, i.created_at,
                isp.resolve_hours,
                res_evt.occurred_at AS resolved_at,
                brk_evt.id          AS breach_event_id,
                COALESCE(i.assigned_to, i.reported_by) AS recipient_id,
                u.id AS user_id, u.email, u.name AS user_name
         FROM incidents i
         JOIN incident_sla_policies isp ON isp.severity = i.severity
         JOIN users u ON u.id = COALESCE(i.assigned_to, i.reported_by)
         LEFT JOIN incident_sla_events res_evt ON res_evt.incident_id = i.id AND res_evt.event_type = 'resolved'
         LEFT JOIN incident_sla_events brk_evt ON brk_evt.incident_id = i.id AND brk_evt.event_type = 'breach'
         WHERE i.status NOT IN ('resolved','closed')
           AND u.is_active = TRUE
           AND res_evt.occurred_at IS NULL
           AND isp.resolve_hours IS NOT NULL
           AND i.created_at + (isp.resolve_hours * INTERVAL '1 hour') < NOW()",
        []
    );

    foreach ($slaRows as $row) {
        $incidentId = (int) $row['id'];

        // Record the breach once (idempotent): the 'breach' event is the durable
        // marker so the SLA report and audit trail reflect it even after the
        // throttle window expires.
        if (empty($row['breach_event_id'])) {
            try {
                Database::insert('incident_sla_events', [
                    'incident_id' => $incidentId,
                    'event_type'  => 'breach',
                    'recorded_by' => null,
                    'notes'       => 'Resolution SLA (' . (int) $row['resolve_hours'] . 'h) breached — auto-detected.',
                ]);
            } catch (\Throwable $e) {
                // A logging failure must not block the alert.
            }
        }

        $userId = (int) $row['user_id'];
        if (!notifEnabled($userId, 'incident_sla_breach')) {
            continue;
        }
        // Throttle: one reminder per incident per 7 days.
        if (alreadyNotified($userId, 'incident_sla_breach', 'incident', $incidentId, 604800)) {
            continue;
        }

        $ageHours = (int) floor((time() - strtotime($row['created_at'])) / 3600);
        $incNum   = htmlspecialchars($row['incident_number'], ENT_QUOTES, 'UTF-8');
        $incTitle = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
        $sev      = htmlspecialchars(ucfirst($row['severity']), ENT_QUOTES, 'UTF-8');
        $target   = (int) $row['resolve_hours'];

        $inner = <<<HTML
<p style="margin-top:0">Hi {$row['user_name']},</p>
<p>An incident assigned to you has <span style="color:#ef4444;font-weight:600">breached its resolution SLA</span>:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Incident</th>
    <th style="padding:8px;text-align:left">Severity</th>
    <th style="padding:8px;text-align:left">SLA Target</th>
    <th style="padding:8px;text-align:left">Age</th>
  </tr>
  <tr>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">{$incNum} — {$incTitle}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$sev}</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb">{$target}h</td>
    <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:700;color:#ef4444">{$ageHours}h</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please progress this incident to resolution. See the <strong>SLA Report</strong> in AEGIS GRC for the full picture.</p>
HTML;

        $subject = "Incident SLA breached: {$row['incident_number']}";
        $body    = emailShell('Incident SLA Breach', $inner);

        $sent = maybeSendOrQueue(
            $userId, 'incident_sla_breach', $row['email'], $row['user_name'], $subject, $body,
            ['incident' => $row, 'ageHours' => $ageHours, 'target' => $target]
        );
        if ($sent) {
            logNotification($userId, 'incident_sla_breach', 'incident', $incidentId);
            $sentSlaBreach++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[incident_sla_breach] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. PENDING APPROVAL REMINDERS
// ═══════════════════════════════════════════════════════════════════════════════
try {
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

        $sent = maybeSendOrQueue(
            $userId, 'pending_approval', $row['email'], $row['user_name'], $subject, $body,
            ['approval' => $row, 'dueAt' => $dueAt]
        );
        if ($sent) {
            logNotification($userId, 'pending_approval', 'approval_request', (int) $row['id']);
            $sentApprovals++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[pending_approval] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. NEW RISK ASSIGNED
// ═══════════════════════════════════════════════════════════════════════════════
try {
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

        $sent = maybeSendOrQueue(
            $userId, 'new_risk_assigned', $row['email'], $row['user_name'], $subject, $body,
            ['risk' => $row, 'score' => $score]
        );
        if ($sent) {
            logNotification($userId, 'new_risk_assigned', 'risk', (int) $row['id']);
            $sentRisks++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[new_risk_assigned] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 5. OPEN INCIDENT AGING
// ═══════════════════════════════════════════════════════════════════════════════
try {
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

        $sent = maybeSendOrQueue(
            $userId, 'open_incident_aging', $row['email'], $row['user_name'], $subject, $body,
            ['incident' => $row, 'ageStr' => $ageStr]
        );
        if ($sent) {
            logNotification($userId, 'open_incident_aging', 'incident', (int) $row['id']);
            $sentIncidents++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[open_incident_aging] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 6. RISK REVIEW OVERDUE
// Query: risks WHERE review_date < CURRENT_DATE AND status NOT IN
//        ('closed','transferred','accepted') AND owner_id IS NOT NULL
// Group by owner_id; throttle once per user per 48 h (entity_id = user_id)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $riskReviewRows = Database::fetchAll(
        "SELECT r.id, r.risk_id, r.title, r.review_date, r.inherent_score,
                r.owner_id,
                u.id AS user_id, u.email, u.name AS user_name
         FROM risks r
         JOIN users u ON u.id = r.owner_id
         WHERE r.review_date < CURRENT_DATE
           AND r.status NOT IN ('closed','transferred','accepted')
           AND r.owner_id IS NOT NULL
           AND u.is_active = TRUE",
        []
    );

    // Group by owner/user
    $riskReviewByUser = [];
    foreach ($riskReviewRows as $row) {
        $riskReviewByUser[$row['user_id']][] = $row;
    }

    foreach ($riskReviewByUser as $userId => $risks) {
        $userId = (int) $userId;

        if (!notifEnabled($userId, 'risk_review_overdue')) {
            continue;
        }

        // Throttle: once per user per 48 h
        if (alreadyNotified($userId, 'risk_review_overdue', 'user', $userId, 172800)) {
            continue;
        }

        $email    = $risks[0]['email'];
        $userName = $risks[0]['user_name'];
        $count    = count($risks);

        $tableRows = '';
        foreach ($risks as $r) {
            $daysOverdue = (int) floor((time() - strtotime($r['review_date'])) / 86400);
            $score       = (int) $r['inherent_score'];
            $scoreColor  = $score >= 15 ? '#ef4444' : ($score >= 8 ? '#f59e0b' : '#22c55e');
            $riskIdStr   = htmlspecialchars($r['risk_id'] ?? "#{$r['id']}", ENT_QUOTES, 'UTF-8');
            $tableRows  .= sprintf(
                '<tr>
                   <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-family:monospace;font-size:13px">%s</td>
                   <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:600">%s</td>
                   <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280">%s</td>
                   <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:700;color:%s">%d</td>
                   <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#ef4444;font-weight:600">%d day%s</td>
                 </tr>',
                $riskIdStr,
                htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($r['review_date'], ENT_QUOTES, 'UTF-8'),
                $scoreColor,
                $score,
                $daysOverdue,
                $daysOverdue === 1 ? '' : 's'
            );
        }

        $inner = <<<HTML
<p style="margin-top:0">Hi {$userName}, you have <strong>{$count} risk(s) overdue for review</strong>:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px">
  <thead>
    <tr style="background:#f3f4f6">
      <th style="padding:8px;text-align:left">Risk ID</th>
      <th style="padding:8px;text-align:left">Title</th>
      <th style="padding:8px;text-align:left">Review Date</th>
      <th style="padding:8px;text-align:left">Score</th>
      <th style="padding:8px;text-align:left">Days Overdue</th>
    </tr>
  </thead>
  <tbody>{$tableRows}</tbody>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to complete the risk reviews.</p>
HTML;

        $subject = "[AEGIS] {$count} risk(s) overdue for review";
        $body    = emailShell('Risk Review Overdue', $inner);

        $sent = maybeSendOrQueue(
            $userId, 'risk_review_overdue', $email, $userName, $subject, $body,
            ['count' => $count, 'risks' => $risks]
        );
        if ($sent) {
            logNotification($userId, 'risk_review_overdue', 'user', $userId);
            $sentRiskReview++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[risk_review_overdue] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 7. TREATMENT DUE
// Query: risk_treatments JOIN risks WHERE due_date <= CURRENT_DATE AND
//        status IN ('planned','in_progress') AND r.owner_id IS NOT NULL
// Group by r.owner_id; throttle once per treatment per 24 h (entity_id = rt.id)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $treatmentRows = Database::fetchAll(
        "SELECT rt.id AS treatment_id, rt.description, rt.due_date, rt.status AS treatment_status,
                r.id AS risk_id_int, r.risk_id, r.title AS risk_title, r.owner_id,
                u.id AS user_id, u.email, u.name AS user_name
         FROM risk_treatments rt
         JOIN risks r ON r.id = rt.risk_id
         JOIN users u ON u.id = r.owner_id
         WHERE rt.due_date <= CURRENT_DATE
           AND rt.status IN ('planned','in_progress')
           AND r.owner_id IS NOT NULL
           AND u.is_active = TRUE",
        []
    );

    // Group by owner/user (send one email per treatment — throttle per treatment id)
    foreach ($treatmentRows as $row) {
        $userId = (int) $row['user_id'];

        if (!notifEnabled($userId, 'treatment_due')) {
            continue;
        }

        // Throttle: once per treatment per 24 h
        if (alreadyNotified($userId, 'treatment_due', 'risk_treatment', (int) $row['treatment_id'], 86400)) {
            continue;
        }

        $email       = $row['email'];
        $userName    = $row['user_name'];
        $dueDate     = date('M j, Y', strtotime($row['due_date']));
        $riskIdStr   = htmlspecialchars($row['risk_id'] ?? "#{$row['risk_id_int']}", ENT_QUOTES, 'UTF-8');
        $riskTitle   = htmlspecialchars($row['risk_title'], ENT_QUOTES, 'UTF-8');
        $treatDesc   = htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8');
        $treatStatus = htmlspecialchars(ucfirst(str_replace('_', ' ', $row['treatment_status'])), ENT_QUOTES, 'UTF-8');

        $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>A risk treatment is due and requires your attention:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Field</th>
    <th style="padding:8px;text-align:left">Value</th>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Treatment</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$treatDesc}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Risk</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$riskIdStr} — {$riskTitle}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Status</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$treatStatus}</td>
  </tr>
  <tr>
    <td style="padding:8px;color:#6b7280;font-weight:600">Due Date</td>
    <td style="padding:8px;color:#ef4444;font-weight:600">{$dueDate}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to update this treatment.</p>
HTML;

        $subject = "[AEGIS] Risk treatment due: {$row['description']}";
        $body    = emailShell('Risk Treatment Due', $inner);

        $sent = maybeSendOrQueue(
            $userId, 'treatment_due', $email, $userName, $subject, $body,
            ['treatment' => $row, 'dueDate' => $dueDate]
        );
        if ($sent) {
            logNotification($userId, 'treatment_due', 'risk_treatment', (int) $row['treatment_id']);
            $sentTreatment++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[treatment_due] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 8. RISK SCORE WORSENED
// Find risks where the most recent score entry is >20% higher than the previous
// entry AND created_at > NOW() - 24h AND r.owner_id IS NOT NULL.
// Throttle: once per risk per 24 h (entity_id = r.id)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    // Fetch recent score history entries (last 2 entries per risk, within the last 24 h for newest)
    $scoreRows = Database::fetchAll(
        "SELECT rsh.risk_id, rsh.score, rsh.created_at,
                r.risk_id AS risk_ref, r.title AS risk_title, r.owner_id,
                u.id AS user_id, u.email, u.name AS user_name
         FROM risk_score_history rsh
         JOIN risks r ON r.id = rsh.risk_id
         JOIN users u ON u.id = r.owner_id
         WHERE rsh.created_at > NOW() - INTERVAL '24 hours'
           AND r.owner_id IS NOT NULL
           AND u.is_active = TRUE
         ORDER BY rsh.risk_id, rsh.created_at DESC",
        []
    );

    // Group by risk_id, taking newest entry; then fetch previous entry separately
    $latestByRisk = [];
    foreach ($scoreRows as $row) {
        if (!isset($latestByRisk[$row['risk_id']])) {
            $latestByRisk[$row['risk_id']] = $row;
        }
    }

    foreach ($latestByRisk as $riskId => $newest) {
        $userId = (int) $newest['user_id'];

        if (!notifEnabled($userId, 'risk_score_worsened')) {
            continue;
        }

        if (alreadyNotified($userId, 'risk_score_worsened', 'risk', (int) $riskId, 86400)) {
            continue;
        }

        // Fetch the previous score entry (before the newest one)
        $prevRow = Database::fetchOne(
            "SELECT score FROM risk_score_history
              WHERE risk_id = ?
                AND created_at < ?
              ORDER BY created_at DESC
              LIMIT 1",
            [$riskId, $newest['created_at']]
        );

        if ($prevRow === null) {
            continue; // no previous entry to compare against
        }

        $prevScore = (float) $prevRow['score'];
        $newScore  = (float) $newest['score'];

        if ($prevScore <= 0) {
            continue; // avoid division by zero / nonsensical data
        }

        $changePct = (($newScore - $prevScore) / $prevScore) * 100;

        if ($changePct <= 20) {
            continue; // not worsened by more than 20%
        }

        $email       = $newest['email'];
        $userName    = $newest['user_name'];
        $riskIdStr   = htmlspecialchars($newest['risk_ref'] ?? "#{$riskId}", ENT_QUOTES, 'UTF-8');
        $riskTitle   = htmlspecialchars($newest['risk_title'], ENT_QUOTES, 'UTF-8');
        $changePctFmt = number_format($changePct, 1);

        $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>The risk score for <strong>{$riskTitle}</strong> has increased significantly:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Field</th>
    <th style="padding:8px;text-align:left">Value</th>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Risk</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$riskIdStr} — {$riskTitle}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Previous Score</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$prevScore}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">New Score</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:700;color:#ef4444">{$newScore}</td>
  </tr>
  <tr>
    <td style="padding:8px;color:#6b7280;font-weight:600">Change</td>
    <td style="padding:8px;font-weight:700;color:#ef4444">+{$changePctFmt}%</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to review this risk and update your treatment plan.</p>
HTML;

        $subject = "[AEGIS] Risk score increased: {$newest['risk_title']}";
        $body    = emailShell('Risk Score Increased', $inner);

        $sent = maybeSendOrQueue(
            $userId, 'risk_score_worsened', $email, $userName, $subject, $body,
            ['risk' => $newest, 'prevScore' => $prevScore, 'newScore' => $newScore, 'changePct' => $changePct]
        );
        if ($sent) {
            logNotification($userId, 'risk_score_worsened', 'risk', (int) $riskId);
            $sentScoreWorsened++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[risk_score_worsened] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 9. VENDOR ASSESSMENT EXPIRING
// Find vendors with next_assessment_date between today and +30 days.
// Throttle: once per vendor per 7 days (entity_id = v.id)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    // Get the latest assessment per vendor within the 30-day window
    $vendorRows = Database::fetchAll(
        "SELECT DISTINCT ON (v.id)
                v.id AS vendor_id, v.name AS vendor_name, v.created_by,
                va.id AS assessment_id, va.next_assessment_date, va.assessed_by,
                COALESCE(u_assessor.id, u_creator.id) AS user_id,
                COALESCE(u_assessor.email, u_creator.email) AS email,
                COALESCE(u_assessor.name, u_creator.name) AS user_name
         FROM vendors v
         JOIN vendor_assessments va ON va.vendor_id = v.id
         LEFT JOIN users u_assessor ON u_assessor.id = va.assessed_by AND u_assessor.is_active = TRUE
         LEFT JOIN users u_creator  ON u_creator.id  = v.created_by   AND u_creator.is_active  = TRUE
         WHERE va.next_assessment_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
           AND COALESCE(u_assessor.id, u_creator.id) IS NOT NULL
         ORDER BY v.id, va.next_assessment_date ASC",
        []
    );

    foreach ($vendorRows as $row) {
        $userId = (int) $row['user_id'];

        if (!notifEnabled($userId, 'vendor_assessment_expiring')) {
            continue;
        }

        // Throttle: once per vendor per 7 days
        if (alreadyNotified($userId, 'vendor_assessment_expiring', 'vendor', (int) $row['vendor_id'], 604800)) {
            continue;
        }

        $email           = $row['email'];
        $userName        = $row['user_name'];
        $vendorName      = htmlspecialchars($row['vendor_name'], ENT_QUOTES, 'UTF-8');
        $dueDate         = date('M j, Y', strtotime($row['next_assessment_date']));
        $daysRemaining   = (int) ceil((strtotime($row['next_assessment_date']) - time()) / 86400);
        $dayColor        = $daysRemaining <= 7 ? '#ef4444' : '#f59e0b';
        $dayWord         = $daysRemaining === 1 ? '' : 's';

        $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>A vendor assessment is due soon:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Field</th>
    <th style="padding:8px;text-align:left">Value</th>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Vendor</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:600">{$vendorName}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Assessment Due</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$dueDate}</td>
  </tr>
  <tr>
    <td style="padding:8px;color:#6b7280;font-weight:600">Days Remaining</td>
    <td style="padding:8px;font-weight:700;color:{$dayColor}">{$daysRemaining} day{$dayWord}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to schedule or complete this vendor assessment.</p>
HTML;

        $subject = "[AEGIS] Vendor assessment due: {$row['vendor_name']}";
        $body    = emailShell('Vendor Assessment Due', $inner);

        $sent = maybeSendOrQueue(
            $userId, 'vendor_assessment_expiring', $email, $userName, $subject, $body,
            ['vendor' => $row, 'dueDate' => $dueDate, 'daysRemaining' => $daysRemaining]
        );
        if ($sent) {
            logNotification($userId, 'vendor_assessment_expiring', 'vendor', (int) $row['vendor_id']);
            $sentVendorAssess++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[vendor_assessment_expiring] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 10. DOCUMENT EXPIRING
// Query: documents WHERE expiry_date BETWEEN today AND today+30
//        AND status NOT IN ('archived','expired') AND owner_id IS NOT NULL
// Group by owner_id; throttle once per document per 7 days (entity_id = d.id)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $docRows = Database::fetchAll(
        "SELECT d.id, d.title, d.document_number, d.expiry_date, d.owner_id,
                u.id AS user_id, u.email, u.name AS user_name
         FROM documents d
         JOIN users u ON u.id = d.owner_id
         WHERE d.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
           AND d.status NOT IN ('archived','expired')
           AND d.owner_id IS NOT NULL
           AND u.is_active = TRUE",
        []
    );

    // Group by owner/user
    $docByUser = [];
    foreach ($docRows as $row) {
        $docByUser[$row['user_id']][] = $row;
    }

    foreach ($docByUser as $userId => $docs) {
        $userId = (int) $userId;

        if (!notifEnabled($userId, 'document_expiring')) {
            continue;
        }

        $email    = $docs[0]['email'];
        $userName = $docs[0]['user_name'];

        // Send one email per document (throttled individually)
        foreach ($docs as $doc) {
            if (alreadyNotified($userId, 'document_expiring', 'document', (int) $doc['id'], 604800)) {
                continue;
            }

            $dueDate       = date('M j, Y', strtotime($doc['expiry_date']));
            $daysRemaining = (int) ceil((strtotime($doc['expiry_date']) - time()) / 86400);
            $docTitle      = htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8');
            $docNumber     = htmlspecialchars($doc['document_number'] ?? '', ENT_QUOTES, 'UTF-8');
            $dayColor      = $daysRemaining <= 7 ? '#ef4444' : '#f59e0b';
            $dayWord       = $daysRemaining === 1 ? '' : 's';

            $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>A document you own is expiring soon:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Field</th>
    <th style="padding:8px;text-align:left">Value</th>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Document</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:600">{$docTitle}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Document Number</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-family:monospace">{$docNumber}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Expiry Date</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$dueDate}</td>
  </tr>
  <tr>
    <td style="padding:8px;color:#6b7280;font-weight:600">Days Remaining</td>
    <td style="padding:8px;font-weight:700;color:{$dayColor}">{$daysRemaining} day{$dayWord}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to renew or archive this document.</p>
HTML;

            $subject = "[AEGIS] Document expiring: {$doc['title']}";
            $body    = emailShell('Document Expiring Soon', $inner);

            $sent = maybeSendOrQueue(
                $userId, 'document_expiring', $email, $userName, $subject, $body,
                ['document' => $doc, 'dueDate' => $dueDate, 'daysRemaining' => $daysRemaining]
            );
            if ($sent) {
                logNotification($userId, 'document_expiring', 'document', (int) $doc['id']);
                $sentDocExpiring++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[document_expiring] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 11. ASSESSMENT PENDING STALE
// Query: risks WHERE assessment_status = 'pending_review' AND
//        updated_at < NOW() - INTERVAL '48 hours'
// Send to: risk owner AND all admin users
// Throttle: once per risk per 48 h (entity_id = r.id)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $staleRows = Database::fetchAll(
        "SELECT r.id, r.risk_id, r.title, r.inherent_score, r.updated_at, r.owner_id,
                u.id AS user_id, u.email, u.name AS user_name
         FROM risks r
         JOIN users u ON u.id = r.owner_id
         WHERE r.assessment_status = 'pending_review'
           AND r.updated_at < NOW() - INTERVAL '48 hours'
           AND r.owner_id IS NOT NULL
           AND u.is_active = TRUE",
        []
    );

    // Fetch admin users for cc-style notifications
    $adminUsers = Database::fetchAll(
        "SELECT id AS user_id, email, name AS user_name FROM users WHERE role = 'admin' AND is_active = TRUE",
        []
    ) ?: [];

    foreach ($staleRows as $row) {
        $riskId      = (int) $row['id'];
        $riskIdStr   = htmlspecialchars($row['risk_id'] ?? "#{$riskId}", ENT_QUOTES, 'UTF-8');
        $riskTitle   = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
        $score       = (int) $row['inherent_score'];
        $daysSince   = (int) floor((time() - strtotime($row['updated_at'])) / 86400);
        $subject     = "[AEGIS] Risk assessment awaiting review: {$row['title']}";

        // Collect all recipients: risk owner + admins (dedup by user_id)
        $recipients = [];
        $recipients[$row['user_id']] = [
            'user_id'   => (int) $row['user_id'],
            'email'     => $row['email'],
            'user_name' => $row['user_name'],
        ];
        foreach ($adminUsers as $admin) {
            if (!isset($recipients[$admin['user_id']])) {
                $recipients[$admin['user_id']] = [
                    'user_id'   => (int) $admin['user_id'],
                    'email'     => $admin['email'],
                    'user_name' => $admin['user_name'],
                ];
            }
        }

        foreach ($recipients as $recipient) {
            $recipientUserId = (int) $recipient['user_id'];

            if (!notifEnabled($recipientUserId, 'assessment_pending_stale')) {
                continue;
            }

            // Throttle: once per risk per 48 h per recipient
            if (alreadyNotified($recipientUserId, 'assessment_pending_stale', 'risk', $riskId, 172800)) {
                continue;
            }

            $recipName  = htmlspecialchars($recipient['user_name'], ENT_QUOTES, 'UTF-8');
            $daysSinceWord = $daysSince === 1 ? '' : 's';

            $inner = <<<HTML
<p style="margin-top:0">Hi {$recipName},</p>
<p>A risk assessment has been waiting for review for <strong>{$daysSince} day{$daysSinceWord}</strong>:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Field</th>
    <th style="padding:8px;text-align:left">Value</th>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Risk</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$riskIdStr} — {$riskTitle}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Score</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:700">{$score}</td>
  </tr>
  <tr>
    <td style="padding:8px;color:#6b7280;font-weight:600">Submitted for Review</td>
    <td style="padding:8px;color:#f59e0b;font-weight:600">{$daysSince} day{$daysSinceWord} ago</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to review and approve or return this risk assessment.</p>
HTML;

            $body = emailShell('Risk Assessment Awaiting Review', $inner);

            $sent = maybeSendOrQueue(
                $recipientUserId, 'assessment_pending_stale', $recipient['email'], $recipient['user_name'],
                $subject, $body,
                ['risk' => $row, 'daysSince' => $daysSince, 'score' => $score]
            );
            if ($sent) {
                logNotification($recipientUserId, 'assessment_pending_stale', 'risk', $riskId);
                $sentAssessStale++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[assessment_pending_stale] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// 12. EVIDENCE FILE EXPIRY
// Query: evidence_files WHERE expires_at BETWEEN today AND today + 30 days
//        AND uploaded_by IS NOT NULL
// Send to: uploader; throttle once per evidence file per 7 days
// ═══════════════════════════════════════════════════════════════════════════════
try {
    $evRows = Database::fetchAll(
        "SELECT ef.id, ef.filename, ef.entity_type, ef.entity_id, ef.expires_at, ef.uploaded_by,
                u.id AS user_id, u.email, u.name AS user_name
         FROM evidence_files ef
         JOIN users u ON u.id = ef.uploaded_by
         WHERE ef.expires_at BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
           AND ef.uploaded_by IS NOT NULL
           AND u.is_active = TRUE",
        []
    );

    $evByUser = [];
    foreach ($evRows as $row) {
        $evByUser[$row['user_id']][] = $row;
    }

    foreach ($evByUser as $userId => $files) {
        $userId = (int) $userId;

        if (!notifEnabled($userId, 'evidence_expiring')) {
            continue;
        }

        $email    = $files[0]['email'];
        $userName = $files[0]['user_name'];

        foreach ($files as $ef) {
            if (alreadyNotified($userId, 'evidence_expiring', 'evidence_files', (int) $ef['id'], 604800)) {
                continue;
            }

            $dueDate       = date('M j, Y', strtotime($ef['expires_at']));
            $daysRemaining = (int) ceil((strtotime($ef['expires_at']) - time()) / 86400);
            $fileName      = htmlspecialchars($ef['filename'], ENT_QUOTES, 'UTF-8');
            $entityRef     = htmlspecialchars(ucfirst(str_replace('_', ' ', $ef['entity_type'])) . ' #' . $ef['entity_id'], ENT_QUOTES, 'UTF-8');
            $dayColor      = $daysRemaining <= 7 ? '#ef4444' : '#f59e0b';
            $dayWord       = $daysRemaining === 1 ? '' : 's';

            $inner = <<<HTML
<p style="margin-top:0">Hi {$userName},</p>
<p>An evidence file you uploaded is expiring soon and may no longer satisfy compliance requirements:</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px">
  <tr style="background:#f3f4f6">
    <th style="padding:8px;text-align:left">Field</th>
    <th style="padding:8px;text-align:left">Value</th>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">File</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:600">{$fileName}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Linked To</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$entityRef}</td>
  </tr>
  <tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600">Expiry Date</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb">{$dueDate}</td>
  </tr>
  <tr>
    <td style="padding:8px;color:#6b7280;font-weight:600">Days Remaining</td>
    <td style="padding:8px;font-weight:700;color:{$dayColor}">{$daysRemaining} day{$dayWord}</td>
  </tr>
</table>
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to upload a fresh evidence file before it expires.</p>
HTML;

            $subject = "[AEGIS] Evidence file expiring: {$ef['filename']}";
            $body    = emailShell('Evidence File Expiring', $inner);

            $sent = maybeSendOrQueue(
                $userId, 'evidence_expiring', $email, $userName, $subject, $body,
                ['evidence' => $ef, 'dueDate' => $dueDate, 'daysRemaining' => $daysRemaining]
            );
            if ($sent) {
                logNotification($userId, 'evidence_expiring', 'evidence_files', (int) $ef['id']);
                $sentEvidenceExpiry++;
            }
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[evidence_expiring] ERROR: " . $e->getMessage() . "\n");
}

// ═══════════════════════════════════════════════════════════════════════════════
// DIGEST: send one combined email per user who has queued notifications
// ═══════════════════════════════════════════════════════════════════════════════
$sentDigests = 0;

if (!empty($digestQueue)) {
    // Section labels per notification type
    $sectionLabels = [
        'overdue_controls'           => 'Overdue Compliance Controls',
        'policy_review_due'          => 'Policy Reviews Due',
        'pending_approval'           => 'Pending Approvals',
        'new_risk_assigned'          => 'New Risks Assigned',
        'open_incident_aging'        => 'Aging Open Incidents',
        'risk_review_overdue'        => 'Risks Overdue for Review',
        'treatment_due'              => 'Risk Treatments Due',
        'risk_score_worsened'        => 'Risk Score Increases',
        'vendor_assessment_expiring' => 'Vendor Assessments Due',
        'document_expiring'          => 'Documents Expiring Soon',
        'assessment_pending_stale'   => 'Stale Risk Assessments',
        'evidence_expiring'          => 'Evidence Files Expiring',
        'policy_expiring'            => 'Policies Expiring Soon',
        'risk_acceptance_expiring'   => 'Risk Acceptances Expiring',
        'kri_breached'               => 'KRI Threshold Breaches',
        'incident_sla_breach'        => 'Incident SLA Breaches',
    ];

    foreach ($digestQueue as $digestUserId => $items) {
        $digestUserId = (int) $digestUserId;
        $mode         = $digestModes[$digestUserId] ?? 'daily';

        // Weekly digest: only send on Monday (date('N') == 1)
        if ($mode === 'weekly' && (int) date('N') !== 1) {
            continue;
        }

        // Gather recipient info from the first queued item
        $recipientEmail    = '';
        $recipientUserName = '';
        foreach ($items as $item) {
            if (!empty($item['data']['_email'])) {
                $recipientEmail    = $item['data']['_email'];
                $recipientUserName = $item['data']['_userName'];
                break;
            }
        }

        if ($recipientEmail === '') {
            // Fall back to DB lookup
            $userRow = Database::fetchOne(
                "SELECT email, name FROM users WHERE id = ? AND is_active = TRUE",
                [$digestUserId]
            );
            if ($userRow === null) {
                continue;
            }
            $recipientEmail    = $userRow['email'];
            $recipientUserName = $userRow['name'];
        }

        $totalCount = count($items);

        // Group items by notification type for sectioned layout
        $byType = [];
        foreach ($items as $item) {
            $byType[$item['type']][] = $item['data'];
        }

        // Build sections HTML
        $sectionsHtml = '';
        foreach ($byType as $type => $typeItems) {
            $label      = $sectionLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
            $typeCount  = count($typeItems);
            $rowsHtml   = '';

            foreach ($typeItems as $data) {
                // Use the original subject line as the item summary
                $itemSubject = htmlspecialchars($data['_subject'] ?? $label, ENT_QUOTES, 'UTF-8');
                $rowsHtml   .= "<li style=\"padding:4px 0;font-size:14px\">{$itemSubject}</li>";
            }

            $sectionsHtml .= <<<HTML
<div style="margin-bottom:20px">
  <h3 style="color:#1e3a5f;font-size:15px;margin-bottom:8px;border-bottom:2px solid #e5e7eb;padding-bottom:6px">
    {$label} <span style="color:#6366f1;font-size:13px">({$typeCount})</span>
  </h3>
  <ul style="margin:0;padding-left:20px;color:#374151">
    {$rowsHtml}
  </ul>
</div>
HTML;
        }

        $recipSafe      = htmlspecialchars($recipientUserName, ENT_QUOTES, 'UTF-8');
        $notifWord      = $totalCount === 1 ? '' : 's';
        $inner          = <<<HTML
<p style="margin-top:0">Hi {$recipSafe},</p>
<p>Here is your AEGIS GRC digest with <strong>{$totalCount} notification{$notifWord}</strong>:</p>
{$sectionsHtml}
<p style="margin-bottom:0;font-size:13px;color:#6b7280">Please log in to AEGIS GRC to address these items.</p>
HTML;

        $subject = "[AEGIS] Your GRC digest \u{2014} {$totalCount} notification" . ($totalCount === 1 ? '' : 's');
        $body    = emailShell('Your AEGIS GRC Digest', $inner);

        if (Mailer::sendFromSettings($recipientEmail, $recipientUserName, $subject, $body)) {
            // Log each queued item as sent
            foreach ($items as $item) {
                $data       = $item['data'];
                $entityType = match ($item['type']) {
                    'overdue_controls'           => 'user',
                    'policy_review_due'          => 'policy',
                    'pending_approval'           => 'approval_request',
                    'new_risk_assigned'          => 'risk',
                    'open_incident_aging'        => 'incident',
                    'risk_review_overdue'        => 'user',
                    'treatment_due'              => 'risk_treatment',
                    'risk_score_worsened'        => 'risk',
                    'vendor_assessment_expiring' => 'vendor',
                    'document_expiring'          => 'document',
                    'assessment_pending_stale'   => 'risk',
                    'evidence_expiring'          => 'evidence_files',
                    default                      => 'entity',
                };
                // Determine entity_id from data — best-effort
                $entityId = match ($item['type']) {
                    'overdue_controls'           => $digestUserId,
                    'policy_review_due'          => (int) ($data['policy']['id'] ?? 0),
                    'pending_approval'           => (int) ($data['approval']['id'] ?? 0),
                    'new_risk_assigned'          => (int) ($data['risk']['id'] ?? 0),
                    'open_incident_aging'        => (int) ($data['incident']['id'] ?? 0),
                    'risk_review_overdue'        => $digestUserId,
                    'treatment_due'              => (int) ($data['treatment']['treatment_id'] ?? 0),
                    'risk_score_worsened'        => (int) ($data['risk']['risk_id'] ?? 0),
                    'vendor_assessment_expiring' => (int) ($data['vendor']['vendor_id'] ?? 0),
                    'document_expiring'          => (int) ($data['document']['id'] ?? 0),
                    'assessment_pending_stale'   => (int) ($data['risk']['id'] ?? 0),
                    'evidence_expiring'          => (int) ($data['evidence']['id'] ?? 0),
                    default                      => 0,
                };

                if ($entityId > 0) {
                    logNotification($digestUserId, $item['type'], $entityType, $entityId);
                }
            }
            $sentDigests++;
        }
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Notifications: {$sentOverdue} overdue, {$sentPolicy} reviews, {$sentPolicyExpiry} policy expiry, "
   . "{$sentApprovals} approvals, {$sentRisks} risk assignments, {$sentIncidents} aging incidents, "
   . "{$sentRiskReview} risk reviews, {$sentTreatment} treatments, {$sentScoreWorsened} score alerts, "
   . "{$sentVendorAssess} vendor assessments, {$sentDocExpiring} doc expiry, {$sentAssessStale} stale assessments, "
   . "{$sentEvidenceExpiry} evidence expiry, {$sentAcceptExpiring} acceptance expiry, "
   . "{$sentKriBreach} KRI breaches, {$sentSlaBreach} SLA breaches, {$sentDigests} digest(s) sent\n";
