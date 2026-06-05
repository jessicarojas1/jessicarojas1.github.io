<?php
$pageTitle    = $audit['name'];
$activeModule = 'audit';
$breadcrumbs  = [['Audits','/audit'],[$audit['name'],null]];
ob_start();
?>

<?php if (!empty($_GET['completed'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Audit completed with score <?= round($audit['score']) ?>%.</div><?php endif; ?>
<?php if (!empty($_GET['updated'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Audit updated.</div><?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($audit['name']) ?></h1>
    <p class="page-subtitle">
      <?= Security::h($audit['package_name'] ?? 'No package') ?> ·
      <?= ucfirst(str_replace('_',' ',$audit['audit_type'])) ?> ·
      <?= $audit['scheduled_date'] ? 'Scheduled '.date('M j, Y', strtotime($audit['scheduled_date'])) : '' ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($audit['status'] === 'planned'): ?>
      <form method="POST" action="/audit/<?= $audit['id'] ?>/update" style="display:inline">
        <?= Security::csrfField() ?>
        <input type="hidden" name="status" value="in_progress">
        <button class="btn btn-primary"><i class="bi bi-play-fill"></i> Start Audit</button>
      </form>
    <?php elseif ($audit['status'] === 'in_progress'): ?>
      <form method="POST" action="/audit/<?= $audit['id'] ?>/complete">
        <?= Security::csrfField() ?>
        <button class="btn btn-success" data-confirm-click="Complete this audit?"><i class="bi bi-check-lg"></i> Complete Audit</button>
      </form>
    <?php endif; ?>
    <?php if ($audit['audit_type'] === 'internal'): ?>
      <a href="#report8d" class="btn btn-secondary"><i class="bi bi-clipboard2-check-fill"></i> 8D Report</a>
    <?php endif; ?>
    <a href="/audit/<?= $audit['id'] ?>/export" class="btn btn-secondary"><i class="bi bi-file-earmark-zip"></i> Export Package</a>
    <a href="/audit" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<!-- Summary row -->
<div class="audit-summary">
  <div class="audit-meta-card">
    <div class="audit-meta-label">Status</div>
    <span class="badge badge-lg badge-<?= $audit['status'] ?>"><?= ucfirst(str_replace('_',' ',$audit['status'])) ?></span>
  </div>
  <div class="audit-meta-card">
    <div class="audit-meta-label">Score</div>
    <div class="audit-score"><?= $audit['score'] !== null ? round($audit['score']).'%' : '—' ?></div>
  </div>
  <div class="audit-meta-card">
    <div class="audit-meta-label">Lead Auditor</div>
    <div class="fw-600"><?= Security::h($audit['auditor_name'] ?? 'Unassigned') ?></div>
  </div>
  <div class="audit-meta-card">
    <div class="audit-meta-label">Progress</div>
    <div class="progress-mini-wrap">
      <?php $total=max(1,(int)($summary['total']??0)); $assessed=$total-($summary['not_assessed']??0); $pct=round($assessed/$total*100); ?>
      <div class="progress-bar-wrap">
        <div class="progress-fill" style="width:<?= $pct ?>%;background:var(--primary)"></div>
      </div>
      <span><?= $assessed ?>/<?= $total ?> assessed</span>
    </div>
  </div>
  <div class="audit-findings-summary">
    <span class="finding-stat green"><i class="bi bi-check-circle-fill"></i> <?= $summary['compliant'] ?> Compliant</span>
    <span class="finding-stat yellow"><i class="bi bi-dash-circle-fill"></i> <?= $summary['partial'] ?> Partial</span>
    <span class="finding-stat red"><i class="bi bi-x-circle-fill"></i> <?= $summary['non_compliant'] ?> Non-Compliant</span>
    <span class="finding-stat gray"><i class="bi bi-circle"></i> <?= $summary['not_assessed'] ?> Not Assessed</span>
  </div>
</div>

<!-- Audit items by domain -->
<?php foreach ($groupedByDomain as $domain => $items): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <h3 class="card-title"><?= Security::h($domain) ?></h3>
    <?php $domainTotal = count($items); $domainComp = count(array_filter($items, fn($i)=>$i['status']==='compliant')); ?>
    <span class="badge badge-<?= $domainComp===$domainTotal ? 'green' : 'gray' ?>"><?= $domainComp ?>/<?= $domainTotal ?></span>
  </div>
  <div class="card-body p0">
    <?php foreach ($items as $item): ?>
    <div class="audit-item-row" id="item-<?= $item['id'] ?>">
      <div class="audit-item-info">
        <span class="control-code"><?= Security::h($item['code']) ?></span>
        <span class="control-title"><?= Security::h($item['title']) ?></span>
      </div>
      <div class="audit-item-controls">
        <select class="status-select-inline" data-change="saveAuditItem" data-args='[<?= $item['id'] ?>,<?= $audit['id'] ?>]' data-item="<?= $item['id'] ?>">
          <?php foreach (['not_assessed'=>'Not Assessed','compliant'=>'Compliant','partial'=>'Partial','non_compliant'=>'Non-Compliant','not_applicable'=>'N/A'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= $item['status']===$val?'selected':'' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" data-click="toggleFinding" data-arg="<?= $item['id'] ?>">
          <i class="bi bi-pencil"></i>
        </button>
      </div>
      <div class="audit-finding-panel" id="finding-<?= $item['id'] ?>" style="display:none">
        <form class="finding-form" data-submit="saveFinding" data-args='[<?= $item['id'] ?>,<?= $audit['id'] ?>]' enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="status" class="finding-status-input" value="<?= Security::h($item['status']) ?>">
          <div class="form-row">
            <div class="form-group flex-1">
              <label class="form-label">Finding</label>
              <textarea name="finding" class="form-control" rows="2" placeholder="Describe the audit finding..."><?= Security::h($item['finding'] ?? '') ?></textarea>
            </div>
            <div class="form-group flex-1">
              <label class="form-label">Evidence Notes</label>
              <textarea name="evidence" class="form-control" rows="2" placeholder="Evidence reviewed..."><?= Security::h($item['evidence'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Risk Level</label>
              <select name="risk_level" class="form-control">
                <option value="">None</option>
                <?php foreach (['low','medium','high','critical'] as $rl): ?>
                  <option value="<?= $rl ?>" <?= ($item['risk_level']??'')===$rl?'selected':'' ?>><?= ucfirst($rl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group flex-2">
              <label class="form-label">Remediation</label>
              <input type="text" name="remediation" class="form-control" placeholder="Required remediation actions..." value="<?= Security::h($item['remediation'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Upload Evidence File</label>
            <input type="file" name="evidence_file[]" multiple class="form-control" accept="image/*,.pdf,.doc,.docx,.xlsx,.csv,.txt,.png,.jpg,.jpeg">
            <p style="margin:4px 0 0;font-size:12px;color:var(--text-muted)">PDF, Word, images, spreadsheets accepted.</p>
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Save Finding</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($audit['audit_type'] === 'internal'): ?>
<!-- 8D Internal Audit Report -->
<div class="card" style="margin-top:24px" id="report8d">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h3 class="card-title" style="margin:0">
      <i class="bi bi-clipboard2-check-fill" style="color:var(--primary);margin-right:6px"></i>
      8D Internal Audit Report
    </h3>
    <div style="display:flex;gap:8px">
      <button class="btn btn-ghost btn-sm" id="btn8dClear"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      <button class="btn btn-secondary btn-sm" id="btn8dPrint"><i class="bi bi-printer-fill"></i> Print Report</button>
    </div>
  </div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:18px" id="eightDBody">

    <?php
    $eightD = [
      ['D1', 'Team Formation',           'List all team members involved in this internal audit investigation, including lead auditor, reviewers, and subject-matter experts.', 'rows' => 3],
      ['D2', 'Problem Description',      'Describe the audit scope, objectives, and summary of non-compliant findings identified during this audit.', 'rows' => 4],
      ['D3', 'Containment Actions',      'Immediate short-term actions taken to contain the impact of identified non-conformities and prevent further compliance drift.', 'rows' => 3],
      ['D4', 'Root Cause Analysis',      'Identify the root cause(s) of each non-compliant finding. Use 5-Why or Fishbone analysis as appropriate.', 'rows' => 4],
      ['D5', 'Corrective Actions',       'Define permanent corrective actions to address each root cause. Include specific tasks, owners, and target completion dates.', 'rows' => 4],
      ['D6', 'Implementation & Verification', 'Document the implementation of corrective actions, verification evidence, and confirmation that they are effective.', 'rows' => 3],
      ['D7', 'Prevention Measures',      'Systemic measures to prevent recurrence across other similar processes, departments, or frameworks.', 'rows' => 3],
      ['D8', 'Team Recognition & Closure','Summarise the outcome, acknowledge the audit team\'s effort, and formally close the 8D report.', 'rows' => 2],
    ];
    foreach ($eightD as $i => $d):
    ?>
    <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
      <div style="padding:10px 16px;background:var(--bg-secondary);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:var(--primary);color:#fff;font-weight:800;font-size:13px;flex-shrink:0"><?= $d[0] ?></span>
        <strong style="font-size:14px;color:var(--text)"><?= $d[1] ?></strong>
      </div>
      <div style="padding:12px 16px">
        <p style="margin:0 0 8px;font-size:12px;color:var(--text-muted)"><?= $d[2] ?></p>
        <textarea class="form-control eightd-field" id="eightd_<?= $i ?>" rows="<?= $d['rows'] ?>"
                  placeholder="<?= $d[0] ?>: <?= $d[1] ?> — enter details here…"
                  style="font-size:13px"></textarea>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
const _auditId8d = '<?= (int)$audit['id'] ?>';
const _8dKey     = 'aegis_8d_audit_' + _auditId8d;

// Persist 8D fields in localStorage
function save8d() {
  const vals = {};
  document.querySelectorAll('.eightd-field').forEach(function(ta) { vals[ta.id] = ta.value; });
  localStorage.setItem(_8dKey, JSON.stringify(vals));
}
function load8d() {
  try {
    const vals = JSON.parse(localStorage.getItem(_8dKey) || '{}');
    document.querySelectorAll('.eightd-field').forEach(function(ta) {
      if (vals[ta.id]) ta.value = vals[ta.id];
    });
  } catch(e) {}
}

// Auto-fill D2 with non-compliant findings on first open
function autoFill8D() {
  const d2 = document.getElementById('eightd_1');
  if (!d2 || d2.value) return;
  const auditName   = <?= json_encode($audit['name']) ?>;
  const scheduled   = <?= json_encode($audit['scheduled_date'] ? date('M j, Y', strtotime($audit['scheduled_date'])) : 'Not scheduled') ?>;
  const ncCount     = parseInt('<?= $summary['non_compliant'] ?? 0 ?>');
  const partCount   = parseInt('<?= $summary['partial'] ?? 0 ?>');
  const totalCount  = parseInt('<?= $summary['total'] ?? 0 ?>');
  d2.value = 'Audit: ' + auditName + '\nScheduled: ' + scheduled +
    '\n\nTotal items assessed: ' + totalCount +
    '\nNon-Compliant findings: ' + ncCount +
    '\nPartial findings: ' + partCount +
    '\n\nSee audit items above for detailed control-level findings.';
}

load8d();
autoFill8D();

document.querySelectorAll('.eightd-field').forEach(function(ta) {
  ta.addEventListener('input', save8d);
});

var clear8dBtn = document.getElementById('btn8dClear');
if (clear8dBtn) clear8dBtn.addEventListener('click', function() {
  if (!confirm('Reset all 8D fields? This cannot be undone.')) return;
  localStorage.removeItem(_8dKey);
  document.querySelectorAll('.eightd-field').forEach(function(ta) { ta.value = ''; });
  autoFill8D();
});

var print8dBtn = document.getElementById('btn8dPrint');
if (print8dBtn) print8dBtn.addEventListener('click', function() {
  window.print();
});

// Audit item functions
function toggleFinding(id) {
  const panel = document.getElementById('finding-' + id);
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

function saveAuditItem(itemId, auditId) {
  const select = this;
  const status = select.value;
  const formData = new FormData();
  formData.append('status', status);
  formData.append('csrf_token', '<?= Security::generateCsrfToken() ?>');

  const panel = document.getElementById('finding-' + itemId);
  if (panel) panel.querySelector('.finding-status-input').value = status;

  fetch('/audit/' + auditId + '/item/' + itemId + '/update', { method:'POST', body:formData })
    .then(r => r.json())
    .then(data => {
      const row = document.getElementById('item-' + itemId);
      row.className = 'audit-item-row item-' + data.status;
    });
}

function saveFinding(e, itemId, auditId) {
  e.preventDefault();
  const form = e.target;
  const fd   = new FormData(form);
  fetch('/audit/' + auditId + '/item/' + itemId + '/update', { method:'POST', body:fd })
    .then(r => r.json())
    .then(() => {
      form.closest('.audit-finding-panel').style.display = 'none';
    });
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
