<?php
declare(strict_types=1);

/**
 * cli/send_review_reminders.php — remind document owners of upcoming/overdue
 * reviews and expirations.
 *
 *   php cli/send_review_reminders.php [lookAheadDays] [cooldownDays]
 *
 * Cron example (daily at 06:00):
 *   0 6 * * *  php /path/to/paladin/cli/send_review_reminders.php 14 7
 *
 * Sends an in-app alert + an email per affected document (email goes to the
 * mail outbox when SMTP isn't configured). A per-document cooldown avoids
 * re-notifying too often.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('PALADIN_ROOT', dirname(__DIR__));

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
foreach ((getenv() ?: []) as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

spl_autoload_register(function (string $class): void {
    foreach ([PALADIN_ROOT . "/src/{$class}.php", PALADIN_ROOT . "/controllers/{$class}.php"] as $p) {
        if (is_file($p)) { require_once $p; return; }
    }
});
require_once PALADIN_ROOT . '/config/database.php';
require_once PALADIN_ROOT . '/src/Database.php';

$lookAhead = isset($argv[1]) ? max(1, (int)$argv[1]) : 14;
$cooldown  = isset($argv[2]) ? max(0, (int)$argv[2]) : 7;
$result = Reminders::run($lookAhead, $cooldown);

fwrite(STDOUT, sprintf(
    "[%s] review reminders: processed=%d reminded=%d (lookAhead=%dd cooldown=%dd) via %s\n",
    date('c'), $result['processed'], $result['reminded'], $lookAhead, $cooldown, Mailer::transport()
));
exit(0);
