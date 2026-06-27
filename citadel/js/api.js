/* CITADEL — Deep-scan API client.
 * When the SPA is served by the CITADEL backend (same origin), this enables
 * server-side scanning with real tools and JWT-based auth (short-lived access
 * tokens + a refresh token, optional TOTP MFA). On a static host the health
 * probe fails and the app stays client-only. window.CITADEL.api
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  let _status = null;
  const TKEY = 'citadel.jwt';        // short-lived access token (Bearer)
  const SKEY = 'citadel.session';    // marker: an httpOnly refresh cookie likely exists
  // One-time migration: drop any refresh token left in localStorage by older
  // builds — the refresh token now lives only in an httpOnly cookie.
  try { localStorage.removeItem('citadel.refresh'); } catch (e) {}

  function getToken() { try { return localStorage.getItem(TKEY) || null; } catch (e) { return null; } }
  function setToken(t) { try { t ? localStorage.setItem(TKEY, t) : localStorage.removeItem(TKEY); } catch (e) {} }
  function hasSession() { try { return !!localStorage.getItem(SKEY); } catch (e) { return false; } }
  function setSession(on) { try { on ? localStorage.setItem(SKEY, '1') : localStorage.removeItem(SKEY); } catch (e) {} }
  // Back-compat shim: callers used getRefresh() to mean "can we refresh?".
  function getRefresh() { return hasSession() ? '1' : null; }
  function setTokens(j) { if (j && j.token) { setToken(j.token); setSession(true); } }
  function clearTokens() { setToken(null); setSession(false); }
  function authHeader() { const t = getToken(); return t ? { Authorization: 'Bearer ' + t } : {}; }

  async function available() {
    if (_status !== null) return _status;
    try {
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), 2500);
      const res = await fetch('api/health', { signal: ctrl.signal });
      clearTimeout(t);
      if (!res.ok) { _status = false; return false; }
      const j = await res.json();
      _status = j && j.ok ? j : false;
      return _status;
    } catch (e) { _status = false; return false; }
  }

  // Exchange the httpOnly refresh cookie for a fresh access token. The cookie is
  // sent automatically (same-origin); script never sees it. Returns true on success.
  async function refresh() {
    if (!hasSession()) return false;
    try {
      const res = await fetch('api/auth/refresh', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}'
      });
      if (!res.ok) { if (res.status === 401) clearTokens(); return false; }
      const j = await res.json();
      if (j && j.token) { setToken(j.token); return true; }
      return false;
    } catch (e) { return false; }
  }

  // fetch wrapper that transparently refreshes an expired access token once.
  async function apiFetch(url, opts, retry) {
    opts = opts || {};
    opts.headers = Object.assign({}, opts.headers || {}, authHeader());
    const res = await fetch(url, opts);
    if (res.status === 401 && retry !== false && hasSession()) {
      if (await refresh()) { return apiFetch(url, opts, false); }
    }
    return res;
  }

  // Throwing helper that preserves HTTP status for 401/403 handling.
  async function asJson(res, fallback) {
    if (!res.ok) {
      let msg = fallback + ' ' + res.status;
      try { const j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
      const err = new Error(msg); err.status = res.status; throw err;
    }
    return res.json();
  }

  async function scan(files, onProgress, opts) {
    opts = opts || {};
    const fd = new FormData();
    for (const file of files) fd.append('files', file, file.webkitRelativePath || file.name);
    if (opts.projectId) fd.append('projectId', opts.projectId);
    if (opts.projectName) fd.append('projectName', opts.projectName);
    onProgress && onProgress('Uploading to scan service…');
    const res = await apiFetch('api/scan', { method: 'POST', body: fd });
    const out = await asJson(res, 'Scan service error');
    onProgress && onProgress('Rendering report…');
    return out;
  }

  async function scanUrl(url, subpath, onProgress, opts) {
    // Back-compat: allow scanUrl(url, onProgress) with no subpath.
    if (typeof subpath === 'function') { opts = onProgress; onProgress = subpath; subpath = ''; }
    opts = opts || {};
    const body = { url, subpath: subpath || '' };
    if (opts.projectId) body.projectId = opts.projectId;
    if (opts.projectName) body.projectName = opts.projectName;
    onProgress && onProgress('Cloning & scanning repository…');
    const res = await apiFetch('api/scan-url', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    return asJson(res, 'Scan service error');
  }

  async function explain(finding) {
    const res = await apiFetch('api/explain', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ finding })
    });
    return asJson(res, 'AI service error');
  }

  /* ---------- Auth ---------- */
  // Returns { user } on success, { mfaRequired, mfaToken } when 2FA is needed,
  // or null on bad credentials.
  async function authLogin(email, password) {
    const res = await fetch('api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password })
    });
    if (!res.ok) return null;
    const j = await res.json();
    if (j && j.mfaRequired) return { mfaRequired: true, mfaToken: j.mfaToken };
    if (j && j.token) { setTokens(j); return { user: j.user }; }
    return null;
  }
  // Complete an MFA login. Returns the user on success, or null on a bad code.
  async function authMfaVerify(mfaToken, code) {
    const res = await fetch('api/auth/mfa/verify', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mfaToken, code })
    });
    if (!res.ok) return null;
    const j = await res.json();
    if (j && j.token) { setTokens(j); return j.user; }
    return null;
  }
  // Change the signed-in user's own password (used by the must-change step).
  // Returns { ok:true } on success or throws with the server error message.
  async function authChangePassword(current, next) {
    const res = await apiFetch('api/auth/password', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ current, next })
    });
    const j = await res.json().catch(() => null);
    if (!res.ok) throw new Error((j && j.error) || 'Could not change password.');
    return j || { ok: true };
  }
  async function authMe() {
    if (!getToken() && !getRefresh()) return null;
    try {
      const res = await apiFetch('api/auth/me');
      if (!res.ok) { if (res.status === 401) clearTokens(); return null; }
      return res.json();
    } catch (e) { return null; }
  }
  async function authLogout() {
    if (getToken()) { try { await apiFetch('api/auth/logout', { method: 'POST' }); } catch (e) {} }
    clearTokens();
  }

  /* ---------- Scan history (durable) ---------- */
  // Returns { enabled, scans:[summary...] } or { enabled:false } if unreachable.
  async function scansList(limit, projectId) {
    try {
      const params = [];
      if (limit) params.push('limit=' + encodeURIComponent(limit));
      if (projectId) params.push('projectId=' + encodeURIComponent(projectId));
      const res = await apiFetch('api/scans' + (params.length ? '?' + params.join('&') : ''));
      if (!res.ok) return { enabled: false, scans: [] };
      return res.json();
    } catch (e) { return { enabled: false, scans: [] }; }
  }
  async function scanGet(id) { const res = await apiFetch('api/scans/' + encodeURIComponent(id)); return asJson(res, 'Could not load scan'); }
  async function scanDelete(id) { const res = await apiFetch('api/scans/' + encodeURIComponent(id), { method: 'DELETE' }); return asJson(res, 'Could not delete scan'); }
  // Give a saved scan a name so it's findable by name in history.
  async function scanRename(id, name) {
    const res = await apiFetch('api/scans/' + encodeURIComponent(id), { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: name }) });
    return asJson(res, 'Could not rename scan');
  }

  /* ---------- Projects (durable scan groupings) ---------- */
  // Returns { enabled, projects:[...] } or { enabled:false, projects:[] } on any error (never throws).
  async function projectsList() {
    try {
      const res = await apiFetch('api/projects');
      if (!res.ok) return { enabled: false, projects: [] };
      return res.json();
    } catch (e) { return { enabled: false, projects: [] }; }
  }
  async function projectCreate(name, description) {
    const res = await apiFetch('api/projects', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: name, description: description }) });
    return asJson(res, 'Could not create project');
  }
  async function projectRename(id, name) {
    const res = await apiFetch('api/projects/' + encodeURIComponent(id), { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: name }) });
    return asJson(res, 'Could not rename project');
  }
  async function projectDelete(id) {
    const res = await apiFetch('api/projects/' + encodeURIComponent(id), { method: 'DELETE' });
    return asJson(res, 'Could not delete project');
  }

  /* ---------- Shared finding dispositions ---------- */
  // Returns the rich { fingerprint: { state, note, actor, updatedAt } } map, or
  // null when unavailable (no DB / not signed in).
  async function dispositionsList() {
    try { const res = await apiFetch('api/dispositions'); if (!res.ok) return null; return res.json(); } catch (e) { return null; }
  }
  // Persist a disposition (with an optional reviewer note); returns true on
  // success (false if not shared / denied).
  async function dispositionSet(fingerprint, state, note) {
    try {
      const res = await apiFetch('api/dispositions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ fingerprint, state, note: note || '' }) });
      return res.ok;
    } catch (e) { return false; }
  }

  /* ---------- Shared dependency-approval workflow ---------- */
  // Returns the rich { key: { status, justification, approver, securityApproved,
  // licenseApproved, updatedAt } } map, or null when unavailable (no DB / not
  // signed in).
  async function depApprovalsList() {
    try { const res = await apiFetch('api/dep-approvals'); if (!res.ok) return null; return res.json(); } catch (e) { return null; }
  }
  // Persist a dependency-approval decision (key = `ecosystem|name`); returns true
  // on success (false if not shared / denied).
  async function depApprovalSet(key, data) {
    try {
      const res = await apiFetch('api/dep-approvals', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ key: key }, data || {})) });
      return res.ok;
    } catch (e) { return false; }
  }

  /* ---------- Shared per-project threat-model overlay ---------- */
  // Returns { enabled, overlay } (overlay may be null), or null on any error
  // (no DB / not signed in / denied). Never throws.
  async function threatmodelGet(projectId) {
    try {
      const res = await apiFetch('api/threatmodel?projectId=' + encodeURIComponent(projectId || ''));
      if (!res.ok) return null;
      return res.json();
    } catch (e) { return null; }
  }
  // Persist the whole overlay for a project; returns true on success (false if
  // not shared / denied / on any error). Never throws.
  async function threatmodelSet(projectId, overlay) {
    try {
      const res = await apiFetch('api/threatmodel', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ projectId, overlay }) });
      return res.ok;
    } catch (e) { return false; }
  }

  CITADEL.api = { available, scan, scanUrl, explain, authLogin, authMfaVerify, authChangePassword, authMe, authLogout, refresh, scansList, scanGet, scanDelete, scanRename, projectsList, projectCreate, projectRename, projectDelete, dispositionsList, dispositionSet, depApprovalsList, depApprovalSet, threatmodelGet, threatmodelSet, getToken, getRefresh };
})(window);
