/* CITADEL — OSV.dev live CVE enrichment (client-side).
 * Queries the free, keyless OSV.dev API to turn the SBOM into real
 * vulnerabilities with IDs, severities, and fixed versions — no backend needed.
 * window.CITADEL.osv
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // CITADEL ecosystem -> OSV ecosystem name
  const ECO = {
    npm: 'npm', pypi: 'PyPI', maven: 'Maven', golang: 'Go', gem: 'RubyGems',
    composer: 'Packagist', cargo: 'crates.io', nuget: 'NuGet'
  };

  function severityOf(vuln) {
    // Prefer CVSS vector score; fall back to database_specific.severity text.
    const sevs = vuln.severity || [];
    for (const s of sevs) {
      const score = cvssScore(s.score);
      if (score != null) return bucket(score);
    }
    const ds = (vuln.database_specific && vuln.database_specific.severity) || '';
    const t = String(ds).toLowerCase();
    if (t.includes('critical')) return 'critical';
    if (t.includes('high')) return 'high';
    if (t.includes('moderate') || t.includes('medium')) return 'medium';
    if (t.includes('low')) return 'low';
    return 'medium';
  }
  function cvssScore(vector) {
    if (vector == null) return null;
    const n = parseFloat(vector);
    if (!isNaN(n) && /^[0-9.]+$/.test(String(vector).trim())) return n; // already a numeric score
    return null; // a CVSS vector string — bucket by base metrics is overkill; treat as unknown
  }
  function bucket(score) {
    if (score >= 9) return 'critical';
    if (score >= 7) return 'high';
    if (score >= 4) return 'medium';
    if (score > 0) return 'low';
    return 'info';
  }

  function fixedVersion(vuln) {
    const out = [];
    (vuln.affected || []).forEach(a => {
      (a.ranges || []).forEach(r => {
        (r.events || []).forEach(e => { if (e.fixed) out.push(e.fixed); });
      });
    });
    return out.length ? [...new Set(out)].join(', ') : null;
  }

  // Convert one OSV vuln detail + component into a CITADEL finding.
  function toFinding(vuln, component) {
    const sev = severityOf(vuln);
    const fixed = fixedVersion(vuln);
    const aliases = (vuln.aliases || []).filter(a => /^CVE-/.test(a));
    const id = aliases[0] || vuln.id;
    return {
      ruleId: id, source: 'osv',
      name: `${id}: ${component.name}${component.version && component.version !== '*' ? ' ' + component.version : ''}`,
      category: 'deps', severity: sev, cwe: (vuln.database_specific && vuln.database_specific.cwe_ids && vuln.database_specific.cwe_ids[0]) || null,
      confidence: 'high',
      file: `${component.name}@${component.version}`, line: 0,
      snippet: (vuln.summary || vuln.details || '').slice(0, 180),
      remediation: fixed ? `Upgrade ${component.name} to ${fixed} or later.` : 'No fixed version published — monitor the advisory and consider mitigations.'
    };
  }

  async function postJson(url, body) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    if (!res.ok) throw new Error('OSV ' + res.status);
    return res.json();
  }

  /* Enrich components with live OSV data. Returns { findings, queried, vulnerable }.
   * Bounded: queries in batches and only fetches detail for components with hits. */
  // Resolve a version spec to a concrete version to query. Exact versions pass
  // through; a floating range ("^1.2.3", ">=1.2.0 <2") is queried at its FLOOR so
  // range-pinned deps (the majority of real manifests) are no longer skipped —
  // OSV checks whether that concrete version is affected. Wildcards stay
  // unresolvable. Reuses advisories.resolve() when present, else a local copy.
  function queryVersion(spec) {
    if (CITADEL.advisories && CITADEL.advisories.resolve) {
      const r = CITADEL.advisories.resolve(spec);
      return r ? r.ver.join('.') : null;
    }
    const m = String(spec == null ? '' : spec).match(/(\d+)(?:\.(\d+))?(?:\.(\d+))?/);
    if (!m || /\*|latest/i.test(String(spec))) return null;
    return [parseInt(m[1], 10), parseInt(m[2] || '0', 10), parseInt(m[3] || '0', 10)].join('.');
  }

  async function enrich(components, onProgress) {
    const queryable = components
      .map(c => ({ c, eco: ECO[c.ecosystem], qv: queryVersion(c.version) }))
      .filter(x => x.eco && x.qv);
    if (!queryable.length) return { findings: [], queried: 0, vulnerable: 0, skipped: components.length };

    const findings = [];
    let vulnerable = 0;
    const BATCH = 100;
    for (let i = 0; i < queryable.length; i += BATCH) {
      const slice = queryable.slice(i, i + BATCH);
      onProgress && onProgress(Math.min(queryable.length, i + slice.length), queryable.length);
      let batch;
      try {
        batch = await postJson('https://api.osv.dev/v1/querybatch', {
          queries: slice.map(x => ({ package: { name: x.c.name, ecosystem: x.eco }, version: x.qv }))
        });
      } catch (e) { continue; }
      const results = (batch && batch.results) || [];
      // Detail fetch for components that returned vuln IDs.
      for (let j = 0; j < results.length; j++) {
        const vulns = (results[j] && results[j].vulns) || [];
        if (!vulns.length) continue;
        vulnerable++;
        const comp = slice[j].c;
        for (const v of vulns.slice(0, 8)) {
          try {
            const detail = await postJson('https://api.osv.dev/v1/query', {
              package: { name: comp.name, ecosystem: slice[j].eco }, version: slice[j].qv
            });
            (detail.vulns || []).forEach(d => findings.push(toFinding(d, comp)));
            break; // /v1/query returns all vulns for the component at once
          } catch (e) {
            findings.push(toFinding({ id: v.id, summary: '' }, comp));
          }
        }
      }
    }
    // de-dupe by id+component
    const seen = new Set();
    const deduped = findings.filter(f => { const k = f.ruleId + '|' + f.file; if (seen.has(k)) return false; seen.add(k); return true; });
    return { findings: deduped, queried: queryable.length, vulnerable, skipped: components.length - queryable.length };
  }

  CITADEL.osv = { enrich, toFinding, severityOf, fixedVersion, ECO };
})(window);
