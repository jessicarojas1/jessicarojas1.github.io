<?php
$breadcrumbs   = $breadcrumbs   ?? [['BCP/DRP', '/bcp'], ['View Plan', null]];
$statusColors = ['draft'=>'#6b7280','active'=>'#22c55e','archived'=>'#9ca3af'];
$sc = $statusColors[$plan['status']] ?? '#6b7280';
$outcomeColors = ['passed'=>'#22c55e','passed_with_findings'=>'#f59e0b','failed'=>'#ef4444','cancelled'=>'#9ca3af'];
$canEdit = Auth::can('policy.write');
ob_start(); ?>
<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
      <h1 class="page-title" style="margin:0"><?= Security::h($plan['title']) ?></h1>
      <?php if (!empty($plan['plan_code'])): ?>
        <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-family:monospace;font-size:13px;padding:4px 10px"><?= Security::h($plan['plan_code']) ?></span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle">
      v<?= Security::h($plan['version']) ?>
      <span class="badge" style="background:<?= $sc ?>20;color:<?= $sc ?>;margin-left:8px"><?= ucfirst($plan['status']) ?></span>
      <?php if ($plan['rto_hours']): ?><span class="badge badge-gray" style="margin-left:4px">RTO ≤<?= (int)$plan['rto_hours'] ?>h</span><?php endif; ?>
      <?php if ($plan['rpo_hours']): ?><span class="badge badge-gray" style="margin-left:4px">RPO ≤<?= (int)$plan['rpo_hours'] ?>h</span><?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <button class="btn btn-ghost" id="btnBcpWord"><i class="bi bi-file-word-fill"></i> Word</button>
    <button class="btn btn-ghost" id="btnBcpPrint"><i class="bi bi-printer-fill"></i> Print</button>
    <?php if ($canEdit): ?>
      <button class="btn btn-secondary" data-toggle-class="hidden" data-target="#editPanel">
        <i class="bi bi-pencil"></i> Edit
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Saved successfully.</div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:24px">
  <button class="tab-btn active" data-tab="sections">Plan Sections</button>
  <button class="tab-btn" data-tab="exercises" id="exercises-tab">Exercises</button>
</div>

