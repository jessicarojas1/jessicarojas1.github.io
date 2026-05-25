#!/usr/bin/env php
<?php
/**
 * Seeds SOC 2, NIST 800-53, HIPAA, and PCI-DSS v4 framework packages.
 * Each JSON seed uses the nested "domains" → "controls" (2-level) format.
 * Safe to re-run — skips packages whose standard code already exists.
 *
 * Usage:
 *   php /var/www/aegis/database/seeds/seed_frameworks.php
 *   php /var/www/aegis/database/seeds/seed_frameworks.php --force   # re-import even if exists
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (['.env.local', '.env'] as $f) {
    if (file_exists(AEGIS_ROOT . '/' . $f)) {
        foreach (file(AEGIS_ROOT . '/' . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}
require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

$force = in_array('--force', $argv ?? [], true);

$seeds = [
    __DIR__ . '/soc2.json',
    __DIR__ . '/nist80053.json',
    __DIR__ . '/hipaa.json',
    __DIR__ . '/pcidss.json',
];

foreach ($seeds as $file) {
    if (!file_exists($file)) { echo "SKIP (not found): {$file}\n"; continue; }

    $data = json_decode(file_get_contents($file), true);
    if (!$data) { echo "ERROR (invalid JSON): {$file}\n"; continue; }

    $std  = $data['standard'] ?? [];
    $code = $std['code'] ?? '';

    // Upsert standard
    $standard = Database::fetchOne("SELECT id FROM standards WHERE code = ?", [$code]);
    if (!$standard) {
        Database::query(
            "INSERT INTO standards (code, name, version, authority, category, is_builtin, is_active)
             VALUES (?,?,?,?,?,TRUE,TRUE)",
            [$code, $std['name'] ?? $code, $data['version'] ?? '1.0', $std['authority'] ?? '', $std['category'] ?? '']
        );
        $standard = Database::fetchOne("SELECT id FROM standards WHERE code = ?", [$code]);
    }
    $standardId = $standard['id'];

    // Check if package already imported
    $existing = Database::fetchOne(
        "SELECT id FROM compliance_packages WHERE standard_id = ? AND name = ?",
        [$standardId, $data['name']]
    );
    if ($existing && !$force) {
        echo "SKIP (already imported): {$data['name']}\n";
        continue;
    }
    if ($existing && $force) {
        // Delete old package + cascades
        Database::query("DELETE FROM compliance_packages WHERE id = ?", [$existing['id']]);
    }

    // Insert package
    Database::query(
        "INSERT INTO compliance_packages (standard_id, name, version, description, is_active, imported_at)
         VALUES (?,?,?,?,TRUE,NOW())",
        [$standardId, $data['name'], $data['version'] ?? '1.0', $data['description'] ?? '']
    );
    $pkg = Database::fetchOne(
        "SELECT id FROM compliance_packages WHERE standard_id = ? AND name = ? ORDER BY id DESC LIMIT 1",
        [$standardId, $data['name']]
    );
    $pkgId = $pkg['id'];

    $domainSort = 0;
    $controlCount = 0;

    foreach ($data['domains'] ?? [] as $domain) {
        // Level 1 — domain
        Database::query(
            "INSERT INTO compliance_objectives (package_id, code, title, description, level, sort_order)
             VALUES (?,?,?,?,1,?)",
            [$pkgId, $domain['code'], $domain['title'], $domain['description'] ?? '', $domainSort++]
        );
        $domainRow = Database::fetchOne(
            "SELECT id FROM compliance_objectives WHERE package_id = ? AND code = ? AND level = 1",
            [$pkgId, $domain['code']]
        );
        $domainId = $domainRow['id'];

        $ctrlSort = 0;
        foreach ($domain['controls'] ?? [] as $ctrl) {
            // Level 2 — control
            Database::query(
                "INSERT INTO compliance_objectives (package_id, parent_id, code, title, description, level, sort_order)
                 VALUES (?,?,?,?,?,2,?)",
                [$pkgId, $domainId, $ctrl['code'], $ctrl['title'], $ctrl['description'] ?? '', $ctrlSort++]
            );
            $controlCount++;
        }
    }

    Database::query("UPDATE compliance_packages SET objectives_count = ? WHERE id = ?", [$controlCount, $pkgId]);
    echo "OK: {$data['name']} — {$controlCount} controls imported (package ID {$pkgId})\n";
}

echo "\nDone.\n";
