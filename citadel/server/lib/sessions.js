'use strict';
/* CITADEL — JWT session registry + revocation.
 *
 * JWTs stay stateless (signature + exp), but each carries a `jti`. We track the
 * jti so an admin (or the user) can see active sessions and revoke them.
 *
 * Storage: an in-memory cache backs the hot read path (isRevoked on every
 * request). When DATABASE_URL is set it is durable + shared across instances —
 * sessions/revocations are written through to Postgres, loaded on boot, and the
 * revoked set is refreshed on an interval so a revoke on one instance propagates
 * to others within REVOKE_REFRESH_MS.
 *
 * Verification is DEFAULT-ALLOW: a validly signed, unexpired token is accepted
 * even if its jti isn't in the registry (and is lazily re-registered), so a
 * restart doesn't log everyone out. Only an explicit revoke rejects a token.
 */
const db = require('./db');
const REVOKE_REFRESH_MS = parseInt(process.env.CITADEL_REVOKE_REFRESH_MS || '20000', 10);

// Per-tenant session buckets. Single-tenant (the default) uses one bucket keyed
// by ''. With schema-per-tenant multi-tenancy (H5) each tenant gets its own
// active/revoked maps, selected by the ambient DB schema (set by db.runInTenant),
// so a jti, revocation, or session listing in one tenant never leaks to another.
const _tenants = new Map();  // key -> { active: Map<jti,rec>, revoked: Map<jti,exp>, lastSweep, loaded }
function bucket() {
  const key = (db.currentSchema && db.currentSchema()) || '';
  let b = _tenants.get(key);
  if (!b) { b = { active: new Map(), revoked: new Map(), lastSweep: 0, loaded: false }; _tenants.set(key, b); }
  return b;
}

function sweep() {
  const b = bucket();
  const now = Math.floor(Date.now() / 1000);
  if ((now - b.lastSweep) < 60) return;
  b.lastSweep = now;
  for (const [jti, s] of b.active) if (s.exp && s.exp <= now) b.active.delete(jti);
  for (const [jti, exp] of b.revoked) if (exp <= now) b.revoked.delete(jti);
}
function pgErr(msg) { return e => console.error(JSON.stringify({ level: 'error', src: 'sessions', msg, err: e.message })); }

// Record (or refresh) a session seen on a request. Safe to call repeatedly.
function register({ jti, userId, email, role, ip, ua, iat, exp }) {
  if (!jti) return;
  sweep();
  const b = bucket();
  const now = Math.floor(Date.now() / 1000);
  const prev = b.active.get(jti);
  const rec = {
    jti, userId, email, role,
    ip: ip || (prev && prev.ip) || null,
    ua: (ua || (prev && prev.ua) || '').slice(0, 200),
    iat: iat || (prev && prev.iat) || now,
    exp: exp || (prev && prev.exp) || (now + 43200),
    firstSeen: (prev && prev.firstSeen) || new Date().toISOString(),
    lastSeen: new Date().toISOString()
  };
  b.active.set(jti, rec);
  if (db.enabled()) {
    db.query(`INSERT INTO citadel_sessions(jti,user_id,email,role,ip,ua,iat,exp,first_seen,last_seen)
      VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,now())
      ON CONFLICT(jti) DO UPDATE SET ip=$5,ua=$6,last_seen=now()`,
      [rec.jti, rec.userId, rec.email, rec.role, rec.ip, rec.ua, rec.iat, rec.exp, rec.firstSeen]).catch(pgErr('register'));
  }
}

function isRevoked(jti) { return !!jti && bucket().revoked.has(jti); }

// Revoke a single session by jti. Returns the revoked record (or null).
function revoke(jti) {
  if (!jti) return null;
  const b = bucket();
  const s = b.active.get(jti);
  const exp = (s && s.exp) || (Math.floor(Date.now() / 1000) + 43200);
  b.revoked.set(jti, exp);
  b.active.delete(jti);
  if (db.enabled()) {
    db.query('INSERT INTO citadel_revoked(jti,exp) VALUES($1,$2) ON CONFLICT(jti) DO NOTHING', [jti, exp]).catch(pgErr('revoke'));
    db.query('DELETE FROM citadel_sessions WHERE jti=$1', [jti]).catch(pgErr('revoke-del'));
  }
  return s || { jti };
}

