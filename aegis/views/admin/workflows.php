<?php
$pageTitle    = 'Workflows';
$activeModule = 'admin_workflows';
$breadcrumbs  = [['Admin','/admin'],['Workflows',null]];
ob_start();
?>

<?php if (!empty($_GET['created'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Workflow created.</div><?php endif; ?>

<div class="page-header">
  <h1 class="page-title">Workflow Automation</h1>
  <button class="btn btn-primary" data-show-modal="createWfModal"><i class="bi bi-plus-lg"></i> New Workflow</button>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead><tr><th>Name</th><th>Trigger</th><th>Actions</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
        <?php if ($workflows): foreach ($workflows as $wf): ?>
          <?php $actions = json_decode($wf['actions'], true) ?? []; ?>
          <tr>
            <td>
              <strong><?= Security::h($wf['name']) ?></strong>
              <?php if ($wf['description']): ?><div class="text-muted text-sm"><?= Security::h(substr($wf['description'],0,60)) ?></div><?php endif; ?>
            </td>
            <td><span class="tag"><?= Security::h(str_replace('_',' ',$wf['trigger_type'])) ?></span></td>
            <td><?= count($actions) ?> action<?= count($actions)!=1?'s':'' ?></td>
            <td>
              <form method="POST" action="/admin/workflows/<?= $wf['id'] ?>/toggle" style="display:inline">
                <?= Security::csrfField() ?>
                <button type="submit" class="toggle-switch <?= $wf['is_active'] ? 'on' : '' ?>">
                  <span></span>
                </button>
              </form>
            </td>
            <td class="text-muted text-sm"><?= date('M j, Y', strtotime($wf['created_at'])) ?></td>
            <td><a href="/admin/workflows/<?= $wf['id'] ?>/edit" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i></a></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6" class="empty-row">
            <div class="empty-state-sm"><i class="bi bi-diagram-3"></i><p>No workflows yet. Automate repetitive compliance tasks.</p></div>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pre-built workflow templates -->
<div class="card">
  <div class="card-header"><h3 class="card-title"><i class="bi bi-lightning-fill"></i> Workflow Templates</h3></div>
  <div class="card-body">
    <div class="workflow-templates">
      <?php
      $templates = [
        ['Risk Review Reminder', 'Sends alert when risk review date is approaching', 'risk_review_due', '#ef4444'],
        ['Policy Expiry Alert', 'Notifies owner when policy review date is due', 'policy_review_due', '#d97706'],
        ['Audit Overdue Alert', 'Escalates when an audit passes its scheduled date', 'audit_overdue', '#dc2626'],
        ['New Risk Notification', 'Notifies admins when a critical risk is logged', 'risk_created_critical', '#f97316'],
        ['Compliance Drop Alert', 'Alerts when compliance score drops below threshold', 'compliance_score_drop', 'var(--secondary)'],
      ];
      foreach ($templates as [$name, $desc, $trigger, $color]): ?>
        <div class="workflow-template">
          <div class="wt-icon" style="background:<?= $color ?>20;color:<?= $color ?>"><i class="bi bi-lightning"></i></div>
          <div class="wt-body">
            <div class="wt-name"><?= $name ?></div>
            <div class="wt-desc"><?= $desc ?></div>
          </div>
          <button class="btn btn-ghost btn-sm" data-click="useTemplate" data-args='[<?= json_encode($name) ?>,<?= json_encode($trigger) ?>]'>Use</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Create workflow modal -->
<div class="modal-overlay" id="createWfModal" style="display:none">
  <div class="modal modal-lg">
    <div class="modal-header"><h3><i class="bi bi-diagram-3-fill"></i> New Workflow</h3><button data-close-modal="createWfModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <form method="POST" action="/admin/workflows/create">
        <?= Security::csrfField() ?>
        <div class="form-row">
          <div class="form-group flex-2">
            <label class="form-label required">Workflow Name</label>
            <input type="text" name="name" id="wf_name" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label required">Trigger</label>
          <select name="trigger_type" id="wf_trigger" class="form-control" required>
            <optgroup label="Risk">
              <option value="risk_created">Risk Created</option>
              <option value="risk_created_critical">Critical Risk Created</option>
              <option value="risk_review_due">Risk Review Due</option>
              <option value="risk_status_changed">Risk Status Changed</option>
            </optgroup>
            <optgroup label="Compliance">
              <option value="control_status_changed">Control Status Changed</option>
              <option value="compliance_score_drop">Compliance Score Drop</option>
            </optgroup>
            <optgroup label="Audit">
              <option value="audit_created">Audit Created</option>
              <option value="audit_overdue">Audit Overdue</option>
              <option value="audit_completed">Audit Completed</option>
            </optgroup>
            <optgroup label="Policy">
              <option value="policy_review_due">Policy Review Due</option>
              <option value="policy_published">Policy Published</option>
            </optgroup>
          </select>
        </div>
        <input type="hidden" name="trigger_config" value="{}">
        <input type="hidden" name="actions" value='[{"type":"create_alert","severity":"info","message":"Workflow triggered"}]'>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Create Workflow</button>
          <button type="button" class="btn btn-ghost" data-close-modal="createWfModal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
// Close modal when clicking overlay background
(function() {
  var m = document.getElementById('createWfModal');
  if (m) m.addEventListener('click', function(e) { if (e.target === m) closeModal('createWfModal'); });
})();
function useTemplate(name, trigger) {
  document.getElementById('wf_name').value    = name;
  document.getElementById('wf_trigger').value = trigger;
  showModal('createWfModal');
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
