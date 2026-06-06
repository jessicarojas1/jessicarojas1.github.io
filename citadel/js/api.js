/* CITADEL — Deep-scan API client.
 * When the SPA is served by the CITADEL backend (same origin), this enables
 * server-side scanning with real tools and JWT-based auth. On a static host
 * (e.g. GitHub Pages) the health probe fails and the app stays client-only.
 * window.CITADEL.api
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  let _status = null;
  const TKEY = 'citadel.jwt';

  function getToken() { try { return localStorage.getItem(TKEY) || null; } catch (e) { return null; } }
  function setToken(t) { try { t ? localStorage.setItem(TKEY, t) : localStorage.removeItem(TKEY); } catch (e) {} }
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

  // Throwing helper that preserves HTTP status for 401/403 handling.
  async function asJson(res, fallback) {
    if (!res.ok) {
      let msg = fallback + ' ' + res.status;
      try { const j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
      const err = new Error(msg); err.status = res.status; throw err;
    }
    return res.json();
  }

  // files: array of File. Appends each with its relative path so the server
  // can rebuild the directory tree (multer reads originalname).
  async function scan(files, onProgress) {
    const fd = new FormData();
    for (const file of files) fd.append('files', file, file.webkitRelativePath || file.name);
    onProgress && onProgress('Uploading to scan service…');
    const res = await fetch('api/scan', { method: 'POST', headers: authHeader(), body: fd });
    const out = await asJson(res, 'Scan service error');
    onProgress && onProgress('Rendering report…');
    return out;
  }

  async function scanUrl(url, onProgress) {
    onProgress && onProgress('Cloning & scanning repository…');
    const res = await fetch('api/scan-url', {
      method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, authHeader()), body: JSON.stringify({ url })
    });
    return asJson(res, 'Scan service error');
  }

  async function explain(finding) {
    const res = await fetch('api/explain', {
      method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, authHeader()), body: JSON.stringify({ finding })
    });
    return asJson(res, 'AI service error');
  }

  /* ---------- Auth ---------- */
  async function authLogin(email, password) {
    const res = await fetch('api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password })
    });
    if (!res.ok) return null;
    const j = await res.json();
    if (j && j.token) { setToken(j.token); return j.user; }
    return null;
  }
  async function authMe() {
    if (!getToken()) return null;
    try { const res = await fetch('api/auth/me', { headers: authHeader() }); if (!res.ok) { if (res.status === 401) setToken(null); return null; } return res.json(); }
    catch (e) { return null; }
  }
  function authLogout() { setToken(null); }

  CITADEL.api = { available, scan, scanUrl, explain, authLogin, authMe, authLogout, getToken };
})(window);
