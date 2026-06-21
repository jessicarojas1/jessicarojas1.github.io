<?php
declare(strict_types=1);

/**
 * Integration: prove the platform-admin cross-tenant switch (Phase 5) against a
 * live Postgres — the switch validates the target tenant, updates the active
 * context, writes an audit row, and exit reverts. Requires DATABASE_URL and
 * AUDIT_HMAC_KEY in env.
 *
 * Usage: php tests/integration/platform_db.php
 */
define('AEGIS_ROOT', dirname(__DIR__, 2));
foreach (getenv() ?: [] as $k => $v) { if (!isset($_ENV[$k])) $_ENV[$k] = $v; }

require_once AEGIS_ROOT . '/config/database.php';
require_once AEGIS_ROOT . '/src/Database.php';
require_once AEGIS_ROOT . '/src/Security.php';
require_once AEGIS_ROOT . '/src/Auth.php';

function fail(string $m): never { fwrite(STDERR, "[platform_db] FAIL: $m\n"); exit(1); }

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// A second, active tenant to switch into.
Database::query(
    "INSERT INTO tenants (id, name, slug) VALUES (2, 'Acme', 'acme') ON CONFLICT (id) DO NOTHING");

// Simulate an authenticated PLATFORM admin (home tenant 1).
$_SESSION = ['user' => [
    'id' => 1, 'name' => 'operator', 'email' => 'op@aegis.test', 'role' => 'admin',
    'tenant_id' => 1, 'is_platform_admin' => true,
]];

if (Auth::activeTenantId() !== 1) fail('should start bound to home tenant 1');

// Switch into tenant 2 — must validate, set context, and write an audit row.
$before = (int)(Database::fetchOne(
    "SELECT count(*) AS c FROM activity_log WHERE action = 'platform.tenant_switch'")['c'] ?? 0);
Auth::switchTenant(2);
if (Auth::activeTenantId() !== 2)        fail('activeTenantId not 2 after switchTenant(2)');
if (!Auth::isImpersonatingTenant())      fail('isImpersonatingTenant should be true after switch');
$after = (int)(Database::fetchOne(
    "SELECT count(*) AS c FROM activity_log WHERE action = 'platform.tenant_switch'")['c'] ?? 0);
if ($after !== $before + 1)              fail('switchTenant did not write an audit row');

// The audit row records from→to.
$row = Database::fetchOne(
    "SELECT entity_id, changes FROM activity_log WHERE action='platform.tenant_switch' ORDER BY id DESC LIMIT 1");
if ((int)$row['entity_id'] !== 2)        fail('audit row entity_id should be the target tenant (2)');
if (strpos((string)$row['changes'], '"to":2') === false) fail('audit changes should record to:2');

// Exit — reverts to home and audits the exit.
Auth::exitTenant();
if (Auth::activeTenantId() !== 1)        fail('exitTenant did not revert to home tenant 1');
if (Auth::isImpersonatingTenant())       fail('should not be impersonating after exit');
if (!Database::fetchOne("SELECT 1 AS x FROM activity_log WHERE action='platform.tenant_exit'"))
    fail('exitTenant did not write an audit row');

// Switching to a non-existent tenant is refused (and writes nothing).
$threw = false;
try { Auth::switchTenant(99999); } catch (RuntimeException) { $threw = true; }
if (!$threw)                             fail('switching to a missing tenant should throw');
if (Auth::activeTenantId() !== 1)        fail('a failed switch must not change the active tenant');

// A non-platform-admin cannot switch even against a live DB.
$_SESSION['user']['is_platform_admin'] = false;
$threw = false;
try { Auth::switchTenant(2); } catch (RuntimeException) { $threw = true; }
if (!$threw)                             fail('a non-platform-admin must not be able to switch tenants');

// Leave tenant 2 in place (other integration steps may reference it; it's seeded
// idempotently). The audited rows remain in activity_log as the operator trail.
fwrite(STDOUT, "[platform_db] switchTenant validation, active-context binding, audit logging, and exit revert verified. OK\n");
exit(0);
