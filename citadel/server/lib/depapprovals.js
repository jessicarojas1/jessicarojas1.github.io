'use strict';
/* CITADEL — shared dependency-approval workflow (Postgres).
 *
 * Sign-off decision for a third-party package (approved / restricted /
 * prohibited / pending), keyed by `ecosystem|name` (lowercased), shared across
 * all users and browsers. Carries an optional justification, an approver, and
 * separate security / license approval flags. Active only when DATABASE_URL is
 * set; with no DB the SPA keeps its per-browser localStorage approvals.
 *
 * Justification text is never logged.
 */
const db = require('./db');

const STATUSES = ['approved', 'restricted', 'prohibited', 'pending'];
function valid(status) { return STATUSES.indexOf(status) >= 0; }

// Whole map { key: { status, justification, approver, securityApproved,
// licenseApproved, updatedAt } }. A row persists whenever it carries a decision
// (a non-default status, a justification, or an approval flag); a bare 'pending'
// reset with no justification and no approvals deletes the row.
async function list() {
  if (!db.enabled()) return {};
  try {
    const r = await db.query(
      'SELECT key, status, justification, approver, security_approved, license_approved, updated_at FROM citadel_dep_approvals');
    const out = {};
    r.rows.forEach(x => {
      out[x.key] = {
        status: x.status,
        justification: x.justification || '',
        approver: x.approver || '',
        securityApproved: !!x.security_approved,
        licenseApproved: !!x.license_approved,
        updatedAt: x.updated_at ? new Date(x.updated_at).toISOString() : null
      };
    });
    return out;
  } catch (e) { return {}; }
}

// Upsert (or delete when status === 'pending' and there is neither a
// justification nor any approval flag — a 'pending' reset). Returns the stored
// status, or null with no DB.
async function set(key, data, actor) {
  if (!db.enabled()) return null;
  key = String(key || '').slice(0, 200);
  data = data || {};
  const status = data.status;
  if (!key || !valid(status)) throw new Error('Invalid approval.');
  const justification = data.justification == null ? '' : String(data.justification).slice(0, 4000);
  const approver = String(data.approver || actor || '').slice(0, 200);
  const securityApproved = !!data.securityApproved;
  const licenseApproved = !!data.licenseApproved;
  if (status === 'pending' && !justification && !securityApproved && !licenseApproved) {
    await db.query('DELETE FROM citadel_dep_approvals WHERE key=$1', [key]);
  } else {
    await db.query(
      `INSERT INTO citadel_dep_approvals(key, status, justification, approver, security_approved, license_approved, updated_at)
       VALUES($1,$2,$3,$4,$5,$6,now())
       ON CONFLICT(key) DO UPDATE SET status=$2, justification=$3, approver=$4, security_approved=$5, license_approved=$6, updated_at=now()`,
      [key, status, justification, approver, securityApproved, licenseApproved]);
  }
  return status;
}

module.exports = { list, set, valid, STATUSES, enabled: () => db.enabled() };
