<?php
declare(strict_types=1);

/**
 * Cache — a tiny TTL cache for expensive read-only aggregates.
 *
 * Why this exists: dashboard and report pages re-run heavy COUNT/GROUP BY
 * aggregations on every load (see TECH_DEBT.md TD-6). A custom dashboard with N
 * widgets fires N independent aggregate batches per request. Those results are
 * read-only and tolerate being a few seconds stale, so a short-lived cache
 * removes most of the repeated DB work with no correctness cost.
 *
 * Design choices (deliberately conservative — see TD-6):
 *   - Backend is APCu (single-node shared memory, the zero-dependency default
 *     for the documented Render deployment). When the extension is absent — CLI
 *     test runs, dev boxes without APCu, the CI image — every call is a
 *     transparent PASS-THROUGH: the producer runs and nothing is cached. So
 *     enabling/disabling the cache never changes results, only latency.
 *   - Keys are NAMESPACED BY TENANT. The cached aggregates are tenant-scoped
 *     (Postgres RLS, migration 028), so a key that ignored the tenant would leak
 *     one tenant's counts to another. keyFor() prepends the active tenant from
 *     Database::tenantContext() (the in-memory context bound per request in
 *     index.php before dispatch) — pure, no DB round-trip.
 *   - SHORT TTLs instead of explicit invalidation. Bounding staleness to a few
 *     seconds means writes don't have to hunt down and bust keys; the cache
 *     self-heals on the next tick. This trades a small, bounded staleness window
 *     for a far simpler and safer invalidation story.
 *
 * A VERSION prefix lets every entry be invalidated at once (bump the constant)
 * without needing apcu_clear_cache, which would also wipe unrelated caches.
 */
final class Cache
{
    /** Bump to invalidate every previously stored entry across the app. */
    private const VERSION = 'v1';

    /** Default TTL (seconds) for aggregate caching — short enough to stay fresh. */
    public const DEFAULT_TTL = 30;

    /** Is a usable APCu backend available in this SAPI? */
    public static function enabled(): bool
    {
        // apcu_enabled() reflects both the extension being loaded AND apc.enabled
        // / apc.enable_cli. In CLI (tests) enable_cli is off by default, so this
        // returns false and the cache degrades to pass-through.
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Build the fully-qualified, tenant-namespaced storage key for a logical key.
     * Pure (no DB) — reads the in-memory tenant context. Public so it can be
     * unit-tested and so callers can forget() a key they remember()'d.
     */
    public static function keyFor(string $key): string
    {
        $tenant = Database::tenantContext() ?? 0;
        return 'aegis:' . self::VERSION . ':t' . $tenant . ':' . $key;
    }

    /**
     * Return the cached value for $key, or compute it with $producer, cache it
     * for $ttl seconds, and return it. The ONLY method most callers need.
     *
     * When the cache is disabled the producer runs every call and nothing is
     * stored — identical results to not using the cache at all.
     *
     * @template T
     * @param  callable():T $producer
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $producer): mixed
    {
        if (!self::enabled()) {
            return $producer();
        }
        $k = self::keyFor($key);
        $ok = false;
        $hit = apcu_fetch($k, $ok);
        if ($ok) {
            return $hit;
        }
        $value = $producer();
        // Never cache a failure sentinel longer than needed; still cache null/0
        // (a legitimate "zero open risks") so the count query isn't re-run.
        apcu_store($k, $value, max(1, $ttl));
        return $value;
    }

    /** Drop a single cached entry (best-effort; no-op when disabled). */
    public static function forget(string $key): void
    {
        if (self::enabled()) {
            apcu_delete(self::keyFor($key));
        }
    }
}
