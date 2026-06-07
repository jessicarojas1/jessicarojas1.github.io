/* AeroMarkup — server bridge.
   The app is offline-first: IndexedDB is authoritative. These calls are
   best-effort. When the backend is reachable they persist to PostgreSQL and
   reconcile multi-device edits; offline, every call no-ops and data stays
   safely local. Nothing here ever blocks the UI. */
import { byIndex, getMeta, setMeta } from "./store.js";

let _online = navigator.onLine;
let _reachable = false;
const listeners = new Set();

export function onNetChange(fn) { listeners.add(fn); return () => listeners.delete(fn); }
function emit() { for (const fn of listeners) fn({ online: _online, reachable: _reachable }); }

window.addEventListener("online", () => { _online = true; emit(); checkReachable(); });
window.addEventListener("offline", () => { _online = false; _reachable = false; emit(); });

export function netState() { return { online: _online, reachable: _reachable }; }

export async function checkReachable() {
  if (!navigator.onLine) { _reachable = false; emit(); return false; }
  try {
    const r = await fetch("api/health", { cache: "no-store" });
    _reachable = r.ok;
  } catch { _reachable = false; }
  emit();
  return _reachable;
}

async function post(path, body) {
  const r = await fetch(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!r.ok) throw new Error(path + " -> " + r.status);
  return r.json();
}

/* ---- best-effort entity pushes (silently skipped when offline) ---- */
async function safePost(path, body) {
  if (!_reachable) return null;
  try { return await post(path, body); } catch { return null; }
}
export const pushNcr      = (n) => safePost("api/ncrs", n);
export const pushApproval = (a) => safePost("api/approvals", a);
export const pushAudit    = (a) => safePost("api/audit", a);
export const pushProject  = (p) => safePost("api/projects", p);

/* ---- drawing markup sync (strokes + annotations + drawing meta) ---- */
export async function syncDrawing(drawing) {
  if (!(await checkReachable())) {
    throw new Error("Server unreachable — your work is saved locally and will sync when you reconnect.");
  }
  const strokes = await byIndex("strokes", "drawing_id", drawing.id);
  const annotations = await byIndex("annotations", "drawing_id", drawing.id);
  const since = +(await getMeta("cursor_" + drawing.id, 0));
  const device = await deviceId();

  const data = await post("api/sync", {
    device_id: device,
    since,
    drawing: {
      client_uid: drawing.client_uid,
      project_id: drawing.project_id,
      title: drawing.title,
      background_kind: drawing.background_kind,
      background_data: drawing.background_data,
      width: drawing.width,
      height: drawing.height,
      view_kind: drawing.view_kind || "2d",
      model_format: drawing.model_format || null,
      model_name: drawing.model_name || null,
      model_data: drawing.model_data || null,
    },
    strokes: strokes.filter((s) => !s.kind),
    annotations: [...strokes.filter((s) => s.kind), ...annotations],
  });
  if (data.cursor != null) await setMeta("cursor_" + drawing.id, data.cursor);
  return data; // { changes: [...] } for the caller to merge
}

async function deviceId() {
  let d = await getMeta("device_id", null);
  if (!d) { d = crypto.randomUUID ? crypto.randomUUID() : "dev-" + Date.now(); await setMeta("device_id", d); }
  return d;
}
