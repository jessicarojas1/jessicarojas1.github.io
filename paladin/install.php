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

    // schema.sql is the full, idempotent baseline. It is safe to re-run, BUT on
    // a pre-existing database `CREATE TABLE IF NOT EXISTS` is a no-op, so columns
    // added to a table after its first creation are NOT applied by schema.sql —
    // and any later statement in the same file that references such a column
    // (e.g. an index on it) fails, aborting the whole multi-statement exec in
    // one rolled-back transaction. The incremental migrations below carry those
    // `ADD COLUMN IF NOT EXISTS` deltas, so a schema.sql failure must NOT stop
    // them from running. Treat the baseline as best-effort and always proceed to
    // migrations, which are what bring an existing database up to date.
    $schema = file_get_contents(PALADIN_ROOT . '/database/schema.sql');
    try {
        $pdo->exec("SET search_path TO paladin");
        $pdo->exec($schema);
        log_msg('[PALADIN] Schema baseline applied.');
    } catch (PDOException $e) {
        log_msg('[PALADIN] Schema baseline warning (continuing to migrations): ' . $e->getMessage());
    }

    // Track applied migrations in schema_migrations so each file is applied once
    // and skipped on subsequent boots. On an existing database that predates this
    // tracking the table is empty, so every (idempotent) migration is applied once
    // to reconcile drift and then recorded; thereafter boots skip them and are fast.
    $pdo->exec("SET search_path TO paladin");
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   TEXT PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    $applied = [];
    foreach (Database::fetchAll("SELECT filename FROM schema_migrations") as $row) {
        $applied[$row['filename']] = true;
    }

    // Run pending incremental migrations in order. Each runs in its own exec so one
    // failing migration cannot block the others, and is recorded only on success so
    // a failure is retried next boot. All migrations are idempotent.
    foreach (glob(PALADIN_ROOT . '/database/migrations/*.sql') ?: [] as $path) {
        $name = basename($path);
        if (isset($applied[$name])) { continue; }
        try {
            $pdo->exec("SET search_path TO paladin");
            $pdo->exec(file_get_contents($path));
            Database::query(
                "INSERT INTO schema_migrations (filename) VALUES (?) ON CONFLICT (filename) DO NOTHING",
                [$name]
            );
            log_msg('[PALADIN] Applied migration: ' . $name);
        } catch (PDOException $e) {
            log_msg('[PALADIN] Migration ' . $name . ' warning: ' . $e->getMessage());
        }
    }

    // Re-run the schema baseline once more now that migrations have added any
    // missing columns — this lets previously-skipped objects (indexes/views that
    // depend on those columns) settle on an existing database. Still best-effort.
    try {
        $pdo->exec("SET search_path TO paladin");
        $pdo->exec($schema);
        log_msg('[PALADIN] Schema baseline reconciled.');
    } catch (PDOException $e) {
        log_msg('[PALADIN] Schema reconcile warning: ' . $e->getMessage());
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
