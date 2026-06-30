/* CITADEL — cross-project portfolio dashboard.
 *
 * Aggregates the latest release-gate decision + readiness score (and the score
 * trend) across ALL projects into one executive view, so leadership can see, at
 * a glance, which applications are blocked vs ready. Reads from the per-browser
 * project list + scan history (each scan carries readinessDecision /
 * readinessOverall), so it works with no backend.
 *
 * window.CITADEL.portfolio
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function $(id) { return document.getElementById(id); }

  const DECISIONS = ['Rejected', 'Requires Manual Review', 'Conditional Approval', 'Approved'];
  function decisionClass(d) {
    switch (String(d || '')) {
      case 'Approved': return 'text-bg-success';
      case 'Conditional Approval': return 'text-bg-warning';
      case 'Requires Manual Review': return 'text-bg-info';
      case 'Rejected': return 'text-bg-danger';
      default: return 'text-bg-secondary';
    }
  }
  // Minimal trend sparkline (mirrors report.js; CSS-var stroke = dark-mode safe).
  function sparkline(values) {
    const vals = (values || []).filter(v => typeof v === 'number');
    if (vals.length < 2) return '';
    const w = 110, h = 22, pad = 3;
    const min = Math.min.apply(null, vals), max = Math.max.apply(null, vals), range = (max - min) || 1, n = vals.length;
    const pts = vals.map((v, i) => (pad + (i / (n - 1)) * (w - 2 * pad)).toFixed(1) + ',' + (h - pad - ((v - min) / range) * (h - 2 * pad)).toFixed(1));
    const last = vals[vals.length - 1];
    const col = last >= 80 ? 'var(--bs-success,#16a34a)' : last >= 50 ? 'var(--bs-warning,#f59e0b)' : 'var(--bs-danger,#ef4444)';
    return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true">'
      + '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + col + '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/></svg>';
  }

  // Aggregate the latest decision + score + trend per project.
  function summarize() {
    const projects = (CITADEL.projects && CITADEL.projects.all && CITADEL.projects.all()) || [];
    const hist = (CITADEL.history && CITADEL.history.list && CITADEL.history.list()) || [];
    const byProj = {};
    hist.forEach(h => { (byProj[h.projectId] = byProj[h.projectId] || []).push(h); });   // history is newest-first
    const rows = projects.map(p => {
      const scans = byProj[p.id] || [];
      const latest = scans[0] || null;
      return {
        id: p.id, name: p.name,
        decision: (latest && latest.readinessDecision) || '',
        overall: latest && typeof latest.readinessOverall === 'number' ? latest.readinessOverall : null,
        count: scans.length, lastAt: (latest && latest.at) || '',
        series: scans.slice().reverse().map(s => s.readinessOverall).filter(v => typeof v === 'number')
      };
    });
    const counts = { Rejected: 0, 'Requires Manual Review': 0, 'Conditional Approval': 0, Approved: 0, none: 0 };
    rows.forEach(r => { if (!r.count || !r.decision) counts.none++; else if (counts[r.decision] != null) counts[r.decision]++; });
    const order = {}; DECISIONS.forEach((d, i) => { order[d] = i; });   // riskiest first
    rows.sort((a, b) => (order[a.decision] == null ? 4 : order[a.decision]) - (order[b.decision] == null ? 4 : order[b.decision])
      || (a.overall == null ? 101 : a.overall) - (b.overall == null ? 101 : b.overall));
    return { rows: rows, counts: counts, total: projects.length };
  }

  function render() {
    const el = $('portfolio-view'); if (!el) return;
    const s = summarize();
    if (!s.total) {
      el.innerHTML = '<div class="page-header"><h1 class="page-title"><i class="bi bi-grid-1x2-fill"></i> Portfolio</h1></div>'
        + '<div class="empty-state"><i class="bi bi-folder-plus"></i><p>No projects yet. Create projects and run scans to see a cross-project readiness overview here.</p></div>';
      return;
    }
    const pill = (label, n, cls) => `<span class="badge ${cls} me-1 mb-1">${esc(n)} ${esc(label)}</span>`;
    const rows = s.rows.map(r => `<tr>
        <td><i class="bi bi-folder-fill text-warning"></i> ${esc(r.name)}</td>
        <td>${r.decision ? `<span class="badge ${decisionClass(r.decision)}">${esc(r.decision)}</span>` : '<span class="text-body-secondary small">no scans</span>'}</td>
        <td>${r.overall == null ? '<span class="text-body-secondary">—</span>' : '<strong>' + esc(r.overall) + '</strong>/100'}</td>
        <td>${sparkline(r.series) || '<span class="text-body-secondary small">—</span>'}</td>
        <td class="small text-body-secondary">${esc(r.count)}</td>
        <td class="small text-body-secondary">${r.lastAt ? esc(new Date(r.lastAt).toLocaleDateString()) : '—'}</td>
        <td><button class="btn btn-sm btn-outline-secondary" data-portfolio-open="${esc(r.id)}" title="Open this project's history" aria-label="Open this project's history"><i class="bi bi-clock-history"></i></button></td>
      </tr>`).join('');
    el.innerHTML = `
      <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="page-title mb-0"><i class="bi bi-grid-1x2-fill"></i> Portfolio</h1>
        <button class="btn btn-sm btn-outline-secondary" data-portfolio-close><i class="bi bi-x-lg"></i> Close</button>
      </div>
      <p class="text-body-secondary">Latest release-gate decision and readiness score across all ${esc(s.total)} project(s) — riskiest first.</p>
      <div class="mb-3">
        ${pill('Rejected', s.counts.Rejected, 'text-bg-danger')}
        ${pill('Manual review', s.counts['Requires Manual Review'], 'text-bg-info')}
        ${pill('Conditional', s.counts['Conditional Approval'], 'text-bg-warning')}
        ${pill('Approved', s.counts.Approved, 'text-bg-success')}
        ${pill('No scans', s.counts.none, 'text-bg-secondary')}
      </div>
      <div class="card citadel-card"><div class="card-body">
        <div class="table-responsive"><table class="table table-sm align-middle citadel-table">
          <thead><tr><th>Project</th><th>Latest decision</th><th>Readiness</th><th>Trend</th><th>Scans</th><th>Last scan</th><th></th></tr></thead>
          <tbody>${rows}</tbody></table></div>
      </div></div>`;
  }

  CITADEL.portfolio = { render: render, summarize: summarize };
})(window);
