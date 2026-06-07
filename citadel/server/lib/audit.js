'use strict';
/* CITADEL — security audit log.
 *
 * Records security-relevant events (logins, lockouts, user/permission/settings
 * changes, session revocations, rate-limit blocks).
 *
 * Storage:
 *  - Always kept in a bounded in-memory ring for fast reads / no-DB hosting.
 *  - When DATABASE_URL is set, every event is also appended to Postgres
 *    (durable, retained, queried for the admin view).
 *  - When CITADEL_AUDIT_SINK_URL is set, every event is POSTed to that HTTP
 *    collector (Splunk HEC / generic SIEM webhook) for retention + alerting.
 */
const db = require('./db');

const CAP = parseInt(process.env.CITADEL_AUDIT_CAP || '500', 10);
const SINK_URL = process.env.CITADEL_AUDIT_SINK_URL || '';
const SINK_TOKEN = process.env.CITADEL_AUDIT_SINK_TOKEN || '';
const SERVICE = process.env.CITADEL_SERVICE_NAME || 'citadel';

const _events = [];
let _seq = 0;

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
  _events.push(e);
  if (_events.length > CAP) _events.splice(0, _events.length - CAP);
  if (db.enabled()) {
    db.query('INSERT INTO citadel_audit(ts,type,actor,ip,detail,ok) VALUES($1,$2,$3,$4,$5,$6)',
      [e.ts, e.type, e.actor, e.ip, e.detail, e.ok])
      .catch(err => console.error(JSON.stringify({ level: 'error', src: 'audit', msg: 'pg insert', err: err.message })));
  }
  toSink(e);
  return e;
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

module.exports = { record, list, stats, sinkEnabled: () => !!SINK_URL };
