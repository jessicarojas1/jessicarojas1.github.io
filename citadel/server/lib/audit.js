'use strict';
/* CITADEL — in-memory security audit log (no dependencies).
 *
 * A bounded ring buffer of security-relevant events (logins, lockouts, user &
 * permission changes, settings flips, rate-limit blocks). Admin-readable via
 * GET /api/audit. In-process only: it resets on deploy/restart, which is an
 * accepted limitation on the free tier (no persistent disk). For long-term
 * retention, ship these to an external log sink.
 */
const CAP = parseInt(process.env.CITADEL_AUDIT_CAP || '500', 10);
const _events = [];
let _seq = 0;

// type: dotted string e.g. 'login.success'. actor: email/id or null. ip: string.
// detail: short human string. ok: boolean outcome.
function record(type, { actor = null, ip = null, detail = '', ok = true } = {}) {
  const e = { seq: ++_seq, ts: new Date().toISOString(), type, actor, ip, detail, ok };
  _events.push(e);
  if (_events.length > CAP) _events.splice(0, _events.length - CAP);
  return e;
}

// Most-recent-first, optionally filtered by a type prefix (e.g. 'login').
function list(n = 200, prefix = null) {
  let out = _events;
  if (prefix) out = out.filter(e => e.type === prefix || e.type.startsWith(prefix + '.'));
  return out.slice(-Math.max(1, Math.min(n, CAP))).reverse();
}

function stats() {
  const by = {};
  for (const e of _events) by[e.type] = (by[e.type] || 0) + 1;
  return { total: _events.length, capacity: CAP, byType: by };
}

module.exports = { record, list, stats };
