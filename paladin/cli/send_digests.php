<?php
declare(strict_types=1);

/**
 * cli/send_digests.php — send notification email digests.
 *
 *   php cli/send_digests.php daily     # default
 *   php cli/send_digests.php weekly
 *
 * Intended to be run from cron, e.g.:
 *   # daily at 07:00
 *   0 7 * * *  php /path/to/paladin/cli/send_digests.php daily
 *   # weekly Monday 07:00
 *   0 7 * * 1  php /path/to/paladin/cli/send_digests.php weekly
 *
 * With no SMTP configured (env MAIL_TRANSPORT != smtp) messages are recorded in
 * the mail_outbox table as 'queued' so the pipeline runs and can be inspected
 * without mail credentials.
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

$frequency = $argv[1] ?? 'daily';
$result = Digest::run($frequency);

fwrite(STDOUT, sprintf(
    "[%s] digest run (%s): processed=%d sent=%d skipped=%d via %s transport\n",
    date('c'), $frequency, $result['processed'], $result['sent'], $result['skipped'], Mailer::transport()
));
exit(0);
