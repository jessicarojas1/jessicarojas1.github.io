<?php
/**
 * views/approval/templates.php — Approval template list for admins.
 *
 * Variables provided by ApprovalController::templates():
 *   $templates  array  — rows from approval_templates
 */
$pageTitle    = 'Approval Templates';
$activeModule = 'approval';
$breadcrumbs  = [['Approvals', '/approval'], ['Templates', null]];
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$breadcrumbs = [['Approvals', '/approvals'], ['Templates', null]];

$entityTypeLabels = [
    'risk'     => 'Risk',
    'policy'   => 'Policy',
    'change'   => 'Change Request',
    'audit'    => 'Audit',
    'incident' => 'Incident',
    'vendor'   => 'Vendor',
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Approval Templates</h1>
    <p class="page-subtitle">Define multi-step approval chains for any entity type.</p>
  </div>
  <div class="page-actions">
    <a href="/admin/approval-templates/create" class="btn btn-primary">
      <i class="bi bi-plus-lg"></i> New Template
    </a>
    <a href="/admin" class="btn btn-ghost">
      <i class="bi bi-arrow-left"></i> Admin
    </a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert-box success" style="margin-bottom:20px">
    <i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?>
  </div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert-box error" style="margin-bottom:20px">
    <i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?>
  </div>
<?php endif; ?>

<?php if (empty($templates)): ?>
  <div class="empty-state">
    <i class="bi bi-diagram-3"></i>
    <h3>No approval templates yet</h3>
    <p>Create your first template to start enforcing multi-step approval workflows.</p>
    <a href="/admin/approval-templates/create" class="btn btn-primary" style="margin-top:12px">
      <i class="bi bi-plus-lg"></i> New Template
    </a>
  </div>
<?php else: ?>
  <div class="card">
    <table class="data-table">
      <thead>
        <tr>
          <th>Template Name</th>
          <th>Entity Type</th>
          <th>Status</th>
          <th>Created</th>
          <th style="width:140px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $tmpl): ?>
          <?php $isActive = !empty($tmpl['is_active']); ?>
          <tr>
            <td>
              <span class="fw-600"><?= Security::h($tmpl['name']) ?></span>
            </td>
            <td>
              <span class="badge badge-secondary">
                <?= Security::h($entityTypeLabels[$tmpl['entity_type']] ?? ucfirst($tmpl['entity_type'])) ?>
              </span>
            </td>
            <td>
              <?php if ($isActive): ?>
                <span class="badge badge-success">Active</span>
              <?php else: ?>
                <span class="badge" style="background:var(--bg-subtle);color:var(--text-muted)">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="text-muted text-sm">
              <?= !empty($tmpl['created_at'])
                    ? date('M j, Y', strtotime($tmpl['created_at']))
                    : '—' ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;align-items:center">
                <!-- Toggle active/inactive -->
                <form
                  method="POST"
                  action="/admin/approval-templates/<?= (int)$tmpl['id'] ?>/toggle"
                  style="display:inline"
                  data-confirm="<?= $isActive ? 'Deactivate' : 'Activate' ?> this template?"
                >
                  <?= Security::csrfField() ?>
                  <button
                    type="submit"
                    class="btn btn-sm <?= $isActive ? 'btn-ghost' : 'btn-primary' ?>"
                    title="<?= $isActive ? 'Deactivate' : 'Activate' ?>"
                  >
                    <?php if ($isActive): ?>
                      <i class="bi bi-pause-circle"></i> Deactivate
                    <?php else: ?>
                      <i class="bi bi-play-circle"></i> Activate
                    <?php endif; ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
