/* CITADEL — Dependency Approval Workflow.
 *
 * Per-package approval decisions (approved / restricted / prohibited / pending)
 * with justification, approver, and explicit security + license sign-off. A
 * default org policy seeds prohibited/restricted packages; reviewers record
 * decisions which persist (localStorage + Postgres) and become auditor evidence.
 *
 * Runs on the main thread, post-scan (it needs the persisted decisions, which a
 * Web Worker can't see). apply(report) annotates components + emits findings;
 * renderSection(report) draws the approval table into the SBOM tab.
 *
 * window.CITADEL.depapproval
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const KEY = 'citadel.depapproval.v1';
  const STATUSES = ['approved', 'restricted', 'prohibited', 'pending'];
  const LABEL = { approved: 'Approved', restricted: 'Restricted', prohibited: 'Prohibited', pending: 'Pending review' };
  const CAP = 60;

  // Default policy — illustrative, override via CITADEL.dependencyPolicy =
  // { prohibited:[...], restricted:[...] } (lowercase name or 'ecosystem:name' substrings).
  const DEFAULT_POLICY = {
    prohibited: ['event-stream', 'flatmap-stream', 'ua-parser-js', 'node-ipc', 'coa', 'rc', 'colors@1.4.44', 'left-pad'],
    restricted: ['request', 'moment', 'node-sass', 'bower', 'tslint']
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function clean(s, n) { return String(s == null ? '' : s).trim().slice(0, n || 200); }
  function keyOf(c) { return String((c.ecosystem || '') + '|' + (c.name || '')).toLowerCase(); }
  function readAll() { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } }
  function writeAll(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} }
  function policy() {
    const p = CITADEL.dependencyPolicy || {};
    return { prohibited: (p.prohibited || DEFAULT_POLICY.prohibited).map(x => String(x).toLowerCase()),
      restricted: (p.restricted || DEFAULT_POLICY.restricted).map(x => String(x).toLowerCase()) };
  }
  function matches(list, c) {
    const name = String(c.name || '').toLowerCase();
    const nv = name + '@' + String(c.version || '').toLowerCase();
    const eco = String(c.ecosystem || '').toLowerCase() + ':' + name;
    return list.some(p => p === name || p === nv || p === eco || (p.length > 2 && (name === p || nv.indexOf(p) === 0 || eco === p)));
  }
  // Policy-derived status when the reviewer hasn't recorded one.
  function policyStatus(c) {
    const pol = policy();
    if (matches(pol.prohibited, c)) return 'prohibited';
    if (matches(pol.restricted, c)) return 'restricted';
    return 'pending';
  }

  let _server = null;   // { key: decision } when a backend is present
  function serverEnabled() { return !!_server; }
  function blank(status) { return { status: status || 'pending', justification: '', approver: '', securityApproved: false, licenseApproved: false }; }

  function decisionOf(c) {
    const k = keyOf(c);
    const stored = (_server && _server[k]) || readAll()[k];
    if (stored && stored.status) return Object.assign(blank(), stored);
    return blank(policyStatus(c));   // unreviewed → policy default
  }
  function set(c, patch) {
    const k = keyOf(c);
    const cur = decisionOf(c);
    const next = {
      status: STATUSES.indexOf(patch.status) >= 0 ? patch.status : cur.status,
      justification: patch.justification != null ? clean(patch.justification, 4000) : cur.justification,
      approver: patch.approver != null ? clean(patch.approver, 200) : cur.approver,
      securityApproved: patch.securityApproved != null ? !!patch.securityApproved : cur.securityApproved,
      licenseApproved: patch.licenseApproved != null ? !!patch.licenseApproved : cur.licenseApproved,
      decidedAt: patch.decidedAt || new Date().toISOString()
    };
    const all = readAll(); all[k] = next; writeAll(all);
    if (_server) { _server[k] = next; if (CITADEL.api && CITADEL.api.depApprovalSet) CITADEL.api.depApprovalSet(k, next); }
    return next;
  }
  function list() { return serverEnabled() ? _server : readAll(); }
  async function load() {
    if (CITADEL.api && CITADEL.api.depApprovalsList) {
      try { const m = await CITADEL.api.depApprovalsList(); if (m) { _server = m; return; } } catch (e) { /* local */ }
    }
  }

  // Annotate components + emit findings; returns a summary. Called post-scan.
  function apply(report) {
    try {
      const comps = (report && report.sbom && report.sbom.components) || [];
      const summary = { approved: 0, restricted: 0, prohibited: 0, pending: 0, total: comps.length };
      const findings = [];
      comps.forEach(c => {
        const d = decisionOf(c); c.approval = d;
        summary[d.status] = (summary[d.status] || 0) + 1;
        if (d.status === 'prohibited') {
          findings.push(mkFinding(c, 'high', 'Prohibited dependency: ' + c.name,
            'This component is on the prohibited dependency list (policy or an explicit reviewer decision) and must not ship.',
            'Remove ' + c.name + ' and replace it with an approved alternative, or obtain a documented exception from the security + risk owners.'));
        } else if (d.status === 'restricted' && !d.justification) {
          findings.push(mkFinding(c, 'medium', 'Restricted dependency without justification: ' + c.name,
            'This component is restricted and has no recorded business/security justification or approval.',
            'Record a justification and obtain security + license approval, or replace the component.'));
        } else if (d.status === 'pending') {
          // unreviewed — informational, only counted (no finding flood).
        }
      });
      report.approval = { summary: summary };
      return { findings: findings, summary: summary };
    } catch (e) { return { findings: [], summary: { approved: 0, restricted: 0, prohibited: 0, pending: 0, total: 0 } }; }
  }
  function mkFinding(c, severity, name, impact, remediation) {
    return {
      ruleId: 'dep-approval', source: 'dep-approval', module: 'approval', category: 'supply-chain',
      severity: severity, confidence: 'high', cwe: 'CWE-1104',
      name: name, file: c.name + '@' + c.version, line: 0,
      snippet: c.ecosystem + ': ' + c.name + ' ' + c.version,
      impact: impact, likelihood: 'medium', remediationEffort: 'medium', remediation: remediation,
      references: ['https://owasp.org/www-project-software-component-verification-standard/'],
      complianceMappings: [
        { framework: 'OWASP SCVS', control: 'V1 Inventory / V2 Package Management', note: 'Supports approved-component governance.' },
        { framework: 'NIST SSDF', control: 'PW.4.1 / PS.3.1', note: 'Potential evidence of acquisition vetting.' },
        { framework: 'NIST 800-53', control: 'SR-3 / SR-5', note: 'Mapped control impact (supply-chain controls).' }
      ]
    };
  }

  /* ---------- UI (rendered into the SBOM tab) ---------- */
  function badge(status) {
    const cls = status === 'approved' ? 'text-bg-success' : status === 'restricted' ? 'text-bg-warning'
      : status === 'prohibited' ? 'text-bg-danger' : 'text-bg-secondary';
    return `<span class="badge ${cls}">${esc(LABEL[status] || status)}</span>`;
  }
  function renderSection(report) {
    const comps = (report && report.sbom && report.sbom.components) || [];
    if (!comps.length) return '';
    const s = (report.approval && report.approval.summary) || apply(report).summary;
    // Show components needing attention first (prohibited > restricted > pending > approved).
    const order = { prohibited: 0, restricted: 1, pending: 2, approved: 3 };
    const sorted = comps.slice().sort((a, b) => order[decisionOf(a).status] - order[decisionOf(b).status]);
    const shown = sorted.slice(0, CAP);
    const opts = (sel) => STATUSES.map(st => `<option value="${st}"${st === sel ? ' selected' : ''}>${esc(LABEL[st])}</option>`).join('');
    const rows = shown.map(c => {
      const d = decisionOf(c); const k = esc(keyOf(c));
      return `<tr>
          <td><code>${esc(c.name)}</code> <span class="text-body-secondary small">${esc(c.version)}</span></td>
          <td><span class="badge bg-secondary">${esc(c.ecosystem)}</span></td>
          <td><select class="form-select form-select-sm" data-approve-set="${k}" aria-label="Approval status">${opts(d.status)}</select></td>
          <td><input type="text" class="form-control form-control-sm" data-approve-just="${k}" value="${esc(d.justification)}" placeholder="Justification" maxlength="4000"></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" data-approve-flag="${k}:security"${d.securityApproved ? ' checked' : ''} title="Security approved"></td>
          <td class="text-center"><input type="checkbox" class="form-check-input" data-approve-flag="${k}:license"${d.licenseApproved ? ' checked' : ''} title="License approved"></td>
        </tr>`;
    }).join('');
    return `<div class="card citadel-card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="mb-0 text-uppercase text-body-secondary small fw-bold"><i class="bi bi-clipboard-check"></i> Dependency approval</h6>
          <div class="small">
            <span class="badge text-bg-success">${esc(s.approved)} approved</span>
            <span class="badge text-bg-secondary">${esc(s.pending)} pending</span>
            <span class="badge text-bg-warning">${esc(s.restricted)} restricted</span>
            <span class="badge text-bg-danger">${esc(s.prohibited)} prohibited</span>
          </div>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle citadel-table">
          <thead><tr><th>Package</th><th>Eco</th><th>Status</th><th>Justification</th><th class="text-center" title="Security approved">Sec</th><th class="text-center" title="License approved">Lic</th></tr></thead>
          <tbody>${rows}</tbody></table></div>
        ${sorted.length > CAP ? `<div class="small text-body-secondary">Showing ${CAP} of ${sorted.length} components (attention-first). Set a default policy via <code>CITADEL.dependencyPolicy</code>.</div>` : ''}
      </div></div>`;
  }

  // Resolve a component by its key (for event handlers).
  function componentByKey(report, k) {
    const comps = (report && report.sbom && report.sbom.components) || [];
    return comps.find(c => keyOf(c) === k) || null;
  }

  CITADEL.depapproval = { decisionOf, set, list, load, apply, renderSection, componentByKey, keyOf, STATUSES, serverEnabled };
})(window);
