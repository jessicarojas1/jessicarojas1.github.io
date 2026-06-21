/* AeroMarkup — session, role-based access control, and CUI classification.
   When a backend is reachable, identity & role are authoritative from the
   server (signed HttpOnly session cookie); offline, a cached local identity
   keeps the PWA usable. The server independently enforces every capability —
   the client matrix below only decides which controls to show. */
import { getMeta, setMeta, uid } from "./store.js";
import { login as apiLogin, logout as apiLogout, fetchMe } from "./api.js";

export const ROLES = ["viewer", "engineer", "inspector", "approver", "admin"];

export const CLASSIFICATIONS = ["UNCLASSIFIED", "CUI", "CUI//SP-PROPIN", "UNCLASS//FOUO"];

/* capability matrix — action -> minimum roles permitted */
const CAP = {
  "drawing.edit":     ["engineer", "admin"],
  "drawing.submit":   ["engineer", "approver", "admin"],
  "drawing.approve":  ["approver", "admin"],
  "drawing.release":  ["approver", "admin"],
  "ncr.create":       ["engineer", "inspector", "admin"],
  "ncr.disposition":  ["approver", "admin"],
  "inspection.perform": ["inspector", "admin"],
  "project.manage":   ["engineer", "admin"],
  "comment.create":   ["engineer", "inspector", "approver", "admin"],
  "user.manage":      ["admin"],
};

let _user = null;
let _serverAuth = false;   // true when identity came from the backend session

export async function loadSession() {
  _user = await getMeta("session", null);
  if (!_user) {
    // Offline / no-backend fallback identity. The server is authoritative when
    // reachable; this only enables local-first work in air-gapped use.
    _user = { id: uid(), username: "engineer", display_name: "Engineering User", role: "engineer" };
    await setMeta("session", _user);
  }
  return _user;
}

export function currentUser() { return _user; }
export function isServerAuthenticated() { return _serverAuth; }

export async function setUser(patch) {
  _user = { ..._user, ...patch };
  await setMeta("session", _user);
  return _user;
}

/* Adopt a server-issued identity (from login or /api/auth/me). */
async function applyServerUser(u) {
  _serverAuth = true;
  _user = { id: u.uid, username: u.username, display_name: u.name || u.username, role: u.role };
  await setMeta("session", _user);
  window.dispatchEvent(new CustomEvent("am:session-changed"));
  return _user;
}

/* Pull the authenticated identity from the backend if a valid session exists. */
export async function hydrateSession() {
  const u = await fetchMe();
  if (u) return applyServerUser(u);
  _serverAuth = false;
  return null;
}

export async function login(username, password) {
  return applyServerUser(await apiLogin(username, password));
}

export async function logout() {
  await apiLogout();
  _serverAuth = false;
  _user = { id: uid(), username: "guest", display_name: "Guest", role: "viewer" };
  await setMeta("session", _user);
  window.dispatchEvent(new CustomEvent("am:session-changed"));
  return _user;
}

export function can(action) {
  if (!_user) return false;
  if (_user.role === "admin") return true;
  const allowed = CAP[action];
  return allowed ? allowed.includes(_user.role) : true;
}

export async function getClassification() {
  return await getMeta("classification", "CUI");
}
export async function setClassification(c) {
  return setMeta("classification", c);
}
