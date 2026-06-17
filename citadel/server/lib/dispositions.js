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

// Whole map { fingerprint: state } (open rows are never stored).
async function list() {
  if (!db.enabled()) return {};
  try {
    const r = await db.query('SELECT fingerprint, state FROM citadel_dispositions');
    const out = {};
    r.rows.forEach(x => { out[x.fingerprint] = x.state; });
    return out;
  } catch (e) { return {}; }
}

// Upsert (or delete when state === 'open'). Returns the stored state.
async function set(fingerprint, state, actor) {
  if (!db.enabled()) return null;
  fingerprint = String(fingerprint || '').slice(0, 64);
  if (!fingerprint || !valid(state)) throw new Error('Invalid disposition.');
  if (state === 'open') {
    await db.query('DELETE FROM citadel_dispositions WHERE fingerprint=$1', [fingerprint]);
  } else {
    await db.query(
      `INSERT INTO citadel_dispositions(fingerprint, state, actor, updated_at)
       VALUES($1,$2,$3,now())
       ON CONFLICT(fingerprint) DO UPDATE SET state=$2, actor=$3, updated_at=now()`,
      [fingerprint, state, (actor || '').slice(0, 200)]);
  }
  return state;
}

module.exports = { list, set, valid, STATES, enabled: () => db.enabled() };
