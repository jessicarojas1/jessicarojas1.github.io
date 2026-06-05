'use strict';
/* CITADEL backend smoke test.
 * Exercises the engine-reuse + merge pipeline against a temp project WITHOUT
 * requiring the real scanners (they degrade to empty). Verifies a valid report
 * is produced, and that injected mock scanner findings merge & re-score.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert');

const engine = require('../lib/engine');
const scanners = require('../lib/scanners');
const N = require('../lib/normalize');

function tmpProject() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'citadel-smoke-'));
  fs.writeFileSync(path.join(dir, 'app.js'),
    'const exec=require("child_process").exec;\n' +
    'app.get("/x",(req,res)=>{ const q="SELECT * FROM u WHERE id="+req.query.id; db.query(q);' +
    ' exec("ping "+req.query.host); });\n' +
    'const AWS_KEY="AKIAIOSFODNN7EXAMPLE";\n');
  fs.writeFileSync(path.join(dir, 'package.json'),
    JSON.stringify({ name: 't', dependencies: { lodash: '*', express: '^4.18.0' } }));
  fs.writeFileSync(path.join(dir, 'Dockerfile'), 'FROM node:18\nCMD ["node","app.js"]\n');
  return dir;
}

(async () => {
  const dir = tmpProject();

  // 1) normalize unit checks
  assert.strictEqual(N.normSeverity('ERROR'), 'high');
  assert.strictEqual(N.normSeverity('CRITICAL'), 'critical');
  assert.strictEqual(N.categorize({ cwe: ['CWE-89'] }), 'injection');
  assert.strictEqual(N.categorize({ cwe: 79 }), 'xss');
  assert.strictEqual(N.categorize({ text: 'hardcoded password found' }), 'secrets');
  assert.strictEqual(N.firstCwe(['CWE-798']), 'CWE-798');
  console.log('✓ normalize helpers');

  // 2) real scanners (likely absent here) must degrade, never throw
  const sr = await scanners.runAll(dir);
  assert.ok(Array.isArray(sr.findings), 'scanner findings array');
  assert.ok(Array.isArray(sr.tools), 'tool status array');
  console.log('✓ scanners.runAll degraded gracefully — tools:',
    sr.tools.map(t => t.tool + '=' + (t.available ? 'on' : 'off')).join(' '));

  // 3) inject mock scanner findings to prove the merge + re-score path
  sr.findings.push(
    { ruleId: 'mock.sqli', source: 'semgrep', name: 'SQL injection', category: 'injection',
      severity: 'critical', cwe: 'CWE-89', confidence: 'high', file: 'app.js', line: 2,
      snippet: 'SELECT ...', remediation: 'Parameterize.' },
    { ruleId: 'CVE-2020-0001', source: 'grype', name: 'CVE in lodash', category: 'deps',
      severity: 'high', cwe: null, confidence: 'high', file: 'package.json', line: 0,
      snippet: 'lodash', remediation: 'Upgrade lodash.' }
  );

  // 4) full report
  const report = await engine.analyzeDir(dir, sr);
  assert.ok(report.meta && report.meta.engine === 'deep', 'engine=deep');
  assert.ok(report.findings.length >= 3, 'has findings (heuristic + mock)');
  assert.ok(report.scoring && typeof report.scoring.security === 'number', 'security score');
  assert.ok(['A', 'B', 'C', 'D', 'E', 'F'].includes(report.scoring.grade), 'grade letter');
  assert.ok(Array.isArray(report.posture) && report.posture.length > 0, 'posture');
  assert.ok(report.languages && report.languages.languages.length > 0, 'languages');
  const hasSemgrep = report.findings.some(f => f.source === 'semgrep');
  const hasHeur = report.findings.some(f => f.source === 'heuristic');
  assert.ok(hasSemgrep && hasHeur, 'merged both heuristic and scanner sources');
  const inj = report.posture.find(p => p.findings > 0);
  assert.ok(inj, 'at least one framework impacted');

  console.log('✓ analyzeDir report:',
    `files=${report.meta.fileCount} findings=${report.findings.length} grade=${report.scoring.grade} security=${report.scoring.security} frameworks_hit=${report.posture.filter(p => p.findings > 0).length}`);

  fs.rmSync(dir, { recursive: true, force: true });
  console.log('\nALL SMOKE TESTS PASSED');
})().catch(e => { console.error('SMOKE FAILED:', e); process.exit(1); });