<div id="tab-sections">
  <?php if (empty($sections)): ?>
    <div class="card"><div class="card-body text-muted">No sections defined yet.</div></div>
  <?php else: foreach ($sections as $sec): ?>
    <div class="card" style="margin-bottom:12px">
      <div class="card-header" style="cursor:pointer" data-toggle-sibling="hidden">
        <span style="text-transform:uppercase;font-size:11px;color:var(--primary);font-weight:600"><?= Security::h($sec['section_type']) ?></span>
        <h3 style="margin:4px 0 0"><?= Security::h($sec['title']) ?></h3>
      </div>
      <div class="card-body">
        <p style="white-space:pre-wrap"><?= Security::h($sec['content'] ?? '') ?></p>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<div id="tab-exercises" class="hidden" id="exercises">
  <?php if (!empty($exercises)): ?>
    <div class="card" style="margin-bottom:16px">
      <table class="data-table">
        <thead><tr><th>Type</th><th>Name</th><th>Scheduled</th><th>Conducted</th><th>Outcome</th><th>Findings</th></tr></thead>
        <tbody>
          <?php foreach ($exercises as $ex): $oc = $outcomeColors[$ex['outcome'] ?? ''] ?? '#9ca3af'; ?>
            <tr>
              <td class="text-sm"><?= Security::h(str_replace('_',' ',ucfirst($ex['exercise_type']))) ?></td>
              <td class="fw-600"><?= Security::h($ex['name']) ?></td>
              <td class="text-sm text-muted"><?= $ex['scheduled_date'] ? date('M j, Y', strtotime($ex['scheduled_date'])) : '—' ?></td>
              <td class="text-sm text-muted"><?= $ex['conducted_date'] ? date('M j, Y', strtotime($ex['conducted_date'])) : '—' ?></td>
              <td><?php if ($ex['outcome']): ?><span class="badge" style="background:<?= $oc ?>20;color:<?= $oc ?>"><?= Security::h(str_replace('_',' ',ucfirst($ex['outcome'] ?? ''))) ?></span><?php else: ?>—<?php endif; ?></td>
              <td class="text-sm text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= Security::h(substr($ex['findings'] ?? '', 0, 80)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (Auth::requireAuth() !== false): ?>
  <div class="card">
    <div class="card-header"><h3>Log Exercise</h3></div>
    <form method="POST" action="/bcp/<?= (int)$plan['id'] ?>/add-exercise">
      <?= Security::csrfField() ?>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="exercise_type" class="form-control">
              <option value="tabletop">Tabletop</option>
              <option value="walkthrough">Walkthrough</option>
              <option value="full_scale">Full Scale</option>
            </select>
          </div>
          <div class="form-group" style="flex:2">
            <label class="form-label">Exercise Name <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="Q1 Tabletop Exercise">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Scheduled Date</label>
            <input type="date" name="scheduled_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Conducted Date</label>
            <input type="date" name="conducted_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Outcome</label>
            <select name="outcome" class="form-control">
              <option value="">— Pending —</option>
              <option value="passed">Passed</option>
              <option value="passed_with_findings">Passed w/ Findings</option>
              <option value="failed">Failed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Findings / Notes</label>
          <textarea name="findings" class="form-control" rows="3"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Lessons Learned</label>
          <textarea name="lessons_learned" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="card-footer">
        <button class="btn btn-primary"><i class="bi bi-journal-check"></i> Log Exercise</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div id="editPanel" class="card hidden" style="margin-top:24px;max-width:760px">
  <div class="card-header"><h3>Edit Plan</h3></div>
  <form method="POST" action="/bcp/<?= (int)$plan['id'] ?>/update">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
      <div class="form-row">
        <div class="form-group" style="flex:2">
          <label class="form-label">Title</label>
          <input type="text" name="title" class="form-control" value="<?= Security::h($plan['title']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Version</label>
          <input type="text" name="version" class="form-control" value="<?= Security::h($plan['version']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <?php foreach (['draft','active','archived'] as $s): ?>
              <option value="<?= $s ?>" <?= $plan['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"><?= Security::h($plan['description'] ?? '') ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">RTO (hours)</label>
          <input type="number" name="rto_hours" class="form-control" value="<?= (int)($plan['rto_hours'] ?? 0) ?: '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">RPO (hours)</label>
          <input type="number" name="rpo_hours" class="form-control" value="<?= (int)($plan['rpo_hours'] ?? 0) ?: '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Owner</label>
          <select name="owner_id" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= (int)($plan['owner_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Next Test Date</label>
          <input type="date" name="next_test_date" class="form-control" value="<?= Security::h($plan['next_test_date'] ?? '') ?>">
        </div>
      </div>
    </div>
    <div class="card-footer">
      <button class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
(function() {
  var planTitle   = <?= json_encode($plan['title']) ?>;
  var planVersion = <?= json_encode('v' . $plan['version']) ?>;
  var rto         = <?= json_encode($plan['rto_hours'] ? '≤' . (int)$plan['rto_hours'] . 'h' : 'Not set') ?>;
  var rpo         = <?= json_encode($plan['rpo_hours'] ? '≤' . (int)$plan['rpo_hours'] . 'h' : 'Not set') ?>;
  var sections    = <?= json_encode(array_values($sections)) ?>;

  document.getElementById('btnBcpPrint').addEventListener('click', function() { window.print(); });

  document.getElementById('btnBcpWord').addEventListener('click', function() {
    function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    var body = '<h1>' + esc(planTitle) + '</h1>' +
      '<p style="color:#555;font-size:10pt">Version: <b>' + esc(planVersion) + '</b> &nbsp;|&nbsp; RTO: <b>' + esc(rto) + '</b> &nbsp;|&nbsp; RPO: <b>' + esc(rpo) + '</b></p>';
    sections.forEach(function(sec) {
      body += '<h2>' + esc(sec.section_type ? sec.section_type.replace(/_/g,' ').toUpperCase() : '') + ' — ' + esc(sec.title) + '</h2>';
      body += '<p>' + esc(sec.content || '').replace(/\n/g,'</p><p>') + '</p>';
    });
    var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
      '<style>body{font-family:Calibri,Arial,sans-serif;margin:2.5cm;color:#111;line-height:1.6}' +
      'h1{font-size:22pt;border-bottom:2pt solid #333;padding-bottom:6pt}' +
      'h2{font-size:13pt;color:#1e40af;margin-top:18pt;border-bottom:1pt solid #ddd;padding-bottom:3pt}' +
      'p{margin:0 0 8pt}</style></head><body>' + body + '</body></html>';
    var blob = new Blob(['﻿', html], { type: 'application/msword' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = planTitle.replace(/[^a-z0-9]/gi, '_') + '.doc';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  });
})();

function showTab(tab, btn) {
  document.getElementById('tab-sections').classList.toggle('hidden', tab !== 'sections');
  document.getElementById('tab-exercises').classList.toggle('hidden', tab !== 'exercises');
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
}
document.querySelectorAll('[data-tab]').forEach(function(btn) {
  btn.addEventListener('click', function() { showTab(btn.dataset.tab, btn); });
});
</script>
<style>
.tab-btn { background:none;border:none;padding:10px 20px;cursor:pointer;font-size:14px;font-weight:500;color:var(--text-muted);border-bottom:2px solid transparent;margin-bottom:-2px; }
.tab-btn.active { color:var(--primary);border-bottom-color:var(--primary); }
.hidden { display:none !important; }
</style>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
