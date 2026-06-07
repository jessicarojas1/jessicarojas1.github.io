'use strict';
/* CITADEL — accuracy benchmark.
 *
 * Runs the heuristic analysis engine over a labeled corpus (vuln/ + safe/) and
 * reports detection recall and false-positive rate, overall and per category.
 *
 * Methodology (file-level, the honest unit):
 *  - A vulnerable file is a TRUE POSITIVE if the engine reports a finding in it
 *    whose CWE matches the ground-truth label (strict). We also report a looser
 *    "any finding in the file" recall to separate detection from CWE-taxonomy.
 *  - A safe file that gets ANY finding is a FALSE POSITIVE (the code is clean) —
 *    the safe set includes decoys that superficially resemble the vulnerable
 *    ones (parameterized queries, escaped output, env-var secrets, identifiers
 *    containing "eval", vulnerable SQL inside a comment, ...).
 *  - precision = TP / (TP + FP), recall = TP / (TP + FN), F1 = harmonic mean.
 *
 * This is a CURATED micro-benchmark for fast regression signal, NOT a substitute
 * for OWASP Benchmark / NIST Juliet. Point CORPUS_DIR at a larger labeled suite
 * (same labels.json schema) for a fuller picture.
 *
 * Usage:  node benchmark/run.js            (report)
 *         FAIL_UNDER_RECALL=0.7 FAIL_UNDER_PRECISION=0.8 node benchmark/run.js  (CI gate)
 */
const fs = require('fs');
const path = require('path');
const engine = require(path.join(__dirname, '..', 'server', 'lib', 'engine'));

const CORPUS = process.env.CORPUS_DIR || path.join(__dirname, 'corpus');
const labels = JSON.parse(fs.readFileSync(path.join(CORPUS, 'labels.json'), 'utf8'));

function pct(n) { return (n * 100).toFixed(1) + '%'; }
function pad(s, n) { s = String(s); return s + ' '.repeat(Math.max(0, n - s.length)); }

(async function main() {
  const report = await engine.analyzeDir(CORPUS, { findings: [] });
  const byFile = new Map();
  for (const f of report.findings) {
    if (!byFile.has(f.file)) byFile.set(f.file, []);
    byFile.get(f.file).push({ cwe: f.cwe, category: f.category, ruleId: f.ruleId, severity: f.severity });
  }

  // ---- Recall over the vulnerable set ----
  const cats = {};
  let tpStrict = 0, tpLoose = 0;
  const misses = [];
  for (const v of labels.vulnerable) {
    const hits = byFile.get(v.file) || [];
    const strict = hits.some(h => h.cwe === v.cwe);
    const loose = hits.length > 0;
    cats[v.category] = cats[v.category] || { total: 0, hit: 0 };
    cats[v.category].total++; if (strict) cats[v.category].hit++;
    if (strict) tpStrict++; if (loose) tpLoose++;
    if (!strict) misses.push({ file: v.file, cwe: v.cwe, sawAnything: loose, saw: hits.map(h => h.ruleId + '/' + h.cwe).join(', ') });
  }

  // ---- False positives over the safe set ----
  let fpFiles = 0; const fps = [];
  for (const s of labels.safe) {
    const hits = byFile.get(s.file) || [];
    if (hits.length) { fpFiles++; fps.push({ file: s.file, fired: hits.map(h => h.ruleId).join(', ') }); }
  }

  const nVuln = labels.vulnerable.length, nSafe = labels.safe.length;
  const fn = nVuln - tpStrict;
  const recall = tpStrict / nVuln;
  const recallLoose = tpLoose / nVuln;
  const precision = (tpStrict + fpFiles) ? tpStrict / (tpStrict + fpFiles) : 0;
  const specificity = (nSafe - fpFiles) / nSafe;
  const f1 = (precision + recall) ? (2 * precision * recall) / (precision + recall) : 0;

  console.log('\n=== CITADEL accuracy benchmark (heuristic engine) ===');
  console.log('corpus: ' + nVuln + ' vulnerable, ' + nSafe + ' safe files\n');
  console.log('Per-category recall (strict CWE match):');
  for (const c of Object.keys(cats).sort()) console.log('  ' + pad(c, 18) + cats[c].hit + '/' + cats[c].total + '  (' + pct(cats[c].hit / cats[c].total) + ')');

  console.log('\nOverall:');
  console.log('  ' + pad('Recall (CWE-strict)', 26) + tpStrict + '/' + nVuln + '   ' + pct(recall));
  console.log('  ' + pad('Recall (any finding)', 26) + tpLoose + '/' + nVuln + '   ' + pct(recallLoose));
  console.log('  ' + pad('Precision (file-level)', 26) + pct(precision));
  console.log('  ' + pad('Specificity (clean=clean)', 26) + (nSafe - fpFiles) + '/' + nSafe + '   ' + pct(specificity));
  console.log('  ' + pad('F1', 26) + f1.toFixed(3));

  if (misses.length) {
    console.log('\nMissed (false negatives):');
    for (const m of misses) console.log('  - ' + pad(m.file, 26) + 'want ' + m.cwe + (m.sawAnything ? '  (saw: ' + m.saw + ')' : '  (no finding)'));
  }
  if (fps.length) {
    console.log('\nFalse positives on safe code:');
    for (const f of fps) console.log('  - ' + pad(f.file, 26) + 'fired: ' + f.fired);
  }

  const out = { ts: new Date().toISOString(), counts: { vulnerable: nVuln, safe: nSafe, tp: tpStrict, fn, fpFiles },
    metrics: { recall, recallLoose, precision, specificity, f1 }, perCategory: cats, misses, falsePositives: fps };
  fs.writeFileSync(path.join(__dirname, 'results.json'), JSON.stringify(out, null, 2));
  console.log('\nWrote ' + path.relative(process.cwd(), path.join(__dirname, 'results.json')));

  // Optional CI gate.
  const minR = parseFloat(process.env.FAIL_UNDER_RECALL || '0');
  const minP = parseFloat(process.env.FAIL_UNDER_PRECISION || '0');
  if (recall < minR || precision < minP) {
    console.error('\nFAIL: recall ' + pct(recall) + ' < ' + pct(minR) + ' or precision ' + pct(precision) + ' < ' + pct(minP));
    process.exit(1);
  }
})().catch(e => { console.error('benchmark error:', e.message); process.exit(2); });
