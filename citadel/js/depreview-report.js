/* CITADEL — Dependency Review & Runtime Requirements: Report tab + exporters
 * Renders report.depreview into #tab-depreview and produces downloadable
 * artifacts (Markdown, standalone HTML, CSV dependency inventory, JSON).
 * window.CITADEL.depreviewReport
 *
 * Codes to the shared data contract (scratchpad/depreview-contract.txt). Every
 * section guards for missing/empty data and renders a tasteful empty note
 * rather than throwing. No network, no Date — timestamps come from
 * report.depreview.generatedAt. CSP-safe: every interpolated value is escaped,
 * no inline event handlers, no hardcoded hex in inline styles.
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function cap(s) { s = String(s == null ? '' : s); return s.charAt(0).toUpperCase() + s.slice(1); }
  function arr(a) { return Array.isArray(a) ? a : []; }

  /* ---------- shared classification helpers ---------- */
  // Higher band score (health/security/readiness/docs/confidence): more is better.
  function bandClassHigh(v) {
    const n = Number(v) || 0;
    if (n >= 75) return 'text-bg-success';
    if (n >= 50) return 'text-bg-warning';
    return 'text-bg-danger';
  }
  // Risk score: more is worse.
  function bandClassRisk(v) {
    const n = Number(v) || 0;
    if (n >= 75) return 'text-bg-danger';
    if (n >= 50) return 'text-bg-warning';
    if (n >= 25) return 'text-bg-info';
    return 'text-bg-success';
  }
  function riskBandClass(b) {
    switch (String(b || '').toLowerCase()) {
      case 'critical': return 'text-bg-danger';
      case 'high': return 'text-bg-danger';
      case 'moderate': return 'text-bg-warning';
      case 'low': return 'text-bg-success';
      default: return 'text-bg-secondary';
    }
  }
  function sevClass(s) {
    switch (String(s || '').toLowerCase()) {
      case 'critical': return 'text-bg-danger';
      case 'high': return 'text-bg-warning';
      case 'medium': return 'text-bg-info';
      case 'low': return 'text-bg-secondary';
      case 'info': return 'text-bg-light text-dark';
      default: return 'text-bg-secondary';
    }
  }
  function statusClass(s) {
    switch (String(s || '').toLowerCase()) {
      case 'ok': return 'text-bg-success';
      case 'outdated': return 'text-bg-warning';
      case 'deprecated': return 'text-bg-danger';
      case 'eol': return 'text-bg-danger';
      default: return 'text-bg-secondary';
    }
  }
  function depRiskClass(r) {
    switch (String(r || '').toLowerCase()) {
      case 'critical': return 'text-bg-danger';
      case 'high': return 'text-bg-danger';
      case 'medium': return 'text-bg-warning';
      case 'low': return 'text-bg-info';
      case 'none': return 'text-bg-success';
      default: return 'text-bg-secondary';
    }
  }
  function licenseCatClass(c) {
    switch (String(c || '').toLowerCase()) {
      case 'permissive': return 'text-bg-success';
      case 'public-domain': return 'text-bg-success';
      case 'weak-copyleft': return 'text-bg-info';
      case 'strong-copyleft': return 'text-bg-warning';
      case 'network-copyleft': return 'text-bg-warning';
      case 'commercial': return 'text-bg-danger';
      case 'unknown': return 'text-bg-secondary';
      default: return 'text-bg-secondary';
    }
  }
  function confClass(c) {
    switch (String(c || '').toLowerCase()) {
      case 'high': return 'text-bg-success';
      case 'medium': return 'text-bg-info';
      case 'low': return 'text-bg-secondary';
      default: return 'text-bg-secondary';
    }
  }

  const SECTION = s => `<h6 class="text-uppercase text-body-secondary small fw-bold mb-2">${esc(s)}</h6>`;
  const EMPTY_NOTE = msg => `<p class="text-body-secondary small mb-0"><i class="bi bi-dash-circle"></i> ${esc(msg)}</p>`;
  const DEP_CAP = 60; // cap very long dependency tables in the DOM view

  /* ===================================================================== *
   *  RENDER
   * ===================================================================== */
  function render(report) {
    const el = document.getElementById('tab-depreview');
    if (!el) return;
    const dr = report && report.depreview;
    if (!dr) {
      el.innerHTML = '<div class="empty-state"><i class="bi bi-box-seam"></i>'
        + '<p>No dependency review available. Run a scan to analyze dependencies, '
        + 'runtime requirements, licenses, and deployment readiness.</p></div>';
      return;
    }
    el.innerHTML = [
      exportBar(dr),
      execSummarySection(dr),
      stackSection(dr),
      depsSection(dr, 'prod', 'Production Dependencies'),
      depsSection(dr, 'dev', 'Development Dependencies'),
      runtimeSection(dr),
      buildSection(dr),
      externalServicesSection(dr),
      infraSection(dr),
      securitySection(dr),
      licenseSection(dr),
      docsSection(dr),
      missingSection(dr),
      recommendationsSection(dr)
    ].join('\n');
  }

  /* ---------- export bar (handlers wired globally by orchestrator) ---------- */
  function exportBar() {
    const btn = (fmt, icon, label) =>
      `<button class="btn btn-sm btn-outline-secondary" data-dep-export="${esc(fmt)}"><i class="bi ${icon}"></i> ${esc(label)}</button>`;
    return `<div class="d-flex flex-wrap gap-2 mb-3" id="depreview-export-bar">
      ${btn('markdown', 'bi-filetype-md', 'Markdown')}
      ${btn('html', 'bi-filetype-html', 'HTML')}
      ${btn('csv', 'bi-filetype-csv', 'CSV')}
      ${btn('json', 'bi-filetype-json', 'JSON')}
      ${btn('pdf', 'bi-printer', 'PDF / Print')}
    </div>`;
  }

  function card(body) {
    return `<div class="card citadel-card mb-3"><div class="card-body">${body}</div></div>`;
  }

  /* ---------- 1. Executive Summary ---------- */
  function execSummarySection(dr) {
    const s = dr.scores || {};
    const tile = (label, val, cls) => `
      <div class="col-6 col-md-4 col-xl-2">
        <div class="text-center p-2">
          <span class="badge ${cls} fs-6 d-block mb-1">${esc(val == null ? '—' : val)}</span>
          <div class="small text-body-secondary text-uppercase fw-bold">${esc(label)}</div>
        </div>
      </div>`;
    const riskVal = (s.risk == null ? '—' : s.risk) + (s.riskBand ? ' · ' + cap(s.riskBand) : '');
    // Colour the risk tile by named band when present, else fall back to the score.
    const riskCls = s.riskBand ? riskBandClass(s.riskBand) : bandClassRisk(s.risk);
    const tiles = [
      tile('Health', s.health, bandClassHigh(s.health)),
      tile('Security', s.security, bandClassHigh(s.security)),
      tile('Readiness', s.readiness, bandClassHigh(s.readiness)),
      tile('Docs', s.docs, bandClassHigh(s.docs)),
      tile('Risk', riskVal, riskCls),
      tile('Confidence', s.confidence == null ? '—' : s.confidence + '%', bandClassHigh(s.confidence))
    ].join('');
    const body = SECTION('Executive Summary')
      + `<div class="row g-2 mb-2">${tiles}</div>`
      + `<p class="mb-0">${verdict(dr)}</p>`;
    return card(body);
  }

  function verdict(dr) {
    const s = dr.scores || {};
    const band = String(s.riskBand || '').toLowerCase();
    const readiness = Number(s.readiness) || 0;
    let cls = 'text-success', txt;
    if (band === 'critical' || band === 'high') {
      cls = 'text-danger';
      txt = 'Not deployment-ready — ' + band + ' supply-chain risk must be remediated before release.';
    } else if (band === 'moderate' || readiness < 60) {
      cls = 'text-warning';
      txt = 'Conditionally deployable — moderate risk; resolve outstanding gaps before production.';
    } else {
      txt = 'Deployment-ready — low risk and good readiness across dependencies and runtime.';
    }
    return `<strong class="${cls}">${esc(txt)}</strong>`;
  }

  /* ---------- 2. Technology Stack ---------- */
  function chip(text, cls) {
    return `<span class="badge ${cls || 'text-bg-secondary'} me-1 mb-1">${esc(text)}</span>`;
  }
  function chipGroup(label, html) {
    if (!html) return '';
    return `<div class="mb-2"><span class="small text-body-secondary me-2">${esc(label)}:</span>${html}</div>`;
  }
  function stackSection(dr) {
    const st = dr.stack || {};
    const groups = [];
    const langs = arr(st.languages).map(l =>
      chip((l.name || '') + (l.files ? ' (' + l.files + ')' : ''), l.primary ? 'text-bg-primary' : 'text-bg-secondary')).join('');
    groups.push(chipGroup('Languages', langs));
    groups.push(chipGroup('Frameworks', arr(st.frameworks).map(f =>
      chip((f.name || '') + (f.version ? ' ' + f.version : '') + (f.confidence ? ' · ' + f.confidence : ''), confClass(f.confidence))).join('')));
    groups.push(chipGroup('Runtimes', arr(st.runtimes).map(r =>
      chip((r.name || '') + (r.version ? ' ' + r.version : ''))).join('')));
    groups.push(chipGroup('SDKs', arr(st.sdks).map(r => chip((r.name || '') + (r.version ? ' ' + r.version : ''))).join('')));
    groups.push(chipGroup('Compilers', arr(st.compilers).map(r => chip((r.name || '') + (r.version ? ' ' + r.version : ''))).join('')));
    groups.push(chipGroup('Package Managers', arr(st.packageManagers).map(p =>
      chip((p.name || '') + (p.lockfile ? ' · ' + p.lockfile : ''), 'text-bg-info')).join('')));
    groups.push(chipGroup('Databases', arr(st.databases).map(d => chip(d, 'text-bg-info')).join('')));
    groups.push(chipGroup('Infrastructure', arr(st.infra).map(d => chip(d)).join('')));
    groups.push(chipGroup('Cloud', arr(st.cloud).map(d => chip(d, 'text-bg-primary')).join('')));
    groups.push(chipGroup('OS', arr(st.os).map(d => chip(d.name || d)).join('')));
    const inner = groups.filter(Boolean).join('');
    const body = SECTION('Technology Stack') + (inner || EMPTY_NOTE('No technology stack detected.'));
    return card(body);
  }

  /* ---------- 3 & 4. Dependencies tables ---------- */
  function depRow(d) {
    return `<tr>
      <td><code>${esc(d.name)}</code>${d.purpose ? ' <span class="text-body-secondary small">' + esc(d.purpose) + '</span>' : ''}</td>
      <td>${esc(d.version || '—')}</td>
      <td>${esc(d.source || '—')}</td>
      <td>${d.license ? esc(d.license) : '<span class="text-body-secondary">unknown</span>'}</td>
      <td><span class="badge ${statusClass(d.status)}">${esc(d.status || 'unknown')}</span></td>
      <td><span class="badge ${depRiskClass(d.risk)}">${esc(d.risk || 'none')}</span></td>
    </tr>`;
  }
  function depsSection(dr, key, title) {
    const deps = dr.dependencies || {};
    const list = arr(deps[key]);
    const counts = deps.counts || {};
    const countN = counts[key] != null ? counts[key] : list.length;
    if (!list.length) {
      return card(SECTION(title) + EMPTY_NOTE('No ' + key + ' dependencies detected.'));
    }
    const shown = list.slice(0, DEP_CAP);
    const rows = shown.map(depRow).join('');
    const moreNote = list.length > DEP_CAP
      ? `<p class="small text-body-secondary mt-2 mb-0"><i class="bi bi-three-dots"></i> Showing ${DEP_CAP} of ${list.length} — +${list.length - DEP_CAP} more (full list in exports).</p>`
      : '';
    const body = `<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h6 class="text-uppercase text-body-secondary small fw-bold mb-0">${esc(title)}</h6>
        <span class="badge text-bg-secondary">${countN} total</span>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle citadel-table">
        <thead><tr><th>Name</th><th>Version</th><th>Source</th><th>License</th><th>Status</th><th>Risk</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>${moreNote}`;
    return card(body);
  }

  /* ---------- 5. Runtime Requirements ---------- */
  function runtimeSection(dr) {
    const rt = dr.runtime || {};
    const st = dr.stack || {};
    const parts = [SECTION('Runtime Requirements')];

    // OS / runtime / pkg-mgr / compiler / sdk summary chips
    const sumChips = [];
    arr(st.os).forEach(o => sumChips.push(chip('OS: ' + (o.name || o))));
    arr(st.runtimes).forEach(r => sumChips.push(chip('Runtime: ' + (r.name || '') + (r.version ? ' ' + r.version : ''), 'text-bg-info')));
    arr(st.packageManagers).forEach(p => sumChips.push(chip('Pkg: ' + (p.name || ''))));
    arr(st.compilers).forEach(cc => sumChips.push(chip('Compiler: ' + (cc.name || ''))));
    arr(st.sdks).forEach(sd => sumChips.push(chip('SDK: ' + (sd.name || ''))));
    parts.push('<div class="mb-3">' + (sumChips.length ? sumChips.join('') : EMPTY_NOTE('No runtime toolchain detected.')) + '</div>');

    // Required services
    const services = arr(rt.services);
    parts.push('<div class="mb-3">' + SECTION('Required Services').replace('mb-2', 'mb-1')
      + (services.length
        ? '<div class="table-responsive"><table class="table table-sm align-middle citadel-table">'
          + '<thead><tr><th>Type</th><th>Name</th><th>Required</th></tr></thead><tbody>'
          + services.map(s => `<tr><td>${esc(s.type || '—')}</td><td>${esc(s.name || '—')}</td>`
            + `<td><span class="badge ${s.required ? 'text-bg-danger' : 'text-bg-secondary'}">${s.required ? 'required' : 'optional'}</span></td></tr>`).join('')
          + '</tbody></table></div>'
        : EMPTY_NOTE('No external services required.')) + '</div>');

    // Databases
    const dbs = arr(rt.databases);
    parts.push('<div class="mb-3">' + SECTION('Databases').replace('mb-2', 'mb-1')
      + (dbs.length
        ? '<div class="table-responsive"><table class="table table-sm align-middle citadel-table">'
          + '<thead><tr><th>Engine</th><th>Min version</th><th>Migration tool</th><th>Connection var</th></tr></thead><tbody>'
          + dbs.map(d => `<tr><td><span class="badge text-bg-info">${esc(d.engine || '—')}</span></td>`
            + `<td>${esc(d.minVersion || '—')}</td><td>${esc(d.migrationTool || '—')}</td>`
            + `<td>${d.connectionVar ? '<code>' + esc(d.connectionVar) + '</code>' : '—'}</td></tr>`).join('')
          + '</tbody></table></div>'
        : EMPTY_NOTE('No databases detected.')) + '</div>');

    // Ports
    const ports = arr(rt.ports);
    parts.push('<div class="mb-3">' + SECTION('Required Ports').replace('mb-2', 'mb-1')
      + (ports.length
        ? '<div class="d-flex flex-wrap gap-1">' + ports.map(p =>
          chip((p.port != null ? p.port : '?') + '/' + (p.protocol || 'tcp') + ' · ' + (p.direction || 'listen'),
            p.direction === 'outbound' ? 'text-bg-secondary' : 'text-bg-info')).join('') + '</div>'
        : EMPTY_NOTE('No network ports detected.')) + '</div>');

    // Environment variables (names only — NEVER values)
    const envVars = arr(rt.envVars);
    parts.push('<div>' + SECTION('Environment Variables').replace('mb-2', 'mb-1')
      + (envVars.length
        ? '<div class="table-responsive"><table class="table table-sm align-middle citadel-table">'
          + '<thead><tr><th>Name</th><th>Category</th><th>Required</th><th>Secret?</th><th>Used in</th></tr></thead><tbody>'
          + envVars.map(v => `<tr>
              <td><code>${esc(v.name)}</code></td>
              <td>${esc(v.category || 'other')}</td>
              <td><span class="badge ${v.required ? 'text-bg-warning' : 'text-bg-secondary'}">${v.required ? 'required' : 'optional'}</span></td>
              <td>${v.secret ? '<span class="badge text-bg-danger"><i class="bi bi-shield-lock"></i> secret</span>' : '<span class="text-body-secondary">no</span>'}</td>
              <td class="small text-body-secondary">${esc(arr(v.usedIn).join(', ') || '—')}</td>
            </tr>`).join('')
          + '</tbody></table></div><p class="small text-body-secondary mt-1 mb-0"><i class="bi bi-info-circle"></i> Names only — values are never captured.</p>'
        : EMPTY_NOTE('No environment variables detected.')) + '</div>');

    return card(parts.join(''));
  }

  /* ---------- 6. Build Instructions ---------- */
  function buildSection(dr) {
    const b = dr.build || {};
    const order = ['install', 'compile', 'build', 'migrate', 'seed', 'lint', 'test', 'run', 'start', 'docker'];
    const items = order.filter(k => b[k]).map(k =>
      `<li class="mb-1"><span class="badge text-bg-secondary me-2">${esc(k)}</span><code>${esc(b[k])}</code></li>`).join('');
    const body = SECTION('Build Instructions')
      + (items ? `<ul class="list-unstyled mb-0">${items}</ul>` : EMPTY_NOTE('No build commands detected.'));
    return card(body);
  }

  /* ---------- 7. External Services ---------- */
  function externalServicesSection(dr) {
    const list = arr(dr.externalServices);
    const rows = list.map(s => {
      const ev = arr(s.evidence).length ? arr(s.evidence) : (s.evidence ? [s.evidence] : []);
      const evChips = ev.map(e => chip(e, 'text-bg-light text-dark')).join('') || '<span class="text-body-secondary small">—</span>';
      return `<div class="mb-2"><strong>${esc(s.name)}</strong> `
        + `<span class="badge text-bg-secondary">${esc(s.category || 'other')}</span><div class="mt-1">${evChips}</div></div>`;
    }).join('');
    const body = SECTION('External Services') + (rows || EMPTY_NOTE('No third-party services detected.'));
    return card(body);
  }

  /* ---------- 8. Infrastructure ---------- */
  function infraSection(dr) {
    const list = arr(dr.infra);
    const body = SECTION('Infrastructure')
      + (list.length
        ? '<div class="table-responsive"><table class="table table-sm align-middle citadel-table">'
          + '<thead><tr><th>Type</th><th>File</th><th>Detail</th></tr></thead><tbody>'
          + list.map(i => `<tr><td><span class="badge text-bg-secondary">${esc(i.type || '—')}</span></td>`
            + `<td><code>${esc(i.file || '—')}</code></td><td class="small">${esc(i.detail || '')}</td></tr>`).join('')
          + '</tbody></table></div>'
        : EMPTY_NOTE('No infrastructure or IaC artifacts detected.'));
    return card(body);
  }

  /* ---------- 9. Security Findings ---------- */
  function securitySection(dr) {
    const sec = dr.security || {};
    const cve = sec.cve || {};
    const sc = arr(sec.supplyChain);
    const cveBadges = ['critical', 'high', 'medium', 'low'].map(k =>
      `<span class="badge ${sevClass(k)} me-1">${cap(k)}: ${cve[k] != null ? cve[k] : 0}</span>`).join('');
    const cveItems = arr(cve.items);
    const cveTable = cveItems.length
      ? '<div class="table-responsive mb-3"><table class="table table-sm align-middle citadel-table">'
        + '<thead><tr><th>ID</th><th>Severity</th><th>Package</th><th>Version</th><th>Fixed in</th></tr></thead><tbody>'
        + cveItems.map(it => `<tr><td><code>${esc(it.id)}</code></td>`
          + `<td><span class="badge ${sevClass(it.severity)}">${esc(it.severity || '')}</span></td>`
          + `<td>${esc(it.package || '—')}</td><td>${esc(it.version || '—')}</td><td>${esc(it.fixedIn || '—')}</td></tr>`).join('')
        + '</tbody></table></div>'
      : '';
    const scTable = sc.length
      ? '<div class="table-responsive"><table class="table table-sm align-middle citadel-table">'
        + '<thead><tr><th>Severity</th><th>Title</th><th>Packages</th><th>Recommendation</th></tr></thead><tbody>'
        + sc.map(f => `<tr>
            <td><span class="badge ${sevClass(f.severity)}">${esc(f.severity || '')}</span></td>
            <td>${esc(f.title || '')}${f.detail ? '<div class="small text-body-secondary">' + esc(f.detail) + '</div>' : ''}</td>
            <td class="small">${arr(f.packages).map(p => '<code>' + esc(p) + '</code>').join(' ') || '—'}</td>
            <td class="small">${esc(f.recommendation || '')}</td>
          </tr>`).join('')
        + '</tbody></table></div>'
      : EMPTY_NOTE('No supply-chain issues detected.');
    const body = SECTION('Security Findings')
      + `<div class="mb-3">${SECTION('Known Vulnerabilities (CVE)').replace('mb-2', 'mb-1')}<div>${cveBadges}</div></div>`
      + cveTable
      + SECTION('Supply-Chain').replace('mb-2', 'mb-1')
      + scTable;
    return card(body);
  }

  /* ---------- 10. License Compliance ---------- */
  function licenseSection(dr) {
    const lic = dr.licenses || {};
    const inv = arr(lic.inventory);
    const conflicts = arr(lic.conflicts);
    const unknown = arr(lic.unknown);
    const invTable = inv.length
      ? '<div class="table-responsive mb-3"><table class="table table-sm align-middle citadel-table">'
        + '<thead><tr><th>License</th><th>Category</th><th>Count</th></tr></thead><tbody>'
        + inv.map(l => `<tr><td>${esc(l.license || 'unknown')}${l.spdx ? ' <span class="text-body-secondary small">' + esc(l.spdx) + '</span>' : ''}</td>`
          + `<td><span class="badge ${licenseCatClass(l.category)}">${esc(l.category || 'unknown')}</span></td>`
          + `<td>${l.count != null ? l.count : 0}</td></tr>`).join('')
        + '</tbody></table></div>'
      : EMPTY_NOTE('No license inventory available.');
    const conflictBlock = conflicts.length
      ? `<div class="alert alert-warning py-2 mb-3"><strong><i class="bi bi-exclamation-triangle"></i> License conflicts</strong><ul class="mb-0 mt-1">`
        + conflicts.map(c => `<li>${esc(c.license || '')} — ${esc(c.reason || '')} `
          + `<span class="badge ${sevClass(c.severity)}">${esc(c.severity || '')}</span> `
          + `<span class="small text-body-secondary">${arr(c.packages).map(esc).join(', ')}</span></li>`).join('')
        + '</ul></div>'
      : '<p class="text-success small mb-3"><i class="bi bi-check-circle"></i> No license conflicts detected.</p>';
    const unknownBlock = unknown.length
      ? `<p class="small text-body-secondary mb-0"><i class="bi bi-question-circle"></i> ${unknown.length} package(s) with no detectable license: `
        + unknown.slice(0, 20).map(p => '<code>' + esc(p) + '</code>').join(', ')
        + (unknown.length > 20 ? ` <span>+${unknown.length - 20} more</span>` : '') + '</p>'
      : '';
    return card(SECTION('License Compliance') + invTable + conflictBlock + unknownBlock);
  }

  /* ---------- 11. Documentation ---------- */
  function docsSection(dr) {
    const docs = dr.docs || {};
    const present = arr(docs.present);
    const missing = arr(docs.missing);
    const presentChips = present.length
      ? present.map(p => `<span class="badge text-bg-success me-1 mb-1"><i class="bi bi-check2"></i> ${esc(p.topic)}`
        + (p.file ? ' <span class="opacity-75">(' + esc(p.file) + ')</span>' : '') + '</span>').join('')
      : EMPTY_NOTE('No documentation topics detected.');
    const missingChips = missing.length
      ? '<div class="mt-2">' + missing.map(m => `<span class="badge text-bg-secondary me-1 mb-1"><i class="bi bi-x"></i> ${esc(m)}</span>`).join('') + '</div>'
      : '';
    const body = SECTION('Documentation')
      + '<div>' + presentChips + '</div>'
      + (missing.length ? '<div class="small text-body-secondary mt-2 mb-1">Missing topics:</div>' + missingChips : '');
    return card(body);
  }

  /* ---------- 12. Missing Information ---------- */
  function missingSection(dr) {
    const m = dr.missing || {};
    const buckets = [
      ['Documentation', m.documentation],
      ['Environment variables', m.envVars],
      ['Deployment steps', m.deploymentSteps],
      ['Dependencies', m.dependencies],
      ['Runtime', m.runtime],
      ['Configuration', m.configuration]
    ];
    const blocks = buckets.filter(b => arr(b[1]).length).map(b =>
      `<div class="col-md-6 mb-2"><div class="small text-body-secondary fw-bold mb-1">${esc(b[0])}</div>`
      + '<ul class="mb-0">' + arr(b[1]).map(x => `<li>${esc(x)}</li>`).join('') + '</ul></div>').join('');
    const body = SECTION('Missing Information')
      + (blocks ? `<div class="row g-2">${blocks}</div>` : '<p class="text-success small mb-0"><i class="bi bi-check-circle"></i> No critical information gaps identified.</p>');
    return card(body);
  }

  /* ---------- 13. Recommendations ---------- */
  function recommendationsSection(dr) {
    const r = dr.recommendations || {};
    const groups = [
      ['Immediate', r.immediate, 'text-danger'],
      ['High priority', r.high, 'text-warning'],
      ['Medium priority', r.medium, 'text-info'],
      ['Low priority', r.low, 'text-body-secondary'],
      ['Best practices', r.bestPractices, 'text-success']
    ];
    const blocks = groups.filter(g => arr(g[1]).length).map(g =>
      `<div class="mb-2"><div class="small fw-bold ${g[2]} mb-1">${esc(g[0])}</div>`
      + '<ul class="mb-0">' + arr(g[1]).map(x => `<li>${esc(x)}</li>`).join('') + '</ul></div>').join('');
    const body = SECTION('Recommendations')
      + (blocks || '<p class="text-success small mb-0"><i class="bi bi-check-circle"></i> No outstanding recommendations.</p>');
    return card(body);
  }

  /* ===================================================================== *
   *  EXPORTERS
   * ===================================================================== */

  function mdTable(headers, rows) {
    let out = '| ' + headers.join(' | ') + ' |\n';
    out += '|' + headers.map(() => '---').join('|') + '|\n';
    rows.forEach(r => { out += '| ' + r.map(c => String(c == null ? '' : c).replace(/\|/g, '\\|').replace(/\n/g, ' ')).join(' | ') + ' |\n'; });
    return out;
  }
  function depToRow(d) {
    return [d.name, d.version || '', d.source || '', d.license || 'unknown', d.status || 'unknown', d.risk || 'none'];
  }

  function markdown(report) {
    const dr = report && report.depreview;
    if (!dr) return '# Dependency Review\n\n_No dependency review data available._\n';
    const s = dr.scores || {};
    const st = dr.stack || {};
    const deps = dr.dependencies || {};
    const rt = dr.runtime || {};
    const b = dr.build || {};
    const sec = dr.security || {};
    const lic = dr.licenses || {};
    const docs = dr.docs || {};
    const out = [];
    out.push('# Dependency Review & Runtime Requirements\n');
    if (dr.generatedAt) out.push('_Generated ' + dr.generatedAt + '_\n');

    // --- Executive Summary (report 1) ---
    out.push('## Executive Summary\n');
    out.push(mdTable(['Metric', 'Score'], [
      ['Health', s.health], ['Security', s.security], ['Readiness', s.readiness],
      ['Documentation', s.docs], ['Risk', (s.risk == null ? '' : s.risk) + (s.riskBand ? ' (' + s.riskBand + ')' : '')],
      ['Confidence', s.confidence == null ? '' : s.confidence + '%']
    ]));
    out.push('');
    out.push(mdVerdict(dr) + '\n');

    // --- Technology Stack ---
    out.push('## Technology Stack\n');
    out.push(mdStack(st) + '\n');

    // --- Production Dependencies (report 2: SBOM / inventory) ---
    out.push('## Production Dependencies (SBOM / Inventory)\n');
    const prod = arr(deps.prod);
    out.push(prod.length ? mdTable(['Name', 'Version', 'Source', 'License', 'Status', 'Risk'], prod.map(depToRow)) : '_None detected._\n');
    out.push('');
    out.push('## Development Dependencies\n');
    const dev = arr(deps.dev);
    out.push(dev.length ? mdTable(['Name', 'Version', 'Source', 'License', 'Status', 'Risk'], dev.map(depToRow)) : '_None detected._\n');
    out.push('');

    // --- Runtime Requirements (report 3) ---
    out.push('## Runtime Requirements\n');
    out.push(mdRuntime(rt, st));

    // --- Build Instructions / Deployment Readiness (report 8) ---
    out.push('## Build Instructions & Deployment Readiness\n');
    out.push(mdBuild(b));
    out.push('Deployment readiness score: **' + (s.readiness == null ? 'n/a' : s.readiness + '/100') + '**.\n');

    // --- External Services (report 5: third-party services) ---
    out.push('## Third-Party / External Services\n');
    const ext = arr(dr.externalServices);
    if (ext.length) ext.forEach(x => out.push('- **' + x.name + '** (' + (x.category || 'other') + ')' + (arr(x.evidence).length ? ' — ' + arr(x.evidence).join(', ') : (x.evidence ? ' — ' + x.evidence : ''))));
    else out.push('_No external services detected._');
    out.push('');

    // --- Infrastructure ---
    out.push('## Infrastructure\n');
    const infra = arr(dr.infra);
    out.push(infra.length ? mdTable(['Type', 'File', 'Detail'], infra.map(i => [i.type, i.file, i.detail])) : '_None detected._\n');
    out.push('');

    // --- Security Findings (report 4: dependency security) ---
    out.push('## Dependency Security\n');
    const cve = sec.cve || {};
    out.push('CVEs — critical: ' + (cve.critical || 0) + ', high: ' + (cve.high || 0) + ', medium: ' + (cve.medium || 0) + ', low: ' + (cve.low || 0) + '\n');
    const sc = arr(sec.supplyChain);
    if (sc.length) {
      out.push(mdTable(['Severity', 'Title', 'Packages', 'Recommendation'],
        sc.map(f => [f.severity, f.title, arr(f.packages).join(', '), f.recommendation])));
    } else out.push('_No supply-chain issues detected._');
    out.push('');

    // --- License Compliance (report 6) ---
    out.push('## License Compliance\n');
    const inv = arr(lic.inventory);
    if (inv.length) out.push(mdTable(['License', 'Category', 'Count'], inv.map(l => [l.license, l.category, l.count])));
    else out.push('_No license inventory._');
    out.push('');
    const conflicts = arr(lic.conflicts);
    if (conflicts.length) {
      out.push('### License Conflicts\n');
      conflicts.forEach(c => out.push('- **' + (c.license || '') + '** (' + (c.severity || '') + ') — ' + (c.reason || '') + ' [' + arr(c.packages).join(', ') + ']'));
      out.push('');
    }
    if (arr(lic.unknown).length) out.push('Packages with unknown license: ' + arr(lic.unknown).join(', ') + '\n');

    // --- Documentation ---
    out.push('## Documentation\n');
    const present = arr(docs.present);
    if (present.length) out.push('Present: ' + present.map(p => p.topic + (p.file ? ' (' + p.file + ')' : '')).join(', '));
    if (arr(docs.missing).length) out.push('Missing: ' + arr(docs.missing).join(', '));
    if (!present.length && !arr(docs.missing).length) out.push('_No documentation analysis available._');
    out.push('');

    // --- Missing Information (report 7) ---
    out.push('## Missing Requirements / Information\n');
    out.push(mdMissing(dr.missing || {}));

    // --- Recommendations ---
    out.push('## Recommendations\n');
    out.push(mdRecommendations(dr.recommendations || {}));

    out.push('\n_Generated by CITADEL — Dependency Review. Environment variable values are never captured; names only._\n');
    return out.join('\n');
  }

  function mdVerdict(dr) {
    const s = dr.scores || {};
    const band = String(s.riskBand || '').toLowerCase();
    if (band === 'critical' || band === 'high') return '**Verdict:** Not deployment-ready — ' + band + ' supply-chain risk.';
    if (band === 'moderate' || (Number(s.readiness) || 0) < 60) return '**Verdict:** Conditionally deployable — moderate risk.';
    return '**Verdict:** Deployment-ready — low risk and good readiness.';
  }
  function mdStack(st) {
    const lines = [];
    const j = (label, a, fmt) => { const v = arr(a).map(fmt).filter(Boolean).join(', '); if (v) lines.push('- **' + label + ':** ' + v); };
    j('Languages', st.languages, l => (l.name || '') + (l.primary ? ' (primary)' : ''));
    j('Frameworks', st.frameworks, f => (f.name || '') + (f.version ? ' ' + f.version : ''));
    j('Runtimes', st.runtimes, r => (r.name || '') + (r.version ? ' ' + r.version : ''));
    j('SDKs', st.sdks, r => (r.name || '') + (r.version ? ' ' + r.version : ''));
    j('Compilers', st.compilers, r => (r.name || ''));
    j('Package managers', st.packageManagers, p => (p.name || '') + (p.lockfile ? ' (' + p.lockfile + ')' : ''));
    j('Databases', st.databases, d => d);
    j('Infrastructure', st.infra, d => d);
    j('Cloud', st.cloud, d => d);
    return lines.length ? lines.join('\n') : '_No stack detected._';
  }
  function mdRuntime(rt, st) {
    const out = [];
    const services = arr(rt.services);
    if (services.length) {
      out.push('### Required Services\n');
      out.push(mdTable(['Type', 'Name', 'Required'], services.map(s => [s.type, s.name, s.required ? 'yes' : 'no'])));
    }
    const dbs = arr(rt.databases);
    if (dbs.length) {
      out.push('### Databases\n');
      out.push(mdTable(['Engine', 'Min version', 'Migration tool', 'Connection var'], dbs.map(d => [d.engine, d.minVersion, d.migrationTool, d.connectionVar])));
    }
    const ports = arr(rt.ports);
    if (ports.length) {
      out.push('### Ports\n');
      out.push(mdTable(['Port', 'Direction', 'Protocol'], ports.map(p => [p.port, p.direction, p.protocol])));
    }
    const envVars = arr(rt.envVars);
    out.push('### Environment Variables (names only)\n');
    if (envVars.length) out.push(mdTable(['Name', 'Category', 'Required', 'Secret', 'Used in'],
      envVars.map(v => [v.name, v.category, v.required ? 'yes' : 'no', v.secret ? 'yes' : 'no', arr(v.usedIn).join(', ')])));
    else out.push('_None detected._');
    out.push('');
    return out.join('\n');
  }
  function mdBuild(b) {
    const order = ['install', 'compile', 'build', 'migrate', 'seed', 'lint', 'test', 'run', 'start', 'docker'];
    const lines = order.filter(k => b[k]).map(k => '- **' + k + ':** `' + b[k] + '`');
    return (lines.length ? lines.join('\n') : '_No build commands detected._') + '\n';
  }
  function mdMissing(m) {
    const buckets = [
      ['Documentation', m.documentation], ['Environment variables', m.envVars],
      ['Deployment steps', m.deploymentSteps], ['Dependencies', m.dependencies],
      ['Runtime', m.runtime], ['Configuration', m.configuration]
    ].filter(b => arr(b[1]).length);
    if (!buckets.length) return '_No critical information gaps identified._\n';
    return buckets.map(b => '### ' + b[0] + '\n' + arr(b[1]).map(x => '- ' + x).join('\n')).join('\n\n') + '\n';
  }
  function mdRecommendations(r) {
    const groups = [
      ['Immediate', r.immediate], ['High priority', r.high], ['Medium priority', r.medium],
      ['Low priority', r.low], ['Best practices', r.bestPractices]
    ].filter(g => arr(g[1]).length);
    if (!groups.length) return '_No outstanding recommendations._\n';
    return groups.map(g => '### ' + g[0] + '\n' + arr(g[1]).map(x => '- ' + x).join('\n')).join('\n\n') + '\n';
  }

  /* ---------- HTML (standalone, self-contained) ---------- */
  function html(report) {
    const dr = report && report.depreview;
    const title = 'CITADEL Dependency Review';
    if (!dr) {
      return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' + esc(title)
        + '</title></head><body><h1>' + esc(title) + '</h1><p>No dependency review data available.</p></body></html>';
    }
    const s = dr.scores || {};
    const st = dr.stack || {};
    const deps = dr.dependencies || {};
    const rt = dr.runtime || {};
    const b = dr.build || {};
    const sec = dr.security || {};
    const lic = dr.licenses || {};
    const docs = dr.docs || {};

    const htmlTable = (headers, rows) => {
      if (!rows.length) return '<p class="muted">None detected.</p>';
      return '<table><thead><tr>' + headers.map(h => '<th>' + esc(h) + '</th>').join('') + '</tr></thead><tbody>'
        + rows.map(r => '<tr>' + r.map(c => '<td>' + esc(c == null ? '' : c) + '</td>').join('') + '</tr>').join('')
        + '</tbody></table>';
    };
    const parts = [];
    parts.push('<h1>' + esc(title) + '</h1>');
    if (dr.generatedAt) parts.push('<p class="muted">Generated ' + esc(dr.generatedAt) + '</p>');

    parts.push('<h2>Executive Summary</h2>');
    parts.push('<div class="tiles">'
      + [['Health', s.health], ['Security', s.security], ['Readiness', s.readiness], ['Docs', s.docs],
         ['Risk', (s.risk == null ? '—' : s.risk) + (s.riskBand ? ' (' + s.riskBand + ')' : '')],
         ['Confidence', s.confidence == null ? '—' : s.confidence + '%']]
        .map(t => '<div class="tile"><div class="tv">' + esc(t[1] == null ? '—' : t[1]) + '</div><div class="tl">' + esc(t[0]) + '</div></div>').join('')
      + '</div>');
    parts.push('<p>' + esc(mdVerdict(dr).replace('**Verdict:** ', '')) + '</p>');

    parts.push('<h2>Technology Stack</h2>');
    parts.push('<ul>' + mdStack(st).split('\n').filter(l => l.indexOf('- ') === 0)
      .map(l => '<li>' + esc(l.replace(/^- \*\*/, '').replace(/\*\*/g, '')) + '</li>').join('') + '</ul>');

    parts.push('<h2>Production Dependencies</h2>');
    parts.push(htmlTable(['Name', 'Version', 'Source', 'License', 'Status', 'Risk'], arr(deps.prod).map(depToRow)));
    parts.push('<h2>Development Dependencies</h2>');
    parts.push(htmlTable(['Name', 'Version', 'Source', 'License', 'Status', 'Risk'], arr(deps.dev).map(depToRow)));

    parts.push('<h2>Runtime Requirements</h2>');
    parts.push('<h3>Required Services</h3>');
    parts.push(htmlTable(['Type', 'Name', 'Required'], arr(rt.services).map(x => [x.type, x.name, x.required ? 'yes' : 'no'])));
    parts.push('<h3>Databases</h3>');
    parts.push(htmlTable(['Engine', 'Min version', 'Migration tool', 'Connection var'], arr(rt.databases).map(d => [d.engine, d.minVersion, d.migrationTool, d.connectionVar])));
    parts.push('<h3>Ports</h3>');
    parts.push(htmlTable(['Port', 'Direction', 'Protocol'], arr(rt.ports).map(p => [p.port, p.direction, p.protocol])));
    parts.push('<h3>Environment Variables (names only)</h3>');
    parts.push(htmlTable(['Name', 'Category', 'Required', 'Secret', 'Used in'], arr(rt.envVars).map(v => [v.name, v.category, v.required ? 'yes' : 'no', v.secret ? 'yes' : 'no', arr(v.usedIn).join(', ')])));

    parts.push('<h2>Build Instructions</h2>');
    const order = ['install', 'compile', 'build', 'migrate', 'seed', 'lint', 'test', 'run', 'start', 'docker'];
    const buildItems = order.filter(k => b[k]);
    parts.push(buildItems.length ? '<ul>' + buildItems.map(k => '<li><strong>' + esc(k) + ':</strong> <code>' + esc(b[k]) + '</code></li>').join('') + '</ul>' : '<p class="muted">No build commands detected.</p>');

    parts.push('<h2>External Services</h2>');
    const ext = arr(dr.externalServices);
    parts.push(ext.length ? '<ul>' + ext.map(x => '<li><strong>' + esc(x.name) + '</strong> (' + esc(x.category || 'other') + ')</li>').join('') + '</ul>' : '<p class="muted">None detected.</p>');

    parts.push('<h2>Infrastructure</h2>');
    parts.push(htmlTable(['Type', 'File', 'Detail'], arr(dr.infra).map(i => [i.type, i.file, i.detail])));

    parts.push('<h2>Security Findings</h2>');
    const cve = sec.cve || {};
    parts.push('<p>CVEs — critical: ' + esc(cve.critical || 0) + ', high: ' + esc(cve.high || 0) + ', medium: ' + esc(cve.medium || 0) + ', low: ' + esc(cve.low || 0) + '</p>');
    parts.push(htmlTable(['Severity', 'Title', 'Packages', 'Recommendation'], arr(sec.supplyChain).map(f => [f.severity, f.title, arr(f.packages).join(', '), f.recommendation])));

    parts.push('<h2>License Compliance</h2>');
    parts.push(htmlTable(['License', 'Category', 'Count'], arr(lic.inventory).map(l => [l.license, l.category, l.count])));
    if (arr(lic.conflicts).length) {
      parts.push('<h3>Conflicts</h3><ul>' + arr(lic.conflicts).map(c => '<li><strong>' + esc(c.license || '') + '</strong> (' + esc(c.severity || '') + ') — ' + esc(c.reason || '') + '</li>').join('') + '</ul>');
    }
    if (arr(lic.unknown).length) parts.push('<p class="muted">Unknown license: ' + esc(arr(lic.unknown).join(', ')) + '</p>');

    parts.push('<h2>Documentation</h2>');
    const present = arr(docs.present);
    parts.push('<p>Present: ' + esc(present.map(p => p.topic).join(', ') || 'none') + '</p>');
    if (arr(docs.missing).length) parts.push('<p class="muted">Missing: ' + esc(arr(docs.missing).join(', ')) + '</p>');

    parts.push('<h2>Missing Information</h2>');
    const m = dr.missing || {};
    const mBuckets = [['Documentation', m.documentation], ['Environment variables', m.envVars], ['Deployment steps', m.deploymentSteps], ['Dependencies', m.dependencies], ['Runtime', m.runtime], ['Configuration', m.configuration]].filter(x => arr(x[1]).length);
    parts.push(mBuckets.length ? mBuckets.map(x => '<h3>' + esc(x[0]) + '</h3><ul>' + arr(x[1]).map(v => '<li>' + esc(v) + '</li>').join('') + '</ul>').join('') : '<p class="muted">No critical gaps identified.</p>');

    parts.push('<h2>Recommendations</h2>');
    const rec = dr.recommendations || {};
    const rGroups = [['Immediate', rec.immediate], ['High priority', rec.high], ['Medium priority', rec.medium], ['Low priority', rec.low], ['Best practices', rec.bestPractices]].filter(g => arr(g[1]).length);
    parts.push(rGroups.length ? rGroups.map(g => '<h3>' + esc(g[0]) + '</h3><ul>' + arr(g[1]).map(v => '<li>' + esc(v) + '</li>').join('') + '</ul>').join('') : '<p class="muted">None.</p>');

    const style = 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#1a1a1a;}'
      + 'h1{border-bottom:2px solid #ddd;padding-bottom:.3rem;}h2{margin-top:2rem;border-bottom:1px solid #eee;padding-bottom:.2rem;}h3{margin-top:1.2rem;}'
      + 'table{border-collapse:collapse;width:100%;margin:.5rem 0;}th,td{border:1px solid #ddd;padding:.4rem .6rem;text-align:left;font-size:.9rem;}th{background:#f5f5f5;}'
      + 'code{background:#f5f5f5;padding:.1rem .3rem;border-radius:3px;font-size:.85rem;}.muted{color:#777;}'
      + '.tiles{display:flex;flex-wrap:wrap;gap:.5rem;}.tile{border:1px solid #ddd;border-radius:6px;padding:.6rem 1rem;text-align:center;min-width:90px;}'
      + '.tile .tv{font-size:1.3rem;font-weight:700;}.tile .tl{font-size:.75rem;text-transform:uppercase;color:#777;}';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width, initial-scale=1">'
      + '<title>' + esc(title) + '</title><style>' + style + '</style></head><body>'
      + parts.join('\n') + '</body></html>';
  }

  /* ---------- CSV (dependency inventory) ---------- */
  function csvCell(s) { return '"' + String(s == null ? '' : s).replace(/"/g, '""') + '"'; }
  function csv(report) {
    const dr = report && report.depreview;
    const header = ['name', 'version', 'ecosystem', 'type', 'direct', 'source', 'license', 'status', 'risk'];
    const rows = [header];
    const deps = (dr && dr.dependencies) || {};
    arr(deps.prod).concat(arr(deps.dev)).forEach(d => {
      rows.push([d.name, d.version, d.ecosystem, d.type, d.direct ? 'yes' : 'no', d.source, d.license, d.status, d.risk]);
    });
    return rows.map(r => r.map(csvCell).join(',')).join('\r\n');
  }

  /* ---------- JSON ---------- */
  function json(report) {
    const dr = report && report.depreview;
    return JSON.stringify(dr == null ? null : dr, null, 2);
  }

  CITADEL.depreviewReport = {
    render: render,
    markdown: markdown,
    html: html,
    csv: csv,
    json: json
  };
})(typeof window !== 'undefined' ? window : this);
