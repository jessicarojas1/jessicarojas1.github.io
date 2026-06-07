/* AeroMarkup — immutable audit trail.
   Every consequential action is recorded locally (works offline) and pushed
   best-effort to the server. Records are append-only; the UI never edits them. */
import { put, all, uid } from "./store.js";
import { currentUser } from "./session.js";
import { pushAudit } from "./api.js";

export async function logAudit(action, entityType, entityId, detail = {}) {
  const u = currentUser();
  const rec = {
    id: uid(),
    client_uid: uid(),
    actor: u ? (u.display_name || u.username) : "system",
    actor_role: u ? u.role : null,
    action,
    entity_type: entityType,
    entity_id: entityId || null,
    detail,
    source: "web",
    ts: new Date().toISOString(),
  };
  await put("audit", rec);
  pushAudit(rec).catch(() => {}); // best-effort; offline-safe
  return rec;
}

export async function recentAudit(limit = 100) {
  const rows = await all("audit");
  return rows.sort((a, b) => (a.ts < b.ts ? 1 : -1)).slice(0, limit);
}
