'use strict';
/* CITADEL — shared per-project threat-model overlay (Postgres).
 *
 * A small JSON blob per project — { custom: [...], edits: {...}, hidden: [...] }
 * (reviewer additions / edits / deletions layered over the generated STRIDE
 * model) — persisted whole and keyed by project, shared across all users and
 * browsers. Active only when DATABASE_URL is set; with no DB the SPA keeps its
 * per-browser local overlay.
 */
const db = require('./db');

const MAX_BYTES = 200 * 1024;   // ~200 KB serialized cap on a stored overlay

// Returns the stored overlay object for a project, or null when not found /
// no DB / on any error. A blank project id resolves to null.
async function get(projectId) {
  if (!db.enabled()) return null;
  projectId = String(projectId == null ? '' : projectId).slice(0, 200);
  if (!projectId) return null;
  try {
    const r = await db.query('SELECT data FROM citadel_threatmodel WHERE project_id=$1', [projectId]);
    if (!r.rows.length) return null;
    return r.rows[0].data || null;
  } catch (e) { return null; }
}

// Upsert the whole overlay for a project. `projectId` is required; `data` must
// be a plain object and is rejected if its serialized size exceeds ~200 KB.
// Returns true on success, false with no DB.
async function set(projectId, data, actor) {
  if (!db.enabled()) return false;
  projectId = String(projectId == null ? '' : projectId).slice(0, 200);
  if (!projectId) throw new Error('Project id required.');
  if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Overlay must be an object.');
  const json = JSON.stringify(data);
  if (Buffer.byteLength(json, 'utf8') > MAX_BYTES) throw new Error('Threat model overlay too large.');
  await db.query(
    `INSERT INTO citadel_threatmodel(project_id, data, actor, updated_at)
     VALUES($1,$2::jsonb,$3,now())
     ON CONFLICT(project_id) DO UPDATE SET data=$2::jsonb, actor=$3, updated_at=now()`,
    [projectId, json, (actor || '').slice(0, 200)]);
  return true;
}

module.exports = { get, set, enabled: () => db.enabled() };
