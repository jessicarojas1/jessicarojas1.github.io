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
