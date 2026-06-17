#!/usr/bin/env node
'use strict';
/* CITADEL CI — SARIF 2.1.0 export validation.
 * Loads the browser SARIF builder (+ fingerprint/remediate) the same way the CLI
 * does, generates SARIF from a synthetic report that exercises a deterministic
 * fix, and asserts the document is structurally valid 2.1.0 with the fields
 * downstream tools (GitHub code scanning) require. Exits non-zero on any failure.
 */
const fs = require('fs');
const path = require('path');

const JS = path.resolve(__dirname, '..', 'js');
global.window = global.window || global;
for (const m of ['fingerprint.js', 'remediate.js', 'sarif.js']) {
  // eslint-disable-next-line no-eval
  (0, eval)(fs.readFileSync(path.join(JS, m), 'utf8'));
}
const sarif = (global.CITADEL && global.CITADEL.sarif) || (global.window.CITADEL && global.window.CITADEL.sarif);

function fail(msg) { console.error('SARIF validation FAILED: ' + msg); process.exit(1); }
if (!sarif || typeof sarif.fromReport !== 'function') fail('sarif.fromReport not loaded');

// A synthetic report: one fixable finding (setSecure(false)) + one without a fix.
const report = {
  meta: { engine: 'deep', scannedAt: new Date().toISOString() },
  scoring: { grade: 'C', security: 70, quality: 80 },
  findings: [
    { ruleId: 'java-cookie-insecure', name: 'Cookie marked non-secure', category: 'session', severity: 'medium',
      cwe: 'CWE-614', file: 'src/A.java', line: 5, lineText: 'c.setSecure(false);', snippet: 'c.setSecure(false);', source: 'heuristic' },
    { ruleId: 'sql-concat', name: 'SQL built by concatenation', category: 'injection', severity: 'high',
      cwe: 'CWE-89', file: 'src/B.java', line: 12, snippet: 'q = "SELECT..." + id', source: 'semgrep' }
  ]
};

const log = sarif.fromReport(report);
const checks = [];
const ck = (cond, msg) => { checks.push(msg); if (!cond) fail(msg); };

ck(log && log.version === '2.1.0', 'version is 2.1.0');
ck(/sarif-2\.1\.0/.test(log.$schema || ''), '$schema references sarif-2.1.0');
ck(Array.isArray(log.runs) && log.runs.length === 1, 'exactly one run');
const run = log.runs[0];
ck(run.tool && run.tool.driver && run.tool.driver.name === 'CITADEL', 'driver name is CITADEL');
ck(Array.isArray(run.tool.driver.rules) && run.tool.driver.rules.length >= 1, 'rules array present');
ck(Array.isArray(run.results) && run.results.length === report.findings.length, 'one result per finding');
run.results.forEach((r, i) => {
  ck(typeof r.ruleId === 'string' && r.ruleId, 'result[' + i + '] has ruleId');
  ck(typeof r.ruleIndex === 'number' && run.tool.driver.rules[r.ruleIndex], 'result[' + i + '] ruleIndex resolves');
  ck(r.message && typeof r.message.text === 'string', 'result[' + i + '] has message.text');
  ck(['error', 'warning', 'note', 'none'].indexOf(r.level) >= 0, 'result[' + i + '] valid level');
  ck(r.partialFingerprints && typeof r.partialFingerprints.citadel === 'string', 'result[' + i + '] has partialFingerprints');
});
// The fixable finding must carry a valid SARIF fix with an artifactChange.
const fixed = run.results.find(r => r.ruleId === 'java-cookie-insecure');
ck(fixed && Array.isArray(fixed.fixes) && fixed.fixes[0]
  && fixed.fixes[0].artifactChanges[0].replacements[0].insertedContent.text.indexOf('setSecure(true)') >= 0,
  'deterministic fix emitted as a valid SARIF fixes[] object');

console.log('SARIF validation OK — ' + checks.length + ' checks passed (' + run.results.length + ' results).');
