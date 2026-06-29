#!/usr/bin/env node
'use strict';
/*
 * CITADEL release-readiness gate — headless CLI.
 *
 * Runs the SAME in-browser analysis engine in Node (the engine modules are pure
 * and DOM-free) over a directory of source, computes the release-readiness gate,
 * prints a summary, and EXITS NON-ZERO when the gate fails — so the decision can
 * block a merge / deploy in CI instead of being advisory only.
 *
 * Usage:
 *   node citadel/cli/citadel-gate.js [dir] [--fail-on=<level>] [--json] [--quiet]
 *                                    [--max-files=N] [--policy=path.json]
 *
 *   dir            directory to scan (default: current directory)
 *   --fail-on      gate level that fails the build (exit 1) and worse:
 *                  approved | conditional | manual | rejected   (default: manual)
 *   --json         print the full readiness JSON to stdout
 *   --quiet        only print the decision line
 *   --max-files=N  cap files ingested (default 20000)
 *   --policy=FILE  JSON merged into CITADEL.readinessPolicy before scoring
 *
 * Exit codes: 0 = gate passed (below --fail-on), 1 = gate failed, 2 = error.
 */
const fs = require('fs');
const path = require('path');

const DECISIONS = ['Approved', 'Conditional Approval', 'Requires Manual Review', 'Rejected'];
const LEVEL_ALIAS = { approved: 0, conditional: 1, manual: 2, rejected: 3 };

function parseArgs(argv) {
  const opts = { dir: '.', failOn: 'manual', json: false, quiet: false, maxFiles: 20000, policy: null };
  for (const a of argv) {
    if (a === '--json') opts.json = true;
    else if (a === '--quiet') opts.quiet = true;
    else if (a.startsWith('--fail-on=')) opts.failOn = a.slice(10).toLowerCase();
    else if (a.startsWith('--max-files=')) opts.maxFiles = parseInt(a.slice(12), 10) || 20000;
    else if (a.startsWith('--policy=')) opts.policy = a.slice(9);
    else if (!a.startsWith('-')) opts.dir = a;
  }
  return opts;
}

// Load the engine: the worker's importScripts list is the single source of
// truth for module load order, so the CLI never drifts from the browser.
function loadEngine(jsDir) {
  const wk = fs.readFileSync(path.join(jsDir, 'worker.js'), 'utf8');
  const m = wk.match(/importScripts\(([\s\S]*?)\)/);
  const order = (m ? (m[1].match(/'([^']+\.js)'/g) || []) : []).map(s => s.replace(/'/g, ''));
  if (!order.length) throw new Error('could not parse the engine module list from worker.js');
  const win = {};
  win.window = win; win.self = win; win.CITADEL = {};
  for (const f of order) {
    const src = fs.readFileSync(path.join(jsDir, f), 'utf8');
    // eslint-disable-next-line no-new-func
    new Function('window', 'self', src)(win, win);
  }
  return win.CITADEL;
}

const SKIP_DIRS = new Set(['node_modules', '.git', '.hg', '.svn', '.tox', '.venv', 'venv', '__pycache__', '.cache']);
const MAX_TEXT = 2 * 1024 * 1024;     // text files larger than this carry bytes only
const MAX_FILE = 15 * 1024 * 1024;    // skip files larger than this entirely

function isBinaryBuf(buf) {
  const n = Math.min(buf.length, 8192);
  for (let i = 0; i < n; i++) if (buf[i] === 0) return true;
  return false;
}

