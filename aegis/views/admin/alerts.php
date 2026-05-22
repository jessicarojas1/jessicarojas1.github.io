<?php
$pageTitle    = 'Alert Management';
$activeModule = 'admin_alerts';
$breadcrumbs  = [['Admin','/admin'],['Alerts',null]];
ob_start();
?>

<div class="page-header">
  <h1 class="page-title">Alert Management</h1>
</div>

<div class="two-col-layout">
  <!-- Recent alerts -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-bell-fill"></i> Recent Alerts</h3></div>
    <div class="card-body p0">
      <?php if ($recent): foreach ($recent as $alert): ?>
        <div class="alert-log-item sev-<?= Security::h($alert['severity']) ?>">
          <div class="alert-log-icon">
            <i class="bi bi-<?= $alert['severity'] === 'critical' ? 'exclamation-octagon-fill' : ($alert['severity'] === 'warning' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"></i>
          </div>
          <div class="alert-log-body">
            <div class="alert-log-title"><?= Security::h($alert['title']) ?></div>
            <div class="alert-log-meta"><?= Security::h($alert['user_name'] ?? 'System') ?> · <?= date('M j, g:ia', strtotime($alert['created_at'])) ?></div>
          </div>
          <span class="badge badge-<?= $alert['severity'] ?>"><?= ucfirst($alert['severity']) ?></span>
          <?= $alert['is_read'] ? '' : '<span class="unread-dot"></span>' ?>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state-sm"><i class="bi bi-bell-slash"></i><p>No alerts logged</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Alert configs -->
  <div>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-gear"></i> Alert Configurations</h3></div>
      <div class="card-body">
        <?php if ($configs): foreach ($configs as $cfg): ?>
          <div class="alert-config-item">
            <div>
              <strong><?= Security::h($cfg['name']) ?></strong>
              <div class="text-muted text-sm"><?= Security::h($cfg['type']) ?></div>
            </div>
            <span class="badge <?= $cfg['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $cfg['is_active'] ? 'Active' : 'Inactive' ?></span>
          </div>
        <?php endforeach; else: ?>
          <p class="text-muted">No alert configurations yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Default alerts info -->
    <div class="card" style="margin-top:16px">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> System Alert Types</h3></div>
      <div class="card-body">
        <?php
        $alertTypes = [
          ['risk_review_due','Risk review date approaching (7 days)','warning'],
          ['policy_review_due','Policy review overdue','warning'],
          ['audit_overdue','Audit past scheduled date','critical'],
          ['critical_risk_logged','Critical risk created','critical'],
          ['compliance_below_50','Compliance score below 50%','warning'],
          ['login_failed','Multiple login failures detected','info'],
        ];
        foreach ($alertTypes as [$type,$desc,$sev]): ?>
          <div class="alert-type-item">
            <span class="badge badge-<?= $sev ?>"><?= ucfirst($sev) ?></span>
            <div>
              <div class="fw-500 text-sm"><?= Security::h($type) ?></div>
              <div class="text-muted text-sm"><?= $desc ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
