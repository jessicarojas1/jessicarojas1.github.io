<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SSP — <?= Security::h($plan['title']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script nonce="<?= Security::nonce() ?>">(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})()</script>
<style>
  *, *::before, *::after { box-sizing: border-box; }

  :root {
    --ssp-bg: #fff;
    --ssp-text: #1a1a2e;
    --ssp-muted: #475569;
    --ssp-subtle: #64748b;
    --ssp-border: #e2e8f0;
    --ssp-surface: #f8fafc;
    --ssp-domain-bg: #eef2ff;
    --ssp-domain-border: #4f46e5;
    --ssp-domain-text: #3730a3;
    --ssp-accent: #4f46e5;
    --ssp-accent-hover: #4338ca;
    --ssp-link: #4f46e5;
    --ssp-input-bg: #fff;
    --ssp-toc-dot: #ccc;
    --ssp-meta-border: #ddd;
    --ssp-meta-label: #666;
    --ssp-cover-sub: #555;
  }

  html[data-theme="dark"] {
    --ssp-bg: #0f172a;
    --ssp-text: #e2e8f0;
    --ssp-muted: #94a3b8;
    --ssp-subtle: #64748b;
    --ssp-border: #334155;
    --ssp-surface: #1e293b;
    --ssp-domain-bg: #1e1b4b;
    --ssp-domain-border: #818cf8;
    --ssp-domain-text: #a5b4fc;
    --ssp-accent: #6366f1;
    --ssp-accent-hover: #4f46e5;
    --ssp-link: #818cf8;
    --ssp-input-bg: #1e293b;
    --ssp-toc-dot: #334155;
    --ssp-meta-border: #334155;
    --ssp-meta-label: #64748b;
    --ssp-cover-sub: #94a3b8;
  }

  body { font-family: 'Georgia', serif; color: var(--ssp-text); background: var(--ssp-bg); margin: 0; font-size: 14px; transition: background 0.2s, color 0.2s; }
  a { color: var(--ssp-link); }

  /* Toolbar */
  .ssp-toolbar { position: fixed; top: 16px; right: 20px; display: flex; gap: 8px; align-items: center; z-index: 200; }
  .tbtn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 600; font-family: 'Georgia', serif; border: none; transition: background 0.15s; white-space: nowrap; line-height: 1; }
  .tbtn-primary { background: var(--ssp-accent); color: #fff; box-shadow: 0 4px 12px rgba(79,70,229,0.3); }
  .tbtn-primary:hover { background: var(--ssp-accent-hover); }
  .tbtn-secondary { background: rgba(255,255,255,0.9); color: var(--ssp-accent); border: 1px solid rgba(79,70,229,0.3) !important; }
  html[data-theme="dark"] .tbtn-secondary { background: rgba(30,27,75,0.95); color: #a5b4fc; border-color: rgba(129,140,248,0.4) !important; }
  .tbtn-secondary:hover { background: rgba(79,70,229,0.08); }
  .tbtn-icon { padding: 9px 11px; }
  @media print { .ssp-toolbar { display: none !important; } }

  /* Cover page */
  .cover { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 60px 40px; border-bottom: 3px solid var(--ssp-accent); }
  .cover-logo { font-size: 2.5rem; color: var(--ssp-accent); margin-bottom: 12px; }
  .cover h1 { font-size: 2rem; margin: 0 0 8px; }
  .cover .subtitle { font-size: 1.1rem; color: var(--ssp-cover-sub); margin-bottom: 40px; }
  .cover-meta { border: 1px solid var(--ssp-meta-border); background: var(--ssp-surface); border-radius: 8px; padding: 24px 40px; display: inline-block; text-align: left; min-width: 400px; }
  .cover-meta table { width: 100%; border-collapse: collapse; }
  .cover-meta td { padding: 6px 4px; font-size: 0.875rem; }
  .cover-meta td:first-child { color: var(--ssp-meta-label); width: 160px; font-weight: 600; }
  .impact-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
  .impact-badge { padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
  .impact-low      { background: rgba(5,150,105,0.15);  color: var(--success); }
  .impact-moderate { background: rgba(217,119,6,0.15);  color: var(--warning); }
  .impact-high     { background: rgba(220,38,38,0.15);  color: var(--danger); }

  /* TOC */
  .toc { page-break-before: always; padding: 50px 60px; }
  .toc h2 { font-size: 1.5rem; margin-bottom: 20px; border-bottom: 2px solid var(--ssp-accent); padding-bottom: 8px; }
  .toc ul { list-style: none; padding: 0; margin: 0; }
  .toc li { padding: 4px 0; border-bottom: 1px dotted var(--ssp-toc-dot); display: flex; justify-content: space-between; }
  .toc a { text-decoration: none; color: var(--ssp-text); }

  /* Sections */
  .section { page-break-before: always; padding: 40px 60px; }
  .section-header { background: var(--ssp-accent); color: #fff; padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; }
  .section-header h2 { margin: 0 0 4px; font-size: 1.25rem; }
  .section-header .pkg-meta { font-size: 0.8rem; opacity: 0.85; }

  /* Domain */
  .domain { margin-bottom: 32px; }
  .domain-header { background: var(--ssp-domain-bg); border-left: 4px solid var(--ssp-domain-border); padding: 10px 16px; margin-bottom: 16px; border-radius: 0 6px 6px 0; }
  .domain-header h3 { margin: 0; font-size: 1rem; color: var(--ssp-domain-text); }

  /* Control */
  .control { border: 1px solid var(--ssp-border); border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
  .control-header { background: var(--ssp-surface); padding: 12px 16px; border-bottom: 1px solid var(--ssp-border); display: flex; align-items: flex-start; gap: 12px; }
  .control-code { background: var(--ssp-accent); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; font-family: monospace; }
  .control-title { font-weight: 600; font-size: 0.9rem; flex: 1; }
  .status-badge { padding: 2px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
  .status-compliant      { background: rgba(5,150,105,0.15);   color: var(--success); }
  .status-partial        { background: rgba(217,119,6,0.15);   color: var(--warning); }
  .status-non_compliant  { background: rgba(220,38,38,0.15);   color: var(--danger); }
  .status-not_applicable { background: rgba(100,116,139,0.15); color: #64748b; }
  .status-default        { background: rgba(100,116,139,0.15); color: #64748b; }

  .control-body { padding: 16px; background: var(--ssp-bg); }
  .control-desc { font-size: 0.83rem; color: var(--ssp-muted); margin-bottom: 14px; line-height: 1.6; }
  .control-desc:empty { display: none; }

  .field-block { margin-bottom: 14px; }
  .field-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ssp-subtle); margin-bottom: 6px; }
  .field-content { font-size: 0.86rem; line-height: 1.6; white-space: pre-wrap; }
  .field-empty { color: var(--ssp-muted); font-style: italic; font-size: 0.83rem; }

  .editable-area { width: 100%; border: 1px solid var(--ssp-border); border-radius: 6px; padding: 10px 12px; font-size: 0.86rem; font-family: inherit; line-height: 1.6; resize: vertical; min-height: 80px; transition: border-color 0.2s; background: var(--ssp-input-bg); color: var(--ssp-text); }
  .editable-area:focus { outline: none; border-color: var(--ssp-accent); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
  .save-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
  .save-btn { background: var(--ssp-accent); color: #fff; border: none; border-radius: 6px; padding: 5px 14px; font-size: 0.8rem; cursor: pointer; font-family: inherit; }
  .save-btn:hover { background: var(--ssp-accent-hover); }
  .save-status { font-size: 0.78rem; color: #10b981; display: none; }
  .save-status.error { color: #ef4444; }

  .assignee-line { font-size: 0.8rem; color: var(--ssp-subtle); margin-top: 4px; }

  /* Print */
  @media print {
    body { font-size: 12px; color: #1a1a2e !important; background: #fff !important; }
    .save-row, .save-btn, .save-status { display: none !important; }
    .editable-area { border: none; padding: 0; resize: none; background: transparent !important; color: #1a1a2e !important; }
    .control { break-inside: avoid; }
    .section { page-break-before: always; padding: 20px 30px; }
    .cover { page-break-after: always; }
    .toc { page-break-after: always; }
  }
</style>
</head>
<body>

<!-- Toolbar -->
<div class="ssp-toolbar" id="sspToolbar">
  <button class="tbtn tbtn-secondary tbtn-icon" id="btnToggleDark" title="Toggle dark mode">
    <i class="bi bi-moon-stars-fill" id="darkModeIcon"></i>
  </button>
  <button class="tbtn tbtn-secondary" id="btnWord">
    <i class="bi bi-file-word-fill"></i> Export Word
  </button>
  <button class="tbtn tbtn-primary" id="btnPrint">
    <i class="bi bi-printer-fill"></i> Print / Save PDF
  </button>
</div>

<!-- Cover Page -->
<div class="cover">
  <div class="cover-logo"><i class="bi bi-shield-fill-check"></i></div>
  <div style="font-size:0.9rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--ssp-accent);margin-bottom:8px;"><?= Security::h($orgName) ?></div>
  <h1>System Security Plan</h1>
  <div class="subtitle"><?= Security::h($plan['title']) ?></div>

  <div class="cover-meta">
    <table>
      <?php if ($plan['system_name']): ?>
      <tr><td>System Name</td><td><?= Security::h($plan['system_name']) ?></td></tr>
      <?php endif; ?>
      <tr><td>System Owner</td><td><?= Security::h($plan['system_owner'] ?: '—') ?></td></tr>
      <?php if ($plan['system_owner_email']): ?>
      <tr><td>Owner Email</td><td><?= Security::h($plan['system_owner_email']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Information Owner</td><td><?= Security::h($plan['information_owner'] ?: '—') ?></td></tr>
      <tr><td>Auth. Official</td><td><?= Security::h($plan['authorizing_official'] ?: '—') ?></td></tr>
      <tr><td>Auth. Date</td><td><?= $plan['authorization_date'] ? date('F j, Y', strtotime($plan['authorization_date'])) : '—' ?></td></tr>
      <tr><td>Next Review</td><td><?= $plan['next_review_date'] ? date('F j, Y', strtotime($plan['next_review_date'])) : '—' ?></td></tr>
      <tr><td>Prepared</td><td><?= date('F j, Y') ?></td></tr>
      <tr>
        <td>Impact Levels</td>
        <td>
          <div class="impact-badges">
            <span class="impact-badge impact-<?= $plan['confidentiality_impact'] ?>">C: <?= ucfirst($plan['confidentiality_impact']) ?></span>
            <span class="impact-badge impact-<?= $plan['integrity_impact'] ?>">I: <?= ucfirst($plan['integrity_impact']) ?></span>
            <span class="impact-badge impact-<?= $plan['availability_impact'] ?>">A: <?= ucfirst($plan['availability_impact']) ?></span>
          </div>
        </td>
      </tr>
    </table>
  </div>

  <?php if ($plan['system_description']): ?>
  <div style="max-width:600px;margin-top:32px;font-size:0.875rem;color:var(--ssp-muted);line-height:1.7;text-align:left;">
    <strong>System Description:</strong> <?= Security::h($plan['system_description']) ?>
  </div>
  <?php endif; ?>
</div>

<!-- Table of Contents -->
<div class="toc">
  <h2>Table of Contents</h2>
  <ul>
    <?php foreach ($sections as $i => $sec): ?>
    <li>
      <a href="#section-<?= (int)$sec['package']['id'] ?>"><?= Security::h($sec['package']['standard_code']) ?> — <?= Security::h($sec['package']['name']) ?></a>
      <span style="color:var(--ssp-muted);">Section <?= ($i + 1) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php foreach ($sections as $sec): ?>
<div class="section" id="section-<?= (int)$sec['package']['id'] ?>">
  <div class="section-header">
    <h2><?= Security::h($sec['package']['standard_name']) ?></h2>
    <div class="pkg-meta">
      <?= Security::h($sec['package']['name']) ?>
      <?php if ($sec['package']['version']): ?> · Version <?= Security::h($sec['package']['version']) ?><?php endif; ?>
    </div>
  </div>

  <?php if (empty($sec['domains'])): ?>
  <p style="color:var(--ssp-muted);font-style:italic;">No domains found in this package.</p>
  <?php endif; ?>

  <?php foreach ($sec['domains'] as $domain): ?>
  <div class="domain">
    <div class="domain-header">
      <h3><?= Security::h($domain['code']) ?> — <?= Security::h($domain['title']) ?></h3>
    </div>

    <?php if (empty($domain['controls'])): ?>
    <p style="color:var(--ssp-muted);font-size:0.83rem;padding-left:16px;">No controls in this domain.</p>
    <?php endif; ?>

    <?php foreach ($domain['controls'] as $ctrl):
      $statusKey = $ctrl['status'] ?: 'default';
      $statusLabels = [
        'compliant'      => 'Compliant',
        'partial'        => 'Partial',
        'non_compliant'  => 'Non-Compliant',
        'not_applicable' => 'N/A',
        'default'        => 'Not Assessed',
      ];
      $statusLabel = $statusLabels[$statusKey] ?? 'Not Assessed';
    ?>
    <div class="control">
      <div class="control-header">
        <span class="control-code"><?= Security::h($ctrl['code']) ?></span>
        <div class="control-title"><?= Security::h($ctrl['title']) ?></div>
        <span class="status-badge status-<?= Security::h($statusKey) ?>"><?= $statusLabel ?></span>
      </div>
      <div class="control-body">
        <?php if ($ctrl['description']): ?>
        <div class="control-desc"><?= nl2br(Security::h($ctrl['description'])) ?></div>
        <?php endif; ?>

        <?php if ($ctrl['assignee_name'] || $ctrl['implementation_notes']): ?>
        <div class="field-block">
          <div class="field-label">Implementation Notes (from Compliance)</div>
          <?php if ($ctrl['implementation_notes']): ?>
          <div class="field-content"><?= nl2br(Security::h($ctrl['implementation_notes'])) ?></div>
          <?php else: ?>
          <div class="field-empty">No implementation notes recorded.</div>
          <?php endif; ?>
          <?php if ($ctrl['assignee_name']): ?>
          <div class="assignee-line">Control Owner: <?= Security::h($ctrl['assignee_name']) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SSP Implementation Statement (editable) -->
        <div class="field-block">
          <div class="field-label">SSP Implementation Statement</div>
          <textarea
            class="editable-area"
            data-field="implementation_statement"
            data-ssp="<?= (int)$plan['id'] ?>"
            data-obj="<?= (int)$ctrl['id'] ?>"
            placeholder="Describe how this control is implemented in your environment…"><?= Security::h($ctrl['implementation_statement'] ?? '') ?></textarea>
          <div class="save-row">
            <button class="save-btn" data-save-field="implementation_statement">Save</button>
            <span class="save-status"></span>
          </div>
        </div>

        <!-- Objective-level responses -->
        <div class="field-block">
          <div class="field-label">Objective-Level Responses
            <span style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:6px;color:var(--ssp-muted);font-size:0.7rem;">e.g. <?= Security::h($ctrl['code']) ?>[a]: The organization…</span>
          </div>
          <textarea
            class="editable-area"
            data-field="objective_responses"
            data-ssp="<?= (int)$plan['id'] ?>"
            data-obj="<?= (int)$ctrl['id'] ?>"
            placeholder="<?= Security::h($ctrl['code']) ?>[a]: …&#10;<?= Security::h($ctrl['code']) ?>[b]: …"
            rows="5"><?= Security::h($ctrl['objective_responses'] ?? '') ?></textarea>
          <div class="save-row">
            <button class="save-btn" data-save-field="objective_responses">Save</button>
            <span class="save-status"></span>
          </div>
        </div>

        <!-- Responsible Roles -->
        <div class="field-block">
          <div class="field-label">Responsible Roles</div>
          <textarea
            class="editable-area"
            data-field="responsible_roles"
            data-ssp="<?= (int)$plan['id'] ?>"
            data-obj="<?= (int)$ctrl['id'] ?>"
            placeholder="e.g. CISO, System Administrator, Data Owner"
            rows="2"><?= Security::h($ctrl['responsible_roles'] ?? '') ?></textarea>
          <div class="save-row">
            <button class="save-btn" data-save-field="responsible_roles">Save</button>
            <span class="save-status"></span>
          </div>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<script nonce="<?= Security::nonce() ?>">
// Dark mode
(function() {
  var icon = document.getElementById('darkModeIcon');
  if (document.documentElement.getAttribute('data-theme') === 'dark' && icon) {
    icon.className = 'bi bi-sun-fill';
  }
})();

document.getElementById('btnToggleDark').addEventListener('click', function() {
  var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
  var icon = document.getElementById('darkModeIcon');
  if (icon) icon.className = next === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
});

// Print
document.getElementById('btnPrint').addEventListener('click', function() { window.print(); });

// Word export
document.getElementById('btnWord').addEventListener('click', function() {
  var values = [];
  document.querySelectorAll('.editable-area').forEach(function(ta) { values.push(ta.value); });

  var bodyClone = document.body.cloneNode(true);
  bodyClone.querySelector('#sspToolbar') && bodyClone.querySelector('#sspToolbar').remove();
  bodyClone.querySelectorAll('.save-row').forEach(function(el) { el.remove(); });
  bodyClone.querySelectorAll('.editable-area').forEach(function(ta, i) {
    var p = document.createElement('p');
    p.style.cssText = 'white-space:pre-wrap;margin:4px 0;font-size:11pt;line-height:1.6';
    p.textContent = values[i] || '';
    ta.parentNode.replaceChild(p, ta);
  });

  var css = [
    'body{font-family:Calibri,sans-serif;color:#1a1a2e;margin:0;font-size:11pt}',
    'a{color:#4f46e5}.ssp-toolbar{display:none}',
    '.cover{text-align:center;padding:60px 40px;border-bottom:3px solid #4f46e5;page-break-after:always}',
    '.cover h1{font-size:22pt}.cover .subtitle{color:#555;font-size:13pt}',
    '.cover-meta{border:1px solid #ddd;border-radius:8px;padding:20px 32px;display:inline-block;text-align:left;min-width:380px;margin-top:24px;background:#f8fafc}',
    '.cover-meta td{padding:5px 4px;font-size:10pt}.cover-meta td:first-child{color:#666;width:150px;font-weight:600}',
    '.impact-badge{padding:2px 8px;border-radius:4px;font-size:9pt;font-weight:700}',
    '.impact-low{background:#d1fae5;color:#065f46}.impact-moderate{background:#fef3c7;color:#92400e}.impact-high{background:#fee2e2;color:#991b1b}',
    '.toc{padding:40px 60px;page-break-after:always}.toc h2{font-size:16pt;border-bottom:2px solid #4f46e5;padding-bottom:6px}',
    '.toc ul{list-style:none;padding:0}.toc li{padding:3px 0;border-bottom:1px dotted #ccc;display:flex;justify-content:space-between}',
    '.toc a{text-decoration:none;color:#1a1a2e}',
    '.section{padding:30px 60px;page-break-before:always}',
    '.section-header{background:#4f46e5;color:#fff;padding:12px 16px;border-radius:6px;margin-bottom:20px}',
    '.section-header h2{margin:0 0 2px;font-size:14pt}.pkg-meta{font-size:9pt;opacity:0.85}',
    '.domain{margin-bottom:24px}.domain-header{background:#eef2ff;border-left:4px solid #4f46e5;padding:8px 12px;margin-bottom:12px;border-radius:0 4px 4px 0}',
    '.domain-header h3{margin:0;font-size:11pt;color:#3730a3}',
    '.control{border:1px solid #e2e8f0;border-radius:6px;margin-bottom:14px;page-break-inside:avoid}',
    '.control-header{background:#f8fafc;padding:10px 14px;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;align-items:flex-start}',
    '.control-code{background:#4f46e5;color:#fff;padding:2px 8px;border-radius:12px;font-size:8pt;font-weight:700;font-family:monospace;white-space:nowrap}',
    '.control-title{font-weight:600;font-size:10pt;flex:1}',
    '.status-badge{padding:2px 8px;border-radius:12px;font-size:8pt;font-weight:700;text-transform:uppercase;white-space:nowrap}',
    '.status-compliant{background:#d1fae5;color:#065f46}.status-partial{background:#fef3c7;color:#92400e}',
    '.status-non_compliant{background:#fee2e2;color:#991b1b}.status-not_applicable,.status-default{background:#f1f5f9;color:#64748b}',
    '.control-body{padding:12px 14px}.control-desc{font-size:9pt;color:#475569;margin-bottom:10px;line-height:1.5}',
    '.field-block{margin-bottom:10px}.field-label{font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:4px}',
    '.field-content,.assignee-line{font-size:10pt;line-height:1.5;white-space:pre-wrap}.assignee-line{font-size:8.5pt;color:#64748b;margin-top:2px}',
  ].join('\n');

  var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' + css + '</style></head><body>' + bodyClone.innerHTML + '</body></html>';
  var blob = new Blob(['﻿', html], { type: 'application/msword' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'System_Security_Plan.doc';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(function() { URL.revokeObjectURL(a.href); }, 1000);
});

// Save statements
document.querySelectorAll('.save-btn').forEach(function(btn) {
  btn.addEventListener('click', function(){ saveStatement(btn, btn.dataset.saveField); });
});
let _csrf = <?= json_encode(Security::generateCsrfToken()) ?>;

function saveStatement(btn, field) {
  const row   = btn.closest('.control-body');
  const ta    = row.querySelector('[data-field="' + field + '"]');
  const status= btn.nextElementSibling;
  const sspId = ta.dataset.ssp;
  const objId = ta.dataset.obj;

  const impl  = row.querySelector('[data-field="implementation_statement"]')?.value ?? '';
  const roles = row.querySelector('[data-field="responsible_roles"]')?.value ?? '';
  const objr  = row.querySelector('[data-field="objective_responses"]')?.value ?? '';

  btn.disabled = true;
  btn.textContent = 'Saving…';

  const body = new URLSearchParams({
    csrf_token: _csrf,
    implementation_statement: impl,
    responsible_roles: roles,
    objective_responses: objr,
  });

  fetch('/ssp/' + sspId + '/statement/' + objId + '/save', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (data.csrf) _csrf = data.csrf;
        status.style.display = 'inline';
        status.className = 'save-status';
        status.textContent = '✓ Saved';
        setTimeout(() => { status.style.display = 'none'; }, 2500);
      } else {
        throw new Error('Save failed');
      }
    })
    .catch(() => {
      status.style.display = 'inline';
      status.className = 'save-status error';
      status.textContent = '✗ Error saving';
    })
    .finally(() => {
      btn.disabled = false;
      btn.textContent = 'Save';
    });
}
</script>
</body>
</html>
