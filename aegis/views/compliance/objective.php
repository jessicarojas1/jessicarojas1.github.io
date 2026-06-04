<?php
$pageTitle    = $objective['code'];
$activeModule = 'compliance';
$breadcrumbs  = [
  ['Compliance','/compliance'],
  [$objective['package_name'],'/compliance/'.$objective['package_id']],
  [$objective['parent_code'] ?? $objective['code'], null]
];

ob_start();
?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Assessment saved successfully.</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><span class="code-tag"><?= Security::h($objective['code']) ?></span></h1>
    <p class="page-subtitle"><?= Security::h($objective['title']) ?></p>
  </div>
  <a href="/compliance/<?= $objective['package_id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="objective-layout">
  <!-- Left: Assessment form -->
  <div class="objective-main">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-clipboard2-check"></i> Assessment</h3>
        <?php $impl = $implementation; ?>
        <?php if ($impl): ?>
          <span class="badge badge-<?= $impl['status'] ?>"><?= ucwords(str_replace('_',' ',$impl['status'])) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" action="/compliance/<?= $objective['package_id'] ?>/objective/<?= $objective['id'] ?>/update">
          <?= Security::csrfField() ?>

          <div class="form-group">
            <label class="form-label">Implementation Status</label>
            <div class="status-selector">
              <?php $statuses = ['not_started'=>['circle','Not Started','#94a3b8'],'compliant'=>['check-circle-fill','Compliant','#059669'],'partial'=>['dash-circle-fill','Partial','#d97706'],'non_compliant'=>['x-circle-fill','Non-Compliant','#dc2626'],'not_applicable'=>['slash-circle-fill','N/A','#64748b']]; ?>
              <?php foreach ($statuses as $val => [$icon,$label,$color]): ?>
                <?php $checked = ($impl['status'] ?? 'not_started') === $val; ?>
                <label class="status-opt <?= $checked ? 'selected' : '' ?>" style="<?= $checked ? "--opt-color:{$color}" : '' ?>">
                  <input type="radio" name="status" value="<?= $val ?>" <?= $checked ? 'checked' : '' ?>>
                  <i class="bi bi-<?= $icon ?>" style="color:<?= $color ?>"></i>
                  <span><?= $label ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label"><i class="bi bi-person"></i> Assigned To</label>
              <select name="assigned_to" class="form-control">
                <option value="">Unassigned</option>
                <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= ($impl['assigned_to'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label"><i class="bi bi-calendar"></i> Due Date</label>
              <input type="date" name="due_date" class="form-control" value="<?= Security::h($impl['due_date'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label"><i class="bi bi-journal-text"></i> Implementation Notes</label>
            <textarea name="implementation_notes" class="form-control" rows="4" placeholder="Describe how this control is implemented..."><?= Security::h($impl['implementation_notes'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label"><i class="bi bi-paperclip"></i> Evidence</label>
            <textarea name="evidence" class="form-control" rows="3" placeholder="List evidence, documents, or references..."><?= Security::h($impl['evidence'] ?? '') ?></textarea>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> Save Assessment</button>
            <?php if ($impl && $impl['last_reviewed']): ?>
              <span class="text-muted">Last reviewed <?= date('M j, Y', strtotime($impl['last_reviewed'])) ?> by <?= Security::h($impl['reviewer_name'] ?? 'unknown') ?></span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Assessment Objectives (sub-items) -->
    <?php if ($children): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-list-check"></i> Assessment Objectives</h3>
      </div>
      <div class="card-body p0">
        <?php foreach ($children as $child): ?>
          <div class="objective-child">
            <span class="child-code"><?= Security::h($child['code']) ?></span>
            <span class="child-text"><?= Security::h($child['title']) ?></span>
            <span class="status-dot <?= $child['impl_status'] ? 'status-'.$child['impl_status'] : 'gray' ?>"></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: Metadata -->
  <div class="objective-sidebar">
    <!-- Mapped policies -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-file-earmark-text"></i> Mapped Policies</h3>
        <a href="/policy" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <div class="card-body p0">
        <?php if ($mappedPolicies): foreach ($mappedPolicies as $pol): ?>
          <div class="list-item">
            <i class="bi bi-file-earmark-text" style="color:#4f46e5;margin-right:8px"></i>
            <div class="list-item-body">
              <a href="/policy/<?= $pol['id'] ?>" class="list-item-title"><?= Security::h($pol['title']) ?></a>
              <div class="list-item-sub">v<?= Security::h($pol['version']) ?></div>
            </div>
            <span class="badge badge-<?= $pol['status'] ?>"><?= ucfirst($pol['status']) ?></span>
          </div>
        <?php endforeach; else: ?>
          <div class="empty-state-sm"><i class="bi bi-file-earmark-x"></i><p>No policies mapped</p><a href="/policy" class="btn btn-ghost btn-sm">Map a policy</a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Audit history -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-clock-history"></i> Audit History</h3>
      </div>
      <div class="card-body p0">
        <?php if ($auditFindings): foreach ($auditFindings as $finding): ?>
          <div class="list-item">
            <div class="list-item-body">
              <div class="list-item-title"><a href="/audit/<?= $finding['audit_id'] ?>"><?= Security::h($finding['audit_name']) ?></a></div>
              <div class="list-item-sub"><?= $finding['completed_date'] ? date('M j, Y', strtotime($finding['completed_date'])) : 'In progress' ?></div>
            </div>
            <span class="badge badge-<?= $finding['status'] ?>"><?= ucfirst(str_replace('_',' ',$finding['status'])) ?></span>
          </div>
        <?php endforeach; else: ?>
          <div class="empty-state-sm"><i class="bi bi-clipboard-x"></i><p>No audit history</p></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function updateStatusUI(radio) {
  document.querySelectorAll('.status-opt').forEach(o => {
    o.classList.remove('selected');
    o.removeAttribute('style');
  });
  const parent = radio.closest('.status-opt');
  parent.classList.add('selected');
}

document.querySelectorAll('input[name="status"]').forEach(function(radio) {
  radio.addEventListener('change', function() { updateStatusUI(radio); });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
