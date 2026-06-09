<?php
declare(strict_types=1);

// Block web access — installer runs via CLI / container startup only.
if (php_sapi_name() !== 'cli' && !defined('PAL_INSTALL_ALLOWED')) {
    http_response_code(403);
    die('Installer is not accessible via HTTP. Run from CLI: php install.php');
}

define('PAL_ROOT', __DIR__);

foreach (['.env.local', '.env'] as $f) {
    if (file_exists(PAL_ROOT . '/' . $f)) {
        foreach (file(PAL_ROOT . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}
foreach ((getenv() ?: []) as $k => $v) {
    if (!isset($_ENV[$k])) $_ENV[$k] = $v;
}

require_once PAL_ROOT . '/config/database.php';
require_once PAL_ROOT . '/src/Database.php';
require_once PAL_ROOT . '/src/Security.php';

function log_msg(string $msg): void {
    if (php_sapi_name() === 'cli') echo $msg . PHP_EOL; else error_log($msg);
}

log_msg('[PAL] Starting database install/migration...');

try {
    $pdo = Database::getInstance();
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS pal");
    $pdo->exec("SET search_path TO pal");

    $freshInstall = !Database::tableExists('users');

    // schema.sql is idempotent — safe to run on every deploy.
    $schema = file_get_contents(PAL_ROOT . '/database/schema.sql');
    $pdo->exec($schema);
    log_msg('[PAL] Schema applied.');

    // Run any incremental migrations (kept in sync with schema.sql).
    foreach (glob(PAL_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        try {
            $pdo->exec("SET search_path TO pal");
            $pdo->exec(file_get_contents($path));
            log_msg('[PAL] Applied migration: ' . basename($path));
        } catch (PDOException $e) {
            log_msg('[PAL] Migration ' . basename($path) . ' warning: ' . $e->getMessage());
        }
    }

    if ($freshInstall) {
        // ── Default admin ────────────────────────────────────────────────
        $adminEmail    = $_ENV['ADMIN_EMAIL']    ?? null;
        $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? null;
        if (!$adminEmail || !$adminPassword) {
            log_msg('[PAL] FATAL: ADMIN_EMAIL and ADMIN_PASSWORD must be set for first install.');
            exit(1);
        }
        Database::query(
            "INSERT INTO users (name, email, password_hash, role, title, department, password_changed_at)
             VALUES (?,?,?,'admin','System Administrator','IT', NOW())",
            ['Administrator', strtolower(trim($adminEmail)), Security::hashPassword($adminPassword)]
        );
        log_msg("[PAL] Admin user created: {$adminEmail}");

        require PAL_ROOT . '/database/seeds/seed_demo.php';
        seed_demo();
        log_msg('[PAL] Demo data seeded.');
    }

    log_msg('[PAL] Installation complete.');
    exit(0);

} catch (Throwable $e) {
    log_msg('[PAL] Installation failed: ' . $e->getMessage());
    log_msg($e->getTraceAsString());
    exit(1);
}
