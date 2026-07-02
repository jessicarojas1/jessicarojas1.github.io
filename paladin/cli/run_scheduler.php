<?php
declare(strict_types=1);

/**
 * cli/run_scheduler.php — run PALADIN's time-based background sweeps once.
 *
 * These are the same sweeps that otherwise run opportunistically on ordinary
 * authenticated requests (see src/Scheduler.php and DashboardController). On a
 * low-traffic site, requests may be too infrequent for timely scheduled
 * publishing, document auto-expiry, webhook retries and retention. Run this
 * from cron / a systemd timer / Render cron to make those actions prompt and
 * request-independent.
 *
 * Sweeps performed (each is idempotent and safe to run repeatedly):
 *   - Scheduler::runDuePages()         publish pages whose scheduled time passed
 *   - Scheduler::runExpiredDocuments() expire controlled docs past expiration
 *   - Webhook::retryDue()              re-attempt failed deliveries (backoff)
 *   - Retention::sweepExpired()        archive published docs past expiration
 *
 *   php cli/run_scheduler.php            # run all sweeps
 *   php cli/run_scheduler.php --json     # machine-readable summary line
 *
 * Suggested cron (every 5 minutes) — note the star-slash-5 minute field:
 *   [*]/5 * * * *  php /path/to/paladin/cli/run_scheduler.php >> /var/log/paladin-scheduler.log 2>&1
 *
 * Exit code 0 on success, 1 on a fatal error (e.g. DB unreachable).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('PALADIN_ROOT', dirname(__DIR__));

// ── Environment (mirror of index.php, minus HTTP/session) ───────────────────
foreach (['.env.local', '.env'] as $envFile) {
    $path = PALADIN_ROOT . '/' . $envFile;
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}
foreach ((getenv() ?: []) as $k => $v) {
    if (!isset($_ENV[$k])) $_ENV[$k] = $v;
}

spl_autoload_register(function (string $class): void {
    foreach ([PALADIN_ROOT . "/src/{$class}.php", PALADIN_ROOT . "/controllers/{$class}.php"] as $p) {
        if (is_file($p)) { require_once $p; return; }
    }
});
require_once PALADIN_ROOT . '/config/database.php';
require_once PALADIN_ROOT . '/src/Database.php';

$json = in_array('--json', $argv, true);

try {
    $result = [
        'published'      => Scheduler::runDuePages(),
        'expired'        => Scheduler::runExpiredDocuments(),
        'webhook_retried'=> Webhook::retryDue(),
        'retention'      => Retention::sweepExpired(),
    ];
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('c') . "] scheduler run failed: " . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    fwrite(STDOUT, json_encode(['timestamp' => date('c')] + $result) . "\n");
} else {
    fwrite(STDOUT, sprintf(
        "[%s] scheduler run: published=%d expired=%d webhook_retried=%d retention_archived=%d\n",
        date('c'), $result['published'], $result['expired'], $result['webhook_retried'], $result['retention']
    ));
}
exit(0);
