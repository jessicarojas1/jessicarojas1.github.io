'use strict';
/* CITADEL — shared finding dispositions (Postgres).
 *
 * Triage state for a finding (accepted / false-positive / remediated / na),
 * keyed by the canonical line-stable fingerprint, shared across all users and
 * browsers. Active only when DATABASE_URL is set; with no DB the SPA keeps its
 * per-browser localStorage dispositions.
 */
const db = require('./db');

const STATES = ['open', 'accepted', 'false-positive', 'remediated', 'na'];
function valid(state) { return STATES.indexOf(state) >= 0; }

// Whole map { fingerprint: { state, note, actor, approver, acceptedUntil,
// updatedAt } }. A row persists whenever it carries a non-'open' state OR a
// reviewer note OR risk-acceptance metadata (open+bare rows are deleted, never
// stored).
async function list() {
  if (!db.enabled()) return {};
  try {
    const r = await db.query('SELECT fingerprint, state, note, actor, approver, accepted_until, updated_at FROM citadel_dispositions');
    const out = {};
    r.rows.forEach(x => {
      out[x.fingerprint] = {
        state: x.state,
        note: x.note || '',
        actor: x.actor || null,
        approver: x.approver || '',
        acceptedUntil: (x.accepted_until instanceof Date ? x.accepted_until.toISOString() : (x.accepted_until || '')),
        updatedAt: x.updated_at ? new Date(x.updated_at).toISOString() : null
      };
    });
    return out;
  } catch (e) { return {}; }
}

// Parse an ISO date string (or Date) to an ISO string for storage; a bad/blank
// value yields null and never throws.
function parseAcceptedUntil(v) {
  if (v == null || v === '') return null;
  const d = (v instanceof Date) ? v : new Date(String(v));
  return isNaN(d.getTime()) ? null : d.toISOString();
}

// Upsert (or delete on a fully-bare 'open' reset). A reviewer can attach a note
// and/or risk-acceptance metadata (approver + expiry) to a finding, which keeps
// the row. Returns the stored state, or null with no DB. `meta` is an optional
// { approver, acceptedUntil } object.
async function set(fingerprint, state, actor, note, meta) {
  if (!db.enabled()) return null;
  fingerprint = String(fingerprint || '').slice(0, 64);
  if (!fingerprint || !valid(state)) throw new Error('Invalid disposition.');
  note = note == null ? null : String(note).slice(0, 2000);
  meta = meta || {};
  const approver = meta.approver == null ? null : (String(meta.approver).slice(0, 200) || null);
  const acceptedUntil = parseAcceptedUntil(meta.acceptedUntil);
  if (state === 'open' && !note && !approver && !acceptedUntil) {
    await db.query('DELETE FROM citadel_dispositions WHERE fingerprint=$1', [fingerprint]);
  } else {
    await db.query(
      `INSERT INTO citadel_dispositions(fingerprint, state, actor, note, approver, accepted_until, updated_at)
       VALUES($1,$2,$3,$4,$5,$6,now())
       ON CONFLICT(fingerprint) DO UPDATE SET state=$2, actor=$3, note=$4, approver=$5, accepted_until=$6, updated_at=now()`,
      [fingerprint, state, (actor || '').slice(0, 200), note, approver, acceptedUntil]);
  }
  return state;
}

module.exports = { list, set, valid, STATES, enabled: () => db.enabled() };
