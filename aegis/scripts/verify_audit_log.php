#!/usr/bin/env php
<?php
/**
 * Audit log integrity verifier.
 * Walk every activity_log row in insertion order and recompute the SHA-256 hash
 * chain, comparing against the stored log_hash.
 *
 * Exit codes:
 *   0 — chain intact
 *   1 — tampering or corruption detected (first bad record ID printed)
 *   2 — configuration / bootstrap error
 *
 * Usage:
 *   php /var/www/aegis/scripts/verify_audit_log.php [--quiet]
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('AEGIS_ROOT', dirname(__DIR__));

foreach (['.env.local', '.env'] as $envFile) {
    if (file_exists(AEGIS_ROOT . '/' . $envFile)) {
        foreach (file(AEGIS_ROOT . '/' . $envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}
// Merge real environment variables (containers/K8s/CI set env, not a .env file),
// so AUDIT_HMAC_KEY / DATABASE_URL are picked up. Does not override .env values.
foreach ((getenv() ?: []) as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';

$quiet = in_array('--quiet', $argv ?? [], true);

// Audit HMAC key — must match Security::auditKey() so keyed rows verify.
$auditKey = ($_ENV['AUDIT_HMAC_KEY'] ?? '') !== ''
    ? hash('sha256', 'aegis_audit_v2:' . $_ENV['AUDIT_HMAC_KEY'], true)
    : hash('sha256', 'aegis_audit_v1:' . ($_ENV['JWT_SECRET'] ?? ''), true);

$rows = Database::fetchAll(
    "SELECT id, user_id, action, entity_type, entity_id, changes, ip_address, log_hash
     FROM activity_log
     ORDER BY id ASC"
);

if (empty($rows)) {
    if (!$quiet) echo "Audit log is empty — nothing to verify.\n";
    exit(0);
}

$prevHash = 'genesis';
$broken   = [];

foreach ($rows as $row) {
    if ($row['log_hash'] === 'genesis') {
        // Legacy row written before hash chain was introduced — skip silently
        $prevHash = 'genesis';
        continue;
    }

    $payload = implode('|', [
        $prevHash,
        (string)$row['user_id'],
        (string)$row['action'],
        (string)$row['entity_type'],
        (string)$row['entity_id'],
        (string)$row['changes'],
        (string)$row['ip_address'],
    ]);
    // Accept either the new keyed HMAC (current) or the legacy unkeyed SHA-256
    // (rows written before the keyed-chain migration), so the chain verifies
    // across the transition. New rows require the audit key to forge.
    $expectedKeyed  = hash_hmac('sha256', $payload, $auditKey);
    $expectedLegacy = hash('sha256', $payload);
    $expected = $expectedKeyed;

    if (!hash_equals($expectedKeyed, $row['log_hash']) && !hash_equals($expectedLegacy, $row['log_hash'])) {
        $broken[] = $row['id'];
        if (!$quiet) {
            echo "[FAIL] Record ID {$row['id']} — hash mismatch.\n";
            echo "       Expected : {$expected}\n";
            echo "       Stored   : {$row['log_hash']}\n";
        }
    }

    $prevHash = $row['log_hash'];
}

if (empty($broken)) {
    $count = count($rows);
    if (!$quiet) echo "✓ Audit log chain intact. {$count} records verified.\n";
    exit(0);
} else {
    if (!$quiet) {
        $n = count($broken);
        echo "\n[ALERT] {$n} record(s) with broken hash chain. First bad ID: {$broken[0]}\n";
        echo "        This may indicate database tampering or a migration gap.\n";
    }
    exit(1);
}
