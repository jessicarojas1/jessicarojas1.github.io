<?php
declare(strict_types=1);

/**
 * Cache — a tiny TTL cache for expensive read-only aggregates, plus the shared
 * atomic-counter primitive the rate limiter uses.
 *
 * Why this exists: dashboard and report pages re-run heavy COUNT/GROUP BY
 * aggregations on every load (TECH_DEBT TD-6). Those results are read-only and
 * tolerate being a few seconds stale, so a short-lived cache removes most of the
 * repeated DB work with no correctness cost.
 *
 * Backend selection (resolved once per request, in priority order):
 *   1. REDIS  — opt-in: set REDIS_URL and have the phpredis extension loaded.
 *      A SHARED store, so it is correct across multiple web instances (the
 *      multi-node case in TD-6). Used for both aggregate caching and the rate
 *      limiter's shared counters.
 *   2. APCU   — single-node shared memory, the zero-dependency default. Correct
 *      for the one-instance Render deployment; used for aggregate caching only
 *      (NOT rate limiting — a per-node counter would weaken brute-force
 *      protection across instances, so that path keeps the shared DB store).
 *   3. NONE   — neither available (CLI tests, dev boxes without either). Every
 *      cache call is a transparent PASS-THROUGH: the producer runs and nothing
 *      is stored, so enabling/disabling the cache never changes results, only
 *      latency. The rate limiter falls back to its authoritative DB store.
 *
 * Redis is opt-in and uses only the optional phpredis extension — no Composer
 * dependency is added, preserving the project's dependency-free design.
 *
 * Keys for remember()/forget() are NAMESPACED BY TENANT: the cached aggregates
 * are tenant-scoped (Postgres RLS, migration 028), so a tenant-blind key would
 * leak one tenant's counts to another. The raw counter primitives take a full
 * key as-is (rate limiting runs pre-auth, keyed by IP/email, with no tenant).
 *
 * SHORT TTLs replace explicit invalidation: bounding staleness to seconds means
 * writes don't have to bust keys; the cache self-heals on the next tick.
 */
final class Cache
{
    /** Bump to invalidate every previously stored entry across the app. */
    private const VERSION = 'v1';

    /** Default TTL (seconds) for aggregate caching — short enough to stay fresh. */
    public const DEFAULT_TTL = 30;

    /** Resolved backend for this request: 'redis' | 'apcu' | 'none'. */
    private static ?string $driver = null;

    /** Live phpredis handle when the driver is 'redis'. */
    private static ?object $redis = null;

