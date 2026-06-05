#!/usr/bin/env node
'use strict';
/* CITADEL CLI runner.
 * Scans a local path with the SAME real scanners + heuristic engine the server
 * uses, then emits a SARIF 2.1.0 report (for GitHub code scanning) or the full
 * CITADEL JSON report. Designed to gate CI: exits non-zero when findings reach a
 * configurable severity threshold.
 *
 * Usage:
 *   node cli.js <path> [--format sarif|json] [--output <file>] [--fail-on critical|high|medium|low|none]
 *
 * Defaults: --format sarif, output to stdout, --fail-on high.
 *
 * Exit codes:
 *   0  success, no findings at/above --fail-on (or --fail-on none)
 *   1  one or more findings at/above the --fail-on threshold
 *   2  usage / runtime error
 *
 * No npm dependencies beyond what citadel/server already installs — this file
 * uses only Node core plus the local lib/ modules.
 */
const fs = require('fs');
const path = require('path');

const scanners = require('./lib/scanners');
const engine = require('./lib/engine');

/* Severity ordering: index 0 is the most severe. "info" sits below "low". */
const SEV_ORDER = ['critical', 'high', 'medium', 'low', 'info'];

const USAGE = `CITADEL — source-code & executable security/compliance scanner (CLI)

Usage:
  node cli.js <path> [options]

Arguments:
  <path>                     Directory (or file) to scan.

Options:
  --format <sarif|json>      Output format. Default: sarif.
  --output <file>            Write report to <file>. Default: stdout.
  --fail-on <level>          Fail (exit 1) if any finding is at or above this
                             severity: critical | high | medium | low | none.
                             Default: high. "none" never fails the build.
  -h, --help                 Show this help and exit.

Examples:
  node cli.js .
  node cli.js ./src --format sarif --output citadel-results.sarif
  node cli.js . --format json --output report.json --fail-on critical
  node cli.js . --fail-on none        # report only, never fail the build

Exit codes:
  0  no findings at/above --fail-on
  1  findings at/above --fail-on (CI build should fail)
  2  usage or runtime error
`;

/* ---------------- argument parsing ---------------- */
function parseArgs(argv) {
  const opts = { path: null, format: 'sarif', output: null, failOn: 'high', help: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    switch (a) {
      case '-h':
      case '--help':
        opts.help = true;
        break;
      case '--format':
        opts.format = argv[++i];
        break;
      case '--output':
        opts.output = argv[++i];
        break;
      case '--fail-on':
        opts.failOn = argv[++i];
        break;
      default:
        if (a && a.startsWith('--') && a.includes('=')) {
          // support --key=value form too
          const [k, ...rest] = a.slice(2).split('=');
          const v = rest.join('=');
          if (k === 'format') opts.format = v;
          else if (k === 'output') opts.output = v;
          else if (k === 'fail-on') opts.failOn = v;
          else throw new Error('Unknown option: ' + a);
        } else if (a && a.startsWith('-')) {
          throw new Error('Unknown option: ' + a);
        } else if (opts.path === null) {
          opts.path = a;
        } else {
          throw new Error('Unexpected extra argument: ' + a);
        }
    }
  }
  return opts;
}

function validateOpts(opts) {
  if (!opts.path) throw new Error('Missing required <path> argument.');
  opts.format = String(opts.format || '').toLowerCase();
  if (opts.format !== 'sarif' && opts.format !== 'json') {
    throw new Error(`Invalid --format "${opts.format}" (expected sarif or json).`);
  }
  opts.failOn = String(opts.failOn || '').toLowerCase();
  if (opts.failOn !== 'none' && !SEV_ORDER.includes(opts.failOn)) {
    throw new Error(`Invalid --fail-on "${opts.failOn}" (expected critical|high|medium|low|none).`);
  }
  const abs = path.resolve(opts.path);
  if (!fs.existsSync(abs)) throw new Error('Path does not exist: ' + abs);
  opts.path = abs;
  return opts;
}

/* ---------------- SARIF loader (best-effort) ----------------
 * sarif.js is a browser/Node IIFE that attaches CITADEL.sarif to a global.
 * It expects a `window` and sets (global.window = global).CITADEL. We provide a
 * window shim, eval it, then read global.CITADEL.sarif.fromReport. If it cannot
 * be loaded (file missing or no fromReport), the caller falls back to JSON.
 */
function loadSarifBuilder() {
  // Local dev: server/cli.js -> ../js. Container: /app/cli.js -> /app/citadel/js.
  const candidates = [
    process.env.CITADEL_APP_DIR && path.join(process.env.CITADEL_APP_DIR, 'js', 'sarif.js'),
    path.resolve(__dirname, '..', 'js', 'sarif.js'),
    path.resolve(__dirname, 'citadel', 'js', 'sarif.js')
  ].filter(Boolean);
  const sarifPath = candidates.find(p => fs.existsSync(p));
  if (!sarifPath) return null;
  try {
    global.window = global.window || global;
    const code = fs.readFileSync(sarifPath, 'utf8');
    // eslint-disable-next-line no-eval
    (0, eval)(code);
    const sarif = (global.CITADEL && global.CITADEL.sarif) ||
                  (global.window.CITADEL && global.window.CITADEL.sarif);
    if (sarif && typeof sarif.fromReport === 'function') return sarif;
    return null;
  } catch (e) {
    process.stderr.write('[citadel] warning: failed to load SARIF builder: ' + e.message + '\n');
    return null;
  }
}

