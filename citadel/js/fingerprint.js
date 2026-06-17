/* CITADEL — deterministic finding fingerprints (browser + Node).
 *
 * A stable identity for a finding that survives line drift elsewhere in the file
 * (it hashes normalized EVIDENCE, not the raw line number) so disposition state
 * and baseline diffs persist across edits, and the SAME issue reported by two
 * tools at the same place collapses to one fingerprint for merge/dedupe.
 *
 * Inputs (per the schema): category/ruleId, file path, CWE, and normalized
 * evidence (snippet or name). Pure JS (FNV-1a), so the identical value is
 * produced in the browser and in the Node engine. window.CITADEL.fingerprint
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  function relFile(f) {
    return String(f == null ? '' : f).replace(/^.*!\//, '').replace(/^[./]+/, '');
  }
  function normEvidence(s) {
    return String(s == null ? '' : s)
      .toLowerCase().replace(/['"`]/g, '').replace(/\s+/g, ' ').trim().slice(0, 200);
  }
  // FNV-1a 32-bit -> 8 hex chars; combine two passes for a 16-char id (low collision).
  function fnv(str) {
    let h = 0x811c9dc5;
    for (let i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0;
    }
    return ('00000000' + h.toString(16)).slice(-8);
  }
  /** Stable, line-drift-resistant fingerprint for a finding.
   *  @param {CitadelFinding} finding @returns {string} */
  function of(finding) {
    finding = finding || {};
    const key = [
      finding.category || finding.ruleId || 'finding',
      relFile(finding.file),
      finding.cwe || '',
      normEvidence(finding.snippet || finding.name || '')
    ].join('|');
    return fnv(key) + fnv('citadel|' + key);
  }

  // Real external scanners (vs. the heuristic engine). A finding from one of
  // these is data-flow / tool "confirmed"; a heuristic finding is "potential".
  const SCANNERS = ['semgrep', 'bandit', 'trivy', 'grype', 'gitleaks', 'clamav',
    'checkov', 'osv-scanner', 'codeql', 'hadolint'];
  // Classify a finding into a kind so the UI can separate confirmed vulns,
  // secrets, dependency CVEs, malware, license risks, PII and quality issues.
  function kindOf(f) {
    const cat = String(f.category || '').toLowerCase();
    const src = String(f.source || '').toLowerCase();
    if (cat === 'secrets') return 'secret';
    if (cat === 'malware' || src === 'clamav') return 'malware';
    if (cat === 'deps' || cat === 'supply-chain' || src === 'grype' || src === 'osv-scanner') return 'cve';
    if (cat === 'privacy' || cat === 'pii') return 'pii';
    if (cat === 'license' || /^license/.test(String(f.ruleId || ''))) return 'license';
    if (cat === 'quality' || cat === 'maintainability') return 'quality';
    if (cat === 'config' || cat === 'logging') return 'policy';
    return 'vuln';
  }
  // Annotate a finding in place with identity + classification fields.
  /** @param {CitadelFinding} f @returns {CitadelFinding} */
  function classify(f) {
    if (!f) return f;
    if (!f.fingerprint) f.fingerprint = of(f);
    const src = String(f.source || 'heuristic').toLowerCase();
    f.detection = SCANNERS.indexOf(src) >= 0 ? 'scanner' : 'heuristic';
    f.confirmed = f.detection === 'scanner';
    f.kind = f.kind || kindOf(f);
    if (!f.disposition) f.disposition = 'open';   // open | accepted | false-positive | remediated | n/a
    return f;
  }
  function tag(findings) { (findings || []).forEach(classify); return findings || []; }

  const SEV = { critical: 5, high: 4, medium: 3, low: 2, info: 1 };
  // Merge findings that share a fingerprint (the SAME issue reported by more than
  // one tool / the heuristic engine). Keeps the worst severity + best confidence,
  // unions the source scanners into `sources`, and prefers a representative that
  // already carries a snippet/remediation/fix.
  function merge(findings) {
    const by = new Map();
    for (const f of (findings || [])) {
      classify(f);
      const key = f.fingerprint;
      const prev = by.get(key);
      if (!prev) { f.sources = [f.source || 'heuristic']; by.set(key, f); continue; }
      if ((SEV[f.severity] || 0) > (SEV[prev.severity] || 0)) prev.severity = f.severity;
      if (f.confidence === 'high') prev.confidence = 'high';
      if (f.confirmed) { prev.confirmed = true; prev.detection = 'scanner'; }
      if (f.tainted) prev.tainted = true;
      if (!prev.remediation && f.remediation) prev.remediation = f.remediation;
      if (!prev.snippet && f.snippet) prev.snippet = f.snippet;
      const s = f.source || 'heuristic';
      if (prev.sources.indexOf(s) < 0) prev.sources.push(s);
    }
    return [...by.values()];
  }

  CITADEL.fingerprint = { of, tag, classify, kindOf, merge, relFile, normEvidence, SCANNERS };
  if (typeof module !== 'undefined' && module.exports) module.exports = CITADEL.fingerprint;
})(typeof window !== 'undefined' ? window : globalThis);
