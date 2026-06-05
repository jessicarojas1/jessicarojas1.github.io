/* CITADEL — Deep-scan API client.
 * When the SPA is served by the CITADEL backend (same origin), this enables
 * server-side scanning with real tools. On a static host (e.g. GitHub Pages)
 * the health probe fails and the app silently stays in client-only mode.
 * window.CITADEL.api
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  let _status = null;

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

  // files: array of File. Appends each with its relative path so the server
  // can rebuild the directory tree (multer reads originalname).
  async function scan(files, onProgress) {
    const fd = new FormData();
    for (const file of files) {
      const rel = file.webkitRelativePath || file.name;
      fd.append('files', file, rel);
    }
    onProgress && onProgress('Uploading to scan service…');
    const res = await fetch('api/scan', { method: 'POST', body: fd });
    if (!res.ok) {
      let msg = 'Scan service error ' + res.status;
      try { const j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
      throw new Error(msg);
    }
    onProgress && onProgress('Rendering report…');
    return res.json();
  }

  async function scanUrl(url, onProgress) {
    onProgress && onProgress('Cloning & scanning repository…');
    const res = await fetch('api/scan-url', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url })
    });
    if (!res.ok) {
      let msg = 'Scan service error ' + res.status;
      try { const j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
      throw new Error(msg);
    }
    return res.json();
  }

  async function explain(finding) {
    const res = await fetch('api/explain', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ finding })
    });
    if (!res.ok) {
      let msg = 'AI service error ' + res.status;
      try { const j = await res.json(); if (j.error) msg = j.error; } catch (e) {}
      throw new Error(msg);
    }
    return res.json();
  }

  CITADEL.api = { available, scan, scanUrl, explain };
})(window);
