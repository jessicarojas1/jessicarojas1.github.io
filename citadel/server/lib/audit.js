'use strict';
/* CITADEL — security audit log.
 *
 * Records security-relevant events (logins, lockouts, user/permission/settings
 * changes, session revocations, rate-limit blocks).
 *
 * Tamper-evidence (hash chain):
 *  - Each event carries hash = SHA-256(prevHash + canonical(event)). Because every
 *    link folds in the previous hash, altering or deleting ANY past record breaks
 *    the chain from that point forward — detectable by re-walking via verifyChain().
 *    The genesis link starts from a fixed all-zero hash. This makes the trail
 *    append-only/immutable in the evidentiary sense (you can't silently rewrite
 *    history) without requiring a WORM datastore; pair it with an external sink
 *    for off-box retention. Single-writer per process: the in-memory chain head is
 *    seeded from the last persisted hash on init() so it continues across restarts.
 *
 * Storage:
 *  - Always kept in a bounded in-memory ring for fast reads / no-DB hosting.
 *  - When DATABASE_URL is set, every event is also appended to Postgres
 *    (durable, retained, queried for the admin view).
 *  - When CITADEL_AUDIT_SINK_URL is set, every event is POSTed to that HTTP
 *    collector (Splunk HEC / generic SIEM webhook) for retention + alerting.
 */
const crypto = require('crypto');
const db = require('./db');

const CAP = parseInt(process.env.CITADEL_AUDIT_CAP || '500', 10);
const SINK_URL = process.env.CITADEL_AUDIT_SINK_URL || '';
const SINK_TOKEN = process.env.CITADEL_AUDIT_SINK_TOKEN || '';
const SERVICE = process.env.CITADEL_SERVICE_NAME || 'citadel';
const GENESIS = '0'.repeat(64);

const _events = [];
let _seq = 0;
let _prevHash = GENESIS;   // chain head: hash of the most recent recorded event

// Deterministic hash of an event linked to the previous hash. Only the immutable
// content fields are bound (NOT seq, which can differ between the in-memory ring
// and the DB's bigserial); ordering is enforced by the prev-hash linkage itself.
function hashEvent(prevHash, e) {
  const canon = JSON.stringify([prevHash, e.ts, e.type, e.actor ?? null, e.ip ?? null, e.detail ?? '', !!e.ok]);
  return crypto.createHash('sha256').update(canon).digest('hex');
}

function toSink(e) {
  if (!SINK_URL || typeof fetch !== 'function') return;
  const headers = { 'Content-Type': 'application/json' };
  if (SINK_TOKEN) headers.Authorization = 'Bearer ' + SINK_TOKEN;
  // Fire-and-forget; never let telemetry failures affect the request path.
  Promise.resolve().then(() => fetch(SINK_URL, {
    method: 'POST', headers,
    body: JSON.stringify({ source: SERVICE, sourcetype: 'citadel:audit', event: e })
  })).catch(() => {});
}

// type: dotted string e.g. 'login.success'. actor: email/id or null. ip: string.
function record(type, { actor = null, ip = null, detail = '', ok = true } = {}) {
  const e = { seq: ++_seq, ts: new Date().toISOString(), type, actor, ip, detail, ok };
  e.prevHash = _prevHash;
  e.hash = hashEvent(_prevHash, e);
  _prevHash = e.hash;
  _events.push(e);
  if (_events.length > CAP) _events.splice(0, _events.length - CAP);
  if (db.enabled()) {
    db.query('INSERT INTO citadel_audit(ts,type,actor,ip,detail,ok,prev_hash,hash) VALUES($1,$2,$3,$4,$5,$6,$7,$8)',
      [e.ts, e.type, e.actor, e.ip, e.detail, e.ok, e.prevHash, e.hash])
      .catch(err => console.error(JSON.stringify({ level: 'error', src: 'audit', msg: 'pg insert', err: err.message })));
  }
  toSink(e);
  return e;
}

