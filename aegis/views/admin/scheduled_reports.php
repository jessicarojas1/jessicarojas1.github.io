<?php
$pageTitle    = 'Scheduled Reports';
$activeModule = 'admin_scheduled_reports';
$breadcrumbs  = [['Admin', '/admin'], ['Scheduled Reports', null]];

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$reportTypeLabels = [
    'risk_register'      => 'Risk Register',
    'compliance_summary' => 'Compliance Summary',
    'audit_status'       => 'Audit Status',
    'executive_summary'  => 'Executive Summary',
];
$reportTypeBadgeColors = [
    'risk_register'      => '#dc2626',
    'compliance_summary' => '#2563eb',
    'audit_status'       => '#7c3aed',
    'executive_summary'  => '#0891b2',
];
$freqLabels = [
    'daily'     => 'Daily',
    'weekly'    => 'Weekly',
    'monthly'   => 'Monthly',
    'quarterly' => 'Quarterly',
];

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
    <h1 class="page-title">Scheduled Reports</h1>
    <p class="page-subtitle">Automate regular report delivery to stakeholders</p>
  </div>
  <div class="page-actions">
    <a href="/admin/scheduled-reports/create" class="btn btn-primary">
      <i class="bi bi-plus-lg"></i> New Schedule
    </a>
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>
</div>

<div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:13px;color:#92400e">
  <i class="bi bi-clock-history" style="flex-shrink:0"></i>
  <span>Scheduled reports are delivered via cron. Ensure <code style="background:#fef3c7;padding:1px 5px;border-radius:3px">send_scheduled_reports.php</code> runs hourly.</span>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Report Type</th>
          <th>Frequency</th>
          <th>Recipients</th>
          <th>Status</th>
          <th>Last Sent</th>
          <th>Next Send</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($schedules)): foreach ($schedules as $sched):
          $recipients = [];
          if (!empty($sched['recipients'])) {
              $recipients = is_array($sched['recipients'])
                  ? $sched['recipients']
                  : (json_decode($sched['recipients'], true) ?? []);
          }
          $recipientCount = count($recipients);
          $typeLabel  = $reportTypeLabels[$sched['report_type']] ?? ucfirst($sched['report_type']);
          $typeColor  = $reportTypeBadgeColors[$sched['report_type']] ?? '#64748b';
          $freqLabel  = $freqLabels[$sched['frequency']] ?? ucfirst($sched['frequency']);
          $lastSent   = $sched['last_sent_at'] ? date('M j, Y g:ia', strtotime($sched['last_sent_at'])) : 'Never';
          $nextSend   = $sched['next_send_at'] ? date('M j, Y g:ia', strtotime($sched['next_send_at'])) : '—';
        ?>
          <tr <?= !$sched['is_active'] ? 'style="opacity:.6"' : '' ?>>
            <td><strong><?= Security::h($sched['name']) ?></strong></td>
            <td>
              <span style="background:<?= Security::h($typeColor) ?>1a;color:<?= Security::h($typeColor) ?>;border:1px solid <?= Security::h($typeColor) ?>33;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:500;white-space:nowrap">
                <?= Security::h($typeLabel) ?>
              </span>
            </td>
            <td style="font-size:13px"><?= Security::h($freqLabel) ?></td>
            <td style="font-size:13px">
              <span title="<?= Security::h(implode(', ', array_slice($recipients, 0, 5))) ?>">
                <?= $recipientCount ?> recipient<?= $recipientCount !== 1 ? 's' : '' ?>
              </span>
            </td>
            <td>
              <?php if ($sched['is_active']): ?>
                <span class="badge badge-green">Active</span>
              <?php else: ?>
                <span class="badge badge-gray">Paused</span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px;color:var(--text-muted)"><?= Security::h($lastSent) ?></td>
            <td style="font-size:13px;color:var(--text-muted)"><?= Security::h($nextSend) ?></td>
            <td style="white-space:nowrap">
              <a href="/admin/scheduled-reports/<?= (int)$sched['id'] ?>/edit" class="btn btn-ghost btn-sm" title="Edit">
                <i class="bi bi-pencil"></i>
              </a>
              <form method="post" action="/admin/scheduled-reports/<?= (int)$sched['id'] ?>/delete"
                    style="display:inline"
                    data-confirm="Delete this scheduled report? This cannot be undone.">
                <?= Security::csrfField() ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="color:#ef4444" title="Delete">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="8">
              <div class="empty-state" style="padding:50px;text-align:center">
                <i class="bi bi-file-earmark-bar-graph" style="font-size:2.5rem;color:var(--text-muted);display:block;margin-bottom:12px"></i>
                <h4 style="margin:0 0 6px;color:var(--text-primary)">No scheduled reports</h4>
                <p style="color:var(--text-muted);margin:0 0 16px;font-size:14px">Set up automatic report delivery to keep stakeholders informed.</p>
                <a href="/admin/scheduled-reports/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Create First Schedule</a>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('form[data-confirm]').forEach(function(f) {
  f.addEventListener('submit', function(e) {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
  });
});
</script>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
