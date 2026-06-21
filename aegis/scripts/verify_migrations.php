#!/usr/bin/env php
<?php
/**
 * Migration verifier — static checks, no database required.
 *
 * Catches the classes of drift that bite a fresh install:
 *   1. Migration files on disk that are NOT registered in install.php's ordered
 *      list (so the installer silently skips them).
 *   2. Files registered in install.php that don't exist on disk.
 *   3. Gaps or duplicates in the NNN_ numeric sequence.
 *   4. Statements that aren't idempotent (CREATE/ALTER/INSERT without an
 *      IF [NOT] EXISTS / ON CONFLICT guard) — flagged as warnings.
 *
 * Usage:  php scripts/verify_migrations.php
 * Exit:   0 = ok (warnings allowed), 1 = errors, 2 = bootstrap error.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$root       = dirname(__DIR__);
$migDir     = $root . '/database/migrations';
$installPhp = $root . '/install.php';

if (!is_dir($migDir) || !is_file($installPhp)) {
    fwrite(STDERR, "Cannot locate migrations directory or install.php\n");
    exit(2);
}

$errors   = [];
$warnings = [];

// Files on disk.
$disk = array_map('basename', glob($migDir . '/*.sql') ?: []);
sort($disk);

// Files registered in install.php (entries like '023_risk_scoring.sql').
$installSrc = file_get_contents($installPhp);
preg_match_all("/'(\d{3}_[A-Za-z0-9_]+\.sql)'/", $installSrc, $m);
$registered = $m[1] ?? [];

// 1 + 2: disk vs registered.
foreach ($disk as $f) {
    if (!in_array($f, $registered, true)) {
        $errors[] = "On disk but NOT registered in install.php: {$f}";
    }
}
foreach ($registered as $f) {
    if (!in_array($f, $disk, true)) {
        $errors[] = "Registered in install.php but missing on disk: {$f}";
    }
}

// 3: numeric sequence gaps / duplicates.
$nums = [];
foreach ($disk as $f) {
    if (preg_match('/^(\d{3})_/', $f, $mm)) {
        $n = (int)$mm[1];
        if (isset($nums[$n])) {
            $errors[] = sprintf("Duplicate migration number %03d: %s and %s", $n, $nums[$n], $f);
        }
        $nums[$n] = $f;
    }
}
if ($nums) {
    $keys = array_keys($nums);
    for ($i = min($keys); $i <= max($keys); $i++) {
        if (!isset($nums[$i])) {
            $warnings[] = sprintf("Gap in migration sequence: no %03d_*.sql", $i);
        }
    }
}

// 4: idempotency heuristics per file.
foreach ($disk as $f) {
    $sql = file_get_contents($migDir . '/' . $f);
    foreach (explode(';', $sql) as $stmt) {
        $s = trim(preg_replace('/--.*$/m', '', $stmt));
        if ($s === '') continue;
        $u = strtoupper($s);
        $bad =
            (str_starts_with($u, 'CREATE TABLE')  && !str_contains($u, 'IF NOT EXISTS')) ||
            (str_starts_with($u, 'CREATE INDEX')  && !str_contains($u, 'IF NOT EXISTS')) ||
            (str_starts_with($u, 'ALTER TABLE')   && str_contains($u, 'ADD COLUMN') && !str_contains($u, 'IF NOT EXISTS')) ||
            (str_starts_with($u, 'INSERT INTO')   && !str_contains($u, 'ON CONFLICT'));
        if ($bad) {
            $warnings[] = "{$f}: possibly non-idempotent → " . substr(preg_replace('/\s+/', ' ', $s), 0, 70) . '…';
        }
    }
}

// Report.
echo "Migrations on disk: " . count($disk) . " | registered: " . count($registered) . "\n";
foreach ($warnings as $w) echo "  [warn]  {$w}\n";
foreach ($errors   as $e) echo "  [ERROR] {$e}\n";

if ($errors) {
    echo "\n" . count($errors) . " error(s).\n";
    exit(1);
}
echo "\nOK — migrations registered and ordered" . ($warnings ? " (" . count($warnings) . " warning(s))" : "") . ".\n";
exit(0);
