'use strict';
/* CITADEL backend — engine reuse.
 * Loads the SAME browser analysis modules server-side (no DOM/Chart needed),
 * walks an extracted directory into CITADEL "entries", runs the heuristic
 * engine, then merges real-scanner findings and recomputes scoring + posture.
 * One report shape, one renderer — the SPA renders deep scans unchanged.
 */
const fs = require('fs');
const path = require('path');
const os = require('os');
const vm = require('vm');

// Local dev: lib/ is citadel/server/lib, shared modules at citadel/js (../../js).
// Container: lib/ is /app/lib, SPA modules at /app/citadel/js. Honor CITADEL_APP_DIR too.
const APP_JS = process.env.CITADEL_APP_DIR
  ? path.join(process.env.CITADEL_APP_DIR, 'js')
  : (fs.existsSync(path.resolve(__dirname, '../../js/scanner.js'))
      ? path.resolve(__dirname, '../../js')
      : path.resolve(__dirname, '../citadel/js'));
// Mirror the SPA's load order (index.html): the full ruleset is rules.js plus
// the rules-extra / rules-mobile packs (which append to CITADEL.rules). Loading
// only rules.js made the server engine run ~57 of 226 rules — keep these in sync.
const MODULES = ['languages.js', 'frameworks.js', 'rules.js', 'rules-extra.js', 'rules-mobile.js', 'rules-pii.js', 'rules-iac.js', 'rules-api.js', 'rules-cicd.js', 'rules-java.js', 'secrets.js', 'sbom.js', 'binary.js', 'fingerprint.js', 'scanner.js'];

let _win = null;
function loadEngine() {
  if (_win) return _win.CITADEL;
  const sandbox = {
    window: {}, Math, Date, JSON, console, TextDecoder, RegExp, Object, Array, String,
    Number, Boolean, parseInt, parseFloat, isNaN, isFinite, Set, Map,
    Uint8Array, Uint32Array, encodeURIComponent, decodeURIComponent
  };
  sandbox.window = sandbox;            // modules attach to window.CITADEL
  vm.createContext(sandbox);
  for (const m of MODULES) {
    let code;
    try { code = fs.readFileSync(path.join(APP_JS, m), 'utf8'); }
    catch (e) { if (/^rules-(extra|mobile|pii|iac|api|cicd|java)\.js$/.test(m)) continue; throw e; }   // rule packs are optional
    vm.runInContext(code, sandbox, { filename: m });
  }
  _win = sandbox;
  return sandbox.CITADEL;
}

const SKIP = /(^|\/)(node_modules|\.git|vendor|dist|build|\.venv|venv|__pycache__|\.next|target|\.idea|\.vscode)(\/|$)/i;
const MAX_TEXT = 2 * 1024 * 1024;
// Cap the total bytes we hold in memory for one scan so a large repo can't OOM a
// small instance (e.g. a 512MB free tier). Past the budget, files are still
// listed/counted but their content is not loaded or scanned.
const MAX_TOTAL_BYTES = parseInt(process.env.CITADEL_MAX_TOTAL_BYTES || String(64 * 1024 * 1024), 10);

function walk(dir, base, out) {
  let items = [];
  try { items = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return out; }
  for (const it of items) {
    const full = path.join(dir, it.name);
    const rel = path.relative(base, full).split(path.sep).join('/');
    if (SKIP.test('/' + rel)) continue;
    if (it.isDirectory()) walk(full, base, out);
    else if (it.isFile()) out.push(full);
    if (out.length > 20000) break;
  }
  return out;
}

function isProbablyText(name, bytes, CITADEL) {
  const ext = name.split('.').pop().toLowerCase();
  if (CITADEL.lang.EXT[ext]) return true;
  if (!ext && /(^|\/)(dockerfile|makefile|gemfile|rakefile|jenkinsfile|license|readme)$/i.test(name)) return true;
  const n = Math.min(bytes.length, 512);
  let ctrl = 0;
  for (let i = 0; i < n; i++) { const c = bytes[i]; if (c === 0) return false; if (c < 9 || (c > 13 && c < 32)) ctrl++; }
  return ctrl / Math.max(1, n) < 0.1;
}

