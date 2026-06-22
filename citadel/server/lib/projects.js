'use strict';
/* CITADEL — durable projects (Postgres).
 *
 * Named groupings a scan can be filed under. Active only when DATABASE_URL is
 * set; with no DB the SPA falls back to its per-browser localStorage projects.
 * Owner-scoped exactly like scan history: non-admins only see/touch their own
 * projects. Each project carries a derived scanCount (number of citadel_scans
 * rows referencing it by project_id).
 */
const db = require('./db');

// scope: { userId, isAdmin } — non-admins only see/touch their own projects.
function ownerClause(scope, n) {
  if (!scope || scope.isAdmin) return { where: '', params: [] };
  return { where: ' AND user_id = $' + n, params: [scope.userId || ' '] };
}

// Create a project. name is required; clamped to 200 chars, description to 2000.
// Returns { id, name, description, createdAt } (id as String).
async function create({ name, description } = {}, user) {
  if (!db.enabled()) return null;
  const clean = name ? String(name).trim().slice(0, 200) : '';
  if (!clean) throw new Error('Project name is required.');
  const desc = description ? String(description).trim().slice(0, 2000) : null;
  const r = await db.query(
    `INSERT INTO citadel_projects (user_id, name, description)
     VALUES ($1, $2, $3) RETURNING id, name, description, created_at`,
    [(user && user.id) || null, clean, desc]);
  const row = r.rows[0];
  return {
    id: String(row.id), name: row.name, description: row.description || '',
    createdAt: (row.created_at instanceof Date ? row.created_at.toISOString() : row.created_at)
  };
}

// All projects, newest first, with a derived scanCount. Owner-scoped.
async function list(scope) {
  if (!db.enabled()) return [];
  const oc = ownerClause(scope, 1);
  const r = await db.query(
    `SELECT p.id, p.name, p.description, p.created_at,
            (SELECT count(*) FROM citadel_scans s WHERE s.project_id = p.id::text) AS scan_count
     FROM citadel_projects p WHERE true${oc.where} ORDER BY p.id DESC`,
    oc.params);
  return r.rows.map(x => ({
    id: String(x.id), name: x.name, description: x.description || '',
    createdAt: (x.created_at instanceof Date ? x.created_at.toISOString() : x.created_at),
    scanCount: Number(x.scan_count) || 0
  }));
}

// One project (owner/admin only). Returns the same shape as list() rows or null.
async function get(id, scope) {
  if (!db.enabled()) return null;
  const oc = ownerClause(scope, 2);
  const r = await db.query(
    `SELECT p.id, p.name, p.description, p.created_at,
            (SELECT count(*) FROM citadel_scans s WHERE s.project_id = p.id::text) AS scan_count
     FROM citadel_projects p WHERE p.id = $1${oc.where}`,
    [parseInt(id, 10) || -1].concat(oc.params));
  const x = r.rows[0];
  if (!x) return null;
  return {
    id: String(x.id), name: x.name, description: x.description || '',
    createdAt: (x.created_at instanceof Date ? x.created_at.toISOString() : x.created_at),
    scanCount: Number(x.scan_count) || 0
  };
}

// Rename a project (owner/admin only). Returns true if a row changed.
async function rename(id, name, scope) {
  if (!db.enabled()) return false;
  const clean = name ? String(name).trim().slice(0, 200) : '';
  if (!clean) throw new Error('Project name is required.');
  const oc = ownerClause(scope, 3);
  const r = await db.query(`UPDATE citadel_projects SET name = $2 WHERE id = $1${oc.where}`,
    [parseInt(id, 10) || -1, clean].concat(oc.params));
  return r.rowCount > 0;
}

// Delete a project (owner/admin only). Detaches its scans (NULLs project_id)
// rather than orphan-deleting them. Returns true if a row changed.
async function remove(id, scope) {
  if (!db.enabled()) return false;
  const oc = ownerClause(scope, 2);
  await db.query(`UPDATE citadel_scans SET project_id = NULL WHERE project_id = $1`,
    [String(parseInt(id, 10) || -1)]);
  const r = await db.query(`DELETE FROM citadel_projects WHERE id = $1${oc.where}`,
    [parseInt(id, 10) || -1].concat(oc.params));
  return r.rowCount > 0;
}

module.exports = { list, get, create, rename, remove, enabled: () => db.enabled() };
