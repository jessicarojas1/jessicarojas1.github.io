/* AeroMarkup — server bridge.
   The app is offline-first: IndexedDB is authoritative. These calls are
   best-effort. When the backend is reachable they persist to PostgreSQL and
   reconcile multi-device edits; offline, every call no-ops and data stays
   safely local. Nothing here ever blocks the UI. */
import { byIndex, getMeta, setMeta, put } from "./store.js";

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

/* ---- auth state ---- */
let _authed = false;
let _me = null;
const authListeners = new Set();
export function onAuthChange(fn) { authListeners.add(fn); return () => authListeners.delete(fn); }
function emitAuth() { for (const fn of authListeners) fn({ authed: _authed, user: _me }); }
export function authState() { return { authed: _authed, user: _me }; }

export class AuthError extends Error {}

function readCookie(name) {
  const m = document.cookie.split("; ").find((r) => r.startsWith(name + "="));
  return m ? decodeURIComponent(m.slice(name.length + 1)) : null;
}

/* Core request helper: same-origin cookies, CSRF double-submit header on writes,
   and 401 handling that flips the app into "needs login" state. */
async function req(path, { method = "GET", body = null } = {}) {
  const headers = {};
  if (body != null) headers["Content-Type"] = "application/json";
  if (method !== "GET" && method !== "HEAD") {
    const csrf = readCookie("am_csrf");
    if (csrf) headers["X-CSRF-Token"] = csrf;
  }
  const r = await fetch(path, {
    method, headers, credentials: "same-origin", cache: "no-store",
    body: body != null ? JSON.stringify(body) : undefined,
  });
  if (r.status === 401) {
    if (_authed) { _authed = false; _me = null; emitAuth(); }
    throw new AuthError(path + " -> 401");
  }
  if (!r.ok) throw new Error(path + " -> " + r.status);
  return r.status === 204 ? null : r.json();
}

function post(path, body) { return req(path, { method: "POST", body }); }

/* ---- auth endpoints ---- */
export async function authStatus() {
  try { return await req("api/auth/status"); }
  catch { return { db: false, needs_bootstrap: false }; }
}
export async function fetchMe() {
  try { const d = await req("api/auth/me"); _authed = true; _me = d.user; emitAuth(); return d.user; }
  catch { _authed = false; _me = null; emitAuth(); return null; }
}
export async function login(username, password) {
  const d = await req("api/auth/login", { method: "POST", body: { username, password } });
  _authed = true; _me = d.user; emitAuth(); return d.user;
}
export async function bootstrap(payload) {
  const d = await req("api/auth/bootstrap", { method: "POST", body: payload });
  _authed = true; _me = d.user; emitAuth(); return d.user;
}
export async function logout() {
  try { await req("api/auth/logout", { method: "POST" }); } catch { /* ignore */ }
  _authed = false; _me = null; emitAuth();
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

  // Pull a 3D model / background uploaded on another device if we don't have it.
  const sd = data.drawing;
  if (sd) {
    let changed = false;
    if (sd.model_data && !drawing.model_data) {
      drawing.model_data = sd.model_data; drawing.model_format = sd.model_format;
      drawing.model_name = sd.model_name; drawing.view_kind = sd.view_kind || drawing.view_kind; changed = true;
    }
    if (sd.background_data && !drawing.background_data) {
      drawing.background_data = sd.background_data; drawing.background_kind = sd.background_kind || "image"; changed = true;
    }
    if (changed) { drawing.updated_at = new Date().toISOString(); await put("drawings", drawing); data.pulledModel = true; }
  }
  return data; // { changes: [...], drawing, pulledModel } for the caller to merge
}

async function deviceId() {
  let d = await getMeta("device_id", null);
  if (!d) { d = crypto.randomUUID ? crypto.randomUUID() : "dev-" + Date.now(); await setMeta("device_id", d); }
  return d;
}
