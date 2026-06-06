'use strict';
/* CITADEL — in-memory rate limiting + brute-force lockout (no dependencies).
 *
 * Two primitives, both backed by plain Maps so they work on the free tier with
 * no external store. State is per-process and resets on deploy/restart — which
 * is fine: limits are short-lived by design.
 *
 *   limit(key, max, windowMs)   fixed-window request counter -> { ok, retryAfter, remaining }
 *   fail(key, opts)             record a failed auth attempt -> { locked, retryAfter, fails }
 *   clearFails(key)             reset the failure counter after a success
 *   lockState(key, opts)        read-only lock check without recording -> { locked, retryAfter }
 */
const _buckets = new Map();   // key -> { count, resetAt }
const _fails = new Map();     // key -> { fails, firstAt, lockedUntil }

let _lastSweep = 0;
function sweep(now) {
  if (now - _lastSweep < 60000) return;       // at most once a minute
  _lastSweep = now;
  for (const [k, b] of _buckets) if (b.resetAt <= now) _buckets.delete(k);
  for (const [k, f] of _fails) {
    if ((f.lockedUntil || 0) <= now && (now - f.firstAt) > 3600000) _fails.delete(k);
  }
}

// Fixed-window limiter. Returns ok=false with retryAfter (seconds) when exceeded.
function limit(key, max, windowMs) {
  const now = Date.now();
  sweep(now);
  let b = _buckets.get(key);
  if (!b || b.resetAt <= now) { b = { count: 0, resetAt: now + windowMs }; _buckets.set(key, b); }
  b.count += 1;
  const remaining = Math.max(0, max - b.count);
  if (b.count > max) return { ok: false, retryAfter: Math.ceil((b.resetAt - now) / 1000), remaining: 0 };
  return { ok: true, retryAfter: 0, remaining };
}

// Brute-force tracker. After `maxFails` failures inside `windowMs`, lock the key
// for `lockMs`. Subsequent calls while locked extend nothing but report the lock.
function fail(key, opts) {
  const o = Object.assign({ maxFails: 5, windowMs: 15 * 60000, lockMs: 15 * 60000 }, opts || {});
  const now = Date.now();
  sweep(now);
  let f = _fails.get(key);
  if (!f || (now - f.firstAt) > o.windowMs) { f = { fails: 0, firstAt: now, lockedUntil: 0 }; _fails.set(key, f); }
  f.fails += 1;
  if (f.fails >= o.maxFails) f.lockedUntil = now + o.lockMs;
  const locked = (f.lockedUntil || 0) > now;
  return { locked, retryAfter: locked ? Math.ceil((f.lockedUntil - now) / 1000) : 0, fails: f.fails };
}

function clearFails(key) { _fails.delete(key); }

function lockState(key) {
  const now = Date.now();
  const f = _fails.get(key);
  const locked = !!(f && (f.lockedUntil || 0) > now);
  return { locked, retryAfter: locked ? Math.ceil((f.lockedUntil - now) / 1000) : 0 };
}

module.exports = { limit, fail, clearFails, lockState };