// Revoke every active session for a user. Returns the count revoked.
function revokeAllForUser(userId) {
  let n = 0;
  for (const [jti, s] of bucket().active) if (s.userId === userId) { revoke(jti); n++; }
  // Also catch sessions only present in PG (e.g. created by another instance).
  if (db.enabled()) {
    db.query(`INSERT INTO citadel_revoked(jti,exp) SELECT jti,exp FROM citadel_sessions WHERE user_id=$1
      ON CONFLICT(jti) DO NOTHING`, [userId]).catch(pgErr('revoke-all'));
    db.query('DELETE FROM citadel_sessions WHERE user_id=$1', [userId]).catch(pgErr('revoke-all-del'));
  }
  return n;
}

function listForUser(userId) {
  sweep();
  return [...bucket().active.values()].filter(s => s.userId === userId)
    .sort((a, b) => (a.lastSeen < b.lastSeen ? 1 : -1));
}
function listAll() {
  sweep();
  return [...bucket().active.values()].sort((a, b) => (a.lastSeen < b.lastSeen ? 1 : -1));
}
function stats() { sweep(); const b = bucket(); return { active: b.active.size, revoked: b.revoked.size }; }

// Load durable state for the current (ambient) tenant bucket from its schema.
async function loadBucket() {
  if (!db.enabled()) return;
  const b = bucket();
  const now = Math.floor(Date.now() / 1000);
  const a = await db.query('SELECT * FROM citadel_sessions WHERE exp > $1', [now]);
  for (const r of a.rows) {
    b.active.set(r.jti, {
      jti: r.jti, userId: r.user_id, email: r.email, role: r.role, ip: r.ip, ua: r.ua,
      iat: Number(r.iat), exp: Number(r.exp),
      firstSeen: (r.first_seen instanceof Date ? r.first_seen.toISOString() : r.first_seen),
      lastSeen: (r.last_seen instanceof Date ? r.last_seen.toISOString() : r.last_seen)
    });
  }
  await refreshRevoked();
  b.loaded = true;
}

// Load durable state on boot (default tenant) + start the cross-instance
// revoked-set refresh across every loaded tenant bucket.
async function init() {
  if (!db.enabled()) return;
  try { await loadBucket(); } catch (e) { pgErr('init')(e); }
  setInterval(() => { refreshAll().catch(pgErr('refresh')); }, REVOKE_REFRESH_MS).unref();
}

// Ensure the current tenant's bucket is loaded (idempotent). Awaited by the
// per-request multi-tenancy middleware before handlers run.
async function ensureLoaded() {
  if (!db.enabled()) return;
  if (bucket().loaded) return;
  await loadBucket();
}

// Refresh the revoked set for the CURRENT tenant bucket from its schema.
async function refreshRevoked() {
  if (!db.enabled()) return;
  const b = bucket();
  const now = Math.floor(Date.now() / 1000);
  const r = await db.query('SELECT jti, exp FROM citadel_revoked WHERE exp > $1', [now]);
  b.revoked.clear();
  for (const row of r.rows) b.revoked.set(row.jti, Number(row.exp));
  db.query('DELETE FROM citadel_revoked WHERE exp <= $1', [now]).catch(pgErr('prune'));
}

// Refresh every loaded tenant bucket, each within its own schema scope.
async function refreshAll() {
  for (const [key, b] of _tenants) {
    if (!b.loaded) continue;
    if (key) await db.runInTenant(key, () => refreshRevoked());
    else await refreshRevoked();
  }
}

module.exports = { init, ensureLoaded, register, isRevoked, revoke, revokeAllForUser, listForUser, listAll, stats };
