'use strict';
/* CITADEL — rate limiting + brute-force lockout.
 *
 * Backend: Redis when REDIS_URL is set (shared across instances — correct under
 * horizontal scaling), otherwise in-memory Maps (per-process; fine on a single
 * instance / free tier). On a backend error the functions return their permissive
 * default but set `error:true`, so a non-critical route can fail open while the
 * login path can choose to fail CLOSED (deny) and not let a Redis outage open a
 * brute-force window.
 *
 *   limit(key, max, windowMs)   fixed-window request counter -> { ok, retryAfter, remaining }
 *   fail(key, opts)             record a failed auth attempt -> { locked, retryAfter, fails }
 *   clearFails(key)             reset the failure counter after a success
 *   lockState(key)              read-only lock check -> { locked, retryAfter }
 */
let redis = null;
if (process.env.REDIS_URL) {
  try {
    const Redis = require('ioredis');
    // rediss:// uses TLS. Verification is opt-in (REDIS_TLS_VERIFY=1) or implied
    // by a supplied CA (REDIS_TLS_CA: PEM string or path); default stays
    // permissive so existing managed-Redis URLs keep working.
    let tls;
    if (/^rediss:/i.test(process.env.REDIS_URL)) {
      let ca = process.env.REDIS_TLS_CA || null;
      if (ca && !/-----BEGIN/.test(ca)) { try { ca = require('fs').readFileSync(ca, 'utf8'); } catch (e) { ca = null; } }
      tls = { rejectUnauthorized: process.env.REDIS_TLS_VERIFY === '1' || !!ca };
      if (ca) tls.ca = ca;
    }
    redis = new Redis(process.env.REDIS_URL, { maxRetriesPerRequest: 2, enableOfflineQueue: false, tls });
    redis.on('error', (e) => console.error(JSON.stringify({ level: 'error', src: 'ratelimit', msg: 'redis error', err: e.message })));
  } catch (e) {
    console.error(JSON.stringify({ level: 'error', src: 'ratelimit', msg: 'ioredis unavailable; using in-memory limiter', err: e.message }));
  }
}
function backend() { return redis ? 'redis' : 'memory'; }

/* ---------------- in-memory fallback ---------------- */
const _buckets = new Map();   // key -> { count, resetAt }
const _fails = new Map();     // key -> { fails, firstAt, lockedUntil }
let _lastSweep = 0;
function sweep(now) {
  if (now - _lastSweep < 60000) return;
  _lastSweep = now;
  for (const [k, b] of _buckets) if (b.resetAt <= now) _buckets.delete(k);
  for (const [k, f] of _fails) if ((f.lockedUntil || 0) <= now && (now - f.firstAt) > 3600000) _fails.delete(k);
}
function memLimit(key, max, windowMs) {
  const now = Date.now(); sweep(now);
  let b = _buckets.get(key);
  if (!b || b.resetAt <= now) { b = { count: 0, resetAt: now + windowMs }; _buckets.set(key, b); }
  b.count += 1;
  if (b.count > max) return { ok: false, retryAfter: Math.ceil((b.resetAt - now) / 1000), remaining: 0 };
  return { ok: true, retryAfter: 0, remaining: Math.max(0, max - b.count) };
}
function memFail(key, o) {
  const now = Date.now(); sweep(now);
  let f = _fails.get(key);
  if (!f || (now - f.firstAt) > o.windowMs) { f = { fails: 0, firstAt: now, lockedUntil: 0 }; _fails.set(key, f); }
  f.fails += 1;
  if (f.fails >= o.maxFails) f.lockedUntil = now + o.lockMs;
  const locked = (f.lockedUntil || 0) > now;
  return { locked, retryAfter: locked ? Math.ceil((f.lockedUntil - now) / 1000) : 0, fails: f.fails };
}
function memLockState(key) {
  const now = Date.now();
  const f = _fails.get(key);
  const locked = !!(f && (f.lockedUntil || 0) > now);
  return { locked, retryAfter: locked ? Math.ceil((f.lockedUntil - now) / 1000) : 0 };
}

/* ---------------- public async API ---------------- */
async function limit(key, max, windowMs) {
  if (!redis) return memLimit(key, max, windowMs);
  try {
    const k = 'rl:' + key;
    const n = await redis.incr(k);
    if (n === 1) await redis.pexpire(k, windowMs);
    if (n > max) { const ttl = await redis.pttl(k); return { ok: false, retryAfter: Math.ceil((ttl > 0 ? ttl : windowMs) / 1000), remaining: 0 }; }
    return { ok: true, retryAfter: 0, remaining: Math.max(0, max - n) };
  } catch (e) { return { ok: true, retryAfter: 0, remaining: max, error: true }; }   // permissive default; callers may fail closed
}
async function fail(key, opts) {
  const o = Object.assign({ maxFails: 5, windowMs: 15 * 60000, lockMs: 15 * 60000 }, opts || {});
  if (!redis) return memFail(key, o);
  try {
    const fk = 'rlf:' + key, lk = 'rll:' + key;
    const n = await redis.incr(fk);
    if (n === 1) await redis.pexpire(fk, o.windowMs);
    if (n >= o.maxFails) { await redis.set(lk, '1', 'PX', o.lockMs); return { locked: true, retryAfter: Math.ceil(o.lockMs / 1000), fails: n }; }
    const ttl = await redis.pttl(lk);
    if (ttl > 0) return { locked: true, retryAfter: Math.ceil(ttl / 1000), fails: n };
    return { locked: false, retryAfter: 0, fails: n };
  } catch (e) { return { locked: false, retryAfter: 0, fails: 0, error: true }; }
}
async function clearFails(key) {
  if (!redis) { _fails.delete(key); return; }
  try { await redis.del('rlf:' + key, 'rll:' + key); } catch (e) {}
}
async function lockState(key) {
  if (!redis) return memLockState(key);
  try { const ttl = await redis.pttl('rll:' + key); if (ttl > 0) return { locked: true, retryAfter: Math.ceil(ttl / 1000) }; return { locked: false, retryAfter: 0 }; }
  catch (e) { return { locked: false, retryAfter: 0, error: true }; }   // caller may choose to fail closed
}

module.exports = { limit, fail, clearFails, lockState, backend };