function ingest(C, root, maxFiles) {
  const entries = [];
  const stack = ['.'];
  while (stack.length && entries.length < maxFiles) {
    const rel = stack.pop();
    const abs = path.join(root, rel);
    let st;
    try { st = fs.lstatSync(abs); } catch (e) { continue; }
    if (st.isSymbolicLink()) continue;
    if (st.isDirectory()) {
      let names = [];
      try { names = fs.readdirSync(abs); } catch (e) { continue; }
      for (const name of names) {
        if (SKIP_DIRS.has(name)) continue;
        stack.push(rel === '.' ? name : rel + '/' + name);
      }
      continue;
    }
    if (!st.isFile() || st.size > MAX_FILE) continue;
    let buf;
    try { buf = fs.readFileSync(abs); } catch (e) { continue; }
    const p = rel.replace(/\\/g, '/');
    const binary = isBinaryBuf(buf);
    const big = buf.length > MAX_TEXT;
    entries.push({
      path: p, size: buf.length, isBinary: binary,
      lang: (C.lang && C.lang.detect) ? C.lang.detect(p) : 'other',
      content: (binary || big) ? null : buf.toString('utf8'),
      bytes: (binary || big) ? new Uint8Array(buf) : null
    });
  }
  return entries;
}

function bar(label, val) {
  const v = Math.max(0, Math.min(100, val | 0));
  const filled = Math.round(v / 10);
  return label.padEnd(22) + '[' + '#'.repeat(filled) + '-'.repeat(10 - filled) + '] ' + String(v).padStart(3) + '/100';
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  const failRank = LEVEL_ALIAS[opts.failOn];
  if (failRank == null) { console.error('Invalid --fail-on: ' + opts.failOn + ' (approved|conditional|manual|rejected)'); process.exit(2); }
  const jsDir = path.resolve(__dirname, '..', 'js');
  const root = path.resolve(opts.dir);

  let C;
  try { C = loadEngine(jsDir); } catch (e) { console.error('Failed to load the CITADEL engine: ' + e.message); process.exit(2); }
  if (opts.policy) {
    try { C.readinessPolicy = JSON.parse(fs.readFileSync(opts.policy, 'utf8')); } catch (e) { console.error('Could not read --policy file: ' + e.message); process.exit(2); }
  }
  if (!fs.existsSync(root)) { console.error('No such directory: ' + root); process.exit(2); }

  const entries = ingest(C, root, opts.maxFiles);
  if (!entries.length) { console.error('No analyzable files found in ' + root); process.exit(2); }

  let report;
  try { report = await C.scanner.scan(entries); } catch (e) { console.error('Scan failed: ' + (e && e.message || e)); process.exit(2); }
  const rd = report.readiness || (C.readiness && C.readiness.analyze ? C.readiness.analyze(report) : null);
  if (!rd) { console.error('Readiness gate produced no result.'); process.exit(2); }

  if (opts.json) { process.stdout.write(JSON.stringify(rd, null, 2) + '\n'); }

  const decisionRank = Math.max(0, DECISIONS.indexOf(rd.decision));
  const failed = decisionRank >= failRank;

  if (!opts.quiet && !opts.json) {
    const sev = report.scoring && report.scoring.sev || {};
    console.log('');
    console.log('  CITADEL — Release Readiness Gate');
    console.log('  ' + '-'.repeat(46));
    console.log('  Scanned : ' + entries.length + ' files in ' + root);
    console.log('  Findings: ' + (report.findings || []).length + '  (critical ' + (sev.critical | 0) + ', high ' + (sev.high | 0) + ', medium ' + (sev.medium | 0) + ')');
    console.log('  ' + bar('Overall readiness', rd.overall));
    (rd.dimensions || []).filter(d => d.status !== 'pass').forEach(d => console.log('  ' + bar('  ' + d.label, d.score) + '  ' + d.status.toUpperCase()));
    if ((rd.blockers || []).length) {
      console.log('');
      console.log('  Blockers:');
      rd.blockers.forEach(b => console.log('   - ' + b));
    }
    console.log('');
  }
  if (!opts.json) console.log('  DECISION: ' + rd.decision + '  (' + rd.overall + '/100)' + (failed ? '  ✗ GATE FAILED' : '  ✓ gate passed'));

  process.exit(failed ? 1 : 0);
}

main().catch(e => { console.error('CITADEL gate error: ' + (e && e.message || e)); process.exit(2); });
