/* CITADEL — Dependency Review & Runtime Requirements (orchestrator).
 *
 * Assembles `report.depreview` from the focused analysis sub-modules and wires
 * the export buttons rendered in the Dependencies & Runtime tab. Runs in BOTH
 * the main thread and the scan Web Worker (worker.js sets `self.window = self`),
 * so all DOM/export wiring is guarded behind a `document` check — the worker
 * only ever calls analyze(), never render()/export.
 *
 *   CITADEL.depreview.analyze(entries, sbomComponents, findings) -> report.depreview
 *   CITADEL.depreview.render(report)        // fills #tab-depreview (main thread)
 *   CITADEL.depreview.exportAs(format)      // md | html | csv | json | pdf
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  function safe(fn, fallback) { try { const v = fn(); return v == null ? fallback : v; } catch (e) { return fallback; } }

  // Compose the full report.depreview from the sub-modules. Each sub-module is
  // pure + defensive; if one is missing or throws we degrade to empty pieces so
  // a dependency-review failure never breaks the underlying scan.
  function analyze(entries, sbomComponents, findings) {
    entries = entries || []; sbomComponents = sbomComponents || []; findings = findings || [];

    const deps = safe(() => CITADEL.depreviewDeps.analyze(entries, sbomComponents), {
      manifests: [], dependencies: { prod: [], dev: [], counts: { prod: 0, dev: 0, total: 0, direct: 0, transitive: 0 } },
      packageManagers: [], licensesRaw: []
    });
    const rt = safe(() => CITADEL.depreviewRuntime.analyze(entries), {
      stack: {}, runtime: { services: [], databases: [], envVars: [], ports: [] },
      build: {}, externalServices: [], infra: []
    });
    const sec = safe(() => CITADEL.depreviewSecurity.analyze({
      entries: entries, dependencies: deps.dependencies, licensesRaw: deps.licensesRaw,
      runtime: rt.runtime, findings: findings
    }), {
      security: { cve: { critical: 0, high: 0, medium: 0, low: 0, items: [] }, supplyChain: [], summary: { total: 0, critical: 0, high: 0, medium: 0, low: 0 } },
      licenses: { inventory: [], conflicts: [], unknown: [] },
      docs: { present: [], missing: [] }, missing: { documentation: [], envVars: [], deploymentSteps: [], dependencies: [], runtime: [], configuration: [] },
      recommendations: { immediate: [], high: [], medium: [], low: [], bestPractices: [] },
      scores: { health: 0, security: 0, readiness: 0, docs: 0, risk: 0, riskBand: 'low', confidence: 0 }
    });

    // Prefer the deps module's package-manager/lockfile inference on the stack.
    const stack = rt.stack || {};
    if (deps.packageManagers && deps.packageManagers.length) stack.packageManagers = deps.packageManagers;

    return {
      version: 1,
      generatedAt: new Date().toISOString(),
      scores: sec.scores,
      stack: stack,
      manifests: deps.manifests || [],
      dependencies: deps.dependencies || { prod: [], dev: [], counts: {} },
      runtime: rt.runtime || { services: [], databases: [], envVars: [], ports: [] },
      build: rt.build || {},
      externalServices: rt.externalServices || [],
      infra: rt.infra || [],
      security: sec.security,
      licenses: sec.licenses,
      docs: sec.docs,
      missing: sec.missing,
      recommendations: sec.recommendations
    };
  }

  /* ---------- Rendering (main thread only) ---------- */
  let _last = null;
  function render(report) {
    _last = report;
    if (CITADEL.depreviewReport && CITADEL.depreviewReport.render) CITADEL.depreviewReport.render(report);
  }

  /* ---------- Exports ---------- */
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
      const w = window.open('', '_blank');
      if (!w) return;
      w.document.open(); w.document.write(html); w.document.close(); w.focus();
      setTimeout(() => { try { w.print(); } catch (e) {} }, 300);
    } catch (e) {}
  }
  function exportAs(fmt) {
    const R = CITADEL.depreviewReport;
    if (!_last || !R) return;
    if (fmt === 'markdown') download('citadel-dependency-review.md', R.markdown(_last), 'text/markdown;charset=utf-8');
    else if (fmt === 'json') download('citadel-dependency-review.json', R.json(_last), 'application/json');
    else if (fmt === 'csv') download('citadel-dependencies.csv', R.csv(_last), 'text/csv;charset=utf-8');
    else if (fmt === 'html') download('citadel-dependency-review.html', R.html(_last), 'text/html;charset=utf-8');
    else if (fmt === 'pdf') printHtml(R.html(_last));
  }

  // Delegated export-button wiring (skipped in the worker, which has no document).
  if (typeof document !== 'undefined' && document.addEventListener) {
    document.addEventListener('click', function (e) {
      const b = e.target && e.target.closest && e.target.closest('[data-dep-export]');
      if (b) { e.preventDefault(); exportAs(b.getAttribute('data-dep-export')); }
    });
  }

  CITADEL.depreview = { analyze, render, exportAs };
})(window);
