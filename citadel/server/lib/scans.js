'use strict';
/* CITADEL — durable scan history (Postgres).
 *
 * Persists each completed scan's report so it can be listed and re-downloaded
 * at any time, from any browser. Active only when DATABASE_URL is set; with no
 * DB the SPA falls back to its per-browser localStorage history.
 *
 * Stores a summary (grade/score/counts/source) for the list view plus the full
 * report JSON for re-rendering/download. Pruned to the most recent
 * CITADEL_SCAN_HISTORY_MAX rows (default 200) to bound storage.
 */
const db = require('./db');
const KEEP = parseInt(process.env.CITADEL_SCAN_HISTORY_MAX || '200', 10);

function summary(r) {
  const sc = r.scoring || {}, sev = sc.sev || {}, meta = r.meta || {};
  return {
    grade: sc.grade || '?', security: sc.security | 0, quality: sc.quality | 0,
    findings: (r.findings || []).length, critical: sev.critical | 0, high: sev.high | 0,
    files: meta.fileCount | 0, engine: meta.engine || 'deep'
  };
}

async function record(report, { user, source } = {}) {
  if (!db.enabled() || !report) return null;
  const s = summary(report);
  try {
    const res = await db.query(
      `INSERT INTO citadel_scans
        (user_id,user_email,source,engine,grade,security,quality,findings,critical,high,files,report)
       VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) RETURNING id`,
      [user && user.id || null, user && user.email || null,
       (source || (report.meta && report.meta.source) || 'scan').slice(0, 300),
       s.engine, s.grade, s.security, s.quality, s.findings, s.critical, s.high, s.files,
       JSON.stringify(report)]);
    // Prune to the most recent KEEP rows.
    db.query(`DELETE FROM citadel_scans WHERE id NOT IN (SELECT id FROM citadel_scans ORDER BY id DESC LIMIT $1)`, [KEEP])
      .catch(() => {});
    return res.rows[0] && String(res.rows[0].id);
  } catch (e) {
    console.error(JSON.stringify({ level: 'error', src: 'scans', msg: 'record failed', err: e.message }));
    return null;
  }
}

// Summaries only (no heavy report blob), newest first.
async function list(limit = 100) {
  if (!db.enabled()) return [];
  const r = await db.query(
    `SELECT id,ts,user_email,source,engine,grade,security,quality,findings,critical,high,files
     FROM citadel_scans ORDER BY id DESC LIMIT $1`, [Math.min(Math.max(1, limit), 500)]);
  return r.rows.map(x => ({
    id: String(x.id), ts: (x.ts instanceof Date ? x.ts.toISOString() : x.ts),
    user: x.user_email, source: x.source, engine: x.engine, grade: x.grade,
    security: x.security, quality: x.quality, findings: x.findings, critical: x.critical, high: x.high, files: x.files
  }));
}

// The full report JSON for one scan (for re-render / download).
async function get(id) {
  if (!db.enabled()) return null;
  const r = await db.query('SELECT report FROM citadel_scans WHERE id=$1', [parseInt(id, 10) || -1]);
  return r.rows[0] ? r.rows[0].report : null;
}

async function remove(id) {
  if (!db.enabled()) return;
  await db.query('DELETE FROM citadel_scans WHERE id=$1', [parseInt(id, 10) || -1]);
}

module.exports = { record, list, get, remove, enabled: () => db.enabled() };
