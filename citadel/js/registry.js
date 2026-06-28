/* CITADEL — live package-registry enrichment (client-side, best-effort).
 *
 * Fills the dependency inventory's "latest available version", "maintainer",
 * "last published" and "deprecated" fields by querying the public, keyless,
 * CORS-enabled registries (npm + PyPI) from the browser after a scan — the same
 * pattern as the OSV / EPSS lookups. Bounded + throttled (direct dependencies
 * first, hard cap, concurrency limit, per-request timeout) so it never hammers a
 * registry, and entirely optional: any failure degrades silently to the offline
 * data (latest stays null).
 *
 * window.CITADEL.registry
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MAX = 80;          // hard cap on packages queried per scan
  const CONCURRENCY = 6;
  const TIMEOUT = 6000;

  /* ---------- semver-ish compare ---------- */
  function cleanVer(v) {
    const m = String(v == null ? '' : v).match(/(\d+)(?:\.(\d+))?(?:\.(\d+))?/);
    if (!m) return null;
    return [parseInt(m[1], 10), parseInt(m[2] || '0', 10), parseInt(m[3] || '0', 10)];
  }
  function cmp(a, b) {
    for (let i = 0; i < 3; i++) { if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) < (b[i] || 0) ? -1 : 1; }
    return 0;
  }
  // Is `cur` strictly behind `latest`? Returns false when either is unparseable.
  function isOutdated(cur, latest) {
    const a = cleanVer(cur), b = cleanVer(latest);
    if (!a || !b) return false;
    return cmp(a, b) < 0;
  }

  async function getJson(url) {
    const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    const t = ctrl ? setTimeout(() => { try { ctrl.abort(); } catch (e) {} }, TIMEOUT) : null;
    try {
      const res = await fetch(url, ctrl ? { signal: ctrl.signal } : undefined);
      if (!res.ok) return null;
      return await res.json();
    } catch (e) { return null; } finally { if (t) clearTimeout(t); }
  }

  // npm: the per-version "latest" manifest is small and carries maintainers,
  // license and deprecation.
  async function npmMeta(name) {
    const d = await getJson('https://registry.npmjs.org/' + encodeURIComponent(name).replace(/^%40/, '@') + '/latest');
    if (!d) return null;
    return {
      latest: d.version || null,
      deprecated: !!d.deprecated,
      license: typeof d.license === 'string' ? d.license : (d.license && d.license.type) || null,
      maintainer: (Array.isArray(d.maintainers) && d.maintainers[0] && d.maintainers[0].name) || (d.author && d.author.name) || null,
      lastPublished: null
    };
  }
  async function pypiMeta(name) {
    const d = await getJson('https://pypi.org/pypi/' + encodeURIComponent(name) + '/json');
    if (!d || !d.info) return null;
    let last = null;
    try { const rel = d.releases && d.releases[d.info.version]; if (rel && rel[0] && rel[0].upload_time) last = rel[0].upload_time; } catch (e) {}
    return {
      latest: d.info.version || null,
      deprecated: false,
      license: d.info.license || null,
      maintainer: d.info.maintainer || d.info.author || null,
      lastPublished: last
    };
  }
  function fetcher(ecosystem) { return ecosystem === 'npm' ? npmMeta : ecosystem === 'pypi' ? pypiMeta : null; }

  // Order components: direct dependencies first (from the dependency review),
  // then cap. Only npm + pypi have a keyless CORS metadata API here.
  function pickTargets(report) {
    const comps = (report.sbom && report.sbom.components) || [];
    const directNames = new Set();
    try {
      const dep = report.depreview && report.depreview.dependencies;
      ['prod', 'dev'].forEach(k => (dep && dep[k] || []).forEach(d => { if (d.direct) directNames.add((d.ecosystem + '|' + d.name).toLowerCase()); }));
    } catch (e) {}
    const eligible = comps.filter(c => fetcher(c.ecosystem) && c.name);
    eligible.sort((a, b) => {
      const ad = directNames.has((a.ecosystem + '|' + a.name).toLowerCase()) ? 0 : 1;
      const bd = directNames.has((b.ecosystem + '|' + b.name).toLowerCase()) ? 0 : 1;
      return ad - bd;
    });
    return eligible.slice(0, MAX);
  }

  async function enrich(report, onProgress) {
    const out = { queried: 0, outdated: 0, deprecated: 0, skipped: 0 };
    try {
      if (!report) return out;
      const targets = pickTargets(report);
      if (!targets.length) return out;
      if (onProgress) onProgress('Checking registries for latest versions…');
      // Also index the dependency-review Dep objects so we can fill their `latest`.
      const depIndex = {};
      try {
        const dep = report.depreview && report.depreview.dependencies;
        ['prod', 'dev'].forEach(k => (dep && dep[k] || []).forEach(d => { depIndex[(d.ecosystem + '|' + String(d.name).toLowerCase())] = d; }));
      } catch (e) {}

      let i = 0;
      async function worker() {
        while (i < targets.length) {
          const c = targets[i++];
          const fn = fetcher(c.ecosystem);
          const meta = fn ? await fn(c.name) : null;
          if (!meta) { out.skipped++; continue; }
          out.queried++;
          c.latest = meta.latest;
          c.maintainer = meta.maintainer;
          c.lastPublished = meta.lastPublished;
          c.registryDeprecated = meta.deprecated;
          c.outdated = isOutdated(c.version, meta.latest);
          if (c.outdated) out.outdated++;
          if (meta.deprecated) out.deprecated++;
          const d = depIndex[(c.ecosystem + '|' + String(c.name).toLowerCase())];
          if (d) { d.latest = meta.latest; if (meta.deprecated) d.status = 'deprecated'; else if (c.outdated && d.status === 'unknown') d.status = 'outdated'; }
        }
      }
      const pool = [];
      for (let w = 0; w < Math.min(CONCURRENCY, targets.length); w++) pool.push(worker());
      await Promise.all(pool);

      // A registry-confirmed deprecation is authoritative — emit a finding.
      if (Array.isArray(report.findings)) {
        targets.forEach(c => {
          if (c.registryDeprecated) {
            report.findings.push({
              ruleId: 'registry-deprecated', source: 'registry', module: 'dependency', category: 'supply-chain',
              severity: 'medium', confidence: 'high', cwe: 'CWE-1104',
              name: 'Deprecated package: ' + c.name, file: c.name + '@' + c.version, line: 0,
              snippet: c.ecosystem + ': ' + c.name + ' ' + c.version + (c.latest ? ' (latest ' + c.latest + ')' : ''),
              impact: 'The maintainer has marked this package deprecated; it will not receive fixes and may have a recommended replacement.',
              likelihood: 'medium', remediationEffort: 'medium',
              remediation: 'Migrate to the maintained replacement named in the deprecation notice, or to an actively-maintained alternative.',
              references: ['https://owasp.org/www-project-software-component-verification-standard/'],
              complianceMappings: [
                { framework: 'OWASP SCVS', control: 'V2 Package Management', note: 'Supports use of maintained components.' },
                { framework: 'NIST 800-53', control: 'SA-22', note: 'Unsupported components: a deprecated package is unsupported.' }
              ]
            });
          }
        });
      }
      return out;
    } catch (e) { return out; }
  }

  CITADEL.registry = { enrich, isOutdated, cleanVer };
})(window);
