#!/usr/bin/env php
<?php
/**
 * Sends scheduled GRC report emails.
 * Run every hour (it internally decides which schedules are due):
 *   0 * * * * php /var/www/aegis/scripts/send_scheduled_reports.php >> /var/log/aegis-reports.log 2>&1
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
require_once AEGIS_ROOT . '/src/Mailer.php';

$now     = time();
$today   = (int)date('j');   // day of month
$weekday = (int)date('w');   // 0=Sun

$schedules = Database::fetchAll(
    "SELECT * FROM report_schedules WHERE is_active = TRUE ORDER BY id"
);

$smtpHost = $_ENV['SMTP_HOST'] ?? '';
$smtpUser = $_ENV['SMTP_USER'] ?? '';
$smtpPass = $_ENV['SMTP_PASS'] ?? '';
$smtpPort = (int)($_ENV['SMTP_PORT'] ?? 587);
$fromAddr = $_ENV['SMTP_FROM'] ?? $smtpUser;

foreach ($schedules as $sched) {
    if (!isDue($sched, $weekday, $today, $now)) continue;

    $body    = buildReportBody($sched['report_type']);
    $subject = "AEGIS GRC Report — {$sched['name']} — " . date('M j, Y');
    $recipients = json_decode($sched['recipients'], true) ?? [];

    if (empty($recipients) || !$smtpHost) {
        logResult($sched['id'], $recipients, 'skipped', 'No recipients or SMTP not configured');
        continue;
    }

    $sent  = 0;
    $error = null;
    foreach ($recipients as $to) {
        try {
            $ok = Mailer::send($to, '', $subject, $body, strip_tags($body),
                $smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromAddr);
            if ($ok) $sent++;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    Database::query("UPDATE report_schedules SET last_sent_at = NOW() WHERE id = ?", [$sched['id']]);
    logResult($sched['id'], $recipients, $error ? 'error' : 'sent', $error);
    echo "[" . date('Y-m-d H:i') . "] Schedule #{$sched['id']} '{$sched['name']}' — sent to {$sent}/" . count($recipients) . " recipients\n";
}

function isDue(array $sched, int $weekday, int $today, int $now): bool {
    $last = $sched['last_sent_at'] ? strtotime($sched['last_sent_at']) : 0;
    return match ($sched['frequency']) {
        'daily'     => $now - $last >= 82800,  // ~23h gap
        'weekly'    => $weekday === (int)($sched['day_of_week'] ?? 1) && $now - $last >= 82800 * 6,
        'monthly'   => $today  === (int)($sched['day_of_month'] ?? 1) && $now - $last >= 82800 * 27,
        'quarterly' => $today  === (int)($sched['day_of_month'] ?? 1)
                       && in_array((int)date('n'), [1,4,7,10])
                       && $now - $last >= 82800 * 27 * 3,
        default     => false,
    };
}

function buildReportBody(string $type): string {
    $snapshot = Database::fetchOne(
        "SELECT * FROM metrics_snapshots ORDER BY snapshot_date DESC LIMIT 1"
    );
    $date = date('F j, Y');

    $s = $snapshot ?? [];
    $grc    = number_format((float)($s['grc_score'] ?? 0), 1);
    $comp   = number_format((float)($s['compliance_pct'] ?? 0), 1);
    $risk   = number_format((float)($s['risk_health'] ?? 0), 1);
    $policy = number_format((float)($s['policy_health'] ?? 0), 1);
    $audit  = number_format((float)($s['audit_health'] ?? 0), 1);

    $specific = match ($type) {
        'compliance' => complianceSection(),
        'risk'       => riskSection(),
        'executive'  => '',
        default      => '',
    };

    return <<<HTML
    <html><body style="font-family:Arial,sans-serif;color:#1f2937;max-width:680px;margin:0 auto">
    <div style="background:#1e3a5f;color:#fff;padding:24px;border-radius:8px 8px 0 0">
      <h1 style="margin:0;font-size:22px">AEGIS GRC Report</h1>
      <p style="margin:4px 0 0;opacity:.8">{$date}</p>
    </div>
    <div style="padding:24px;background:#f9fafb;border:1px solid #e5e7eb;border-top:none">
      <h2 style="margin-top:0">GRC Score Summary</h2>
      <table style="width:100%;border-collapse:collapse">
        <tr style="background:#fff;border:1px solid #e5e7eb">
          <td style="padding:12px;font-weight:600">Overall GRC Score</td>
          <td style="padding:12px;text-align:right;font-size:20px;font-weight:700;color:#1e3a5f">{$grc}%</td>
        </tr>
        <tr><td style="padding:12px">Compliance</td><td style="padding:12px;text-align:right">{$comp}%</td></tr>
        <tr style="background:#f9fafb"><td style="padding:12px">Risk Health</td><td style="padding:12px;text-align:right">{$risk}%</td></tr>
        <tr><td style="padding:12px">Policy Health</td><td style="padding:12px;text-align:right">{$policy}%</td></tr>
        <tr style="background:#f9fafb"><td style="padding:12px">Audit Health</td><td style="padding:12px;text-align:right">{$audit}%</td></tr>
      </table>
      <table style="width:100%;border-collapse:collapse;margin-top:16px">
        <tr>
          <td style="padding:12px;background:#fef2f2;border-radius:4px">Open Incidents: <strong>{$s['open_incidents']}</strong></td>
          <td style="padding:12px;background:#fff7ed;border-radius:4px">Open Risks: <strong>{$s['open_risks']}</strong></td>
          <td style="padding:12px;background:#fffbeb;border-radius:4px">Open Issues: <strong>{$s['open_issues']}</strong></td>
        </tr>
      </table>
      {$specific}
    </div>
    <div style="padding:16px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb">
      Generated by AEGIS GRC &mdash; <a href="{$_ENV['APP_URL']}">View Full Dashboard</a>
    </div>
    </body></html>
    HTML;
}

function complianceSection(): string {
    $pkgs = Database::fetchAll(
        "SELECT cp.name,
                COUNT(ci.id) FILTER (WHERE ci.status='compliant') as compliant,
                COUNT(co.id) as total
         FROM compliance_packages cp
         LEFT JOIN compliance_objectives co ON co.package_id=cp.id AND co.level=2
         LEFT JOIN control_implementations ci ON ci.objective_id=co.id
         WHERE cp.is_active=TRUE GROUP BY cp.id,cp.name ORDER BY cp.name"
    );
    $rows = '';
    foreach ($pkgs as $p) {
        $pct = $p['total'] > 0 ? round($p['compliant'] / $p['total'] * 100) : 0;
        $rows .= "<tr><td style='padding:8px'>{$p['name']}</td><td style='padding:8px;text-align:right'>{$p['compliant']}/{$p['total']}</td><td style='padding:8px;text-align:right'>{$pct}%</td></tr>";
    }
    return "<h3>Compliance by Framework</h3><table style='width:100%;border-collapse:collapse'><tr style='background:#f3f4f6'><th style='padding:8px;text-align:left'>Framework</th><th style='padding:8px;text-align:right'>Controls</th><th style='padding:8px;text-align:right'>%</th></tr>{$rows}</table>";
}

function riskSection(): string {
    $risks = Database::fetchAll(
        "SELECT r.title, r.inherent_score, r.status, u.name as owner
         FROM risks r LEFT JOIN users u ON r.owner_id=u.id
         WHERE r.status NOT IN ('closed','transferred')
         ORDER BY r.inherent_score DESC LIMIT 10"
    );
    $rows = '';
    foreach ($risks as $r) {
        $color = $r['inherent_score'] >= 15 ? '#ef4444' : ($r['inherent_score'] >= 8 ? '#f59e0b' : '#22c55e');
        $rows .= "<tr><td style='padding:8px'>{$r['title']}</td><td style='padding:8px;color:{$color};font-weight:600'>{$r['inherent_score']}</td><td style='padding:8px'>{$r['owner']}</td></tr>";
    }
    return "<h3>Top 10 Open Risks</h3><table style='width:100%;border-collapse:collapse'><tr style='background:#f3f4f6'><th style='padding:8px;text-align:left'>Risk</th><th style='padding:8px'>Score</th><th style='padding:8px'>Owner</th></tr>{$rows}</table>";
}

function logResult(int $schedId, array $recipients, string $status, ?string $error): void {
    Database::query(
        "INSERT INTO report_schedule_logs (schedule_id, sent_at, recipients, status, error)
         VALUES (?, NOW(), ?, ?, ?)",
        [$schedId, json_encode($recipients), $status, $error]
    );
}
