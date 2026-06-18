/* CITADEL — Scan history & suppressions (localStorage).
 * History: keep a rolling record of past scans to show trend & diff two runs.
 * Suppressions: mark findings as accepted risk so they drop out of the active view.
 * window.CITADEL.history / window.CITADEL.suppress
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const HKEY = 'citadel.history.v1';
  const MAX = 25;

  function read(key) { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { return []; } }
  function write(key, v) { try { localStorage.setItem(key, JSON.stringify(v)); } catch (e) {} }

  /* ---------- History ---------- */
  function record(report, label) {
    const list = read(HKEY);
    const entry = {
      id: 'h' + Date.now(),
      at: report.meta.scannedAt || new Date().toISOString(),
      label: label || ('Scan ' + new Date().toLocaleString()),
      engine: (report.meta && report.meta.engine) || 'quick',
      grade: report.scoring.grade,
      security: report.scoring.security,
      quality: report.scoring.quality,
      findings: report.findings.length,
      sev: report.scoring.sev,
      files: report.meta.fileCount,
      frameworks: report.posture.filter(p => p.findings > 0).length
    };
    list.unshift(entry);
    write(HKEY, list.slice(0, MAX));
    return entry;
  }
  function list() { return read(HKEY); }
  function clear() { write(HKEY, []); }
  function compare(aId, bId) {
    const l = read(HKEY);
    const a = l.find(x => x.id === aId), b = l.find(x => x.id === bId);
    if (!a || !b) return null;
    return {
      a, b,
      delta: {
        security: a.security - b.security, quality: a.quality - b.quality,
        findings: a.findings - b.findings,
        critical: d2(a, b, 'critical'), high: d2(a, b, 'high'), medium: d2(a, b, 'medium'), low: d2(a, b, 'low')
      }
    };
  }
  function d2(a, b, k) { return ((a.sev && a.sev[k]) || 0) - ((b.sev && b.sev[k]) || 0); }

  /* ---------- Finding disposition (triage state) ----------
   * Per-browser localStorage map { fingerprint: state }. States:
   *   open | accepted | false-positive | remediated | na (not applicable).
   * Keyed by the canonical, line-stable fingerprint so a disposition survives
   * edits and re-scans. The legacy `suppress` API is kept (hidden = non-open). */
  const DKEY = 'citadel.disposition.v1';
  const DISPOSITIONS = ['open', 'accepted', 'false-positive', 'remediated', 'na'];
  const DISPO_LABEL = { open: 'Open', accepted: 'Accepted risk', 'false-positive': 'False positive', remediated: 'Remediated', na: 'Not applicable' };
  function fpOf(f) {
    if (CITADEL.fingerprint && CITADEL.fingerprint.of) return CITADEL.fingerprint.of(f);
    return [f.ruleId || '', (f.file || '').replace(/^.*!\//, ''), f.name || ''].join('|');
  }
  function dmap() { try { return JSON.parse(localStorage.getItem(DKEY) || '{}'); } catch (e) { return {}; } }
  // When a backend is present, a shared server-side map (by fingerprint) is the
  // source of truth and wins over the per-browser localStorage map.
  let _server = null;   // { fingerprint: state } or null when not loaded/unavailable
  function effective() { return _server ? Object.assign({}, dmap(), _server) : dmap(); }
  function dispositionOf(f) { const k = fpOf(f); return (_server && _server[k]) || dmap()[k] || 'open'; }
  function setDisposition(f, state) {
    const k = fpOf(f);
    const m = dmap();
    if (!state || state === 'open') delete m[k]; else if (DISPOSITIONS.indexOf(state) >= 0) m[k] = state;
    try { localStorage.setItem(DKEY, JSON.stringify(m)); } catch (e) {}
    if (_server) {                                  // optimistic shared update + persist
      if (!state || state === 'open') delete _server[k]; else _server[k] = state;
      if (CITADEL.api && CITADEL.api.dispositionSet) CITADEL.api.dispositionSet(k, state || 'open');
    }
  }
  // Load the shared server map (called once a backend is detected).
  function syncServer(map) { _server = map || {}; }
  function serverShared() { return !!_server; }
  function dispositionCounts() {
    const m = effective(); const c = { open: 0, accepted: 0, 'false-positive': 0, remediated: 0, na: 0 };
    Object.keys(m).forEach(k => { if (c[m[k]] !== undefined) c[m[k]]++; });
    return c;
  }
  // Back-compat suppress API — "suppressed" = any non-open disposition.
  function isSuppressed(f) { return dispositionOf(f) !== 'open'; }
  function suppress(f) { setDisposition(f, 'accepted'); }
  function unsuppress(f) { setDisposition(f, 'open'); }
  function clearSuppress() { try { localStorage.removeItem(DKEY); } catch (e) {} }
  function suppressCount() { const m = effective(); return Object.keys(m).filter(k => m[k] && m[k] !== 'open').length; }
  function suppressed() { const m = effective(); return new Set(Object.keys(m).filter(k => m[k] && m[k] !== 'open')); }

  CITADEL.history = { record, list, clear, compare };
  CITADEL.suppress = { fingerprint: fpOf, isSuppressed, suppress, unsuppress, clear: clearSuppress, count: suppressCount, all: suppressed };
  CITADEL.disposition = { of: dispositionOf, set: setDisposition, counts: dispositionCounts, states: DISPOSITIONS, label: DISPO_LABEL, fpOf, syncServer, serverShared };
})(window);