function ingestDir(dir) {
  const CITADEL = loadEngine();
  const files = walk(dir, dir, []);
  const entries = [];
  const dec = new TextDecoder('utf-8', { fatal: false });
  let loaded = 0;
  for (const full of files) {
    const rel = path.relative(dir, full).split(path.sep).join('/');
    let buf;
    try { buf = fs.readFileSync(full); } catch (e) { continue; }
    const bytes = new Uint8Array(buf);
    const text = isProbablyText(rel, bytes, CITADEL);
    const lang = CITADEL.lang.detect(rel);
    const entry = { path: rel, size: bytes.length, isBinary: !text, lang, content: null, bytes: null };
    // Load content/bytes only while under the per-scan memory budget; otherwise
    // keep the entry as metadata only so totals stay accurate without OOM risk.
    if (loaded < MAX_TOTAL_BYTES) {
      if (text && bytes.length <= MAX_TEXT) { entry.content = dec.decode(bytes); loaded += bytes.length; }
      else if (!text) { entry.bytes = bytes; loaded += bytes.length; }
    } else {
      entry.truncated = true;
    }
    entries.push(entry);
  }
  return entries;
}

function dedupe(findings) {
  const seen = new Set();
  const out = [];
  for (const f of findings) {
    const key = [f.source || 'heuristic', f.file || '', f.line || 0, f.ruleId || '', f.name || ''].join('|');
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(f);
  }
  return out;
}

// A safe, empty heuristic result used when the isolated pass times out or fails,
// so a deep scan still completes (with the external-scanner findings only).
function emptyBase() {
  return {
    findings: [], languages: { total: 0, languages: [], primary: 'Unknown' },
    sbom: { components: [], doc: null }, binaries: [],
    quality: { maintainability: 0, commentRatio: 0, loc: 0, codeLines: 0, totalFiles: 0 },
    deployment: [], licenses: []
  };
}

// In-process heuristic pass (default; used by CLI/benchmarks/tests).
async function runHeuristicInProcess(dir) {
  const CITADEL = loadEngine();
  const entries = ingestDir(dir);
  const base = await CITADEL.scanner.scan(entries, () => {});
  return { base, fileCount: entries.filter(e => !e.archive).length, totalBytes: entries.reduce((a, e) => a + e.size, 0) };
}

// Isolated heuristic pass: runs the regex/taint SAST in a worker thread and
// enforces a wall-clock deadline. Terminating the worker is the only reliable
// way to stop a catastrophic-backtracking (ReDoS) regex. On timeout/failure we
// degrade gracefully to scanner-only findings instead of hanging the request.
function runHeuristicIsolated(dir, timeoutMs) {
  return new Promise((resolve) => {
    const { Worker } = require('worker_threads');
    let done = false;
    const finish = (v) => { if (done) return; done = true; try { w.terminate(); } catch (e) {} clearTimeout(timer); resolve(v); };
    let w;
    const timer = setTimeout(() => finish({
      degraded: true, base: emptyBase(), fileCount: 0, totalBytes: 0,
      warning: 'heuristic scan exceeded ' + timeoutMs + 'ms and was terminated (possible ReDoS input); returned external-scanner findings only'
    }), timeoutMs);
    try {
      w = new Worker(path.join(__dirname, 'scanWorker.js'), { workerData: { dir } });
    } catch (e) {
      return finish({ degraded: true, base: emptyBase(), fileCount: 0, totalBytes: 0, warning: 'heuristic worker failed to start: ' + e.message });
    }
    w.on('message', (msg) => finish(msg && msg.ok
      ? { base: msg.base, fileCount: msg.fileCount, totalBytes: msg.totalBytes }
      : { degraded: true, base: emptyBase(), fileCount: 0, totalBytes: 0, warning: 'heuristic worker error: ' + (msg && msg.error) }));
    w.on('error', (err) => finish({ degraded: true, base: emptyBase(), fileCount: 0, totalBytes: 0, warning: 'heuristic worker crashed: ' + err.message }));
  });
}

