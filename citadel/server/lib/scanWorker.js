'use strict';
/* CITADEL — heuristic-scan worker.
 *
 * Runs the regex/taint SAST pass in an isolated worker thread so a pathological
 * input (catastrophic-backtracking "ReDoS" file) can be killed by terminating
 * the worker — a JS regex cannot otherwise be interrupted once running. The
 * parent (engine.runHeuristic) enforces the wall-clock timeout. This file only
 * does the CPU-bound heuristic work; real external scanners run in the parent.
 */
const { parentPort, workerData } = require('worker_threads');
const engine = require('./engine');

(async () => {
  try {
    const entries = engine.ingestDir(workerData.dir);
    const CITADEL = engine.loadEngine();
    const base = await CITADEL.scanner.scan(entries, () => {});
    const fileCount = entries.filter(e => !e.archive).length;
    const totalBytes = entries.reduce((a, e) => a + e.size, 0);
    parentPort.postMessage({ ok: true, base, fileCount, totalBytes });
  } catch (e) {
    parentPort.postMessage({ ok: false, error: e && e.message || String(e) });
  }
})();
