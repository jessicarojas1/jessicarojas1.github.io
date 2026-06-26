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

// Whole map { fingerprint: { state, note, actor, updatedAt } }. A row persists
// whenever it carries a non-'open' state OR a reviewer note (open+no-note rows
// are deleted, never stored).
async function list() {
  if (!db.enabled()) return {};
  try {
    const r = await db.query('SELECT fingerprint, state, note, actor, updated_at FROM citadel_dispositions');
    const out = {};
    r.rows.forEach(x => {
      out[x.fingerprint] = {
        state: x.state,
        note: x.note || '',
        actor: x.actor || null,
        updatedAt: x.updated_at ? new Date(x.updated_at).toISOString() : null
      };
    });
    return out;
  } catch (e) { return {}; }
}

// Upsert (or delete when state === 'open' and there is no note). A reviewer can
// attach a note to a still-'open' finding, which keeps the row. Returns the
// stored state, or null with no DB.
async function set(fingerprint, state, actor, note) {
  if (!db.enabled()) return null;
  fingerprint = String(fingerprint || '').slice(0, 64);
  if (!fingerprint || !valid(state)) throw new Error('Invalid disposition.');
  note = note == null ? null : String(note).slice(0, 2000);
  if (state === 'open' && !note) {
    await db.query('DELETE FROM citadel_dispositions WHERE fingerprint=$1', [fingerprint]);
  } else {
    await db.query(
      `INSERT INTO citadel_dispositions(fingerprint, state, actor, note, updated_at)
       VALUES($1,$2,$3,$4,now())
       ON CONFLICT(fingerprint) DO UPDATE SET state=$2, actor=$3, note=$4, updated_at=now()`,
      [fingerprint, state, (actor || '').slice(0, 200), note]);
  }
  return state;
}

module.exports = { list, set, valid, STATES, enabled: () => db.enabled() };
