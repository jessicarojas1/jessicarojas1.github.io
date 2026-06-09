<?php
declare(strict_types=1);

// Block web access — installer runs via CLI / container startup only.
if (php_sapi_name() !== 'cli' && !defined('PALADIN_INSTALL_ALLOWED')) {
    http_response_code(403);
    die('Installer is not accessible via HTTP. Run from CLI: php install.php');
}

define('PALADIN_ROOT', __DIR__);

foreach (['.env.local', '.env'] as $f) {
    if (file_exists(PALADIN_ROOT . '/' . $f)) {
        foreach (file(PALADIN_ROOT . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}
foreach ((getenv() ?: []) as $k => $v) {
    if (!isset($_ENV[$k])) $_ENV[$k] = $v;
}

require_once PALADIN_ROOT . '/config/database.php';
require_once PALADIN_ROOT . '/src/Database.php';
require_once PALADIN_ROOT . '/src/Security.php';

function log_msg(string $msg): void {
    if (php_sapi_name() === 'cli') echo $msg . PHP_EOL; else error_log($msg);
}

log_msg('[PALADIN] Starting database install/migration...');

try {
    $pdo = Database::getInstance();
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS paladin");
    $pdo->exec("SET search_path TO paladin");

    $freshInstall = !Database::tableExists('users');

    // schema.sql is idempotent — safe to run on every deploy.
    $schema = file_get_contents(PALADIN_ROOT . '/database/schema.sql');
    $pdo->exec($schema);
    log_msg('[PALADIN] Schema applied.');

    // Run any incremental migrations (kept in sync with schema.sql).
    foreach (glob(PALADIN_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        try {
            $pdo->exec("SET search_path TO paladin");
            $pdo->exec(file_get_contents($path));
            log_msg('[PALADIN] Applied migration: ' . basename($path));
        } catch (PDOException $e) {
            log_msg('[PALADIN] Migration ' . basename($path) . ' warning: ' . $e->getMessage());
        }
    }

    if ($freshInstall) {
        // ── Default admin ────────────────────────────────────────────────
        $adminEmail    = $_ENV['ADMIN_EMAIL']    ?? null;
        $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? null;
        if (!$adminEmail || !$adminPassword) {
            log_msg('[PALADIN] FATAL: ADMIN_EMAIL and ADMIN_PASSWORD must be set for first install.');
            exit(1);
        }
        Database::query(
            "INSERT INTO users (name, email, password_hash, role, title, department, password_changed_at)
             VALUES (?,?,?,'admin','System Administrator','IT', NOW())",
            ['Administrator', strtolower(trim($adminEmail)), Security::hashPassword($adminPassword)]
        );
        log_msg("[PALADIN] Admin user created: {$adminEmail}");

        require PALADIN_ROOT . '/database/seeds/seed_demo.php';
        seed_demo();
        log_msg('[PALADIN] Demo data seeded.');
    }

    log_msg('[PALADIN] Installation complete.');
    exit(0);

} catch (Throwable $e) {
    log_msg('[PALADIN] Installation failed: ' . $e->getMessage());
    log_msg($e->getTraceAsString());
    exit(1);
}
