/* CITADEL — scan Web Worker.
 * Runs the CPU-heavy analysis pipeline off the main thread so the UI never
 * freezes on large repositories. Loads the same pure engine modules (no DOM).
 * Ingest happens on the main thread; this worker receives ready "entries".
 * The main thread falls back to inline scanning if the worker is unavailable.
 */
self.window = self;            // engine modules attach to window.CITADEL
try {
  // Must mirror index.html's rule-pack load order — otherwise the worker path
  // runs a fraction of the ruleset and quick-scans disagree with the inline /
  // server engines.
  importScripts(
    'languages.js', 'frameworks.js',
    'controls-federal.js', 'controls-appsec.js', 'controls-extra.js',
    'rules.js', 'rules-extra.js', 'rules-mobile.js', 'rules-pii.js',
    'rules-iac.js', 'rules-api.js', 'rules-cicd.js', 'rules-java.js',
    'secrets.js', 'sbom.js', 'binary.js', 'fingerprint.js', 'scanner.js'
  );
} catch (e) {
  self.postMessage({ type: 'fatal', message: 'worker import failed: ' + (e && e.message || e) });
}

self.onmessage = function (e) {
  var m = e.data || {};
  if (m.type !== 'scan') return;
  var C = self.CITADEL;
  if (!C || !C.scanner) { self.postMessage({ type: 'error', message: 'engine not loaded' }); return; }
  C.scanner.scan(m.entries, function (stage) { self.postMessage({ type: 'progress', stage: stage }); })
    .then(function (report) { self.postMessage({ type: 'done', report: report }); })
    .catch(function (err) { self.postMessage({ type: 'error', message: String(err && err.message || err) }); });
};
