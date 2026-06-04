<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SSP — <?= Security::h($plan['title']) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Georgia', serif; color: #1a1a2e; background: #fff; margin: 0; font-size: 14px; }
  a { color: var(--primary); }

  /* Cover page */
  .cover { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 60px 40px; border-bottom: 3px solid var(--primary); }
  .cover-logo { font-size: 2.5rem; color: var(--primary); margin-bottom: 12px; }
  .cover h1 { font-size: 2rem; margin: 0 0 8px; }
  .cover .subtitle { font-size: 1.1rem; color: #555; margin-bottom: 40px; }
  .cover-meta { border: 1px solid #ddd; border-radius: 8px; padding: 24px 40px; display: inline-block; text-align: left; min-width: 400px; }
  .cover-meta table { width: 100%; border-collapse: collapse; }
  .cover-meta td { padding: 6px 4px; font-size: 0.875rem; }
  .cover-meta td:first-child { color: #666; width: 160px; font-weight: 600; }
  .impact-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
  .impact-badge { padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
  .impact-low { background: #d1fae5; color: var(--success); }
  .impact-moderate { background: #fef3c7; color: var(--warning); }
  .impact-high { background: #fee2e2; color: #991b1b; }

  /* TOC */
  .toc { page-break-before: always; padding: 50px 60px; }
  .toc h2 { font-size: 1.5rem; margin-bottom: 20px; border-bottom: 2px solid var(--primary); padding-bottom: 8px; }
  .toc ul { list-style: none; padding: 0; margin: 0; }
  .toc li { padding: 4px 0; border-bottom: 1px dotted #ccc; display: flex; justify-content: space-between; }
  .toc a { text-decoration: none; color: #1a1a2e; }

  /* Sections */
  .section { page-break-before: always; padding: 40px 60px; }
  .section-header { background: var(--primary); color: #fff; padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; }
  .section-header h2 { margin: 0 0 4px; font-size: 1.25rem; }
  .section-header .pkg-meta { font-size: 0.8rem; opacity: 0.85; }

  /* Domain */
  .domain { margin-bottom: 32px; }
  .domain-header { background: rgba(11,97,4,.06); border-left: 4px solid var(--primary); padding: 10px 16px; margin-bottom: 16px; border-radius: 0 6px 6px 0; }
  .domain-header h3 { margin: 0; font-size: 1rem; color: var(--primary-dark); }

  /* Control */
  .control { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
  .control-header { background: #f8fafc; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: flex-start; gap: 12px; }
  .control-code { background: var(--primary); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; font-family: monospace; }
  .control-title { font-weight: 600; font-size: 0.9rem; flex: 1; }
  .status-badge { padding: 2px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
  .status-compliant      { background: #d1fae5; color: var(--success); }
  .status-partial        { background: #fef3c7; color: var(--warning); }
  .status-non_compliant  { background: #fee2e2; color: #991b1b; }
  .status-not_applicable { background: #f1f5f9; color: var(--text-muted); }
  .status-default        { background: #f1f5f9; color: var(--text-muted); }

  .control-body { padding: 16px; }
  .control-desc { font-size: 0.83rem; color: var(--text-muted); margin-bottom: 14px; line-height: 1.6; }
  .control-desc:empty { display: none; }

  .field-block { margin-bottom: 14px; }
  .field-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 6px; }
  .field-content { font-size: 0.86rem; line-height: 1.6; white-space: pre-wrap; }
  .field-empty { color: #94a3b8; font-style: italic; font-size: 0.83rem; }

  .editable-area { width: 100%; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 0.86rem; font-family: inherit; line-height: 1.6; resize: vertical; min-height: 80px; transition: border-color 0.2s; background: #fff; color: inherit; }
  .editable-area:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
  .save-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
  .save-btn { background: var(--primary); color: #fff; border: none; border-radius: 6px; padding: 5px 14px; font-size: 0.8rem; cursor: pointer; font-family: inherit; }
  .save-btn:hover { background: var(--primary-dark); }
  .save-status { font-size: 0.78rem; color: #10b981; display: none; }
  .save-status.error { color: #ef4444; }

  .assignee-line { font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; }

  /* Print */
  @media print {
    body { font-size: 12px; }
    .save-row, .save-btn, .save-status { display: none !important; }
    .editable-area { border: none; padding: 0; resize: none; background: transparent; }
    .control { break-inside: avoid; }
    .section { page-break-before: always; padding: 20px 30px; }
    .cover { page-break-after: always; }
    .toc { page-break-after: always; }
  }

  .print-btn { position: fixed; top: 20px; right: 20px; background: var(--primary); color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 0.875rem; font-weight: 600; z-index: 100; box-shadow: 0 4px 12px rgba(79,70,229,0.3); }
  .print-btn:hover { background: var(--primary-dark); }
  @media print { .print-btn { display: none; } }
</style>
</head>
<body>

<button class="print-btn" id="btnPrint"><i>⎙</i> Print / Save PDF</button>

<!-- Cover Page -->
<div class="cover">
  <div class="cover-logo">⚔</div>
  <div style="font-size:0.9rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--primary);margin-bottom:8px;"><?= Security::h($orgName) ?></div>
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
  <div style="max-width:600px;margin-top:32px;font-size:0.875rem;color:var(--text-muted);line-height:1.7;text-align:left;">
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
      <span style="color:#94a3b8;">Section <?= ($i + 1) ?></span>
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
  <p style="color:#94a3b8;font-style:italic;">No domains found in this package.</p>
  <?php endif; ?>

  <?php foreach ($sec['domains'] as $domain): ?>
  <div class="domain">
    <div class="domain-header">
      <h3><?= Security::h($domain['code']) ?> — <?= Security::h($domain['title']) ?></h3>
    </div>

    <?php if (empty($domain['controls'])): ?>
    <p style="color:#94a3b8;font-size:0.83rem;padding-left:16px;">No controls in this domain.</p>
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
            <span style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:6px;color:#94a3b8;font-size:0.7rem;">e.g. <?= Security::h($ctrl['code']) ?>[a]: The organization…</span>
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
document.getElementById('btnPrint').addEventListener('click', function(){ window.print(); });
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

  // Collect all three fields for this control
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
