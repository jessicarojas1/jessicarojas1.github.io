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
      <form method="POST" action="/audit/<?= $audit['id'] ?>/complete" id="form-complete-audit" data-confirm="Complete this audit?">
        <?= Security::csrfField() ?>
        <button class="btn btn-success"><i class="bi bi-check-lg"></i> Complete Audit</button>
      </form>
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
        <div class="progress-fill" style="width:<?= $pct ?>%;background:#4f46e5"></div>
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
        <select class="status-select-inline" data-item="<?= $item['id'] ?>" data-audit="<?= $audit['id'] ?>">
          <?php foreach (['not_assessed'=>'Not Assessed','compliant'=>'Compliant','partial'=>'Partial','non_compliant'=>'Non-Compliant','not_applicable'=>'N/A'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= $item['status']===$val?'selected':'' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm btn-toggle-finding" data-item="<?= $item['id'] ?>">
          <i class="bi bi-pencil"></i>
        </button>
      </div>
      <div class="audit-finding-panel" id="finding-<?= $item['id'] ?>" style="display:none">
        <form class="finding-form" data-item="<?= $item['id'] ?>" data-audit="<?= $audit['id'] ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="status" class="finding-status-input" value="<?= Security::h($item['status']) ?>">
          <div class="form-row">
            <div class="form-group flex-1">
              <label class="form-label">Finding</label>
              <textarea name="finding" class="form-control" rows="2" placeholder="Describe the audit finding..."><?= Security::h($item['finding'] ?? '') ?></textarea>
            </div>
            <div class="form-group flex-1">
              <label class="form-label">Evidence</label>
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
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Save Finding</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<script nonce="<?= Security::nonce() ?>">
function toggleFinding(id) {
  const panel = document.getElementById('finding-' + id);
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

function saveAuditItem(itemId, auditId, select) {
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

// Confirm dialog for complete-audit form
(function() {
  var f = document.getElementById('form-complete-audit');
  if (f) {
    f.addEventListener('submit', function(e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  }
})();

// Event delegation for status selects (loop-generated)
document.querySelectorAll('.status-select-inline').forEach(function(select) {
  select.addEventListener('change', function() {
    saveAuditItem(parseInt(select.dataset.item), parseInt(select.dataset.audit), select);
  });
});

// Event delegation for toggle-finding buttons (loop-generated)
document.querySelectorAll('.btn-toggle-finding').forEach(function(btn) {
  btn.addEventListener('click', function() {
    toggleFinding(parseInt(btn.dataset.item));
  });
});

// Event delegation for finding forms (loop-generated)
document.querySelectorAll('.finding-form').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    saveFinding(e, parseInt(form.dataset.item), parseInt(form.dataset.audit));
  });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
