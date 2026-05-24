<?php
/**
 * APEX - Apply schema.sql if the `users` table does not yet exist.
 * Called automatically by bin/start.sh on every container start.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Response.php';

// Load .env if present (local dev).
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        if (!getenv($k)) putenv("$k=$v");
    }
}

try {
    $pdo = Nexus\Database::pdo();
    $row = $pdo->query("SELECT to_regclass('public.users') AS t")->fetchColumn();
    if ($row === null) {
        echo "[migrate] Applying schema…\n";
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
        $pdo->exec($sql);
        echo "[migrate] Done.\n";
    } else {
        echo "[migrate] Schema already present — skipping.\n";
    }
} catch (Throwable $e) {
    echo "[migrate] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
