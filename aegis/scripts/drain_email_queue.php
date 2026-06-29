#!/usr/bin/env php
<?php
/**
 * AEGIS GRC — Outbound email queue drainer (TD-9).
 * Retries emails that failed their immediate send (Mailer::sendFromSettings
 * enqueues failures). Exponential back-off; gives up after max_attempts and
 * marks the row 'failed' so it stops being retried.
 *
 * Add to crontab (provisioned as a Render cron service in render.yaml) — run
 * every 5 minutes, e.g. schedule "(every 5 min) * * * *":
 *   php /var/www/html/scripts/drain_email_queue.php >> /var/log/aegis-email.log 2>&1
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
require_once AEGIS_ROOT . '/src/Ssrf.php';
require_once AEGIS_ROOT . '/src/Mailer.php';

const BATCH_SIZE   = 50;
const BACKOFF_CAP_MINUTES = 60; // exponential, capped

// SMTP must be configured for any of this to matter.
$cfg = Mailer::smtpConfig();
if ($cfg === null) {
    fwrite(STDOUT, "[email-drain] SMTP not configured / disabled — nothing to do.\n");
    exit(0);
}

// Due rows: queued and their next attempt time has arrived. The table may not
// exist yet on a brand-new deploy whose install.php hasn't run — treat that as
// "nothing to drain" rather than an error.
try {
    $due = Database::fetchAll(
        "SELECT * FROM email_queue
          WHERE status = 'queued' AND next_attempt_at <= NOW()
          ORDER BY next_attempt_at ASC
          LIMIT " . (int) BATCH_SIZE
    );
} catch (Throwable $e) {
    fwrite(STDOUT, "[email-drain] queue not available: " . $e->getMessage() . "\n");
    exit(0);
}

$sent = 0; $retried = 0; $failed = 0;

foreach ($due as $row) {
    $id = (int) $row['id'];
    $ok = false;
    $err = '';
    try {
        $ok = Mailer::send(
            (string) $row['to_email'],
            (string) ($row['to_name'] ?? ''),
            (string) $row['subject'],
            (string) $row['body_html'],
            $cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'],
            $cfg['from'], $cfg['fromName'], $cfg['tls']
        );
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    if ($ok) {
        Database::query(
            "UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = ?",
            [$id]
        );
        $sent++;
        continue;
    }

    // Failed this attempt: increment, then either give up or back off.
    $attempts    = (int) $row['attempts'] + 1;
    $maxAttempts = (int) ($row['max_attempts'] ?? 6);
    $lastError   = $err !== '' ? $err : 'send returned false';

    if ($attempts >= $maxAttempts) {
        Database::query(
            "UPDATE email_queue SET status = 'failed', attempts = ?, last_error = ? WHERE id = ?",
            [$attempts, mb_substr($lastError, 0, 1000), $id]
        );
        $failed++;
    } else {
        // Exponential back-off in minutes, capped: 2^attempts (2,4,8,16,32,60…).
        $delay = (int) min(2 ** $attempts, BACKOFF_CAP_MINUTES);
        Database::query(
            "UPDATE email_queue
                SET attempts = ?, last_error = ?, next_attempt_at = NOW() + (? || ' minutes')::interval
              WHERE id = ?",
            [$attempts, mb_substr($lastError, 0, 1000), (string) $delay, $id]
        );
        $retried++;
    }
}

fwrite(STDOUT, "[email-drain] sent={$sent} retried={$retried} failed={$failed} (of " . count($due) . " due)\n");
exit(0);
