/* CITADEL — Scan history & suppressions (localStorage).
 * History: keep a rolling record of past scans to show trend & diff two runs.
 * Suppressions: mark findings as accepted risk so they drop out of the active view.
 * window.CITADEL.history / window.CITADEL.suppress
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const HKEY = 'citadel.history.v1';
  const SKEY = 'citadel.suppress.v1';
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
    const d = (k) => (a[k] || 0) - (b[k] || 0);
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

  /* ---------- Suppressions ---------- */
  function fingerprint(f) {
    return [f.ruleId || '', (f.file || '').replace(/^.*!\//, ''), f.line || 0, f.name || ''].join('|');
  }
  function suppressed() { return new Set(read(SKEY)); }
  function isSuppressed(f) { return suppressed().has(fingerprint(f)); }
  function suppress(f) { const s = read(SKEY); const fp = fingerprint(f); if (!s.includes(fp)) { s.push(fp); write(SKEY, s); } }
  function unsuppress(f) { write(SKEY, read(SKEY).filter(x => x !== fingerprint(f))); }
  function clearSuppress() { write(SKEY, []); }
  function suppressCount() { return read(SKEY).length; }

  CITADEL.history = { record, list, clear, compare };
  CITADEL.suppress = { fingerprint, isSuppressed, suppress, unsuppress, clear: clearSuppress, count: suppressCount, all: suppressed };
})(window);
