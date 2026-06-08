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
  const TKEY = 'citadel.jwt';        // access token
  const RKEY = 'citadel.refresh';    // refresh token

  function getToken() { try { return localStorage.getItem(TKEY) || null; } catch (e) { return null; } }
  function setToken(t) { try { t ? localStorage.setItem(TKEY, t) : localStorage.removeItem(TKEY); } catch (e) {} }
  function getRefresh() { try { return localStorage.getItem(RKEY) || null; } catch (e) { return null; } }
  function setRefresh(t) { try { t ? localStorage.setItem(RKEY, t) : localStorage.removeItem(RKEY); } catch (e) {} }
  function setTokens(j) { if (j && j.token) setToken(j.token); if (j && j.refreshToken) setRefresh(j.refreshToken); }
  function clearTokens() { setToken(null); setRefresh(null); }
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

  // Exchange the refresh token for a fresh access token. Returns true on success.
  async function refresh() {
    const r = getRefresh();
    if (!r) return false;
    try {
      const res = await fetch('api/auth/refresh', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ refreshToken: r })
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
    if (res.status === 401 && retry !== false && getRefresh()) {
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

  async function scan(files, onProgress) {
    const fd = new FormData();
    for (const file of files) fd.append('files', file, file.webkitRelativePath || file.name);
    onProgress && onProgress('Uploading to scan service…');
    const res = await apiFetch('api/scan', { method: 'POST', body: fd });
    const out = await asJson(res, 'Scan service error');
    onProgress && onProgress('Rendering report…');
    return out;
  }

  async function scanUrl(url, onProgress) {
    onProgress && onProgress('Cloning & scanning repository…');
    const res = await apiFetch('api/scan-url', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url })
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
  async function scansList(limit) {
    try {
      const res = await apiFetch('api/scans' + (limit ? '?limit=' + limit : ''));
      if (!res.ok) return { enabled: false, scans: [] };
      return res.json();
    } catch (e) { return { enabled: false, scans: [] }; }
  }
  async function scanGet(id) { const res = await apiFetch('api/scans/' + encodeURIComponent(id)); return asJson(res, 'Could not load scan'); }
  async function scanDelete(id) { const res = await apiFetch('api/scans/' + encodeURIComponent(id), { method: 'DELETE' }); return asJson(res, 'Could not delete scan'); }

  CITADEL.api = { available, scan, scanUrl, explain, authLogin, authMfaVerify, authMe, authLogout, refresh, scansList, scanGet, scanDelete, getToken, getRefresh };
})(window);
