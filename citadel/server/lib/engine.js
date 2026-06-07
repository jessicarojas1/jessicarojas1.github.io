'use strict';
/* CITADEL backend — engine reuse.
 * Loads the SAME browser analysis modules server-side (no DOM/Chart needed),
 * walks an extracted directory into CITADEL "entries", runs the heuristic
 * engine, then merges real-scanner findings and recomputes scoring + posture.
 * One report shape, one renderer — the SPA renders deep scans unchanged.
 */
const fs = require('fs');
const path = require('path');
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
const MODULES = ['languages.js', 'frameworks.js', 'rules.js', 'rules-extra.js', 'rules-mobile.js', 'secrets.js', 'sbom.js', 'binary.js', 'scanner.js'];

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
    catch (e) { if (/^rules-(extra|mobile)\.js$/.test(m)) continue; throw e; }   // rule packs are optional
    vm.runInContext(code, sandbox, { filename: m });
  }
  _win = sandbox;
  return sandbox.CITADEL;
}

const SKIP = /(^|\/)(node_modules|\.git|vendor|dist|build|\.venv|venv|__pycache__|\.next|target|\.idea|\.vscode)(\/|$)/i;
const MAX_TEXT = 2 * 1024 * 1024;

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
  for (const full of files) {
    const rel = path.relative(dir, full).split(path.sep).join('/');
    let buf;
    try { buf = fs.readFileSync(full); } catch (e) { continue; }
    const bytes = new Uint8Array(buf);
    const text = isProbablyText(rel, bytes, CITADEL);
    const lang = CITADEL.lang.detect(rel);
    const entry = { path: rel, size: bytes.length, isBinary: !text, lang, content: null, bytes: null };
    if (text && bytes.length <= MAX_TEXT) entry.content = dec.decode(bytes);
    else entry.bytes = bytes;
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

/* Produce the unified report from an extracted directory. */
async function analyzeDir(dir, scannerResult, onStage) {
  const CITADEL = loadEngine();
  const entries = ingestDir(dir);
  onStage && onStage('Running heuristic engine…');
  const base = await CITADEL.scanner.scan(entries, () => {});

  // tag heuristic findings with a source for the UI
  base.findings.forEach(f => { if (!f.source) f.source = 'heuristic'; });

  const merged = dedupe(base.findings.concat(scannerResult.findings || []));
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
      fileCount: entries.filter(e => !e.archive).length,
      totalBytes: entries.reduce((a, e) => a + e.size, 0),
      engine: 'deep',
      scanners: scannerResult.tools || []
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

module.exports = { loadEngine, ingestDir, analyzeDir, dedupe };
