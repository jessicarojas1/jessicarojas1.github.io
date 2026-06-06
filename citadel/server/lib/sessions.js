'use strict';
/* CITADEL — in-memory JWT session registry + revocation (no dependencies).
 *
 * JWTs stay stateless (signature + exp), but each carries a `jti`. We track the
 * jti here so an admin (or the user) can see active sessions and revoke them.
 *
 * Design notes (free tier, no persistent store):
 *  - The registry is per-process and clears on deploy/restart. To avoid logging
 *    everyone out on every deploy, verification is DEFAULT-ALLOW: a validly
 *    signed, unexpired token is accepted even if its jti isn't in the registry,
 *    and it is lazily re-registered on first use ("rehydrate"). Only an
 *    explicit revoke (tracked in `_revoked` until the token would have expired)
 *    rejects a token.
 *  - Revoked-jti entries are pruned once past their original exp.
 */
const _active = new Map();   // jti -> session record
const _revoked = new Map();  // jti -> exp (epoch seconds)

let _lastSweep = 0;
function sweep() {
  const now = Math.floor(Date.now() / 1000);
  if ((now - _lastSweep) < 60) return;
  _lastSweep = now;
  for (const [jti, s] of _active) if (s.exp && s.exp <= now) _active.delete(jti);
  for (const [jti, exp] of _revoked) if (exp <= now) _revoked.delete(jti);
}

// Record (or refresh) a session seen on a request. Safe to call repeatedly.
function register({ jti, userId, email, role, ip, ua, iat, exp }) {
  if (!jti) return;
  sweep();
  const now = Math.floor(Date.now() / 1000);
  const prev = _active.get(jti);
  _active.set(jti, {
    jti, userId, email, role,
    ip: ip || (prev && prev.ip) || null,
    ua: (ua || (prev && prev.ua) || '').slice(0, 200),
    iat: iat || (prev && prev.iat) || now,
    exp: exp || (prev && prev.exp) || (now + 43200),
    firstSeen: (prev && prev.firstSeen) || new Date().toISOString(),
    lastSeen: new Date().toISOString()
  });
}

function isRevoked(jti) { return !!jti && _revoked.has(jti); }

// Revoke a single session by jti. Returns the revoked record (or null).
function revoke(jti) {
  if (!jti) return null;
  const s = _active.get(jti);
  const exp = (s && s.exp) || (Math.floor(Date.now() / 1000) + 43200);
  _revoked.set(jti, exp);
  _active.delete(jti);
  return s || { jti };
}

// Revoke every active session for a user. Returns the count revoked.
function revokeAllForUser(userId) {
  let n = 0;
  for (const [jti, s] of _active) if (s.userId === userId) { revoke(jti); n++; }
  return n;
}

function listForUser(userId) {
  sweep();
  return [..._active.values()].filter(s => s.userId === userId)
    .sort((a, b) => (a.lastSeen < b.lastSeen ? 1 : -1));
}
function listAll() {
  sweep();
  return [..._active.values()].sort((a, b) => (a.lastSeen < b.lastSeen ? 1 : -1));
}
function stats() { sweep(); return { active: _active.size, revoked: _revoked.size }; }

module.exports = { register, isRevoked, revoke, revokeAllForUser, listForUser, listAll, stats };
