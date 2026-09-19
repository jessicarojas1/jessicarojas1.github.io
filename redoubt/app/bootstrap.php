<?php
/**
 * REDOUBT — application bootstrap.
 *
 * Loaded by public/index.php. Sets up autoloading (Composer if present, else a
 * built-in PSR-4 autoloader so the app also runs under `php -S` without a vendor
 * dir), loads environment configuration, and applies safe runtime defaults.
 *
 * Phase 1 skeleton. Core framework services are implemented; portal modules
 * (announcements, documents, etc.) are not yet built — see OPEN_ITEMS.md.
 */

declare(strict_types=1);

define('REDOUBT_ROOT', dirname(__DIR__));
define('REDOUBT_APP', __DIR__);

// --- Autoloading -----------------------------------------------------------
$composer = REDOUBT_ROOT . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    // Minimal PSR-4 autoloader for the "Redoubt\" namespace -> app/.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Redoubt\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = REDOUBT_APP . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

// --- Environment (.env is optional and never committed) ---------------------
// Real secrets come from the container/orchestrator environment or a secret
// manager. A local .env is a developer convenience only.
$envFile = REDOUBT_ROOT . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

// --- Runtime defaults ------------------------------------------------------
date_default_timezone_set('UTC');
$isProd = \Redoubt\Support\Config::env() === 'production';
error_reporting(E_ALL);
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