    /**
     * Resolve (once per request) which backend to use. Redis takes priority when
     * configured and reachable; otherwise APCu; otherwise none. Any failure
     * connecting to Redis degrades quietly to the next option.
     */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }
        $url = getenv('REDIS_URL') ?: ($_ENV['REDIS_URL'] ?? '');
        if ($url && class_exists('Redis')) {
            try {
                $r = self::connectRedis((string) $url);
                if ($r !== null) {
                    self::$redis = $r;
                    return self::$driver = 'redis';
                }
            } catch (\Throwable) {
                // unreachable / auth failure → fall through to APCu/none
            }
        }
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            return self::$driver = 'apcu';
        }
        return self::$driver = 'none';
    }

    /** Open a phpredis connection from a redis(s):// URL, or null on failure. */
    private static function connectRedis(string $url): ?object
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            return null;
        }
        $redis = new \Redis();
        $tls   = (($p['scheme'] ?? 'redis') === 'rediss');
        $host  = ($tls ? 'tls://' : '') . $p['host'];
        $port  = (int) ($p['port'] ?? 6379);
        // Short timeout so a misconfigured/unreachable Redis can't stall requests.
        if (!@$redis->connect($host, $port, 1.5)) {
            return null;
        }
        if (!empty($p['pass'])) {
            $redis->auth($p['pass']);
        }
        $db = isset($p['path']) ? ltrim($p['path'], '/') : '';
        if ($db !== '' && ctype_digit($db)) {
            $redis->select((int) $db);
        }
        return $redis;
    }

    /** Is a usable in-memory backend (redis or apcu) available? */
    public static function enabled(): bool
    {
        return self::driver() !== 'none';
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
     * When no backend is available the producer runs every call and nothing is
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
        $k  = self::keyFor($key);
        $ok = false;
        $hit = self::backendGet($k, $ok);
        if ($ok) {
            return $hit;
        }
        $value = $producer();
        // Cache null/0 too (a legitimate "zero open risks") so the query isn't re-run.
        self::backendSet($k, $value, max(1, $ttl));
        return $value;
    }

    /** Drop a single cached entry (best-effort; no-op when disabled). */
    public static function forget(string $key): void
    {
        if (self::enabled()) {
            self::backendDelete(self::keyFor($key));
        }
    }

    /**
     * Atomically increment a counter stored under a RAW key (not tenant-scoped),
     * setting a TTL window on first creation, and return the new count — or null
     * when there is no SHARED in-memory backend, signalling the caller to use its
     * authoritative store instead.
     *
     * Only the Redis backend is treated as shared/authoritative here: APCu is
     * per-node, so for cross-instance rate limiting it returns null and the
     * caller keeps the shared DB store. Used by Security::checkRateLimit (TD-7).
     */
    public static function incrementCounter(string $rawKey, int $windowSeconds): ?int
    {
        if (self::driver() !== 'redis') {
            return null; // APCu (per-node) / none → caller uses its shared DB store
        }
        try {
            $n = (int) self::$redis->incr($rawKey);
            if ($n === 1) {
                self::$redis->expire($rawKey, max(1, $windowSeconds));
            }
            return $n;
        } catch (\Throwable) {
            return null; // Redis hiccup → caller falls back to DB
        }
    }

    /** Set a raw (non-namespaced) key with TTL on the shared backend. No-op otherwise. */
    public static function setCounterFlag(string $rawKey, int $ttlSeconds): void
    {
        if (self::driver() === 'redis') {
            try { self::$redis->setex($rawKey, max(1, $ttlSeconds), '1'); } catch (\Throwable) {}
        }
    }

    /** True if a raw flag key exists on the shared backend. */
    public static function counterFlagExists(string $rawKey): bool
    {
        if (self::driver() === 'redis') {
            try { return (bool) self::$redis->exists($rawKey); } catch (\Throwable) {}
        }
        return false;
    }

    /** Delete raw keys on the shared backend (best-effort). */
    public static function deleteRaw(string ...$rawKeys): void
    {
        if (self::driver() === 'redis' && $rawKeys) {
            try { self::$redis->del(...$rawKeys); } catch (\Throwable) {}
        }
    }

    // ---- backend dispatch ---------------------------------------------------

    private static function backendGet(string $k, bool &$ok): mixed
    {
        switch (self::driver()) {
            case 'redis':
                try {
                    $v = self::$redis->get($k);
                } catch (\Throwable) {
                    $ok = false; return null;
                }
                if ($v === false) { $ok = false; return null; }
                $ok = true;
                return unserialize($v);
            case 'apcu':
                $found = false;
                $v = apcu_fetch($k, $found);
                $ok = $found;
                return $found ? $v : null;
            default:
                $ok = false;
                return null;
        }
    }

    private static function backendSet(string $k, mixed $v, int $ttl): void
    {
        switch (self::driver()) {
            case 'redis':
                try { self::$redis->setex($k, $ttl, serialize($v)); } catch (\Throwable) {}
                break;
            case 'apcu':
                apcu_store($k, $v, $ttl);
                break;
        }
    }

    private static function backendDelete(string $k): void
    {
        switch (self::driver()) {
            case 'redis':
                try { self::$redis->del($k); } catch (\Throwable) {}
                break;
            case 'apcu':
                apcu_delete($k);
                break;
        }
    }
}