/* ---------------- summary (human-readable, to stderr) ---------------- */
function severityRank(sev) {
  const i = SEV_ORDER.indexOf(String(sev || '').toLowerCase());
  return i === -1 ? SEV_ORDER.length : i; // unknown sorts last
}

function countBySeverity(findings) {
  const counts = { critical: 0, high: 0, medium: 0, low: 0, info: 0 };
  for (const f of findings) {
    const s = String(f.severity || '').toLowerCase();
    if (counts[s] === undefined) counts[s] = 0;
    counts[s]++;
  }
  return counts;
}

function printSummary(report) {
  const w = (s) => process.stderr.write(s + '\n');
  const findings = report.findings || [];
  const sc = report.scoring || {};
  const counts = countBySeverity(findings);

  w('');
  w('  CITADEL scan summary');
  w('  ────────────────────');
  if (sc.grade !== undefined) w(`  Grade            : ${sc.grade}`);
  if (sc.security !== undefined) w(`  Security score   : ${sc.security}/100`);
  if (sc.overall !== undefined) w(`  Overall score    : ${sc.overall}/100`);
  w(`  Files scanned    : ${(report.meta && report.meta.fileCount) || 0}`);
  w(`  Total findings   : ${findings.length}`);
  w(`    critical : ${counts.critical}`);
  w(`    high     : ${counts.high}`);
  w(`    medium   : ${counts.medium}`);
  w(`    low      : ${counts.low}`);
  w(`    info     : ${counts.info}`);

  const tools = (report.meta && report.meta.scanners) || [];
  if (tools.length) {
    const on = tools.filter(t => t.available).map(t => t.tool);
    const off = tools.filter(t => !t.available).map(t => t.tool);
    w(`  Scanners active  : ${on.length ? on.join(', ') : '(none — install scanners or use the Docker image)'}`);
    if (off.length) w(`  Scanners missing : ${off.join(', ')}`);
  }

  const top = findings
    .slice()
    .sort((a, b) => severityRank(a.severity) - severityRank(b.severity))
    .slice(0, 5);
  if (top.length) {
    w('');
    w('  Top findings');
    w('  ────────────');
    top.forEach((f, i) => {
      const loc = f.file ? `${f.file}${f.line ? ':' + f.line : ''}` : '(no location)';
      w(`  ${i + 1}. [${String(f.severity || '?').toUpperCase()}] ${f.name || f.ruleId || 'finding'}`);
      w(`     ${loc}${f.cwe ? '  ' + f.cwe : ''}${f.source ? '  (' + f.source + ')' : ''}`);
    });
  }
  w('');
}

/* ---------------- exit-code decision ---------------- */
function shouldFail(findings, failOn) {
  if (failOn === 'none') return false;
  const threshold = SEV_ORDER.indexOf(failOn);
  return findings.some(f => severityRank(f.severity) <= threshold);
}

/* ---------------- output ---------------- */
function emit(text, outputFile) {
  if (outputFile) {
    fs.writeFileSync(outputFile, text);
    process.stderr.write(`[citadel] wrote ${Buffer.byteLength(text)} bytes to ${path.resolve(outputFile)}\n`);
  } else {
    process.stdout.write(text + (text.endsWith('\n') ? '' : '\n'));
  }
}

/* ---------------- main ---------------- */
async function main() {
  let opts;
  try {
    opts = parseArgs(process.argv.slice(2));
  } catch (e) {
    process.stderr.write('Error: ' + e.message + '\n\n' + USAGE);
    return 2;
  }

  if (opts.help || process.argv.length <= 2) {
    process.stdout.write(USAGE);
    return 0;
  }

  try {
    validateOpts(opts);
  } catch (e) {
    process.stderr.write('Error: ' + e.message + '\n\n' + USAGE);
    return 2;
  }

  const stage = (s) => process.stderr.write('[citadel] ' + s + '\n');

  stage(`Scanning ${opts.path} …`);
  const scannerResult = await scanners.runAll(opts.path, stage);
  const report = await engine.analyzeDir(opts.path, scannerResult, stage);

  // Human summary always goes to stderr so stdout stays machine-readable.
  printSummary(report);

  let outFormat = opts.format;
  let text;
  if (opts.format === 'sarif') {
    const sarif = loadSarifBuilder();
    if (sarif) {
      const doc = sarif.fromReport(report);
      text = JSON.stringify(doc, null, 2);
    } else {
      process.stderr.write('[citadel] warning: SARIF builder unavailable — falling back to JSON output.\n');
      outFormat = 'json';
      text = JSON.stringify(report, null, 2);
    }
  } else {
    text = JSON.stringify(report, null, 2);
  }

  // If we fell back to JSON but the user asked for a .sarif file, keep the
  // filename they chose; the warning above explains the format change.
  emit(text, opts.output);

  const fail = shouldFail(report.findings || [], opts.failOn);
  if (fail) {
    process.stderr.write(`[citadel] FAIL: findings at or above "${opts.failOn}" severity (format=${outFormat}).\n`);
    return 1;
  }
  process.stderr.write(`[citadel] OK: no findings at or above "${opts.failOn}" severity (format=${outFormat}).\n`);
  return 0;
}

main()
  .then(code => process.exit(code))
  .catch(err => {
    process.stderr.write('[citadel] fatal: ' + (err && err.stack ? err.stack : err) + '\n');
    process.exit(2);
  });
