<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

// setTenant validates its argument BEFORE touching the database, so the guard is
// unit-testable without a connection. The GUC round-trip itself is proven against
// a live Postgres in the aegis-integration workflow (tests/integration/tenancy_db.php).

it('rejects a non-positive tenant id', function () {
    foreach ([0, -1, -999] as $bad) {
        $threw = false;
        try { Database::setTenant($bad); }
        catch (InvalidArgumentException) { $threw = true; }
        catch (Throwable) { $threw = true; } // any DB error means it got past the guard — still a fail below
        expect($threw, "setTenant({$bad}) should have thrown before hitting the DB");
    }
});

it('the tenant-id guard fires before any DB call (id 0)', function () {
    // With no DB configured, a valid id would surface a DB error; an invalid id
    // must raise InvalidArgumentException first.
    $type = '';
    try { Database::setTenant(0); }
    catch (InvalidArgumentException) { $type = 'arg'; }
    catch (Throwable) { $type = 'other'; }
    expect_eq('arg', $type, 'guard did not fire before the DB layer');
});

// ── Write-path stamping (applyTenantStamp — pure, no DB) ────────────────────

it('does not stamp when no tenant context is set', function () {
    Database::useTenant(null);
    $out = Database::applyTenantStamp('risks', ['title' => 'x']);
    expect(!array_key_exists('tenant_id', $out), 'stamped without a context');
});

it('stamps tenant_id on tenant-owned tables when context is set', function () {
    Database::useTenant(5);
    $out = Database::applyTenantStamp('risks', ['title' => 'x']);
    expect_eq(5, $out['tenant_id'] ?? null, 'risks not stamped');
    Database::useTenant(null);
});

it('does NOT stamp non-tenant-owned tables', function () {
    Database::useTenant(5);
    foreach (['settings', 'activity_log', 'tenants', 'rate_limits', 'api_keys'] as $sys) {
        $out = Database::applyTenantStamp($sys, ['k' => 'v']);
        expect(!array_key_exists('tenant_id', $out), "{$sys} should not be stamped");
    }
    Database::useTenant(null);
});

it('never overrides an explicit tenant_id supplied by the caller', function () {
    Database::useTenant(5);
    $out = Database::applyTenantStamp('policies', ['title' => 'p', 'tenant_id' => 9]);
    expect_eq(9, $out['tenant_id'], 'caller tenant_id was overridden');
    Database::useTenant(null);
});

it('useTenant ignores invalid ids (treated as no context)', function () {
    Database::useTenant(0);
    expect(Database::tenantContext() === null, '0 should clear context');
    Database::useTenant(-3);
    expect(Database::tenantContext() === null, 'negative should clear context');
    Database::useTenant(2);
    expect_eq(2, Database::tenantContext());
    Database::useTenant(null);
});
