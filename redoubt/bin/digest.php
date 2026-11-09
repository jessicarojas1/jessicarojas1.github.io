<?php

declare(strict_types=1);

/**
 * REDOUBT review-digest runner (CLI).
 *
 * Builds and delivers review digests (Teams webhook + Graph email) for one or all
 * programs. Intended to be invoked on a schedule — a cron entry or a Kubernetes
 * CronJob — e.g. hourly or each business morning:
 *
 *   php bin/digest.php            # every active program
 *   php bin/digest.php 7          # just program id 7
 *
 * Exit code is 0 on success, 1 if the database is not configured. Delivery
 * channels that are not configured are skipped safely (see Support\Digest).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Redoubt\Support\Db;
use Redoubt\Support\Digest;

if (!Db::isConfigured()) {
    fwrite(STDERR, "[digest] DATABASE_URL is not configured.\n");
    exit(1);
}

$arg = $argv[1] ?? null;
$programIds = [];
if ($arg !== null && ctype_digit((string) $arg)) {
    $programIds[] = (int) $arg;
} else {
    foreach (Db::fetchAll("SELECT id FROM program WHERE status = 'active' ORDER BY id") as $row) {
        $programIds[] = (int) $row['id'];
    }
}

$total = 0;
foreach ($programIds as $pid) {
    $r = Digest::run($pid, null);
    $total += $r['recipients'];
    $line = sprintf(
        "[digest] program=%d recipients=%d emails_sent=%d emails_skipped=%d teams=%s%s\n",
        $pid,
        $r['recipients'],
        $r['emails_sent'],
        $r['emails_skipped'],
        $r['teams'] === null ? 'none' : ($r['teams'] ? 'ok' : 'failed'),
        $r['reason'] !== null ? ' reason="' . $r['reason'] . '"' : ''
    );
    fwrite(STDOUT, $line);
}

fwrite(STDOUT, sprintf("[digest] done — %d program(s), %d recipient(s) total.\n", count($programIds), $total));
exit(0);
