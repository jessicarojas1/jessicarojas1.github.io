/* CITADEL — lightweight dependency reachability.
 *
 * A genuine call-graph reachability analysis is out of scope for a static,
 * in-browser scanner, so this provides a useful, honest PROXY: is the vulnerable
 * package actually IMPORTED in first-party source (a likely-reachable direct
 * dependency), or is it only present transitively / never directly used?
 *
 * index(entries) runs at SCAN TIME (entries exist there, incl. in the Web
 * Worker) and returns the set of directly-imported package names, attached to
 * the report. apply(report) runs POST-SCAN (after OSV/Trivy add CVE findings)
 * and annotates each dependency/CVE finding with finding.reachability:
 *   'direct'     — the package is imported in source (likely reachable)
 *   'transitive' — a dependency that is not directly imported (lower priority)
 *   'unknown'    — could not be determined (e.g. import-name != package-name)
 *
 * This is a LOW-CONFIDENCE hint (import name and registry name differ for some
 * ecosystems, e.g. PyPI). It informs prioritisation; it does not prove safety.
 *
 * window.CITADEL.reachability
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // Reduce an import specifier to a package base name.
  //   'lodash/fp'        -> 'lodash'
  //   '@scope/pkg/sub'   -> '@scope/pkg'
  //   'os.path' (python) -> 'os'
  function npmBase(spec) {
    spec = String(spec || '');
    if (spec[0] === '@') { const p = spec.split('/'); return p.length >= 2 ? p[0] + '/' + p[1] : spec; }
    return spec.split('/')[0];
  }
  function pyBase(spec) { return String(spec || '').split(/[./]/)[0]; }

  // Build the set of directly-imported package names from first-party source.
  function index(entries) {
    const set = new Set();
    if (!Array.isArray(entries)) return [];
    const add = (name) => { const n = String(name || '').trim().toLowerCase(); if (n && n[0] !== '.' && n[0] !== '/') set.add(n); };
    for (const e of entries) {
      try {
        if (!e || e.isBinary || !e.content || e.archive) continue;
        const lang = String(e.lang || '').toLowerCase();
        const path = String(e.path || '').toLowerCase();
        const text = e.content.length > 300000 ? e.content.slice(0, 300000) : e.content;
        const isPy = lang === 'python' || /\.py$/.test(path);
        const isJs = lang === 'javascript' || lang === 'typescript' || /\.(jsx?|tsx?|mjs|cjs)$/.test(path);
        if (isJs) {
          let m;
          const req = /require\(\s*['"]([^'"]+)['"]\s*\)/g;
          while ((m = req.exec(text))) add(npmBase(m[1]));
          const imp = /\bimport\b[^'"]*?['"]([^'"]+)['"]/g;       // import x from 'p' / import 'p' / import('p')
          while ((m = imp.exec(text))) add(npmBase(m[1]));
          const exp = /\bexport\b[^'"]*?\bfrom\s+['"]([^'"]+)['"]/g;
          while ((m = exp.exec(text))) add(npmBase(m[1]));
        } else if (isPy) {
          let m;
          const imp = /^\s*import\s+([a-zA-Z0-9_.,\s]+)/gm;
          while ((m = imp.exec(text))) { m[1].split(',').forEach(part => add(pyBase(part.split(/\s+as\s+/)[0]))); }
          const from = /^\s*from\s+([a-zA-Z0-9_.]+)\s+import/gm;
          while ((m = from.exec(text))) add(pyBase(m[1]));
        }
      } catch (e2) { /* skip a bad entry */ }
    }
    return Array.from(set);
  }

  // Extract the package name a dependency/CVE finding refers to.
  function pkgOf(f) {
    if (!f) return '';
    // OSV/SCA findings use file = 'name@version'.
    const file = String(f.file || '');
    if (/@/.test(file) && !/[/\\]/.test(file)) return file.split('@')[0].toLowerCase();
    if (f.component && f.component.name) return String(f.component.name).toLowerCase();
    if (f.package) return String(f.package).toLowerCase();
    return '';
  }
  function isDepFinding(f) {
    return f && (f.source === 'osv' || f.source === 'trivy' || f.source === 'grype' ||
      f.category === 'deps' || /CVE-\d{4}-\d+|GHSA-/i.test((f.ruleId || '') + ' ' + (f.name || '')));
  }

  // Annotate dependency findings with a reachability hint using report.imports.
  function apply(report) {
    try {
      if (!report) return { direct: 0, transitive: 0, unknown: 0 };
      const imports = new Set((report.imports || []).map(x => String(x).toLowerCase()));
      const comps = new Set(((report.sbom && report.sbom.components) || []).map(c => String(c.name || '').toLowerCase()));
      const out = { direct: 0, transitive: 0, unknown: 0 };
      (report.findings || []).forEach(f => {
        if (!isDepFinding(f)) return;
        const pkg = pkgOf(f);
        let r = 'unknown';
        if (pkg && imports.has(pkg)) r = 'direct';
        else if (pkg && comps.has(pkg)) r = 'transitive';
        f.reachability = r;
        out[r] = (out[r] || 0) + 1;
      });
      return out;
    } catch (e) { return { direct: 0, transitive: 0, unknown: 0 }; }
  }

  CITADEL.reachability = { index, apply, pkgOf };
})(window);
