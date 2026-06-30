/* CITADEL — Release Readiness & Security Gate: Report tab + exporters
 * Renders report.readiness + report.reviews into #tab-readiness and produces
 * downloadable, audience-targeted artifacts (Executive / Developer / Auditor
 * Markdown reports, a combined Markdown doc, standalone HTML, JSON, and a
 * findings-register CSV).
 * window.CITADEL.reviewReport
 *
 * Codes to the shared readiness contract. Every section guards for missing or
 * empty data and renders a tasteful empty note rather than throwing. No
 * network, no Date — timestamps come from report.readiness.generatedAt /
 * report.meta.scannedAt. CSP-safe: every interpolated value is escaped, no
 * inline event handlers (the orchestrator wires [data-readiness-export]), and
 * no hardcoded hex in app-DOM styles (Bootstrap classes / CSS vars only).
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
  function num(n) { const v = Number(n); return isFinite(v) ? v : 0; }

  const SEV_ORDER = ['critical', 'high', 'medium', 'low', 'info'];

  /* ---------- classification helpers ---------- */
  function decisionClass(d) {
    switch (String(d || '').toLowerCase()) {
      case 'approved': return 'text-bg-success';
      case 'conditional approval': return 'text-bg-warning';
      case 'requires manual review': return 'text-bg-info';
      case 'rejected': return 'text-bg-danger';
      default: return 'text-bg-secondary';
    }
  }
  // Readiness/dimension score: higher is better.
  function scoreClass(v) {
    const n = num(v);
    if (n >= 75) return 'text-bg-success';
    if (n >= 50) return 'text-bg-warning';
    return 'text-bg-danger';
  }
  function statusClass(s) {
    switch (String(s || '').toLowerCase()) {
      case 'pass': return 'text-bg-success';
      case 'warn': return 'text-bg-warning';
      case 'fail': return 'text-bg-danger';
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
  function residualClass(r) {
    switch (String(r || '').toLowerCase()) {
      case 'high': return 'text-bg-danger';
      case 'medium': return 'text-bg-warning';
      case 'low': return 'text-bg-success';
      default: return 'text-bg-secondary';
    }
  }

  const SECTION = s => `<h6 class="text-uppercase text-body-secondary small fw-bold mb-2">${esc(s)}</h6>`;
  const EMPTY_NOTE = msg => `<p class="text-body-secondary small mb-0"><i class="bi bi-dash-circle"></i> ${esc(msg)}</p>`;
  function card(body) { return `<div class="card citadel-card mb-3"><div class="card-body">${body}</div></div>`; }

  /* shared accessors -------------------------------------------------- */
  function rdOf(report) { return (report && report.readiness) || null; }
  function reviewsOf(report) { return (report && report.reviews) || {}; }
  function metaOf(report) { return (report && report.meta) || {}; }
  function sevCounts(report) {
    const sev = (report && report.scoring && report.scoring.sev) || {};
    return {
      critical: num(sev.critical), high: num(sev.high), medium: num(sev.medium),
      low: num(sev.low), info: num(sev.info)
    };
  }
  function generatedAt(report) {
    const rd = rdOf(report);
    return (rd && rd.generatedAt) || metaOf(report).scannedAt || '';
  }

  /* ===================================================================== *
   *  RENDER
   * ===================================================================== */
  function render(report) {
    const el = document.getElementById('tab-readiness');
    if (!el) return;
    const rd = rdOf(report);
    if (!rd) {
      el.innerHTML = '<div class="empty-state"><i class="bi bi-clipboard-check"></i>'
        + '<p>No release-readiness assessment available. Run a scan to evaluate the '
        + 'security gate, threat model, logging, testing, and architecture posture.</p></div>';
      return;
    }
    const rev = reviewsOf(report);
    el.innerHTML = [
      decisionBanner(rd, report),
      exportBar(),
      dimensionsSection(rd),
      blockersSection(rd),
      remediationSection(rd),
      threatModelSection(rev.threatModel),
      loggingSection(rev.logging),
      testingSection(rev.testing),
      operationsSection(rev.operations),
      architectureSection(rev.architecture)
    ].join('\n');
  }

  /* ---------- 1. Decision banner ---------- */
  function decisionBanner(rd, report) {
    const decision = rd.decision || 'Requires Manual Review';
    const score = rd.overall == null ? '—' : num(rd.overall);
    const rationale = arr(rd.rationale)[0] || '';
    const approvers = arr(rd.approverRoles);
    const metaBits = [];
    if (rd.policyName) metaBits.push('<span class="me-3"><i class="bi bi-shield-check"></i> ' + esc(rd.policyName) + '</span>');
    const gen = generatedAt(report);
    if (gen) metaBits.push('<span class="me-3"><i class="bi bi-clock"></i> ' + esc(gen) + '</span>');
    const riskBadge = rd.riskAcceptanceRequired
      ? '<span class="badge text-bg-danger ms-1"><i class="bi bi-exclamation-octagon"></i> Risk acceptance required</span>'
      : '<span class="badge text-bg-success ms-1"><i class="bi bi-check2"></i> No formal risk acceptance required</span>';
    const approverBadges = approvers.length
      ? approvers.map(a => `<span class="badge text-bg-secondary me-1">${esc(a)}</span>`).join('')
      : '<span class="text-body-secondary small">—</span>';
    const body = `
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
        <div>
          <div class="text-uppercase text-body-secondary small fw-bold mb-1">Release Decision</div>
          <span class="badge ${decisionClass(decision)} fs-5">${esc(decision)}</span>
        </div>
        <div class="text-center">
          <div class="display-5 fw-bold">${esc(String(score))}<span class="fs-6 text-body-secondary">/100</span></div>
          <div class="small text-body-secondary text-uppercase fw-bold">Overall Readiness</div>
        </div>
      </div>
      <div class="small text-body-secondary mb-2">${metaBits.join('')}</div>
      ${rationale ? '<p class="mb-2">' + esc(rationale) + '</p>' : ''}
      <div class="d-flex flex-wrap align-items-center gap-2">
        ${riskBadge}
        <span class="ms-2"><span class="small text-body-secondary me-1">Approvers:</span>${approverBadges}</span>
      </div>`;
    return card(body);
  }

  /* ---------- 2. Export bar (handlers wired globally by orchestrator) ---------- */
  function exportBar() {
    const btn = (fmt, icon, label, cls) =>
      `<button class="btn btn-sm ${cls || 'btn-outline-secondary'}" data-readiness-export="${esc(fmt)}"><i class="bi ${icon}"></i> ${esc(label)}</button>`;
    return `<div class="d-flex flex-wrap gap-2 mb-3" id="readiness-export-bar">
      ${btn('executive', 'bi-briefcase', 'Executive Report', 'btn-outline-primary')}
      ${btn('developer', 'bi-code-slash', 'Developer Report', 'btn-outline-primary')}
      ${btn('auditor', 'bi-clipboard-data', 'Auditor Report', 'btn-outline-primary')}
      ${btn('markdown', 'bi-filetype-md', 'Combined Markdown')}
      ${btn('html', 'bi-filetype-html', 'HTML')}
      ${btn('json', 'bi-filetype-json', 'JSON')}
      ${btn('csv', 'bi-filetype-csv', 'Findings CSV')}
      ${btn('pdf', 'bi-printer', 'PDF / Print')}
    </div>`;
  }

  /* ---------- 3. Dimension scores ---------- */
  function dimensionsSection(rd) {
    const dims = arr(rd.dimensions);
    if (!dims.length) return card(SECTION('Dimension Scores') + EMPTY_NOTE('No dimension scores available.'));
    const tiles = dims.map(d => {
      const score = d.score == null ? '—' : num(d.score);
      const counts = [];
      counts.push(`<span class="badge text-bg-light text-dark">${esc(num(d.findings))} findings</span>`);
      if (num(d.critical)) counts.push(`<span class="badge text-bg-danger">${esc(num(d.critical))} crit</span>`);
      if (num(d.high)) counts.push(`<span class="badge text-bg-warning">${esc(num(d.high))} high</span>`);
      return `
        <div class="col-6 col-md-4 col-xl-3">
          <div class="border rounded p-2 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="small fw-bold">${esc(d.label || d.key || '')}</span>
              <span class="badge ${d.status ? statusClass(d.status) : scoreClass(d.score)}">${esc(String(score))}</span>
            </div>
            <div class="d-flex flex-wrap gap-1 mb-1">${counts.join('')}</div>
            ${d.notes ? '<div class="small text-body-secondary">' + esc(d.notes) + '</div>' : ''}
          </div>
        </div>`;
    }).join('');
    return card(SECTION('Dimension Scores') + `<div class="row g-2">${tiles}</div>`);
  }

  /* ---------- 4. Top blockers ---------- */
  function blockersSection(rd) {
    const blockers = arr(rd.blockers);
    if (!blockers.length) {
      return card(SECTION('Top Blockers')
        + '<p class="text-success small mb-0"><i class="bi bi-check-circle"></i> No release blockers identified.</p>');
    }
    const items = blockers.map(b =>
      `<li class="list-group-item list-group-item-danger d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-octagon-fill mt-1"></i><span>${esc(b)}</span></li>`).join('');
    return card(SECTION('Top Blockers') + `<ul class="list-group list-group-flush">${items}</ul>`);
  }

  /* ---------- 5. Required / recommended remediation ---------- */
  function remediationSection(rd) {
    const required = arr(rd.requiredRemediation);
    const after = arr(rd.afterRemediation);
    const list = (items, emptyMsg) => items.length
      ? '<ul class="mb-0">' + items.map(x => `<li>${esc(x)}</li>`).join('') + '</ul>'
      : EMPTY_NOTE(emptyMsg);
    const body = `
      <div class="row g-3">
        <div class="col-md-6">
          ${SECTION('Required Before Release')}
          ${list(required, 'Nothing required before release.')}
        </div>
        <div class="col-md-6">
          ${SECTION('Recommended After Release')}
          ${list(after, 'No follow-up recommendations.')}
        </div>
      </div>`;
    return card(body);
  }

  /* ---------- 6. Threat model ---------- */
  // Current project id + apply the per-project reviewer overlay (custom/edited/
  // removed threats) over the generated model, for both the tab and exports.
  function projId() { try { return (CITADEL.projects && CITADEL.projects.currentId && CITADEL.projects.currentId()) || ''; } catch (e) { return ''; } }
  function applyTM(tm) { try { return (CITADEL.threatmodel && CITADEL.threatmodel.apply) ? CITADEL.threatmodel.apply(tm, projId()) : tm; } catch (e) { return tm; } }

  function threatModelSection(tmRaw) {
    const tm = applyTM(tmRaw);
    if (!tm) return card(SECTION('Threat Model') + EMPTY_NOTE('No threat model generated.'));
    const editable = !!(CITADEL.threatmodel && CITADEL.threatmodel.apply);
    const summary = tm.summary || {};
    const byStride = summary.byStride || {};
    const strideOrder = (CITADEL.threatmodel && CITADEL.threatmodel.STRIDE) || ['Spoofing', 'Tampering', 'Repudiation', 'InformationDisclosure', 'DenialOfService', 'ElevationOfPrivilege'];
    const riskList = (CITADEL.threatmodel && CITADEL.threatmodel.RISK) || ['high', 'medium', 'low'];
    const riskOpts = (sel) => riskList.map(r => `<option value="${esc(r)}"${r === sel ? ' selected' : ''}>${esc(r)}</option>`).join('');
    const strideBadges = strideOrder.map(k =>
      `<span class="badge text-bg-secondary me-1 mb-1">${esc(k)}: ${esc(num(byStride[k]))}</span>`).join('');
    const parts = [SECTION('Threat Model') + (tm.edited ? ' <span class="badge text-bg-warning">edited</span>' : '')];
    if (tm.overview) parts.push(`<p class="mb-2">${esc(tm.overview)}</p>`);
    parts.push(`<div class="mb-2"><span class="small text-body-secondary me-2">STRIDE summary `
      + `(total ${esc(num(summary.total))}):</span>${strideBadges}</div>`);

    const entryPoints = arr(tm.entryPoints);
    if (entryPoints.length) {
      parts.push('<div class="mb-2">' + SECTION('Entry Points').replace('mb-2', 'mb-1')
        + entryPoints.map(e => `<span class="badge text-bg-light text-dark me-1 mb-1">${esc(e.name || e)}`
          + (e.detail ? ' <span class="opacity-75">(' + esc(e.detail) + ')</span>' : '') + '</span>').join('') + '</div>');
    }
    const boundaries = arr(tm.trustBoundaries);
    if (boundaries.length) {
      parts.push('<div class="mb-2">' + SECTION('Trust Boundaries').replace('mb-2', 'mb-1')
        + boundaries.map(t => `<span class="badge text-bg-info me-1 mb-1">${esc(t)}</span>`).join('') + '</div>');
    }
    const threats = arr(tm.threats);
    if (threats.length) {
      const rows = threats.map(t => `<tr>
          <td><span class="badge text-bg-secondary">${esc(t.stride || '—')}</span>${t.custom ? ' <span class="badge text-bg-info">custom</span>' : ''}</td>
          <td>${esc(t.title || '')}${t.description ? '<div class="small text-body-secondary">' + esc(t.description) + '</div>' : ''}</td>
          <td class="small">${esc(t.surface || '—')}</td>
          <td>${editable
    ? `<select class="form-select form-select-sm" data-threat-residual="${esc(t.id)}" aria-label="Residual risk">${riskOpts(t.residualRisk)}</select>`
    : `<span class="badge ${residualClass(t.residualRisk)}">${esc(t.residualRisk || 'unknown')}</span>`}</td>
          <td class="small">${editable
    ? `<input type="text" class="form-control form-control-sm" data-threat-mitig="${esc(t.id)}" value="${esc(arr(t.missingMitigations).join('; '))}" placeholder="missing mitigations (; separated)">`
    : (arr(t.missingMitigations).map(esc).join('; ') || '—')}</td>
          ${editable ? `<td><button class="btn btn-sm btn-outline-danger" data-threat-remove="${esc(t.id)}" title="Remove threat" aria-label="Remove threat"><i class="bi bi-trash"></i></button></td>` : ''}
        </tr>`).join('');
      parts.push('<div class="table-responsive"><table class="table table-sm align-middle citadel-table">'
        + '<thead><tr><th>STRIDE</th><th>Threat</th><th>Surface</th><th>Residual</th><th>Missing mitigations</th>' + (editable ? '<th></th>' : '') + '</tr></thead>'
        + `<tbody>${rows}</tbody></table></div>`);
    } else {
      parts.push(EMPTY_NOTE('No specific threats enumerated.'));
    }
    if (editable) {
      const strideSel = strideOrder.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join('');
      parts.push(`<div class="border-top pt-2 mt-2">
        <div class="small text-uppercase text-body-secondary fw-bold mb-2"><i class="bi bi-plus-circle"></i> Add a threat</div>
        <div class="row g-2 align-items-end">
          <div class="col-sm-3"><select class="form-select form-select-sm" id="tm-add-stride" aria-label="STRIDE category">${strideSel}</select></div>
          <div class="col-sm-3"><input type="text" class="form-control form-control-sm" id="tm-add-title" maxlength="200" placeholder="Threat title"></div>
          <div class="col-sm-3"><input type="text" class="form-control form-control-sm" id="tm-add-surface" maxlength="200" placeholder="Surface / asset"></div>
          <div class="col-sm-2"><select class="form-select form-select-sm" id="tm-add-residual" aria-label="Residual risk">${riskOpts('medium')}</select></div>
          <div class="col-sm-1"><button class="btn btn-sm btn-primary w-100" data-threat-add title="Add threat" aria-label="Add threat"><i class="bi bi-plus-lg"></i></button></div>
        </div>
      </div>`);
    }
    return card(parts.join(''));
  }

  /* ---------- 7. Logging & auditability ---------- */
  function loggingSection(logging) {
    const s = (logging && logging.summary) || null;
    if (!s) return card(SECTION('Logging & Auditability') + EMPTY_NOTE('No logging analysis available.'));
    const chips = (label, list, cls) => {
      const a = arr(list);
      return '<div class="mb-2"><span class="small text-body-secondary me-2">' + esc(label) + ':</span>'
        + (a.length ? a.map(x => `<span class="badge ${cls} me-1 mb-1">${esc(x)}</span>`).join('')
          : '<span class="text-body-secondary small">none</span>') + '</div>';
    };
    const body = SECTION('Logging & Auditability')
      + `<div class="mb-2"><span class="badge ${scoreClass(s.score)} fs-6">${esc(s.score == null ? '—' : num(s.score))}/100</span>`
      + (s.hasCentralLogger ? ' <span class="badge text-bg-success ms-1">Central logger</span>' : ' <span class="badge text-bg-warning ms-1">No central logger</span>') + '</div>'
      + chips('Events covered', s.eventsCovered, 'text-bg-success')
      + chips('Events missing', s.eventsMissing, 'text-bg-secondary')
      + chips('Bad practices', s.badPractices, 'text-bg-danger');
    return card(body);
  }

  /* ---------- 8. Test readiness ---------- */
  function testingSection(testing) {
    const s = (testing && testing.summary) || null;
    if (!s) return card(SECTION('Test Readiness') + EMPTY_NOTE('No test analysis available.'));
    const kinds = arr(s.kinds);
    const frameworks = arr(s.frameworks);
    const body = SECTION('Test Readiness')
      + `<div class="mb-2"><span class="badge ${scoreClass(s.score)} fs-6">${esc(s.score == null ? '—' : num(s.score))}/100</span>`
      + (s.hasTests ? ' <span class="badge text-bg-success ms-1">Tests present</span>' : ' <span class="badge text-bg-danger ms-1">No tests</span>')
      + (s.hasCiTestGate ? ' <span class="badge text-bg-success ms-1">CI test gate</span>' : ' <span class="badge text-bg-warning ms-1">No CI test gate</span>')
      + ` <span class="badge text-bg-light text-dark ms-1">Coverage threshold: ${s.coverageThreshold == null ? 'none' : esc(num(s.coverageThreshold)) + '%'}</span></div>`
      + '<div class="mb-2"><span class="small text-body-secondary me-2">Kinds present:</span>'
      + (kinds.length ? kinds.map(k => `<span class="badge text-bg-info me-1 mb-1">${esc(k)}</span>`).join('') : '<span class="text-body-secondary small">none</span>') + '</div>'
      + (frameworks.length ? '<div><span class="small text-body-secondary me-2">Frameworks:</span>'
        + frameworks.map(f => `<span class="badge text-bg-secondary me-1 mb-1">${esc(f)}</span>`).join('') + '</div>' : '');
    return card(body);
  }

  /* ---------- 8b. Operational readiness ---------- */
  function operationsSection(operations) {
    const s = (operations && operations.summary) || null;
    if (!s) return '';
    const chip = (label, on) => `<span class="badge ${on ? 'text-bg-success' : 'text-bg-secondary'} me-1 mb-1">${esc(label)}: ${on ? 'yes' : 'no'}</span>`;
    const health = arr(s.healthEndpoints), mon = arr(s.monitoring);
    const body = SECTION('Operational Readiness')
      + `<div class="mb-2"><span class="badge ${scoreClass(s.score)} fs-6">${esc(s.score == null ? '—' : num(s.score))}/100</span></div>`
      + '<div class="mb-2"><span class="small text-body-secondary me-2">Health endpoints:</span>'
      + (health.length ? health.map(h => `<span class="badge text-bg-info me-1 mb-1">${esc(h)}</span>`).join('') : '<span class="text-body-secondary small">none detected</span>') + '</div>'
      + '<div class="mb-2"><span class="small text-body-secondary me-2">Monitoring:</span>'
      + (mon.length ? mon.map(m => `<span class="badge text-bg-info me-1 mb-1">${esc(m)}</span>`).join('') : '<span class="text-body-secondary small">none detected</span>') + '</div>'
      + '<div>' + chip('Backup', s.backup) + chip('Restore', s.restore) + chip('Alerting', s.alerting) + chip('DR', s.dr) + '</div>';
    return card(body);
  }

  /* ---------- 9. Architecture ---------- */
  function architectureSection(architecture) {
    const s = (architecture && architecture.summary) || null;
    if (!s) return card(SECTION('Architecture') + EMPTY_NOTE('No architecture analysis available.'));
    const block = (label, list) => {
      const a = arr(list);
      if (!a.length) return '';
      return '<div class="mb-2">' + SECTION(label).replace('mb-2', 'mb-1')
        + '<ul class="mb-0">' + a.map(x => `<li class="small">${esc(x)}</li>`).join('') + '</ul></div>';
    };
    const body = SECTION('Architecture')
      + `<div class="mb-2"><span class="badge ${scoreClass(s.score)} fs-6">${esc(s.score == null ? '—' : num(s.score))}/100</span>`
      + ' <span class="badge text-bg-light text-dark ms-1">Heuristic — low/medium confidence</span></div>'
      + block('Observations', s.observations)
      + block('Maintainability', s.maintainability)
      + block('Security Architecture', s.securityArchitecture)
      + (arr(s.observations).length || arr(s.maintainability).length || arr(s.securityArchitecture).length
        ? '' : EMPTY_NOTE('No architecture observations recorded.'));
    return card(body);
  }

  /* ===================================================================== *
   *  SHARED EXPORT HELPERS
   * ===================================================================== */
  function mdTable(headers, rows) {
    let out = '| ' + headers.join(' | ') + ' |\n';
    out += '|' + headers.map(() => '---').join('|') + '|\n';
    rows.forEach(r => {
      out += '| ' + r.map(c => String(c == null ? '' : c).replace(/\|/g, '\\|').replace(/\n/g, ' ')).join(' | ') + ' |\n';
    });
    return out;
  }
  function appMeta(report) {
    const m = metaOf(report);
    const langs = (report && report.languages) || {};
    const fileCount = m.fileCount != null ? m.fileCount
      : (m.files != null ? m.files : (langs.total != null ? langs.total : null));
    return {
      name: m.appName || m.name || m.project || 'Application',
      scannedAt: m.scannedAt || generatedAt(report) || '',
      fileCount: fileCount,
      primaryLang: langs.primary || '',
      languages: arr(langs.languages).map(l => (l.name || '') + (l.files ? ' (' + l.files + ')' : '')).filter(Boolean)
    };
  }
  function sevTotalsLines(report) {
    const c = sevCounts(report);
    return SEV_ORDER.map(k => '- **' + cap(k) + ':** ' + c[k]);
  }
  function postureLines(report) {
    const posture = arr(report && report.posture);
    if (!posture.length) return [];
    return posture.map(p => {
      const total = p.controlCount != null ? p.controlCount : (Array.isArray(p.controls) ? p.controls.length : null);
      const status = p.status != null ? p.status : '';
      let line = '- **' + (p.name || p.id || 'Framework') + ':** ' + (status || 'see controls');
      if (total != null) line += ' (' + total + ' controls)';
      if (p.findings != null) line += ' — ' + num(p.findings) + ' gap(s)';
      return line;
    });
  }
  // Findings sorted Critical -> Low (drops info to the end), stable.
  function sortedFindings(report) {
    const findings = arr(report && report.findings).slice();
    const rank = s => { const i = SEV_ORDER.indexOf(String(s || '').toLowerCase()); return i < 0 ? SEV_ORDER.length : i; };
    return findings
      .map((f, i) => ({ f, i }))
      .sort((a, b) => (rank(a.f.severity) - rank(b.f.severity)) || (a.i - b.i))
      .map(x => x.f);
  }
  function findingId(f, idx) {
    return f.ruleId || f.id || ('FND-' + String(idx + 1).padStart(3, '0'));
  }

  /* ===================================================================== *
   *  EXECUTIVE REPORT (leadership)
   * ===================================================================== */
  function executive(report) {
    const rd = rdOf(report);
    const meta = appMeta(report);
    const c = sevCounts(report);
    const out = [];
    out.push('# Executive Summary — Release Readiness\n');
    out.push('**Application:** ' + meta.name);
    if (meta.scannedAt) out.push('**Scan date:** ' + meta.scannedAt);
    if (meta.fileCount != null) out.push('**Files analyzed:** ' + meta.fileCount);
    if (meta.primaryLang) out.push('**Primary language:** ' + meta.primaryLang);
    out.push('');

    if (rd) {
      out.push('## Decision\n');
      out.push('**' + (rd.decision || 'Requires Manual Review') + '** — overall readiness **'
        + (rd.overall == null ? 'n/a' : num(rd.overall) + '/100') + '**.');
      const rationale = arr(rd.rationale)[0];
      if (rationale) out.push('\n' + rationale);
      out.push('');
    } else {
      out.push('_No readiness assessment was produced for this scan._\n');
    }

    out.push('## Findings by Severity\n');
    out.push(sevTotalsLines(report).join('\n'));
    out.push('');

    out.push('## Top Critical Blockers\n');
    const blockers = rd ? arr(rd.blockers) : [];
    if (blockers.length) out.push(blockers.slice(0, 8).map(b => '- ' + b).join('\n'));
    else if (c.critical) out.push('- ' + c.critical + ' critical finding(s) require remediation before release.');
    else out.push('_No critical release blockers identified._');
    out.push('');

    out.push('## Business Risk Summary\n');
    const riskBits = [];
    if (c.critical || c.high) riskBits.push('Unresolved critical/high findings represent direct exposure to security incidents, data loss, or compliance failure if released as-is.');
    if (rd && rd.riskAcceptanceRequired) riskBits.push('Releasing now would require formal, documented risk acceptance by the designated risk owner.');
    if (!riskBits.length) riskBits.push('No material security risks block release; residual risk is within normal operating tolerance.');
    out.push(riskBits.map(x => '- ' + x).join('\n'));
    out.push('');

    out.push('## Compliance Readiness\n');
    const pl = postureLines(report);
    if (pl.length) {
      out.push('Mapped framework posture (indicative — requires compliance-owner review):');
      out.push(pl.join('\n'));
    } else {
      out.push('_No compliance framework posture available for this scan._');
    }
    out.push('');

    out.push('## Recommended Decision & Action\n');
    if (rd) {
      out.push('- **Recommended gate decision:** ' + (rd.decision || 'Requires Manual Review'));
      const approvers = arr(rd.approverRoles);
      if (rd.riskAcceptanceRequired) {
        out.push('- **Risk-owner action:** Sign-off required from ' + (approvers.length ? approvers.join(', ') : 'the designated risk owner(s)') + ' before release, with documented risk acceptance.');
      } else {
        out.push('- **Risk-owner action:** Proceed per standard release governance' + (approvers.length ? ' (' + approvers.join(', ') + ').' : '.'));
      }
    } else {
      out.push('- Manual review recommended — no automated decision available.');
    }
    out.push('');
    out.push('_Generated by CITADEL — Release Readiness. Compliance mappings are indicative and require compliance-owner verification._');
    return out.join('\n');
  }

  /* ===================================================================== *
   *  DEVELOPER REPORT (engineering)
   * ===================================================================== */
  const DEV_CAP_PER_SEV = 50;
  function developer(report) {
    const findings = sortedFindings(report);
    const out = [];
    out.push('# Developer Remediation Report\n');
    const meta = appMeta(report);
    out.push('**Application:** ' + meta.name + (meta.scannedAt ? '  ·  **Scanned:** ' + meta.scannedAt : ''));
    out.push('');
    if (!findings.length) {
      out.push('_No findings to remediate._');
      return out.join('\n');
    }
    // Group by severity, keep original (overall) index for stable ids.
    const idxOf = new Map();
    arr(report && report.findings).forEach((f, i) => { if (!idxOf.has(f)) idxOf.set(f, i); });
    const groups = {};
    SEV_ORDER.forEach(s => { groups[s] = []; });
    findings.forEach(f => {
      const s = String(f.severity || 'info').toLowerCase();
      (groups[s] || groups.info).push(f);
    });

    SEV_ORDER.forEach(sev => {
      const list = groups[sev];
      if (!list || !list.length) return;
      out.push('## ' + cap(sev) + ' (' + list.length + ')\n');
      const shown = list.slice(0, DEV_CAP_PER_SEV);
      shown.forEach(f => {
        const gi = idxOf.has(f) ? idxOf.get(f) : 0;
        out.push('### ' + findingId(f, gi) + ' — ' + (f.name || f.title || 'Finding'));
        const tags = [];
        if (f.module) tags.push('module: ' + f.module);
        if (f.category) tags.push('category: ' + f.category);
        if (f.confidence) tags.push('confidence: ' + f.confidence);
        if (f.cwe) tags.push('CWE: ' + f.cwe);
        if (tags.length) out.push('_' + tags.join('  ·  ') + '_');
        const loc = f.file ? f.file + (f.line != null ? ':' + f.line : '') : '';
        if (loc) out.push('- **Evidence:** `' + loc + '`' + (f.snippet ? ' — `' + String(f.snippet).replace(/`/g, "'") + '`' : ''));
        if (f.impact) out.push('- **Impact:** ' + f.impact);
        if (f.likelihood) out.push('- **Likelihood:** ' + f.likelihood);
        if (f.remediation) out.push('- **Recommended fix:** ' + f.remediation);
        if (f.remediationEffort) out.push('- **Effort:** ' + f.remediationEffort);
        const refs = arr(f.references);
        if (refs.length) out.push('- **References:** ' + refs.join(', '));
        out.push('- **Retest:** Re-run the CITADEL scan after the fix and confirm this finding no longer appears; add a regression test where applicable.');
        out.push('');
      });
      if (list.length > DEV_CAP_PER_SEV) {
        out.push('> +' + (list.length - DEV_CAP_PER_SEV) + ' more ' + sev + ' finding(s) not shown — see the findings CSV/JSON export for the full register.\n');
      }
    });
    out.push('_Generated by CITADEL — Release Readiness (developer view)._');
    return out.join('\n');
  }

  /* ===================================================================== *
   *  AUDITOR REPORT (compliance evidence)
   * ===================================================================== */
  function auditor(report) {
    const rd = rdOf(report);
    const meta = appMeta(report);
    const rev = reviewsOf(report);
    const c = sevCounts(report);
    const out = [];
    out.push('# Auditor / Compliance Evidence Report\n');

    out.push('## Scan Scope\n');
    out.push('- **Application:** ' + meta.name);
    if (meta.scannedAt) out.push('- **Scan date:** ' + meta.scannedAt);
    if (meta.fileCount != null) out.push('- **Files analyzed:** ' + meta.fileCount);
    out.push('- **Languages:** ' + (meta.languages.length ? meta.languages.join(', ') : (meta.primaryLang || 'n/a')));
    out.push('');

    out.push('## Methodology & Modules\n');
    out.push('Static, browser-local analysis (no source transmitted off-device). Modules engaged:');
    const modules = ['Security findings engine', 'Dependency & SBOM review'];
    if (rev.logging) modules.push('Logging & auditability review');
    if (rev.testing) modules.push('Test readiness review');
    if (rev.architecture) modules.push('Architecture risk review');
    if (rev.threatModel) modules.push('STRIDE threat model');
    modules.push('Release-readiness security gate');
    out.push(modules.map(m => '- ' + m).join('\n'));
    out.push('');

    out.push('## Findings Summary\n');
    out.push(mdTable(['Severity', 'Count'], SEV_ORDER.map(s => [cap(s), c[s]])));
    out.push('');

    out.push('## Compliance Mappings\n');
    const mapRows = [];
    arr(report && report.findings).forEach((f, i) => {
      arr(f.complianceMappings).forEach(m => {
        mapRows.push([m.framework || '', m.control || '', findingId(f, i), m.note || 'Requires compliance owner review']);
      });
    });
    if (mapRows.length) {
      out.push(mdTable(['Framework', 'Control', 'Finding', 'Note'], mapRows));
    } else {
      out.push('_No finding-level compliance mappings emitted._');
    }
    out.push('');
    const pl = postureLines(report);
    if (pl.length) {
      out.push('### Framework Posture (indicative)\n');
      out.push(pl.join('\n'));
      out.push('');
    }

    out.push('## Evidence Artifacts\n');
    out.push([
      '- Release-readiness decision record (this assessment)',
      '- Findings register (CSV / JSON export)',
      '- Software Bill of Materials (SBOM) — dependency review export',
      '- STRIDE threat model artifact',
      '- Logging, testing, and architecture review summaries'
    ].join('\n'));
    out.push('');

    out.push('## Risk Acceptance Items\n');
    if (rd && rd.riskAcceptanceRequired) {
      out.push('Formal risk acceptance is required prior to release. Items requiring sign-off:');
      const items = arr(rd.blockers).length ? arr(rd.blockers) : arr(rd.requiredRemediation);
      out.push((items.length ? items.map(x => '- ' + x) : ['- See required remediation below.']).join('\n'));
      const approvers = arr(rd.approverRoles);
      out.push('\n**Designated approvers:** ' + (approvers.length ? approvers.join(', ') : 'risk owner(s) to be assigned') + '.');
    } else {
      out.push('_No formal risk acceptance required for this assessment._');
    }
    const acceptedItems = arr(rd && rd.acceptedRisks);
    if (acceptedItems.length) {
      out.push('\n**Reviewer-accepted findings** (' + acceptedItems.length + ') — formally dispositioned as accepted risk:');
      out.push(acceptedItems.map(a => '- [' + String(a.severity || '').toUpperCase() + '] ' + (a.title || 'Finding')
        + (a.file ? ' — `' + a.file + '`' : '')
        + (a.approver ? ' — approved by: ' + a.approver : '')
        + (a.acceptedUntil ? ' — until: ' + a.acceptedUntil : '')
        + (a.note ? ' — reviewer note: ' + a.note : '')).join('\n'));
    }
    const expired = (rd && rd.expiredAcceptances) | 0;
    if (expired) out.push('\n**' + expired + ' risk acceptance(s) have EXPIRED** and are blocking again pending re-review/re-approval.');
    out.push('');

    out.push('## Remediation Tracking\n');
    const required = rd ? arr(rd.requiredRemediation) : [];
    const after = rd ? arr(rd.afterRemediation) : [];
    const trackRows = [];
    required.forEach(x => trackRows.push([x, 'Before release', 'Open']));
    after.forEach(x => trackRows.push([x, 'After release', 'Open']));
    if (trackRows.length) out.push(mdTable(['Item', 'Timing', 'Status'], trackRows));
    else out.push('_No remediation items tracked._');
    out.push('');

    out.push('## Final Decision\n');
    out.push(rd ? ('**' + (rd.decision || 'Requires Manual Review') + '** (overall '
      + (rd.overall == null ? 'n/a' : num(rd.overall) + '/100') + ')') : '_No automated decision available._');
    out.push('');

    out.push('## Reviewer Notes\n');
    out.push('_[Compliance reviewer to complete: scope confirmation, sampling notes, exceptions, and sign-off.]_');
    out.push('');

    if (meta.scannedAt) out.push('_Export timestamp: ' + meta.scannedAt + '_');
    out.push('_Generated by CITADEL — Release Readiness (auditor view). Compliance mappings are indicative (potential evidence support / potential control weakness) and require compliance-owner verification — they do not constitute certification._');
    return out.join('\n');
  }

  /* ===================================================================== *
   *  COMBINED MARKDOWN
   * ===================================================================== */
  function markdown(report) {
    return [
      executive(report),
      '\n\n---\n',
      developer(report),
      '\n\n---\n',
      auditor(report)
    ].join('\n');
  }

  /* ===================================================================== *
   *  STANDALONE HTML
   * ===================================================================== */
  function mdToHtml(md) {
    // Minimal, escape-first Markdown -> HTML for the combined report. Handles
    // headings, list items, tables, bold, inline code. Everything is escaped
    // first so no user content can inject markup.
    const lines = String(md == null ? '' : md).split('\n');
    const htmlParts = [];
    let inList = false, inTable = false, tableHeader = false;
    const inline = s => esc(s)
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/_([^_]+)_/g, '<em>$1</em>');
    const closeList = () => { if (inList) { htmlParts.push('</ul>'); inList = false; } };
    const closeTable = () => { if (inTable) { htmlParts.push('</tbody></table>'); inTable = false; tableHeader = false; } };
    lines.forEach(raw => {
      const line = raw.replace(/\s+$/, '');
      const h = /^(#{1,6})\s+(.*)$/.exec(line);
      if (h) { closeList(); closeTable(); htmlParts.push('<h' + h[1].length + '>' + inline(h[2]) + '</h' + h[1].length + '>'); return; }
      if (/^\s*\|.*\|\s*$/.test(line)) {
        const cells = line.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(c => c.trim());
        if (/^[:\- ]+$/.test(cells.join(''))) { return; } // separator row
        if (!inTable) { closeList(); htmlParts.push('<table><thead>'); inTable = true; tableHeader = true; }
        if (tableHeader) {
          htmlParts.push('<tr>' + cells.map(c => '<th>' + inline(c) + '</th>').join('') + '</tr></thead><tbody>');
          tableHeader = false;
        } else {
          htmlParts.push('<tr>' + cells.map(c => '<td>' + inline(c) + '</td>').join('') + '</tr>');
        }
        return;
      }
      closeTable();
      const li = /^[-*]\s+(.*)$/.exec(line);
      if (li) { if (!inList) { htmlParts.push('<ul>'); inList = true; } htmlParts.push('<li>' + inline(li[1]) + '</li>'); return; }
      const bq = /^>\s+(.*)$/.exec(line);
      if (bq) { closeList(); htmlParts.push('<blockquote>' + inline(bq[1]) + '</blockquote>'); return; }
      closeList();
      if (line.trim() === '' || line.trim() === '---') { return; }
      htmlParts.push('<p>' + inline(line) + '</p>');
    });
    closeList(); closeTable();
    return htmlParts.join('\n');
  }

  function html(report) {
    const meta = appMeta(report);
    const title = 'CITADEL Release Readiness — ' + meta.name;
    const body = mdToHtml(markdown(report));
    const style = 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#1a1a1a;}'
      + 'h1{border-bottom:2px solid #ddd;padding-bottom:.3rem;}h2{margin-top:2rem;border-bottom:1px solid #eee;padding-bottom:.2rem;}h3{margin-top:1.2rem;}'
      + 'table{border-collapse:collapse;width:100%;margin:.5rem 0;}th,td{border:1px solid #ddd;padding:.4rem .6rem;text-align:left;font-size:.9rem;vertical-align:top;}th{background:#f5f5f5;}'
      + 'code{background:#f5f5f5;padding:.1rem .3rem;border-radius:3px;font-size:.85rem;}'
      + 'blockquote{border-left:3px solid #ccc;margin:.5rem 0;padding:.2rem .8rem;color:#555;}'
      + 'ul{margin:.4rem 0;}@media print{body{margin:0;max-width:none;}}';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width, initial-scale=1">'
      + '<title>' + esc(title) + '</title><style>' + style + '</style></head><body>'
      + body + '</body></html>';
  }

  /* ===================================================================== *
   *  JSON
   * ===================================================================== */
  function json(report) {
    const reviews = reviewsOf(report);
    const payload = {
      readiness: rdOf(report),
      // Reflect reviewer threat-model edits (the per-project overlay) in the export.
      reviews: reviews && reviews.threatModel
        ? Object.assign({}, reviews, { threatModel: applyTM(reviews.threatModel) })
        : reviews
    };
    if (payload.readiness == null) payload.readiness = null;
    return JSON.stringify(payload, null, 2);
  }

  /* ===================================================================== *
   *  CSV (findings register)
   * ===================================================================== */
  function csvCell(s) { return '"' + String(s == null ? '' : s).replace(/"/g, '""') + '"'; }
  function csv(report) {
    const header = ['id', 'module', 'severity', 'confidence', 'title', 'file', 'line', 'remediation'];
    const rows = [header];
    const idxOf = new Map();
    arr(report && report.findings).forEach((f, i) => { if (!idxOf.has(f)) idxOf.set(f, i); });
    sortedFindings(report).forEach(f => {
      const gi = idxOf.has(f) ? idxOf.get(f) : 0;
      rows.push([
        findingId(f, gi),
        f.module || '',
        f.severity || '',
        f.confidence || '',
        f.name || f.title || '',
        f.file || '',
        f.line == null ? '' : f.line,
        f.remediation || ''
      ]);
    });
    return rows.map(r => r.map(csvCell).join(',')).join('\r\n');
  }

  CITADEL.reviewReport = {
    render: render,
    executive: executive,
    developer: developer,
    auditor: auditor,
    markdown: markdown,
    html: html,
    json: json,
    csv: csv
  };
})(typeof window !== 'undefined' ? window : this);
