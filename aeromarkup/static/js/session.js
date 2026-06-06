/* AeroMarkup — session, role-based access control, and CUI classification.
   Local-first identity (no password here; auth is delegated to the host/SSO
   in a real gov deployment). Roles gate which lifecycle actions are allowed. */
import { getMeta, setMeta, uid } from "./store.js";

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
  "user.manage":      ["admin"],
};

let _user = null;

export async function loadSession() {
  _user = await getMeta("session", null);
  if (!_user) {
    _user = { id: uid(), username: "engineer", display_name: "Engineering User", role: "engineer" };
    await setMeta("session", _user);
  }
  return _user;
}

export function currentUser() { return _user; }

export async function setUser(patch) {
  _user = { ..._user, ...patch };
  await setMeta("session", _user);
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
