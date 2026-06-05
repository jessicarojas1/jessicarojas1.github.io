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
  function renderFindings(r) {
    const sorted = r.findings.slice().sort((a, b) => SEV_ORDER.indexOf(a.severity) - SEV_ORDER.indexOf(b.severity));
    const filterBtns = ['all'].concat(SEV_ORDER).map(sv =>
      `<button class="btn btn-sm ${sv === 'all' ? 'btn-primary' : 'btn-outline-secondary'} finding-filter" data-sev="${sv}">${sv === 'all' ? 'All' : c(sv)} ${sv === 'all' ? '' : '(' + (r.scoring.sev[sv] || 0) + ')'}</button>`
    ).join(' ');
    const rows = sorted.length ? sorted.map((f, i) => `
      <div class="finding" data-sev="${f.severity}">
        <div class="finding-head" data-finding-toggle="${i}">
          <span class="sev-dot" style="background:${SEV_COLOR[f.severity]}"></span>
          <span class="finding-name">${esc(f.name)}</span>
          <span class="badge sev-badge" style="background:${SEV_COLOR[f.severity]}">${f.severity}</span>
          <span class="text-body-secondary small ms-auto d-none d-md-inline">${esc(f.cwe || '')}</span>
          <i class="bi bi-chevron-down finding-chev"></i>
        </div>
        <div class="finding-body d-none" id="finding-body-${i}">
          <div class="finding-loc"><i class="bi bi-file-earmark-code"></i> ${esc(f.file || '—')}${f.line ? ':' + f.line : ''}</div>
          ${f.snippet ? `<pre class="finding-snippet">${esc(f.snippet)}</pre>` : ''}
          <div class="finding-meta">
            <span><strong>Category:</strong> ${esc(CITADEL.frameworks.CATEGORIES[f.category] || f.category)}</span>
            <span><strong>Confidence:</strong> ${esc(f.confidence || 'n/a')}</span>
            <span><strong>Rule:</strong> ${esc(f.ruleId || '—')}</span>
          </div>
          <div class="finding-fix"><i class="bi bi-wrench-adjustable"></i> ${esc(f.remediation || 'Review and remediate.')}</div>
          ${mappedControls(f.category)}
        </div>
      </div>`).join('') :
      '<div class="empty-state"><i class="bi bi-check2-circle"></i><p>No findings — clean scan.</p></div>';
    $('tab-findings').innerHTML = `
      <div class="d-flex flex-wrap gap-2 mb-3" id="finding-filters">${filterBtns}</div>
      <div id="findings-list">${rows}</div>`;
  }

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
  function renderCompliance(r) {
    const cards = r.posture.map(p => {
      const statusClass = p.status === 'fail' ? 'status-fail' : p.status === 'partial' ? 'status-partial' : 'status-pass';
      const statusTxt = p.findings === 0 ? 'No mapped findings' : p.status === 'fail' ? 'At Risk' : p.status === 'partial' ? 'Gaps Found' : 'OK';
      const ctrls = p.controls.slice(0, 6).map(cc => `<li><code>${esc(cc.id)}</code> <span class="text-body-secondary">×${cc.count}</span></li>`).join('');
      return `<div class="col-md-6 col-xl-4">
        <div class="card citadel-card compliance-card ${statusClass} h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <h6 class="mb-0"><a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.name)}</a></h6>
            <span class="badge framework-tag">${esc(p.tag)}</span>
          </div>
          <div class="text-body-secondary small mb-2">${esc(p.version)} — ${esc(p.desc)}</div>
          <div class="compliance-status">${statusTxt}</div>
          ${p.findings ? `<div class="small text-body-secondary mb-1">${p.findings} finding(s) · ${p.controlCount} control(s)</div>
            <ul class="ctrl-list">${ctrls}${p.controls.length > 6 ? `<li class="text-body-secondary">+${p.controls.length - 6} more…</li>` : ''}</ul>` :
            '<div class="text-success small"><i class="bi bi-check-circle"></i> No findings implicate this framework.</div>'}
        </div></div>
      </div>`;
    }).join('');
    $('tab-compliance').innerHTML = `
      <p class="text-body-secondary">Each finding is cross-walked to the specific controls it implicates across <strong>${r.posture.length}</strong> frameworks. Status reflects the weighted severity of mapped findings.</p>
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
    $('tab-sbom').innerHTML = `
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <p class="text-body-secondary mb-0">${comps.length} component(s) across ${[...new Set(comps.map(c => c.ecosystem))].length} ecosystem(s). CycloneDX 1.5 SBOM available in <strong>Export</strong>.</p>
        <button class="btn btn-sm btn-outline-primary" id="dl-sbom"><i class="bi bi-box-arrow-down"></i> Download SBOM (JSON)</button>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle citadel-table">
        <thead><tr><th>Component</th><th>Version</th><th>Ecosystem</th><th>Scope</th><th>Supply-chain</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>`;
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
        <div class="col-md-6 col-lg-3"><button class="btn btn-outline-primary w-100 export-btn" id="exp-sbom"><i class="bi bi-box-seam"></i><span>SBOM<br><small>CycloneDX 1.5</small></span></button></div>
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

  CITADEL.report = { render, exportJson, exportSbom, exportMarkdown, exportPdf, get current() { return current; } };
})(window);
