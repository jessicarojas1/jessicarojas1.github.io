<?php
$isEdit = !empty($config);
$pageTitle    = $isEdit ? 'Edit Alert Config' : 'New Alert Config';
$activeModule = 'admin';
$breadcrumbs  = [['Admin','/admin'],['Alerts','/admin/alerts'],[$isEdit?'Edit':'New',null]];
$nonce = Security::nonce();
ob_start();

$alertTypes = [
    'risk_review_due'        => 'Risk Review Due',
    'policy_review_due'      => 'Policy Review Due',
    'audit_overdue'          => 'Audit Overdue',
    'critical_risk_logged'   => 'Critical Risk Logged',
    'compliance_below_50'    => 'Compliance Below 50%',
    'login_failed'           => 'Login Failed',
    'control_non_compliant'  => 'Control Non-Compliant',
    'incident_critical'      => 'Critical Incident Created',
    'vendor_overdue'         => 'Vendor Assessment Overdue',
    'treatment_overdue'      => 'Treatment Plan Overdue',
    'risk_score_threshold'   => 'Risk Score Exceeds Threshold',
    'custom'                 => 'Custom',
];
?>

<div class="page-header">
  <h1 class="page-title"><?= $isEdit ? 'Edit Alert Configuration' : 'New Alert Configuration' ?></h1>
  <a href="/admin/alerts" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="two-col-layout">
  <div class="card" style="flex:2">
    <div class="card-body">
      <form method="POST" action="/admin/alerts/config/save">
        <?= Security::csrfField() ?>
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int)$config['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label required">Alert Name</label>
          <input type="text" name="name" class="form-control" required
            value="<?= Security::h($config['name'] ?? '') ?>"
            placeholder="e.g. Critical Risk Alert">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label required">Alert Type</label>
            <select name="type" class="form-control" required>
              <option value="">— Select type —</option>
              <?php foreach ($alertTypes as $v=>$l): ?>
                <option value="<?= $v ?>" <?= ($config['type']??'')===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <div style="padding-top:8px">
              <label class="toggle-switch">
                <input type="checkbox" name="is_active" <?= !$isEdit||($config['is_active']??true)?'checked':'' ?>>
                <span>Active</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Trigger Config -->
        <div class="form-group">
          <label class="form-label">Trigger Configuration (JSON)</label>
          <textarea name="trigger_config" class="form-control" rows="4" style="font-family:monospace;font-size:13px"
            placeholder='{"min_score": 15, "category": "operational"}'><?= Security::h(json_encode($config['trigger_config'] ?? [], JSON_PRETTY_PRINT)) ?></textarea>
          <div class="form-hint">JSON object with trigger parameters. Available keys vary by alert type (e.g. <code>min_score</code>, <code>days_overdue</code>, <code>severity</code>).</div>
        </div>

        <!-- Recipients -->
        <div class="form-group">
          <label class="form-label">Recipient Email Addresses</label>
          <textarea name="recipients" class="form-control" rows="4" placeholder="one@example.com&#10;two@example.com"><?= Security::h(implode("\n", $config['recipients'] ?? [])) ?></textarea>
          <div class="form-hint">One email address per line. Leave empty to only use in-app alerts.</div>
        </div>

        <!-- Channels -->
        <div class="form-group">
          <label class="form-label">Delivery Channels</label>
          <div style="display:flex;gap:20px;margin-top:6px">
            <?php foreach (['in_app'=>'In-App Alert','email'=>'Email','webhook'=>'Webhook'] as $cv=>$cl): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer">
              <input type="checkbox" name="channels[]" value="<?= $cv ?>"
                <?= in_array($cv, $config['channels'] ?? ['in_app'])?'checked':'' ?>>
              <?= $cl ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Alert Config</button>
          <a href="/admin/alerts" class="btn btn-ghost">Cancel</a>
          <?php if ($isEdit): ?>
            <form method="POST" action="/admin/alerts/config/<?= (int)$config['id'] ?>/delete" style="margin:0" data-confirm="Delete this alert configuration?">
              <?= Security::csrfField() ?>
              <button type="submit" class="btn btn-ghost" style="color:#ef4444"><i class="bi bi-trash3"></i> Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Help Sidebar -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-question-circle"></i> Configuration Guide</h3></div>
    <div class="card-body" style="font-size:13px">
      <p style="color:#64748b;margin-bottom:12px">Each alert type supports different trigger configuration parameters:</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ([
          'risk_score_threshold' => ['min_score'=>'int','status'=>'string'],
          'audit_overdue'        => ['days_overdue'=>'int'],
          'policy_review_due'    => ['days_ahead'=>'int'],
          'compliance_below_50'  => ['threshold_pct'=>'int'],
          'login_failed'         => ['max_attempts'=>'int','window_minutes'=>'int'],
        ] as $t=>$params): ?>
        <div style="background:var(--bg-secondary);border-radius:6px;padding:10px">
          <div style="font-weight:600;margin-bottom:4px"><?= $alertTypes[$t] ?? $t ?></div>
          <code style="font-size:11px;color:#6366f1"><?= Security::h(json_encode($params)) ?></code>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('form[data-confirm]').forEach(function(f) {
  f.addEventListener('submit', function(e) {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
  });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