/* Produce the unified report from an extracted directory.
 * opts.isolate (or env CITADEL_SCAN_ISOLATION=1) runs the heuristic pass in a
 * worker with a timeout (opts.timeoutMs or CITADEL_SCAN_TIMEOUT_MS, default 30s)
 * — recommended for the multi-tenant server where inputs are untrusted. */
async function analyzeDir(dir, scannerResult, onStage, opts) {
  opts = opts || {};
  const CITADEL = loadEngine();
  // Worker isolation loads a SECOND copy of the engine, so it roughly doubles
  // scan-time memory. On a small instance (free tier) that can OOM the process
  // and surface as a 502. Decide: explicit env wins; otherwise isolate only when
  // the host has enough RAM (CITADEL_ISOLATION_MIN_MEM_MB, default 900MB).
  const explicit = process.env.CITADEL_SCAN_ISOLATION;
  const lowMem = os.totalmem() < parseInt(process.env.CITADEL_ISOLATION_MIN_MEM_MB || '900', 10) * 1024 * 1024;
  let isolate;
  if (explicit === '1') isolate = true;
  else if (explicit === '0') isolate = false;
  else isolate = !!opts.isolate && !lowMem;
  const timeoutMs = opts.timeoutMs || parseInt(process.env.CITADEL_SCAN_TIMEOUT_MS || '30000', 10);
  onStage && onStage(isolate ? 'Running heuristic engine (isolated)…' : 'Running heuristic engine…');
  const hr = isolate ? await runHeuristicIsolated(dir, timeoutMs) : await runHeuristicInProcess(dir);
  const base = hr.base;
  if (hr.warning) { (scannerResult.warnings = scannerResult.warnings || []).push(hr.warning); onStage && onStage(hr.warning); }

  // tag heuristic findings with a source for the UI
  base.findings.forEach(f => { if (!f.source) f.source = 'heuristic'; });

  // Merge heuristic + real-scanner findings by stable fingerprint: the SAME issue
  // reported by more than one tool collapses to one finding with united sources,
  // worst severity, and a scanner-"confirmed" flag (falls back to the legacy
  // composite dedupe if the fingerprint module isn't loaded).
  const all = base.findings.concat(scannerResult.findings || []);
  const merged = (CITADEL.fingerprint && CITADEL.fingerprint.merge)
    ? CITADEL.fingerprint.merge(all) : dedupe(all);
  const scoring = CITADEL.scanner.score(merged, base.quality);
  const posture = CITADEL.frameworks.posture(merged);

  // Prefer Syft's SBOM when available; otherwise keep the manifest-derived one.
  let sbom = base.sbom;
  if (scannerResult.sbom && scannerResult.sbom.components && scannerResult.sbom.components.length) {
    const comps = scannerResult.sbom.components;
    sbom = { components: comps, doc: scannerResult.sbom.doc || CITADEL.sbom.cyclonedx(comps, 'analyzed-project') };
  }

  return {
    meta: {
      scannedAt: new Date().toISOString(),
      fileCount: hr.fileCount,
      totalBytes: hr.totalBytes,
      engine: 'deep',
      scanners: scannerResult.tools || [],
      scanSummary: scannerResult.summary || null,
      warnings: scannerResult.warnings || []
    },
    languages: base.languages,
    findings: merged,
    sbom,
    binaries: base.binaries,
    quality: base.quality,
    deployment: base.deployment,
    licenses: base.licenses,
    scoring,
    posture
  };
}

module.exports = { loadEngine, ingestDir, analyzeDir };
