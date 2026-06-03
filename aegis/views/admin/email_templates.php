<?php
$pageTitle    = 'Email Templates';
$activeModule = 'admin_email_templates';
$breadcrumbs  = [['Admin', '/admin'], ['Email Templates', null]];

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

ob_start();
?>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Email Templates</h1>
    <p class="page-subtitle">Manage system email notification templates</p>
  </div>
  <div class="page-actions">
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>
</div>

<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px;font-size:14px;color:#1e40af">
  <i class="bi bi-info-circle-fill" style="flex-shrink:0;margin-top:1px"></i>
  <span>Templates use <code style="background:#dbeafe;padding:1px 5px;border-radius:4px;font-size:12px">{{variable}}</code> placeholders. Available variables are listed on the edit page for each template.</span>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <table class="table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Name</th>
          <th>Subject Preview</th>
          <th>Status</th>
          <th>Last Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($templates)): foreach ($templates as $tmpl):
          $subjectPreview = mb_strlen($tmpl['subject']) > 60
            ? mb_substr($tmpl['subject'], 0, 57) . '…'
            : $tmpl['subject'];
          $updatedAt = $tmpl['updated_at']
            ? date('M j, Y g:ia', strtotime($tmpl['updated_at']))
            : '—';
        ?>
          <tr>
            <td>
              <code style="font-size:12px;background:var(--bg-secondary,#f8fafc);padding:2px 7px;border-radius:4px;color:var(--text-muted)"><?= Security::h($tmpl['type']) ?></code>
            </td>
            <td><strong><?= Security::h($tmpl['name']) ?></strong></td>
            <td style="color:var(--text-muted);font-size:13px"><?= Security::h($subjectPreview) ?></td>
            <td>
              <?php if ($tmpl['is_active']): ?>
                <span class="badge badge-green"><i class="bi bi-check-circle-fill"></i> Active</span>
              <?php else: ?>
                <span class="badge badge-gray"><i class="bi bi-dash-circle"></i> Inactive</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--text-muted);font-size:13px"><?= Security::h($updatedAt) ?></td>
            <td>
              <a href="/admin/email-templates/<?= (int)$tmpl['id'] ?>/edit" class="btn btn-ghost btn-sm" title="Edit template">
                <i class="bi bi-pencil"></i> Edit
              </a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="6">
              <div class="empty-state" style="padding:40px;text-align:center">
                <i class="bi bi-envelope-x" style="font-size:2rem;color:var(--text-muted);display:block;margin-bottom:12px"></i>
                <p style="color:var(--text-muted);margin:0">No email templates found. Add templates via the form below.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