// Seed the chain head from the last persisted hash so the chain is continuous
// across process restarts. Best-effort: a fresh/empty store keeps the genesis head.
async function init() {
  if (!db.enabled()) return;
  try {
    const r = await db.query('SELECT hash FROM citadel_audit WHERE hash IS NOT NULL ORDER BY seq DESC LIMIT 1');
    if (r.rows.length && r.rows[0].hash) _prevHash = r.rows[0].hash;
  } catch (e) { /* leave genesis head; columns may not exist yet on first migrate */ }
}

// Re-walk the chain oldest→newest and confirm every link: each row's prev_hash
// must equal the running head, and its hash must equal hashEvent(prev, row).
// Returns { ok, count, store, brokenAt, reason }. brokenAt is the seq of the
// first tampered/broken row (null when intact). DB rows predating the chain
// columns (NULL hash) are treated as a pre-chain prefix and skipped, not failed.
async function verifyChain() {
  let rows;
  let store = 'memory';
  if (db.enabled()) {
    try {
      const r = await db.query('SELECT seq, ts, type, actor, ip, detail, ok, prev_hash, hash FROM citadel_audit ORDER BY seq ASC');
      rows = r.rows.map(x => ({
        seq: Number(x.seq), ts: (x.ts instanceof Date ? x.ts.toISOString() : x.ts),
        type: x.type, actor: x.actor, ip: x.ip, detail: x.detail, ok: x.ok,
        prevHash: x.prev_hash, hash: x.hash
      }));
      store = 'postgres';
    } catch (e) { rows = null; }
  }
  if (!rows) { rows = _events.slice(); store = 'memory'; }

  let head = GENESIS;
  let count = 0;
  for (const e of rows) {
    if (!e.hash) continue;                 // pre-chain legacy row; skip without failing
    if (count === 0 && e.prevHash) head = e.prevHash; // anchor to first chained row's prev
    if (e.prevHash !== head) return { ok: false, count, store, brokenAt: e.seq, reason: 'broken link (prev_hash mismatch — a record was altered or removed)' };
    if (hashEvent(e.prevHash, e) !== e.hash) return { ok: false, count, store, brokenAt: e.seq, reason: 'content tampered (hash does not match record)' };
    head = e.hash;
    count++;
  }
  return { ok: true, count, store, brokenAt: null, reason: null };
}

// Most-recent-first, optionally filtered by a type prefix (e.g. 'login').
async function list(n = 200, prefix = null) {
  const lim = Math.max(1, Math.min(n, 1000));
  if (db.enabled()) {
    try {
      const where = prefix ? 'WHERE type = $2 OR type LIKE $3' : '';
      const params = prefix ? [lim, prefix, prefix + '.%'] : [lim];
      const r = await db.query(
        `SELECT seq, ts, type, actor, ip, detail, ok FROM citadel_audit ${where} ORDER BY seq DESC LIMIT $1`, params);
      return r.rows.map(x => ({ seq: Number(x.seq), ts: (x.ts instanceof Date ? x.ts.toISOString() : x.ts), type: x.type, actor: x.actor, ip: x.ip, detail: x.detail, ok: x.ok }));
    } catch (e) { /* fall back to memory */ }
  }
  let out = _events;
  if (prefix) out = out.filter(e => e.type === prefix || e.type.startsWith(prefix + '.'));
  return out.slice(-lim).reverse();
}

async function stats() {
  if (db.enabled()) {
    try {
      const tot = await db.query('SELECT count(*)::int AS n FROM citadel_audit');
      const by = await db.query('SELECT type, count(*)::int AS n FROM citadel_audit GROUP BY type');
      const byType = {}; for (const r of by.rows) byType[r.type] = r.n;
      return { total: tot.rows[0].n, capacity: null, byType, store: 'postgres' };
    } catch (e) { /* fall back */ }
  }
  const byType = {};
  for (const e of _events) byType[e.type] = (byType[e.type] || 0) + 1;
  return { total: _events.length, capacity: CAP, byType, store: 'memory' };
}

module.exports = { record, list, stats, init, verifyChain, hashEvent, sinkEnabled: () => !!SINK_URL };
