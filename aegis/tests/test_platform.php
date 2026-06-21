<?php
declare(strict_types=1);

// Platform-admin tenant-switch logic. The resolution helpers (isPlatformAdmin,
// homeTenantId, activeTenantId, isImpersonatingTenant) read only $_SESSION, and
// the switchTenant guards fire BEFORE any DB call — so all of this is unit-
// testable without a database. The audited switch/exit round-trip itself is
// proven against a live Postgres in tests/integration/platform_db.php.
require_once __DIR__ . '/../src/Auth.php';

function platform_session(bool $isPlatformAdmin, int $homeTenant = 1, ?array $active = null): void {
    $_SESSION = ['user' => [
        'id' => 1, 'name' => 'op', 'email' => 'op@x', 'role' => 'admin',
        'tenant_id' => $homeTenant, 'is_platform_admin' => $isPlatformAdmin,
    ]];
    if ($active !== null) {
        $_SESSION['active_tenant'] = $active;
    }
}

it('isPlatformAdmin reflects the session flag', function () {
    platform_session(false);
    expect(!Auth::isPlatformAdmin(), 'non-platform-admin misreported');
    platform_session(true);
    expect(Auth::isPlatformAdmin(), 'platform admin not detected');
});

it('a non-platform-admin always binds the home tenant (active_tenant ignored)', function () {
    // Even if a stale active_tenant is present, a non-platform-admin must not use it.
    platform_session(false, 3, ['id' => 9, 'expires' => time() + 999]);
    expect_eq(3, Auth::activeTenantId(), 'non-platform-admin escaped home tenant');
    expect(!Auth::isImpersonatingTenant(), 'non-platform-admin cannot impersonate');
});

it('a platform admin with no switch binds the home tenant', function () {
    platform_session(true, 1);
    expect_eq(1, Auth::activeTenantId());
    expect(!Auth::isImpersonatingTenant(), 'no switch ⇒ not impersonating');
});

it('a platform admin with a live switch binds the target tenant', function () {
    platform_session(true, 1, ['id' => 2, 'expires' => time() + 999]);
    expect_eq(2, Auth::activeTenantId(), 'live switch not honored');
    expect(Auth::isImpersonatingTenant(), 'should be impersonating tenant 2');
});

it('an expired switch reverts to home and is cleared', function () {
    platform_session(true, 1, ['id' => 2, 'expires' => time() - 1]);
    expect_eq(1, Auth::activeTenantId(), 'expired switch should revert to home');
    expect(!isset($_SESSION['active_tenant']), 'expired switch should be cleared from session');
});

it('switchTenant refuses a non-platform-admin before any DB call', function () {
    platform_session(false);
    $type = '';
    try { Auth::switchTenant(2); }
    catch (RuntimeException) { $type = 'runtime'; }
    catch (Throwable) { $type = 'other'; }
    expect_eq('runtime', $type, 'non-platform-admin switch was not refused up front');
});

it('switchTenant rejects a non-positive tenant id before any DB call', function () {
    platform_session(true);
    $type = '';
    try { Auth::switchTenant(0); }
    catch (InvalidArgumentException) { $type = 'arg'; }
    catch (Throwable) { $type = 'other'; }
    expect_eq('arg', $type, 'invalid tenant id guard did not fire first');
});

it('exitTenant is a no-op when not switched (no DB needed)', function () {
    platform_session(true, 1);
    Auth::exitTenant(); // must not throw or touch the DB
    expect_eq(1, Auth::activeTenantId());
});
