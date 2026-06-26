<?php
declare(strict_types=1);

// Cache is a short-TTL aggregate cache (TD-6). Its two correctness-critical
// properties are testable without a database or APCu:
//   1. remember() always returns the producer's value, and degrades to a pure
//      pass-through when no APCu backend is present (CI/dev) — so turning the
//      cache on or off can never change results, only latency.
//   2. keys are namespaced by tenant, so one tenant's cached aggregates can
//      never be served to another (RLS data isolation must hold through the cache).
require_once __DIR__ . '/../src/Database.php'; // pure tenantContext()/useTenant() — no connection
require_once __DIR__ . '/../src/Cache.php';

it('remember() returns the producer value', function () {
    Database::useTenant(null);
    expect_eq(42, Cache::remember('t_value', 5, fn() => 42), 'producer result is returned');
    expect_eq([1, 2, 3], Cache::remember('t_array', 5, fn() => [1, 2, 3]), 'array results pass through');
    expect_eq(0, Cache::remember('t_zero', 5, fn() => 0), 'a legitimate 0 is returned, not skipped');
});

it('keyFor() namespaces by tenant and version', function () {
    Database::useTenant(7);
    $k7 = Cache::keyFor('widget:open_risks');
    Database::useTenant(9);
    $k9 = Cache::keyFor('widget:open_risks');
    Database::useTenant(null);
    $k0 = Cache::keyFor('widget:open_risks');

    expect($k7 !== $k9, 'different tenants produce different keys (no cross-tenant leakage)');
    expect(str_contains($k7, ':t7:'), 'key encodes the active tenant id');
    expect(str_contains($k9, ':t9:'), 'key encodes the active tenant id');
    expect(str_contains($k0, ':t0:'), 'unset tenant falls back to a stable namespace');
    expect(str_starts_with($k7, 'aegis:'), 'keys carry the app + version prefix');
});

it('degrades to a pass-through when APCu is unavailable', function () {
    Database::useTenant(null);
    if (Cache::enabled()) {
        // APCu present (not the CI default): a hit must return the FIRST value,
        // proving the second producer never runs.
        $key = 'apcu_hit_' . bin2hex(random_bytes(4));
        $first = Cache::remember($key, 30, fn() => 'first');
        $second = Cache::remember($key, 30, fn() => 'second');
        expect_eq('first', $first, 'first call populates the cache');
        expect_eq('first', $second, 'second call is served from cache, producer not re-run');
        Cache::forget($key);
    } else {
        // APCu absent (CI/dev default): every call re-runs the producer.
        $calls = 0;
        $producer = function () use (&$calls) { $calls++; return 'x'; };
        Cache::remember('pass_through', 30, $producer);
        Cache::remember('pass_through', 30, $producer);
        expect_eq(2, $calls, 'producer runs every call when the cache is disabled');
    }
});

it('forget() is a safe no-op regardless of backend', function () {
    Database::useTenant(3);
    Cache::forget('never_stored'); // must not throw whether APCu is present or not
    expect(true, 'forget() did not throw');
});
