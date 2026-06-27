/* CITADEL — Release Readiness & Security Gate (orchestrator + decision engine).
 *
 * Consumes an assembled scan `report` (SAST findings, dependency review, SBOM,
 * secrets, license, compliance posture) plus the four new reviewers
 * (logging, testing, threat model, architecture) and produces `report.readiness`:
 * per-dimension scores, an overall 0-100 score, and a security gate decision
 * (Approved / Conditional Approval / Requires Manual Review / Rejected) with
 * blockers, required/after remediation, risk-acceptance, and approver roles.
 *
 * The policy (weights, thresholds, severity penalties) is configurable by
 * setting `CITADEL.readinessPolicy` before a scan. Runs in the main thread and
 * the scan Web Worker (worker.js sets self.window = self); all DOM/export wiring
 * is guarded behind a `document` check — the worker only ever calls analyze().
 *
 *   CITADEL.readiness.analyze(report) -> report.readiness
 *   CITADEL.readiness.render(report)   // fills #tab-readiness (main thread)
 *   CITADEL.readiness.exportAs(format) // executive|developer|auditor|markdown|html|json|csv|pdf
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // ---- Default, overridable policy -----------------------------------------
  const DEFAULT_POLICY = {
    name: 'CITADEL default readiness policy v1',
    // Per-finding penalty by severity, scaled by a confidence factor.
    penalty: { critical: 40, high: 18, medium: 7, low: 2, info: 0 },
    confidenceFactor: { high: 1, medium: 0.7, low: 0.4 },
    // Dimension weights for the overall score (should sum to ~1).
    weights: {
      security: 0.15, dependency: 0.12, secrets: 0.12, configuration: 0.08,
      container: 0.06, auth: 0.12, api: 0.08, data: 0.08, logging: 0.06, operations: 0.05, cicd: 0.06, test: 0.06, compliance: 0.07
    },
    multipleHighThreshold: 3,   // >= this many High findings => at least Conditional
    warnAt: 80, failAt: 50      // dimension status thresholds
  };
  const DIMENSIONS = [
    ['security', 'Application Security'], ['dependency', 'Dependencies & CVEs'],
    ['secrets', 'Secrets'], ['configuration', 'Configuration'], ['container', 'Containers'],
    ['auth', 'Auth & Access'],
    ['api', 'API Security'], ['data', 'Data Protection'], ['logging', 'Logging & Audit'],
    ['operations', 'Operational Readiness'],
    ['cicd', 'CI/CD Pipeline'], ['test', 'Test Readiness'], ['compliance', 'Compliance']
  ];
  const DECISIONS = ['Approved', 'Conditional Approval', 'Requires Manual Review', 'Rejected'];

  function policy() {
    const p = CITADEL.readinessPolicy || {};
    return {
      name: p.name || DEFAULT_POLICY.name,
      penalty: Object.assign({}, DEFAULT_POLICY.penalty, p.penalty),
      confidenceFactor: Object.assign({}, DEFAULT_POLICY.confidenceFactor, p.confidenceFactor),
      weights: Object.assign({}, DEFAULT_POLICY.weights, p.weights),
      multipleHighThreshold: p.multipleHighThreshold || DEFAULT_POLICY.multipleHighThreshold,
      warnAt: p.warnAt || DEFAULT_POLICY.warnAt, failAt: p.failAt || DEFAULT_POLICY.failAt
    };
  }
  function sev(f) { return String(f && f.severity || 'info').toLowerCase(); }
  function conf(f) { return String(f && f.confidence || 'medium').toLowerCase(); }
  function clampInt(n) { return Math.max(0, Math.min(100, Math.round(n || 0))); }

  // Assign a finding to exactly ONE readiness dimension (highest priority wins),
  // so a count isn't double-attributed across dimensions.
  function dimensionOf(f) {
    const cat = String(f && f.category || '').toLowerCase();
    const mod = String(f && f.module || '').toLowerCase();
    const rid = String(f && f.ruleId || '').toLowerCase();
    const file = String(f && f.file || '').toLowerCase();
    if (mod === 'logging') return 'logging';
    if (mod === 'testing') return 'test';
    if (mod === 'container') return 'container';
    if (mod === 'operations') return 'operations';
    if (cat === 'secrets' || /secret|credential|api[-_]?key|token|password/.test(rid)) return 'secrets';
    if (cat === 'authn' || cat === 'authz' || /auth|rbac|jwt|session|login/.test(rid)) return 'auth';
    if (/(^|[-_/])api([-_/]|$)/.test(rid) || (/route|controller|endpoint|graphql|swagger|openapi/.test(file) && cat !== 'privacy')) return 'api';
    if (cat === 'privacy') return 'data';
    if (cat === 'supply-chain') return 'dependency';
    if (/cicd|pipeline|workflow|jenkins|gitlab-ci|azure-pipelines/.test(rid) || /\.github\/workflows|\.gitlab-ci|jenkinsfile|azure-pipelines/.test(file)) return 'cicd';
    if (cat === 'config') return 'configuration';
    // injection, crypto, transport, random, architecture, misc -> core security
    return 'security';
  }

  // Score one penalty-based dimension from its findings.
  function scoreFromFindings(items, pol) {
    let score = 100;
    for (const f of items) score -= (pol.penalty[sev(f)] || 0) * (pol.confidenceFactor[conf(f)] || 0.7);
    return clampInt(score);
  }
  function statusOf(score, pol) { return score >= pol.warnAt ? 'pass' : score >= pol.failAt ? 'warn' : 'fail'; }

  // A finding's triage disposition (live shared/local store when present, else
  // the finding's own field). Drives which findings the gate still counts.
  function dispoOf(f) {
    try { if (CITADEL.disposition && CITADEL.disposition.of) return CITADEL.disposition.of(f); } catch (e) {}
    return (f && f.disposition) || 'open';
  }
  function noteOf(f) {
    try { if (CITADEL.disposition && CITADEL.disposition.note) return CITADEL.disposition.note(f) || ''; } catch (e) {}
    return '';
  }

  function analyze(report) {
    report = report || {};
    const pol = policy();
    const allFindings = Array.isArray(report.findings) ? report.findings : [];
    const dep = report.depreview || {};
    const rv = report.reviews || {};

    // Partition by triage disposition: 'open' findings still count toward the
    // gate; 'accepted' become risk-acceptance items; false-positive/remediated/
    // na are excluded entirely.
    const findings = [], accepted = [];
    for (const f of allFindings) {
      const d = dispoOf(f);
      if (d === 'accepted') accepted.push(f);
      else if (d === 'open') findings.push(f);
    }
    const acceptedRisks = accepted.map(f => ({
      title: f.name || f.ruleId || 'Finding', severity: sev(f), module: f.module || f.category || '',
      file: f.file || '', note: noteOf(f)
    }));

    // Bucket the active findings by dimension.
    const byDim = {}; DIMENSIONS.forEach(d => { byDim[d[0]] = []; });
    for (const f of findings) { const k = dimensionOf(f); (byDim[k] || byDim.security).push(f); }

    // CVEs from the dependency review fold into the dependency dimension count.
    const cve = (dep.security && dep.security.cve) || { critical: 0, high: 0, medium: 0, low: 0 };

    const dimensions = DIMENSIONS.map(([key, label]) => {
      const items = byDim[key] || [];
      let score;
      if (key === 'logging') score = clampInt(rv.logging && rv.logging.summary ? rv.logging.summary.score : scoreFromFindings(items, pol));
      else if (key === 'operations') score = clampInt(rv.operations && rv.operations.summary ? rv.operations.summary.score : scoreFromFindings(items, pol));
      else if (key === 'test') score = clampInt(rv.testing && rv.testing.summary ? rv.testing.summary.score : scoreFromFindings(items, pol));
      else if (key === 'compliance') score = complianceScore(report, items, pol);
      else if (key === 'dependency') score = clampInt(scoreFromFindings(items, pol) - (cve.critical * 20 + cve.high * 8));
      else score = scoreFromFindings(items, pol);

      const critical = items.filter(f => sev(f) === 'critical').length + (key === 'dependency' ? cve.critical : 0);
      const high = items.filter(f => sev(f) === 'high').length + (key === 'dependency' ? cve.high : 0);
      return {
        key, label, score, weight: pol.weights[key] || 0,
        findings: items.length + (key === 'dependency' ? (cve.critical + cve.high + cve.medium + cve.low) : 0),
        critical, high, status: statusOf(score, pol), notes: noteFor(key, report, rv, dep)
      };
    });

    // Weighted overall.
    let wsum = 0, acc = 0;
    dimensions.forEach(d => { wsum += d.weight; acc += d.score * d.weight; });
    const overall = clampInt(wsum > 0 ? acc / wsum : 0);

    const gate = decide(report, dimensions, dep, pol, findings);
    const acceptedSerious = accepted.some(f => ['critical', 'high'].includes(sev(f)));
    if (acceptedSerious && !gate.rationale.some(r => /risk-accepted/i.test(r))) {
      gate.rationale.push(acceptedRisks.length + ' finding(s) risk-accepted by a reviewer — see accepted risks.');
    }

    return {
      generatedAt: new Date().toISOString(), policyName: pol.name,
      dimensions, overall,
      decision: gate.decision, rationale: gate.rationale, blockers: gate.blockers,
      requiredRemediation: gate.required, afterRemediation: gate.after,
      riskAcceptanceRequired: gate.riskAcceptanceRequired || acceptedSerious,
      approverRoles: gate.approverRoles, acceptedRisks: acceptedRisks
    };
  }

  // Compliance dimension from the framework posture pass-rate when available.
  function complianceScore(report, items, pol) {
    const posture = Array.isArray(report.posture) ? report.posture : [];
    if (posture.length) {
      let passed = 0, total = 0;
      posture.forEach(p => {
        if (typeof p.controlCount === 'number') {
          total += p.controlCount;
          const fails = (p.findings | 0);
          passed += Math.max(0, p.controlCount - fails);
        } else { total += 1; if ((p.status || '').toLowerCase() === 'pass') passed += 1; }
      });
      if (total > 0) return clampInt((passed / total) * 100);
    }
    return scoreFromFindings(items, pol);
  }

  function noteFor(key, report, rv, dep) {
    try {
      if (key === 'dependency') { const n = (report.sbom && report.sbom.components || []).length; return n + ' component(s) inventoried'; }
      if (key === 'secrets') { const n = (report.findings || []).filter(f => dimensionOf(f) === 'secrets').length; return n ? n + ' secret finding(s)' : 'No hardcoded secrets detected'; }
      if (key === 'logging') return rv.logging && rv.logging.summary ? ((rv.logging.summary.eventsCovered || []).length + ' security event(s) logged') : 'Not assessed';
      if (key === 'test') return rv.testing && rv.testing.summary ? (rv.testing.summary.hasTests ? ('tests: ' + (rv.testing.summary.kinds || []).join(', ')) : 'No tests found') : 'Not assessed';
      if (key === 'compliance') return (report.posture || []).length + ' framework(s) mapped';
      if (key === 'configuration') return 'config files reviewed';
      return '';
    } catch (e) { return ''; }
  }

  // ---- The security gate decision ------------------------------------------
  function decide(report, dimensions, dep, pol, findings) {
    findings = findings || [];
    const dimMap = {}; dimensions.forEach(d => { dimMap[d.key] = d; });
    const rationale = [], blockers = [], required = [], after = [];
    let level = 0;   // index into DECISIONS
    const raise = (lvl, why) => { if (lvl > level) level = lvl; if (why) rationale.push(why); };

    const secretFindings = findings.filter(f => dimensionOf(f) === 'secrets');
    const exposedSecret = secretFindings.some(f => ['critical', 'high'].includes(sev(f)) || conf(f) === 'high');
    const criticalHigh = findings.filter(f => sev(f) === 'critical' && conf(f) === 'high');
    const highs = findings.filter(f => sev(f) === 'high');
    const authFail = dimMap.auth && dimMap.auth.status === 'fail';
    const deniedLicense = (report.licenses && (report.licenses.denied || []).length) || 0;
    const unknownLicense = (dep.licenses && (dep.licenses.unknown || []).length) || 0;
    const noSbom = !((report.sbom && report.sbom.components || []).length);
    const kevFindings = findings.filter(f => f && f.kev === true);   // CISA Known-Exploited Vulns
    const prohibitedDeps = findings.filter(f => f && f.ruleId === 'dep-approval' && sev(f) === 'high');
    // Only apply logging/test/CI-gate penalties when the relevant reviewer
    // actually ran — absence of a reviewer must not fabricate a downgrade.
    const rv = report.reviews || {};
    const loggingWeak = !!rv.logging && dimMap.logging && dimMap.logging.status !== 'pass';
    const testWeak = !!rv.testing && dimMap.test && dimMap.test.status !== 'pass';
    const noCiGate = !!(rv.testing && rv.testing.summary && rv.testing.summary.hasCiTestGate === false);

    // Rejected-level conditions.
    if (kevFindings.length) { raise(3, kevFindings.length + ' actively-exploited (CISA KEV) vulnerabilit(ies) present.'); blockers.push(kevFindings.length + ' actively-exploited (CISA KEV) CVE(s)'); required.push('Patch or remove the actively-exploited (CISA KEV) component(s) before release — these have confirmed in-the-wild exploitation and are the highest remediation priority.'); }
    if (exposedSecret) { raise(3, 'Exposed secret(s) detected — must be removed and rotated before release.'); blockers.push('Exposed/hardcoded secret(s)'); required.push('Remove every hardcoded secret from source, rotate the exposed credentials, and move them to an approved secrets manager. Review commit history for prior exposure.'); }
    if (criticalHigh.length) { raise(3, criticalHigh.length + ' high-confidence Critical finding(s) present.'); blockers.push(criticalHigh.length + ' Critical (high-confidence) finding(s)'); required.push('Resolve all high-confidence Critical findings (see Developer report) or obtain documented risk acceptance.'); }
    if (authFail) { raise(3, 'Authentication/authorization gaps on protected functionality.'); blockers.push('Auth/access-control gaps'); required.push('Enforce authentication on protected routes and add server-side authorization (RBAC / object-level checks).'); }
    if (prohibitedDeps.length) { raise(3, prohibitedDeps.length + ' prohibited dependenc(ies) present.'); blockers.push(prohibitedDeps.length + ' prohibited dependenc(ies)'); required.push('Remove the prohibited dependenc(ies) (see the Dependency approval table) or obtain a documented exception from the security + risk owners.'); }
    if (deniedLicense) { raise(3, deniedLicense + ' prohibited license(s) present.'); blockers.push(deniedLicense + ' prohibited license(s)'); required.push('Replace or obtain an exception for components under prohibited (strong/network copyleft) licenses.'); }

    // Manual-review conditions (uncertainty the tool will not auto-clear).
    if (unknownLicense) raise(2, unknownLicense + ' dependency license(s) could not be determined — compliance owner review required.');
    const lowConfCrit = findings.filter(f => sev(f) === 'critical' && conf(f) !== 'high');
    if (lowConfCrit.length) raise(2, lowConfCrit.length + ' lower-confidence Critical finding(s) require manual verification.');

    // Conditional conditions.
    if (highs.length >= pol.multipleHighThreshold) { raise(1, highs.length + ' High-severity findings — conditional pending remediation.'); after.push('Burn down the High-severity findings before the next release.'); }
    if (noSbom) { raise(1, 'No Software Bill of Materials could be generated.'); after.push('Generate and retain an SBOM (Dependencies & Runtime tab → export).'); }
    if (loggingWeak) { raise(1, 'Security-event logging / audit trail is incomplete.'); after.push('Add audit logging for authentication, authorization, admin and data-access events.'); }
    if (noCiGate || testWeak) { raise(1, 'Missing test and/or CI security gate.'); after.push('Add a CI test + security-scan gate and a minimum coverage threshold.'); }

    const decision = DECISIONS[level];
    const riskAcceptanceRequired = level >= 2;
    const approverRoles = level >= 3 ? ['Security Lead', 'Risk Owner', 'Engineering Manager']
      : level === 2 ? ['Security Reviewer', 'Compliance Owner']
        : level === 1 ? ['Engineering Manager', 'Security Reviewer']
          : ['Engineering Manager'];
    if (!rationale.length) rationale.push('No release-blocking conditions detected; only low/informational findings (if any).');
    return { decision, rationale, blockers, required, after, riskAcceptanceRequired, approverRoles };
  }

  // ---- Render + export delegation (main thread only) -----------------------
  let _last = null;
  function render(report) {
    _last = report;
    if (CITADEL.reviewReport && CITADEL.reviewReport.render) { try { CITADEL.reviewReport.render(report); } catch (e) {} }
  }
  function download(name, text, mime) {
    try {
      const blob = new Blob([text], { type: mime || 'text/plain;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = name; document.body.appendChild(a); a.click();
      setTimeout(() => { try { URL.revokeObjectURL(url); a.remove(); } catch (e) {} }, 0);
    } catch (e) {}
  }
  function printHtml(html) {
    try {
      const w = window.open('', '_blank'); if (!w) return;
      w.document.open(); w.document.write(html); w.document.close(); w.focus();
      setTimeout(() => { try { w.print(); } catch (e) {} }, 300);
    } catch (e) {}
  }
  function exportAs(fmt) {
    const R = CITADEL.reviewReport; if (!_last || !R) return;
    const map = {
      executive: ['citadel-executive-summary.md', R.executive, 'text/markdown;charset=utf-8'],
      developer: ['citadel-developer-remediation.md', R.developer, 'text/markdown;charset=utf-8'],
      auditor: ['citadel-auditor-evidence.md', R.auditor, 'text/markdown;charset=utf-8'],
      markdown: ['citadel-release-readiness.md', R.markdown, 'text/markdown;charset=utf-8'],
      json: ['citadel-release-readiness.json', R.json, 'application/json'],
      csv: ['citadel-findings-register.csv', R.csv, 'text/csv;charset=utf-8']
    };
    if (fmt === 'pdf') { if (R.html) printHtml(R.html(_last)); return; }
    if (fmt === 'html') { download('citadel-release-readiness.html', R.html(_last), 'text/html;charset=utf-8'); return; }
    const m = map[fmt]; if (m && typeof m[1] === 'function') download(m[0], m[1](_last), m[2]);
  }

  if (typeof document !== 'undefined' && document.addEventListener) {
    document.addEventListener('click', function (e) {
      const b = e.target && e.target.closest && e.target.closest('[data-readiness-export]');
      if (b) { e.preventDefault(); exportAs(b.getAttribute('data-readiness-export')); }
    });
  }

  CITADEL.readiness = { analyze, render, exportAs, dimensionOf };
})(window);
