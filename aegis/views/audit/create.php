<?php
$isEdit       = !empty($audit);
$pageTitle    = $isEdit ? 'Edit Audit' : 'New Audit';
$activeModule = 'audit';
$breadcrumbs  = [['Audits','/audit'],[$pageTitle,null]];
ob_start();
?>

<div class="page-header">
  <h1 class="page-title"><?= $pageTitle ?></h1>
  <a href="/audit" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($_SESSION['audit_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['audit_error']) ?></div>
  <?php unset($_SESSION['audit_error']); ?>
<?php endif; ?>

<div class="form-page card">
  <div class="card-body">
    <form method="POST" action="<?= $isEdit ? '/audit/'.$audit['id'].'/update' : '/audit/create' ?>">
      <?= Security::csrfField() ?>

      <div class="form-row">
        <div class="form-group flex-2">
          <label class="form-label required">Audit Name</label>
          <input type="text" name="name" class="form-control" placeholder="e.g. CMMC Level 2 Annual Audit" value="<?= Security::h($audit['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Audit Type</label>
          <select name="audit_type" class="form-control">
            <?php foreach (['internal'=>'Internal','external'=>'External','gap'=>'Gap Analysis','follow_up'=>'Follow-up'] as $val=>$label): ?>
              <option value="<?= $val ?>" <?= ($audit['audit_type']??'internal')===$val?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Compliance Package</label>
        <select name="package_id" class="form-control">
          <option value="">— No specific package —</option>
          <?php foreach ($packages as $pkg): ?>
            <option value="<?= $pkg['id'] ?>" <?= ($audit['package_id']??'')==$pkg['id']?'selected':'' ?>>
              [<?= Security::h($pkg['code']) ?>] <?= Security::h($pkg['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Selecting a package auto-populates audit items with all controls.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label required">Scheduled Date</label>
          <input type="date" name="scheduled_date" class="form-control" value="<?= Security::h($audit['scheduled_date'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Frequency</label>
          <select name="frequency" class="form-control">
            <?php foreach (['one_time'=>'One-time','monthly'=>'Monthly','quarterly'=>'Quarterly','biannual'=>'Bi-annual','annual'=>'Annual'] as $val=>$label): ?>
              <option value="<?= $val ?>" <?= ($audit['frequency']??'annual')===$val?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Lead Auditor</label>
          <select name="auditor_id" class="form-control">
            <option value="">Unassigned</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>" <?= ($audit['auditor_id']??'')==$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Scope</label>
        <textarea name="description" class="form-control" rows="4" placeholder="Describe the audit scope, objectives, and methodology..."><?= Security::h($audit['description'] ?? '') ?></textarea>
      </div>

      <?php if ($isEdit): ?>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <?php foreach (['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue','cancelled'=>'Cancelled'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= ($audit['status']??'')===$val?'selected':'' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> <?= $isEdit ? 'Update' : 'Create' ?> Audit</button>
        <a href="/audit" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
