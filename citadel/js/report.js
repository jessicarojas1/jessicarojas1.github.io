/* CITADEL — Report & Export Engine
 * Renders the scan report into the DOM and produces exportable artifacts
 * (JSON report, CycloneDX SBOM, Markdown summary, printable PDF).
 * window.CITADEL.report
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const charts = {};
  let current = null;
  let aiOn = false;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  const SEV_ORDER = ['critical', 'high', 'medium', 'low', 'info'];
  const SEV_COLOR = { critical: '#dc3545', high: '#fd7e14', medium: '#ffc107', low: '#0dcaf0', info: '#6c757d' };
  function $(id) { return document.getElementById(id); }
  function destroyChart(k) { if (charts[k]) { charts[k].destroy(); delete charts[k]; } }

  function render(report) {
    current = report;
    renderScorecard(report);
    renderReport(report);
    renderAiFix(report);
    renderOverview(report);
    renderFindings(report);
    renderCompliance(report);
    renderSbom(report);
    renderBinary(report);
    renderQuality(report);
    renderDeploy(report);
    renderExport(report);
    $('findings-count').textContent = report.findings.length;
  }

  /* ---------- Scorecard ---------- */
  function renderScorecard(r) {
    const s = r.scoring;
    const gradeClass = 'grade-' + s.grade.toLowerCase();
    const ring = (label, val, hint) => `
      <div class="score-ring-card">
        <div class="score-ring" style="--val:${val}">
          <span class="score-num">${val}</span>
        </div>
        <div class="score-label">${esc(label)}</div>
        <div class="score-hint">${esc(hint)}</div>
      </div>`;
    $('scorecard').innerHTML = `
      <div class="grade-badge ${gradeClass}">
        <div class="grade-letter">${s.grade}</div>
        <div class="grade-sub">Security<br>Grade</div>
      </div>
      <div class="score-rings">
        ${ring('Security', s.security, 'Weighted by severity & density')}
        ${ring('Quality', s.quality, 'Maintainability index')}
        ${ring('Overall', s.overall, 'Composite posture')}
      </div>
      <div class="scorecard-stats">
        <div><span class="sc-val">${r.meta.fileCount}</span><span class="sc-lbl">Files</span></div>
        <div><span class="sc-val">${(r.quality.loc).toLocaleString()}</span><span class="sc-lbl">Lines of code</span></div>
        <div><span class="sc-val">${r.languages.languages.length}</span><span class="sc-lbl">Languages</span></div>
        <div><span class="sc-val">${r.sbom.components.length}</span><span class="sc-lbl">Dependencies</span></div>
        <div><span class="sc-val text-danger">${s.sev.critical + s.sev.high}</span><span class="sc-lbl">Critical+High</span></div>
        <div><span class="sc-val">${r.posture.filter(p => p.findings > 0).length}</span><span class="sc-lbl">Frameworks impacted</span></div>
        <div><span class="sc-val">${r.meta && r.meta.engine === 'deep' ? 'Deep' : 'Quick'}</span><span class="sc-lbl">Scan mode</span></div>
      </div>`;
  }

  /* ---------- Overview ---------- */
  function renderOverview(r) {
    const s = r.scoring.sev;
    $('tab-overview').innerHTML = `
      <div class="row g-4">
        <div class="col-md-4">
          <div class="card citadel-card h-100"><div class="card-body">
            <h6 class="card-subtitle mb-3 text-body-secondary text-uppercase small fw-bold">Findings by severity</h6>
            <canvas id="chart-sev" height="200"></canvas>
          </div></div>
        </div>
        <div class="col-md-4">
          <div class="card citadel-card h-100"><div class="card-body">
            <h6 class="card-subtitle mb-3 text-body-secondary text-uppercase small fw-bold">Language composition</h6>
            <canvas id="chart-lang" height="200"></canvas>
          </div></div>
        </div>
        <div class="col-md-4">
          <div class="card citadel-card h-100"><div class="card-body">
            <h6 class="card-subtitle mb-3 text-body-secondary text-uppercase small fw-bold">Weakness categories</h6>
            <canvas id="chart-cat" height="200"></canvas>
          </div></div>
        </div>
      </div>
      <div class="row g-4 mt-1">
        <div class="col-lg-7">
          <div class="card citadel-card h-100"><div class="card-body">
            <h6 class="card-subtitle mb-3 text-body-secondary text-uppercase small fw-bold">Top compliance impact</h6>
            <div id="overview-frameworks"></div>
          </div></div>
        </div>
        <div class="col-lg-5">
          <div class="card citadel-card h-100"><div class="card-body">
            <h6 class="card-subtitle mb-3 text-body-secondary text-uppercase small fw-bold">Executive summary</h6>
            <div id="overview-summary" class="exec-summary"></div>
          </div></div>
        </div>
      </div>`;

    // severity doughnut
    destroyChart('sev');
    charts.sev = new Chart($('chart-sev'), {
      type: 'doughnut',
      data: { labels: SEV_ORDER.map(c), datasets: [{ data: SEV_ORDER.map(k => s[k] || 0), backgroundColor: SEV_ORDER.map(k => SEV_COLOR[k]), borderWidth: 0 }] },
      options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }, cutout: '62%' }
    });
    // language bar
    destroyChart('lang');
    const langs = r.languages.languages.slice(0, 8);
    charts.lang = new Chart($('chart-lang'), {
      type: 'bar',
      data: { labels: langs.map(l => l.lang), datasets: [{ data: langs.map(l => l.pct), backgroundColor: langs.map(l => l.color), borderWidth: 0 }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { callback: v => v + '%' } } } }
    });
    // category bar
    destroyChart('cat');
    const catCount = {};
    r.findings.forEach(f => { catCount[f.category] = (catCount[f.category] || 0) + 1; });
    const cats = Object.keys(catCount).map(k => ({ k, n: catCount[k] })).sort((a, b) => b.n - a.n).slice(0, 8);
    charts.cat = new Chart($('chart-cat'), {
      type: 'bar',
      data: { labels: cats.map(c => (CITADEL.frameworks.CATEGORIES[c.k] || c.k).split(' (')[0]), datasets: [{ data: cats.map(c => c.n), backgroundColor: '#0ea5e9', borderWidth: 0 }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false } } }
    });

    // top frameworks
    const top = r.posture.filter(p => p.findings > 0).slice(0, 6);
    $('overview-frameworks').innerHTML = top.length ? top.map(fwRow).join('') :
      '<p class="text-success mb-0"><i class="bi bi-check-circle"></i> No findings mapped to any framework control.</p>';

    // exec summary
    $('overview-summary').innerHTML = execSummary(r);
  }

  function fwRow(p) {
    const statusClass = p.status === 'fail' ? 'bg-danger' : p.status === 'partial' ? 'bg-warning text-dark' : 'bg-success';
    const statusTxt = p.status === 'fail' ? 'At Risk' : p.status === 'partial' ? 'Gaps' : 'OK';
    return `<div class="fw-row">
      <div class="fw-row-main">
        <a href="${esc(p.url)}" target="_blank" rel="noopener" class="fw-name">${esc(p.name)} <span class="text-body-secondary small">${esc(p.version)}</span></a>
        <span class="badge ${statusClass}">${statusTxt}</span>
      </div>
      <div class="fw-row-meta text-body-secondary small">${p.controlCount} control(s) implicated · ${p.findings} finding(s)</div>
    </div>`;
  }

  function execSummary(r) {
    const s = r.scoring;
    const ch = s.sev.critical, hi = s.sev.high;
    let verdict, cls;
    if (ch > 0) { verdict = 'Not ready for authorization'; cls = 'text-danger'; }
    else if (hi > 0) { verdict = 'Conditional — remediate high-severity items'; cls = 'text-warning'; }
    else if (s.security >= 85) { verdict = 'Strong posture'; cls = 'text-success'; }
    else { verdict = 'Moderate — review medium findings'; cls = 'text-warning'; }
    const fw = r.posture.filter(p => p.findings > 0).slice(0, 3).map(p => p.name).join(', ') || 'none';
    return `
      <p><strong class="${cls}">${esc(verdict)}.</strong></p>
      <ul class="mb-2">
        <li>Primary language: <strong>${esc(r.languages.primary)}</strong> across ${r.languages.languages.length} language(s).</li>
        <li><strong>${ch}</strong> critical and <strong>${hi}</strong> high-severity findings.</li>
        <li>Most-impacted frameworks: <strong>${esc(fw)}</strong>.</li>
        <li>Deployment signals: <strong>${r.deployment.length ? esc(r.deployment.map(d => d.tech).join(', ')) : 'none detected'}</strong>.</li>
        <li>Maintainability index: <strong>${r.quality.maintainability}/100</strong> (${r.quality.commentRatio}% comments).</li>
      </ul>
      <p class="small text-body-secondary mb-0">Address critical/high findings first, pin floating dependencies, and confirm cryptography is FIPS-validated before an ATO package.</p>`;
  }

  /* ---------- Findings ---------- */
  let showSuppressed = false;
  function renderFindings(r) {
    const all = r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity));
    const sup = CITADEL.suppress;
    const shown = showSuppressed ? all : all.filter(f => !sup.isSuppressed(f));
    r._shown = shown;
    const supN = all.length - all.filter(f => !sup.isSuppressed(f)).length;
    const filterBtns = ['all'].concat(SEV_ORDER).map(sv =>
      `<button class="btn btn-sm ${sv === 'all' ? 'btn-primary' : 'btn-outline-secondary'} finding-filter" data-sev="${sv}">${sv === 'all' ? 'All' : c(sv)} ${sv === 'all' ? '' : '(' + (r.scoring.sev[sv] || 0) + ')'}</button>`
    ).join(' ');
    const supToggle = supN > 0
      ? `<button class="btn btn-sm ${showSuppressed ? 'btn-warning text-dark' : 'btn-outline-warning'} ms-auto" id="toggle-suppressed"><i class="bi bi-eye${showSuppressed ? '-slash' : ''}"></i> ${showSuppressed ? 'Hide' : 'Show'} suppressed (${supN})</button>`
      : '';
    const rows = shown.length ? shown.map((f, i) => {
      const isSup = sup.isSuppressed(f);
      return `
      <div class="finding${isSup ? ' finding-suppressed' : ''}" data-sev="${f.severity}">
        <div class="finding-head" data-finding-toggle="${i}">
          <span class="sev-dot" style="background:${SEV_COLOR[f.severity]}"></span>
          <span class="finding-name">${esc(f.name)}${isSup ? ' <span class="badge bg-secondary">suppressed</span>' : ''}</span>
          <span class="badge sev-badge" style="background:${SEV_COLOR[f.severity]}">${f.severity}</span>
          ${f.tainted ? '<span class="badge bg-warning text-dark" title="User input flows into this sink (data-flow taint)">tainted</span>' : ''}
          <span class="text-body-secondary small ms-auto d-none d-md-inline">${esc(f.source || 'heuristic')} · ${esc(f.cwe || '')}</span>
          <i class="bi bi-chevron-down finding-chev"></i>
        </div>
        <div class="finding-body d-none" id="finding-body-${i}">
          <div class="finding-loc"><i class="bi bi-file-earmark-code"></i> ${esc(f.file || '—')}${f.line ? ':' + f.line : ''}</div>
          ${f.snippet ? `<pre class="finding-snippet">${esc(f.snippet)}</pre>` : ''}
          <div class="finding-meta">
            <span><strong>Category:</strong> ${esc(CITADEL.frameworks.CATEGORIES[f.category] || f.category)}</span>
            <span><strong>Confidence:</strong> ${esc(f.confidence || 'n/a')}</span>
            <span><strong>Source:</strong> ${esc(f.source || 'heuristic')}</span>
          </div>
          <div class="finding-fix"><i class="bi bi-wrench-adjustable"></i> ${esc(f.remediation || 'Review and remediate.')}</div>
          ${mappedControls(f.category)}
          <div class="finding-actions">
            ${aiOn ? `<button class="btn btn-sm btn-outline-primary" data-explain="${i}"><i class="bi bi-stars"></i> Explain &amp; fix (AI)</button>` : ''}
            <button class="btn btn-sm btn-outline-secondary" data-suppress="${i}"><i class="bi bi-${isSup ? 'arrow-counterclockwise' : 'slash-circle'}"></i> ${isSup ? 'Un-suppress' : 'Accept risk'}</button>
          </div>
          <div class="finding-ai d-none" id="finding-ai-${i}"></div>
        </div>
      </div>`; }).join('') :
      '<div class="empty-state"><i class="bi bi-check2-circle"></i><p>No findings to show.</p></div>';
    $('tab-findings').innerHTML = `
      <div class="d-flex flex-wrap gap-2 mb-3 align-items-center" id="finding-filters">${filterBtns}${supToggle}</div>
      <div id="findings-list">${rows}</div>`;
  }
  function shownFinding(i) { return current && current._shown ? current._shown[i] : null; }
  function toggleSuppressedView() { showSuppressed = !showSuppressed; renderFindings(current); }

  function mappedControls(cat) {
    const m = CITADEL.frameworks.MAP[cat] || {};
    const keys = Object.keys(m).slice(0, 8);
    if (!keys.length) return '';
    const chips = keys.map(k => {
      const fw = CITADEL.frameworks.CATALOG.find(f => f.id === k);
      return `<span class="ctrl-chip" title="${esc(fw ? fw.name : k)}">${esc(fw ? fw.name.split(' ')[0] : k)}: ${esc(m[k][0])}</span>`;
    }).join('');
    return `<div class="finding-controls"><span class="small text-body-secondary me-1">Maps to:</span>${chips}</div>`;
  }

  /* ---------- Compliance ---------- */
  // Set of implicated control IDs (leading token of each mapped control string).
  function implicatedSet(p) {
    const s = new Set();
    p.controls.forEach(cc => s.add(String(cc.id).split(/\s+/)[0]));
    return s;
  }
  // Full control catalog for a framework, with implicated controls highlighted.
  function fullControlsHtml(p, idx) {
    const cat = p.catalog;
    if (!cat || !cat.families) return '';
    const impl = implicatedSet(p);
    const fams = cat.families.map(fam => {
      const rows = (fam.controls || []).map(ctrl => {
        const hit = impl.has(ctrl.id);
        return `<li class="${hit ? 'ctrl-hit' : ''}"><code>${esc(ctrl.id)}</code> ${esc(ctrl.title)}${hit ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="implicated by a finding"></i>' : ''}</li>`;
      }).join('');
      return `<div class="ctrl-fam"><div class="ctrl-fam-name">${esc(fam.id)} — ${esc(fam.name)}</div><ul class="ctrl-full-list">${rows}</ul></div>`;
    }).join('');
    return `<div class="full-controls d-none" id="fwctrls-${idx}">
      ${cat.note ? `<p class="small text-body-secondary mb-2"><i class="bi bi-info-circle"></i> ${esc(cat.note)}</p>` : ''}
      ${fams}</div>`;
  }
  function renderCompliance(r) {
    const cards = r.posture.map((p, idx) => {
      const statusClass = p.status === 'fail' ? 'status-fail' : p.status === 'partial' ? 'status-partial' : 'status-pass';
      const statusTxt = p.findings === 0 ? 'No mapped findings' : p.status === 'fail' ? 'At Risk' : p.status === 'partial' ? 'Gaps Found' : 'OK';
      const ctrls = p.controls.slice(0, 6).map(cc => `<li><code>${esc(cc.id)}</code> <span class="text-body-secondary">×${cc.count}</span></li>`).join('');
      const totalLbl = p.totalControls ? `${p.controlCount} of ${p.totalControls}` : `${p.controlCount}`;
      return `<div class="col-md-6 col-xl-4">
        <div class="card citadel-card compliance-card ${statusClass} h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <h6 class="mb-0"><a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.name)}</a></h6>
            <span class="badge framework-tag">${esc(p.tag)}</span>
          </div>
          <div class="text-body-secondary small mb-2">${esc(p.version)} — ${esc(p.desc)}</div>
          <div class="compliance-status">${statusTxt}</div>
          ${p.findings ? `<div class="small text-body-secondary mb-1">${p.findings} finding(s) · ${totalLbl} control(s) implicated</div>
            <ul class="ctrl-list">${ctrls}${p.controls.length > 6 ? `<li class="text-body-secondary">+${p.controls.length - 6} more…</li>` : ''}</ul>` :
            `<div class="text-success small mb-1"><i class="bi bi-check-circle"></i> No findings implicate this framework.</div>`}
          ${p.totalControls ? `<button class="btn btn-sm btn-link p-0 ctrl-expand" data-fwctrls="${idx}">View all ${p.totalControls} controls</button>${fullControlsHtml(p, idx)}` : ''}
        </div></div>
      </div>`;
    }).join('');
    $('tab-compliance').innerHTML = `
      <p class="text-body-secondary">Each finding is cross-walked to the specific controls it implicates across <strong>${r.posture.length}</strong> frameworks (<strong>${CITADEL.frameworks.catalogTotal()}</strong> controls catalogued). Expand any framework to see its full control set with implicated controls flagged.</p>
      <div class="row g-3">${cards}</div>`;
  }

  /* ---------- SBOM ---------- */
  function renderSbom(r) {
    const comps = r.sbom.components;
    const flags = {};
    CITADEL.sbom.riskFlags(comps).forEach(f => { flags[f.component.name + f.component.version] = f.reason; });
    const rows = comps.length ? comps.map(c => {
      const flag = flags[c.name + c.version];
      return `<tr>
        <td><code>${esc(c.name)}</code></td>
        <td>${esc(c.version)}</td>
        <td><span class="badge bg-secondary">${esc(c.ecosystem)}</span></td>
        <td>${esc(c.scope)}</td>
        <td>${flag ? `<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> ${esc(flag)}</span>` : '<span class="text-success"><i class="bi bi-check"></i></span>'}</td>
      </tr>`;
    }).join('') : '<tr><td colspan="5" class="text-center text-body-secondary py-4">No dependency manifests detected.</td></tr>';
    // Live/real CVEs (from OSV in quick mode, or Trivy/Grype in deep mode)
    const cves = r.findings.filter(f => f.source === 'osv' || ((f.source === 'trivy' || f.source === 'grype') && f.category === 'deps'));
    const cveBox = cves.length
      ? `<div class="alert-cve mb-3"><i class="bi bi-shield-exclamation"></i> <strong>${cves.length} known vulnerabilit${cves.length === 1 ? 'y' : 'ies'}</strong> matched against live advisories
           (${cves.filter(f => f.severity === 'critical').length} critical, ${cves.filter(f => f.severity === 'high').length} high). See the <strong>Findings</strong> tab for details &amp; fixes.</div>`
      : (r.meta && r.meta.engine === 'deep'
          ? ''
          : '<div class="text-body-secondary small mb-3" id="osv-status"><i class="bi bi-hourglass-split"></i> Checking dependencies against the OSV.dev vulnerability database…</div>');
    $('tab-sbom').innerHTML = `
      ${cveBox}
      ${renderLicenses(r)}
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <p class="text-body-secondary mb-0">${comps.length} component(s) across ${[...new Set(comps.map(c => c.ecosystem))].length} ecosystem(s). CycloneDX 1.5 SBOM available in <strong>Export</strong>.</p>
        <button class="btn btn-sm btn-outline-primary" id="dl-sbom"><i class="bi bi-box-arrow-down"></i> Download SBOM (JSON)</button>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle citadel-table">
        <thead><tr><th>Component</th><th>Version</th><th>Ecosystem</th><th>Scope</th><th>Supply-chain</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>`;
  }

  function renderLicenses(r) {
    const lic = r.licenses;
    if (!lic || !lic.detected) return '';
    const chip = (l) => `<span class="badge ${l.copyleft ? 'bg-warning text-dark' : 'bg-success'}" title="${esc(l.file)}">${esc(l.license)}</span>`;
    return `<div class="card citadel-card mb-3"><div class="card-body py-2">
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <strong class="small"><i class="bi bi-card-checklist"></i> Licenses:</strong>
        ${lic.all.map(chip).join(' ')}
        ${lic.copyleft.length ? `<span class="small text-warning ms-1">${lic.copyleft.length} copyleft — review distribution obligations</span>` : '<span class="small text-success ms-1">all permissive</span>'}
      </div></div></div>`;
  }

  /* ---------- Binary ---------- */
  function renderBinary(r) {
    if (!r.binaries.length) {
      $('tab-binary').innerHTML = '<div class="empty-state"><i class="bi bi-cpu"></i><p>No binaries or executables ingested. Upload an <code>.exe</code>, <code>.dll</code>, <code>.so</code>, <code>.bin</code>, or archive to analyze.</p></div>';
      return;
    }
    $('tab-binary').innerHTML = r.binaries.map(b => `
      <div class="card citadel-card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <h6 class="mb-2"><i class="bi bi-file-earmark-binary"></i> ${esc(b.name)}</h6>
          <span class="badge ${b.packed ? 'bg-warning text-dark' : 'bg-secondary'}">${b.packed ? 'Likely packed' : b.kind}</span>
        </div>
        <div class="row g-2 binary-stats">
          <div class="col-6 col-md-3"><span class="bs-lbl">Format</span><span class="bs-val">${esc(b.format)}</span></div>
          <div class="col-6 col-md-3"><span class="bs-lbl">Platform</span><span class="bs-val">${esc(b.platform)}</span></div>
          <div class="col-6 col-md-3"><span class="bs-lbl">Size</span><span class="bs-val">${(b.size / 1024).toFixed(1)} KB</span></div>
          <div class="col-6 col-md-3"><span class="bs-lbl">Entropy</span><span class="bs-val">${b.entropy} / 8.0</span></div>
        </div>
        ${b.indicators.length ? `<div class="mt-3"><strong class="small">Capability indicators</strong><div class="d-flex flex-wrap gap-1 mt-1">
          ${b.indicators.map(i => `<span class="badge" style="background:${SEV_COLOR[i.severity]}">${esc(i.label)}</span>`).join('')}</div></div>` :
          '<div class="mt-2 text-success small"><i class="bi bi-check-circle"></i> No suspicious capability strings matched.</div>'}
        ${b.urls.length ? `<div class="mt-2 small"><strong>Embedded URLs:</strong> ${b.urls.slice(0, 6).map(u => esc(u)).join(', ')}${b.urls.length > 6 ? '…' : ''}</div>` : ''}
      </div></div>`).join('');
  }

  /* ---------- Quality ---------- */
  function renderQuality(r) {
    const q = r.quality;
    const metric = (lbl, val, hint) => `<div class="col-6 col-md-3"><div class="quality-metric"><div class="qm-val">${val}</div><div class="qm-lbl">${esc(lbl)}</div><div class="qm-hint">${esc(hint || '')}</div></div></div>`;
    $('tab-quality').innerHTML = `
      <div class="row g-3 mb-3">
        ${metric('Maintainability', q.maintainability + '/100', 'composite index')}
        ${metric('Lines of code', q.loc.toLocaleString(), q.codeFiles + ' code files')}
        ${metric('Comment ratio', q.commentRatio + '%', 'documentation density')}
        ${metric('Largest file', q.maxFile.toLocaleString(), 'lines')}
        ${metric('Blank lines', q.blank.toLocaleString(), '')}
        ${metric('Long files (>800)', q.longFiles, 'refactor candidates')}
        ${metric('Total files', q.totalFiles.toLocaleString(), 'ingested')}
        ${metric('Code lines', q.codeLines.toLocaleString(), 'non-comment/non-blank')}
      </div>
      <div class="card citadel-card"><div class="card-body">
        <h6 class="text-uppercase text-body-secondary small fw-bold mb-2">Interpretation</h6>
        <ul class="mb-0">
          <li>${q.commentRatio < 5 ? '<span class="text-warning">Low comment density</span> — consider documenting complex logic.' : 'Comment density is healthy.'}</li>
          <li>${q.longFiles > 0 ? `<span class="text-warning">${q.longFiles} file(s) exceed 800 lines</span> — candidates for modularization.` : 'No oversized files detected.'}</li>
          <li>${q.maintainability >= 80 ? 'Maintainability index indicates a well-structured codebase.' : 'Maintainability index suggests room for refactoring.'}</li>
        </ul>
      </div></div>`;
  }

  /* ---------- Deployment ---------- */
  function renderDeploy(r) {
    const d = r.deployment;
    const items = d.length ? d.map(s => `
      <div class="col-md-6 col-lg-4"><div class="card citadel-card deploy-card h-100"><div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-1"><i class="bi bi-box-seam fs-5 text-info"></i><h6 class="mb-0">${esc(s.tech)}</h6></div>
        <div class="small text-body-secondary">${esc(s.detail)}</div>
        <div class="small mt-1"><code>${esc(s.file)}</code></div>
      </div></div></div>`).join('') :
      '<div class="col-12"><div class="empty-state"><i class="bi bi-rocket"></i><p>No deployment or IaC artifacts detected (Dockerfile, Terraform, K8s, CI/CD, etc.).</p></div></div>';
    const how = d.length ?
      `This project is deployed via <strong>${esc(d.map(x => x.tech).join(', '))}</strong>. ${d.some(x => /Docker|Kubernetes|Helm/.test(x.tech)) ? 'Containerized — portable to Azure Gov / AWS GovCloud.' : ''}` :
      'No deployment automation found; CITADEL itself ships Azure Gov &amp; AWS GovCloud IaC under <code>deploy/</code>.';
    $('tab-deploy').innerHTML = `<p class="text-body-secondary">${how}</p><div class="row g-3">${items}</div>`;
  }

  /* ---------- Export ---------- */
  function renderExport(r) {
    $('tab-export').innerHTML = `
      <div class="row g-3">
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-json"><i class="bi bi-filetype-json"></i><span>Full report<br><small>JSON</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-sarif"><i class="bi bi-shield-check"></i><span>SARIF<br><small>2.1.0 · code scanning</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-sbom"><i class="bi bi-box-seam"></i><span>SBOM<br><small>CycloneDX 1.5</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-poam"><i class="bi bi-list-check"></i><span>POA&amp;M<br><small>CSV</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-ssp"><i class="bi bi-file-earmark-text"></i><span>Control appendix<br><small>SSP · Markdown</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-junit"><i class="bi bi-filetype-xml"></i><span>JUnit<br><small>CI test report</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-prcomment"><i class="bi bi-chat-left-text"></i><span>PR comment<br><small>Markdown</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-md"><i class="bi bi-filetype-md"></i><span>Summary<br><small>Markdown</small></span></button></div>
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-pdf"><i class="bi bi-printer"></i><span>Printable<br><small>PDF / print</small></span></button></div>
      </div>
      <div class="card citadel-card mt-4"><div class="card-body">
        <h6 class="text-uppercase text-body-secondary small fw-bold mb-2">Report metadata</h6>
        <pre class="export-preview">${esc(JSON.stringify({ scannedAt: r.meta.scannedAt, files: r.meta.fileCount, bytes: r.meta.totalBytes, grade: r.scoring.grade, security: r.scoring.security, findings: r.findings.length, components: r.sbom.components.length, frameworks: r.posture.length }, null, 2))}</pre>
      </div></div>`;
  }

  /* ---------- Exporters ---------- */
  function download(name, content, type) {
    const blob = new Blob([content], { type: type || 'application/octet-stream' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name; document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1000);
  }
  function exportJson() { download('citadel-report.json', JSON.stringify(current, null, 2), 'application/json'); }
  function exportSbom() { download('citadel-sbom.cdx.json', JSON.stringify(current.sbom.doc, null, 2), 'application/json'); }
  function exportMarkdown() {
    const r = current, s = r.scoring;
    let md = `# CITADEL Security & Compliance Report\n\n`;
    md += `**Scanned:** ${r.meta.scannedAt}  \n**Grade:** ${s.grade} · Security ${s.security}/100 · Quality ${s.quality}/100  \n`;
    md += `**Files:** ${r.meta.fileCount} · **LOC:** ${r.quality.loc} · **Primary language:** ${r.languages.primary}\n\n`;
    md += `## Severity Summary\n\n| Severity | Count |\n|---|---|\n`;
    SEV_ORDER.forEach(k => { md += `| ${c(k)} | ${s.sev[k] || 0} |\n`; });
    md += `\n## Compliance Posture\n\n| Framework | Version | Status | Controls | Findings |\n|---|---|---|---|---|\n`;
    r.posture.forEach(p => { md += `| ${p.name} | ${p.version} | ${p.findings ? (p.status) : 'clean'} | ${p.controlCount} | ${p.findings} |\n`; });
    md += `\n## Findings\n\n`;
    r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity)).forEach(f => {
      md += `- **[${f.severity.toUpperCase()}]** ${f.name} (${f.cwe || ''}) — \`${f.file || ''}${f.line ? ':' + f.line : ''}\`\n  - ${f.remediation || ''}\n`;
    });
    md += `\n## SBOM (${r.sbom.components.length} components)\n\n`;
    r.sbom.components.forEach(cc => { md += `- ${cc.name}@${cc.version} (${cc.ecosystem})\n`; });
    md += `\n_Generated by CITADEL — heuristic analysis for triage & education._\n`;
    download('citadel-report.md', md, 'text/markdown');
  }
  function exportPdf() { window.print(); }

  function c(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

  function exportSarif() {
    if (!CITADEL.sarif) return;
    download('citadel-results.sarif', JSON.stringify(CITADEL.sarif.fromReport(current), null, 2), 'application/json');
  }

  function csvCell(s) { return '"' + String(s == null ? '' : s).replace(/"/g, '""') + '"'; }
  // POA&M — Plan of Action & Milestones (CSV), one row per finding.
  function exportPoam() {
    const r = current;
    const rows = [['POAM ID', 'Weakness', 'CWE', 'Severity', 'Category', 'Source', 'Location', 'Recommended Correction', 'Status', 'Identified']];
    r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity)).forEach((f, i) => {
      rows.push([
        'POAM-' + String(i + 1).padStart(4, '0'),
        f.name, f.cwe || '', f.severity, CITADEL.frameworks.CATEGORIES[f.category] || f.category,
        f.source || 'heuristic', (f.file || '') + (f.line ? ':' + f.line : ''),
        f.remediation || '', 'Open', r.meta.scannedAt
      ]);
    });
    download('citadel-poam.csv', rows.map(row => row.map(csvCell).join(',')).join('\r\n'), 'text/csv');
  }

  // SSP-style control implementation appendix (Markdown) from the compliance posture.
  function exportSsp() {
    const r = current;
    let md = `# CITADEL — Control Impact Appendix\n\n`;
    md += `_Generated ${r.meta.scannedAt} · Grade ${r.scoring.grade} · Security ${r.scoring.security}/100_\n\n`;
    md += `This appendix maps scanner findings to the security controls they implicate across ${r.posture.length} frameworks. Use it to seed an SSP gap analysis or a POA&M.\n\n`;
    r.posture.forEach(p => {
      md += `## ${p.name} ${p.version} — ${p.findings ? p.status.toUpperCase() : 'no findings'}\n\n`;
      if (!p.controls.length) { md += `_No findings implicate this framework._\n\n`; return; }
      md += `| Control | Findings |\n|---|---|\n`;
      p.controls.forEach(cc => { md += `| ${cc.id} | ${cc.count} |\n`; });
      md += `\n`;
    });
    download('citadel-control-appendix.md', md, 'text/markdown');
  }

  function setAi(on) { aiOn = !!on; }

  /* ---------- History panel ---------- */
  function renderHistory(targetId) {
    const el = $(targetId || 'tab-history');
    if (!el) return;
    const hist = CITADEL.history.list();
    if (!hist.length) { el.innerHTML = '<div class="empty-state"><i class="bi bi-clock-history"></i><p>No scan history yet. Each scan you run is recorded here (locally) so you can track trend and compare runs.</p></div>'; return; }
    const opts = hist.map(h => `<option value="${h.id}">${esc(h.label)} — ${h.grade} (${h.security})</option>`).join('');
    const rows = hist.map(h => `<tr>
      <td>${esc(new Date(h.at).toLocaleString())}</td>
      <td><span class="badge ${h.engine === 'deep' ? 'bg-info text-dark' : 'bg-secondary'}">${esc(h.engine)}</span></td>
      <td><span class="badge grade-pill grade-${h.grade.toLowerCase()}">${h.grade}</span></td>
      <td>${h.security}</td><td>${h.findings}</td>
      <td class="text-danger">${(h.sev && (h.sev.critical + h.sev.high)) || 0}</td>
      <td>${h.files}</td>
    </tr>`).join('');
    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <p class="text-body-secondary mb-0">${hist.length} scan(s) recorded locally in this browser.</p>
        <button class="btn btn-sm btn-outline-danger" id="hist-clear"><i class="bi bi-trash"></i> Clear history</button>
      </div>
      <div class="card citadel-card mb-3"><div class="card-body">
        <h6 class="text-uppercase text-body-secondary small fw-bold mb-2">Compare two runs</h6>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <select class="form-select form-select-sm" id="hist-a" style="max-width:280px">${opts}</select>
          <span>vs</span>
          <select class="form-select form-select-sm" id="hist-b" style="max-width:280px">${opts}</select>
          <button class="btn btn-sm btn-primary" id="hist-compare">Compare</button>
        </div>
        <div id="hist-result" class="mt-3"></div>
      </div></div>
      <div class="table-responsive"><table class="table table-sm align-middle citadel-table">
        <thead><tr><th>When</th><th>Mode</th><th>Grade</th><th>Security</th><th>Findings</th><th>Crit+High</th><th>Files</th></tr></thead>
        <tbody>${rows}</tbody></table></div>`;
    if (hist[1]) { $('hist-b').selectedIndex = 1; }
  }

  function renderCompare(aId, bId) {
    const cmp = CITADEL.history.compare(aId, bId);
    const el = $('hist-result');
    if (!cmp || !el) return;
    const d = cmp.delta;
    const arrow = (n, goodWhenNegative) => {
      if (n === 0) return '<span class="text-body-secondary">±0</span>';
      const good = goodWhenNegative ? n < 0 : n > 0;
      const cls = good ? 'text-success' : 'text-danger';
      return `<span class="${cls}">${n > 0 ? '+' : ''}${n}</span>`;
    };
    el.innerHTML = `<div class="row g-2">
      <div class="col-6 col-md-3"><div class="quality-metric"><div class="qm-val">${arrow(d.security, false)}</div><div class="qm-lbl">Security</div></div></div>
      <div class="col-6 col-md-3"><div class="quality-metric"><div class="qm-val">${arrow(d.findings, true)}</div><div class="qm-lbl">Findings</div></div></div>
      <div class="col-6 col-md-3"><div class="quality-metric"><div class="qm-val">${arrow(d.critical, true)}</div><div class="qm-lbl">Critical</div></div></div>
      <div class="col-6 col-md-3"><div class="quality-metric"><div class="qm-val">${arrow(d.high, true)}</div><div class="qm-lbl">High</div></div></div>
    </div><p class="small text-body-secondary mt-2">Delta = "${esc(cmp.a.label)}" minus "${esc(cmp.b.label)}". Green = improvement.</p>`;
  }

  /* ---------- Consolidated Report tab ---------- */
  function verdictOf(r) {
    const s = r.scoring.sev;
    if (s.critical > 0) return { label: 'NOT READY FOR AUTHORIZATION', cls: 'text-danger' };
    if (s.high > 0) return { label: 'CONDITIONAL — remediate high-severity items', cls: 'text-warning' };
    if (r.scoring.security >= 85) return { label: 'STRONG POSTURE', cls: 'text-success' };
    return { label: 'MODERATE — review medium findings', cls: 'text-warning' };
  }
  function cveList(r) {
    return r.findings.filter(f => f.source === 'osv' || ((f.source === 'trivy' || f.source === 'grype') && f.category === 'deps'));
  }
  function renderReport(r) {
    const s = r.scoring, v = verdictOf(r);
    const sorted = r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity));
    const cves = cveList(r);
    const sevRow = SEV_ORDER.map(k => `<span class="rep-sev"><span class="dot" style="background:${SEV_COLOR[k]}"></span>${c(k)}: <strong>${s.sev[k] || 0}</strong></span>`).join('');
    const topFindings = sorted.slice(0, 12).map(f => `<tr>
        <td><span class="badge sev-badge" style="background:${SEV_COLOR[f.severity]}">${f.severity}</span></td>
        <td>${esc(f.name)}</td><td class="text-body-secondary">${esc(f.cwe || '')}</td>
        <td><code>${esc((f.file || '').split('/').pop())}${f.line ? ':' + f.line : ''}</code></td>
      </tr>`).join('');
    const impacted = r.posture.filter(p => p.findings > 0);
    const fwRows = impacted.map(p => `<tr>
        <td>${esc(p.name)} <span class="text-body-secondary small">${esc(p.version)}</span></td>
        <td><span class="badge ${p.status === 'fail' ? 'bg-danger' : p.status === 'partial' ? 'bg-warning text-dark' : 'bg-success'}">${p.status === 'fail' ? 'At Risk' : p.status === 'partial' ? 'Gaps' : 'OK'}</span></td>
        <td class="text-center">${p.controlCount}${p.totalControls ? ' / ' + p.totalControls : ''}</td>
        <td class="text-center">${p.findings}</td>
      </tr>`).join('');
    const lic = r.licenses;

    $('tab-report').innerHTML = `
      <div class="report-doc">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h3 class="mb-1">Security &amp; Compliance Report</h3>
            <div class="text-body-secondary small">
              ${esc(new Date(r.meta.scannedAt).toLocaleString())} ·
              ${r.meta.engine === 'deep' ? 'Deep scan (real scanners)' : 'Quick scan (heuristics + OSV)'} ·
              ${r.meta.fileCount} files · ${r.quality.loc.toLocaleString()} LOC · ${esc(r.languages.primary)}
              ${r.meta.source ? '· <code>' + esc(r.meta.source) + '</code>' : ''}
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-primary" id="dl-report"><i class="bi bi-download"></i> Download full report</button>
            <button class="btn btn-sm btn-outline-primary" id="exp-pdf"><i class="bi bi-printer"></i> Print / PDF</button>
          </div>
        </div>

        <div class="report-verdict ${v.cls}"><i class="bi bi-${s.sev.critical ? 'x-octagon-fill' : s.sev.high ? 'exclamation-triangle-fill' : 'check-circle-fill'}"></i> ${v.label}</div>

        <div class="row g-3 my-1">
          <div class="col-6 col-md-3"><div class="rep-metric"><div class="rep-val">${s.grade}</div><div class="rep-lbl">Security grade</div></div></div>
          <div class="col-6 col-md-3"><div class="rep-metric"><div class="rep-val">${s.security}</div><div class="rep-lbl">Security / 100</div></div></div>
          <div class="col-6 col-md-3"><div class="rep-metric"><div class="rep-val text-danger">${cves.length}</div><div class="rep-lbl">Known CVEs</div></div></div>
          <div class="col-6 col-md-3"><div class="rep-metric"><div class="rep-val">${r.findings.length}</div><div class="rep-lbl">Total findings</div></div></div>
        </div>

        <h5 class="report-h">Vulnerabilities &amp; findings</h5>
        <div class="rep-sevrow mb-2">${sevRow}</div>
        ${cves.length ? `<p class="small"><i class="bi bi-shield-exclamation text-danger"></i> <strong>${cves.length}</strong> known vulnerabilit${cves.length === 1 ? 'y' : 'ies'} matched against live advisories (${cves.filter(f => f.severity === 'critical').length} critical, ${cves.filter(f => f.severity === 'high').length} high).</p>` : '<p class="small text-success"><i class="bi bi-check-circle"></i> No known CVEs matched in dependencies.</p>'}
        ${sorted.length ? `<div class="table-responsive"><table class="table table-sm citadel-table"><thead><tr><th>Sev</th><th>Issue</th><th>CWE</th><th>Location</th></tr></thead><tbody>${topFindings}</tbody></table></div>
          ${sorted.length > 12 ? `<p class="small text-body-secondary">Showing top 12 of ${sorted.length}. Full list in the <strong>Findings</strong> tab; AI fix instructions in <strong>AI Fix Prompt</strong>.</p>` : ''}` : '<p class="text-success">No findings.</p>'}

        ${hotspotsHtml(r)}

        <h5 class="report-h">Compliance posture</h5>
        ${impacted.length ? `<div class="table-responsive"><table class="table table-sm citadel-table"><thead><tr><th>Framework</th><th>Status</th><th class="text-center">Controls hit / total</th><th class="text-center">Findings</th></tr></thead><tbody>${fwRows}</tbody></table></div>` : '<p class="text-success">No findings implicate any framework control.</p>'}

        <h5 class="report-h">Dependencies, licenses &amp; deployment</h5>
        <ul class="report-list">
          <li><strong>${r.sbom.components.length}</strong> dependencies across ${[...new Set(r.sbom.components.map(c => c.ecosystem))].length} ecosystem(s); <strong>${cves.length}</strong> with known CVEs.</li>
          <li>Licenses: ${lic && lic.detected ? lic.all.map(l => `<span class="badge ${l.copyleft ? 'bg-warning text-dark' : 'bg-success'}">${esc(l.license)}</span>`).join(' ') + (lic.copyleft.length ? ` — ${lic.copyleft.length} copyleft` : '') : 'none detected'}.</li>
          <li>Deployment: ${r.deployment.length ? esc(r.deployment.map(d => d.tech).join(', ')) : 'no IaC/CI detected'}.</li>
          <li>Maintainability index: <strong>${r.quality.maintainability}/100</strong> (${r.quality.commentRatio}% comments).</li>
        </ul>

        <div class="report-cta"><i class="bi bi-robot"></i> Want an AI to fix these? The <strong>AI Fix Prompt</strong> tab gives you exact, copy-paste wording listing every issue for Claude or any coding assistant.</div>
      </div>`;
  }

  /* ---------- AI fix prompt ---------- */
  function buildFixPrompt(r) {
    const s = r.scoring;
    const sorted = r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity));
    let p = '';
    p += `You are a senior application-security engineer. Fix ALL of the security and compliance issues listed below in this codebase. For each issue, apply a minimal, correct fix that preserves existing behavior, follows the language's secure-coding best practices, and does not introduce new vulnerabilities. Work in priority order: CRITICAL first, then HIGH, MEDIUM, and LOW.\n\n`;
    p += `Project context: ${r.meta.fileCount} files, primary language ${r.languages.primary}`;
    if (r.languages.languages.length > 1) p += ` (also ${r.languages.languages.slice(1, 5).map(l => l.lang).join(', ')})`;
    p += `. Current security grade ${s.grade} (${s.security}/100). `;
    p += `${r.findings.length} issues: ${s.sev.critical} critical, ${s.sev.high} high, ${s.sev.medium} medium, ${s.sev.low} low.\n\n`;
    p += `For each fix: (1) show the file and a unified diff or before/after snippet, (2) briefly explain why it resolves the weakness, (3) note any follow-up (rotate a leaked secret, upgrade a dependency, add a test). After all fixes, summarize residual risk and recommend a re-scan.\n\n`;
    p += `=== ISSUES (${r.findings.length}) ===\n`;
    let lastSev = '';
    sorted.forEach((f, i) => {
      if (f.severity !== lastSev) { p += `\n--- ${f.severity.toUpperCase()} ---\n`; lastSev = f.severity; }
      p += `\n${i + 1}. ${f.name}${f.cwe ? ' [' + f.cwe + ']' : ''}\n`;
      p += `   Location: ${f.file || 'n/a'}${f.line ? ':' + f.line : ''}\n`;
      if (f.snippet) p += `   Flagged code: ${String(f.snippet).slice(0, 200)}\n`;
      p += `   Category: ${CITADEL.frameworks.CATEGORIES[f.category] || f.category}\n`;
      p += `   Required fix: ${f.remediation || 'Remediate per secure-coding guidance.'}\n`;
      const m = CITADEL.frameworks.MAP[f.category] || {};
      const refs = Object.keys(m).slice(0, 4).map(k => {
        const fw = CITADEL.frameworks.CATALOG.find(x => x.id === k);
        return (fw ? fw.name : k) + ' ' + m[k][0];
      });
      if (refs.length) p += `   Compliance: ${refs.join('; ')}\n`;
    });
    p += `\n=== END ISSUES ===\n`;
    p += `\nApply every fix above. Do not skip low-severity items. If a fix requires a decision (e.g. which library to adopt), state the options and pick the most secure sensible default.\n`;
    return p;
  }
  function renderAiFix(r) {
    const prompt = buildFixPrompt(r);
    $('tab-aifix').innerHTML = `
      <p class="text-body-secondary">Copy this prompt into <strong>Claude</strong> (or any coding assistant) alongside your code — it enumerates <strong>every</strong> finding with its location, the required fix, and the compliance controls it affects, and instructs the AI to fix them all in priority order.</p>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <button class="btn btn-sm btn-primary" id="copy-aifix"><i class="bi bi-clipboard"></i> Copy prompt</button>
        <button class="btn btn-sm btn-outline-primary" id="dl-aifix"><i class="bi bi-download"></i> Download .txt</button>
        ${aiOn ? '<span class="badge align-self-center" style="background:#10b981">Backend AI is on — use the per-finding “Explain &amp; fix” in Findings for inline help</span>' : ''}
      </div>
      <textarea id="aifix-text" class="form-control aifix-box" rows="22" readonly>${esc(prompt)}</textarea>`;
  }
  function copyAiFix() {
    const t = $('aifix-text'); if (!t) return;
    t.select(); t.setSelectionRange(0, t.value.length);
    try { navigator.clipboard.writeText(t.value); } catch (e) { try { document.execCommand('copy'); } catch (e2) {} }
    const b = $('copy-aifix'); if (b) { const o = b.innerHTML; b.innerHTML = '<i class="bi bi-check2"></i> Copied'; setTimeout(() => { b.innerHTML = o; }, 1500); }
  }
  function downloadAiFix() { download('citadel-ai-fix-prompt.txt', current ? buildFixPrompt(current) : '', 'text/plain'); }

  /* ---------- Full standalone HTML report download ---------- */
  function downloadHtmlReport(r) {
    const tmp = document.getElementById('tab-report');
    const body = tmp ? tmp.innerHTML.replace(/<button[\s\S]*?<\/button>/g, '') : '';
    const brand = (CITADEL.branding ? CITADEL.branding.get() : { orgName: 'CITADEL', logoUrl: '', accent: '' });
    const org = esc(brand.orgName || 'CITADEL');
    const accent = /^#[0-9a-fA-F]{3,8}$/.test(brand.accent || '') ? brand.accent : '#0ea5e9';
    const logo = (brand.logoUrl && /^(https?:|data:image\/)/i.test(brand.logoUrl))
      ? `<img src="${esc(brand.logoUrl)}" alt="${org} logo" style="height:40px;width:auto;vertical-align:middle;margin-right:.6rem">` : '';
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${org} Report — ${esc(r.meta.scannedAt)}</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#1f2937;line-height:1.5}
h3{margin:0 0 .3rem}h5{margin:1.6rem 0 .5rem;border-bottom:2px solid ${accent};padding-bottom:.2rem}
table{border-collapse:collapse;width:100%;font-size:.85rem;margin:.5rem 0}th,td{border:1px solid #e5e7eb;padding:.35rem .5rem;text-align:left}
th{background:#f3f4f6}code{background:#f3f4f6;padding:.05rem .3rem;border-radius:3px;font-size:.85em}
.badge{display:inline-block;padding:.15rem .4rem;border-radius:4px;color:#fff;font-size:.72rem}
.bg-danger{background:#dc3545}.bg-warning{background:#ffc107;color:#000}.bg-success{background:#16a34a}.sev-badge{text-transform:uppercase}
.report-verdict{font-weight:700;font-size:1.05rem;margin:.6rem 0;padding:.6rem .8rem;border-radius:8px;background:#f3f4f6}
.text-danger{color:#dc3545}.text-warning{color:#b45309}.text-success{color:#16a34a}.text-body-secondary,.small{color:#6b7280}
.rep-metric{border:1px solid #e5e7eb;border-radius:8px;padding:.6rem;text-align:center;display:inline-block;min-width:120px;margin:.2rem}
.rep-val{font-size:1.6rem;font-weight:700}.rep-lbl{font-size:.72rem;color:#6b7280}.rep-sev{margin-right:1rem}.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:3px}
.report-cta{margin-top:1.2rem;padding:.7rem .9rem;border-left:3px solid #10b981;background:#ecfdf5;border-radius:0 8px 8px 0;font-size:.9rem}
.rep-brand{display:flex;align-items:center;border-bottom:3px solid ${accent};padding-bottom:.6rem;margin-bottom:1rem}
.rep-brand h1{font-size:1.4rem;margin:0}
ul{padding-left:1.1rem}</style></head>
<body><div class="rep-brand">${logo}<h1>${org} <span style="font-weight:400;color:#6b7280;font-size:1rem">— Security &amp; Compliance Report</span></h1></div>
<div class="report-doc">${body}</div>
<hr><p class="small">Generated by ${org} (powered by CITADEL) — heuristic + scanner analysis for triage &amp; education. Verify findings before acting.</p>
</body></html>`;
    download('citadel-report.html', html, 'text/html');
  }

  function setAi(on) { aiOn = !!on; }

  /* ---------- Risk hotspots (riskiest files) ---------- */
  const RISK_W = { critical: 25, high: 10, medium: 4, low: 1, info: 0 };
  function hotspots(r) {
    const by = {};
    r.findings.forEach(f => {
      const file = (f.file || 'unknown').replace(/^.*!\//, '');
      const h = by[file] || (by[file] = { file, score: 0, n: 0, critical: 0, high: 0 });
      h.score += RISK_W[f.severity] || 0; h.n++;
      if (f.severity === 'critical') h.critical++; if (f.severity === 'high') h.high++;
    });
    return Object.values(by).sort((a, b) => b.score - a.score || b.n - a.n).slice(0, 10);
  }
  function hotspotsHtml(r) {
    const hs = hotspots(r);
    if (!hs.length) return '';
    const max = hs[0].score || 1;
    const rows = hs.map(h => `<div class="hotspot">
      <div class="hotspot-bar-wrap"><div class="hotspot-bar" style="width:${Math.max(6, Math.round(h.score / max * 100))}%"></div></div>
      <div class="hotspot-meta"><code>${esc(h.file)}</code><span class="text-body-secondary small">${h.n} finding(s)${h.critical ? ' · ' + h.critical + ' critical' : ''}${h.high ? ' · ' + h.high + ' high' : ''} · risk ${h.score}</span></div>
    </div>`).join('');
    return `<h5 class="report-h">Risk hotspots</h5>
      <p class="small text-body-secondary">Files ranked by weighted risk (critical×25, high×10, medium×4, low×1) — fix these first.</p>
      <div class="hotspots">${rows}</div>`;
  }

  /* ---------- CI exporters: JUnit XML + PR-comment Markdown ---------- */
  function xmlEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function exportJUnit() {
    const r = current;
    const sorted = r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity));
    const fails = sorted.filter(f => ['critical', 'high', 'medium'].includes(f.severity)).length;
    let xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
    xml += `<testsuites name="CITADEL" tests="${sorted.length}" failures="${fails}">\n`;
    xml += `  <testsuite name="security-compliance" tests="${sorted.length}" failures="${fails}" timestamp="${xmlEsc(r.meta.scannedAt)}">\n`;
    sorted.forEach(f => {
      const cls = (f.category || 'finding');
      const nm = `${f.severity.toUpperCase()}: ${f.name}${f.cwe ? ' (' + f.cwe + ')' : ''} @ ${(f.file || '')}${f.line ? ':' + f.line : ''}`;
      const fail = ['critical', 'high', 'medium'].includes(f.severity);
      xml += `    <testcase classname="${xmlEsc(cls)}" name="${xmlEsc(nm)}">`;
      if (fail) {
        xml += `\n      <failure type="${xmlEsc(f.severity)}" message="${xmlEsc(f.name)}">${xmlEsc((f.snippet ? f.snippet + '\n' : '') + 'Fix: ' + (f.remediation || ''))}</failure>\n    `;
      }
      xml += `</testcase>\n`;
    });
    xml += '  </testsuite>\n</testsuites>\n';
    download('citadel-junit.xml', xml, 'application/xml');
  }
  function exportPrComment() {
    const r = current, s = r.scoring;
    const cves = cveList(r);
    let md = `## 🛡️ CITADEL Security & Compliance Report\n\n`;
    md += `**Grade ${s.grade}** · Security ${s.security}/100 · ${r.findings.length} findings · ${cves.length} known CVEs\n\n`;
    md += `| Severity | Count |\n|---|---|\n`;
    SEV_ORDER.forEach(k => { if (s.sev[k]) md += `| ${c(k)} | ${s.sev[k]} |\n`; });
    const top = r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity)).slice(0, 15);
    md += `\n<details><summary>Top ${top.length} findings</summary>\n\n`;
    top.forEach(f => { md += `- **[${f.severity.toUpperCase()}]** ${f.name} ${f.cwe ? '(' + f.cwe + ')' : ''} — \`${(f.file || '').split('/').pop()}${f.line ? ':' + f.line : ''}\`\n`; });
    md += `\n</details>\n\n`;
    const hit = r.posture.filter(p => p.findings > 0).slice(0, 6).map(p => p.name).join(', ');
    md += `**Frameworks impacted:** ${hit || 'none'}.\n\n`;
    md += `_Generated by CITADEL · ${s.sev.critical ? '🔴 blocking — critical issues present' : s.sev.high ? '🟠 review required' : '🟢 no blocking issues'}_\n`;
    download('citadel-pr-comment.md', md, 'text/markdown');
  }

  CITADEL.report = {
    render, renderHistory, renderCompare, renderFindings, renderReport, renderAiFix, setAi,
    shownFinding, toggleSuppressedView, copyAiFix, downloadAiFix, downloadHtmlReport,
    exportJson, exportSbom, exportMarkdown, exportPdf, exportSarif, exportPoam, exportSsp, exportJUnit, exportPrComment,
    get current() { return current; }
  };
})(window);
