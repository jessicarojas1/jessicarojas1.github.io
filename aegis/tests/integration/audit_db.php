<?php
declare(strict_types=1);

/**
 * Integration seeder — writes a REAL keyed audit chain against a live Postgres,
 * so the CI `aegis-integration` job can then run scripts/verify_audit_log.php and
 * prove (a) the keyed HMAC chain verifies, (b) user AND system rows are covered,
 * and (c) tampering is detected. Requires DATABASE_URL + AUDIT_HMAC_KEY in env.
 *
 * Usage: php tests/integration/audit_db.php
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));

foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';
require_once AEGIS_ROOT . '/src/Auth.php';

function fail(string $m): never { fwrite(STDERR, "[audit_db] FAIL: $m\n"); exit(1); }

// A user row is needed for the FK on activity_log.user_id.
$uid = (int)(Database::fetchOne(
    "INSERT INTO users (name, email, password_hash, role)
     VALUES ('Integration Bot', 'integration@aegis.test', 'x', 'admin')
     ON CONFLICT (email) DO UPDATE SET name = EXCLUDED.name
     RETURNING id"
)['id'] ?? 0);
if ($uid <= 0) { fail('could not create test user'); }

// Simulate an authenticated session so Auth::log() records under this user.
$_SESSION['user'] = ['id' => $uid, 'name' => 'Integration Bot', 'role' => 'admin'];

// Write a mix of user-attributed and system events — exercising the unified,
// serialized, keyed append path.
Auth::log('risk.create', 'risk', 101, ['title' => 'Test risk', 'score' => 12]);
Auth::log('policy.publish', 'policy', 7, null);
Auth::logSystem('workflow.run', 'workflow', 42);
Auth::log('audit.close', 'audit', 3, ['result' => 'passed']);
Auth::logSystem('webhook.deliver', 'webhook_endpoint', 9);

$n = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM activity_log")['c'] ?? 0);
if ($n < 5) { fail("expected >=5 audit rows, got {$n}"); }

fwrite(STDOUT, "[audit_db] seeded {$n} audit rows (user_id={$uid}). OK\n");
exit(0);
