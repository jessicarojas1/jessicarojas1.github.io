'use strict';
/* CITADEL — server-side readiness gate.
 *
 * The release-readiness engine (js/review-readiness.js) is a pure, DOM-free
 * browser module. Deep scans run server-side and are persisted before the SPA
 * ever computes a gate decision, so this loads that same engine in Node (once)
 * and computes `report.readiness` at record time — giving DB-backed scan history
 * an authoritative gate decision (decision + dimension scores) without relying
 * on a client pass.
 *
 * Defensive: any load/parse failure leaves readiness null (history simply
 * stores no decision, exactly as before).
 */
const fs = require('fs');
const path = require('path');

let _analyze; // undefined = not yet loaded; false = unavailable; function = ready

function load() {
  if (_analyze !== undefined) return _analyze;
  try {
    const src = fs.readFileSync(path.join(__dirname, '..', '..', 'js', 'review-readiness.js'), 'utf8');
    const win = {};
    win.window = win; win.self = win; win.CITADEL = {};
    // The module guards its DOM/export wiring behind `typeof document` — which is
    // undefined here — so only the pure analyze() path is exercised.
    // eslint-disable-next-line no-new-func
    new Function('window', 'self', src)(win, win);
    _analyze = (win.CITADEL.readiness && win.CITADEL.readiness.analyze) || false;
  } catch (e) {
    _analyze = false;
  }
  return _analyze;
}

// Compute and return the readiness object for a report (or null on failure).
function analyze(report) {
  const fn = load();
  if (!fn || !report) return null;
  try { return fn(report); } catch (e) { return null; }
}

// Attach report.readiness in place when absent; returns the report.
function ensure(report) {
  if (report && !report.readiness) {
    const rd = analyze(report);
    if (rd) report.readiness = rd;
  }
  return report;
}

module.exports = { analyze, ensure, available: () => load() !== false };
