<?php
$breadcrumbs = $breadcrumbs ?? [['Automation', '/automation'], ['New Rule', null]];
$csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div><h1 class="page-title">New Automation Rule</h1></div>
  <a href="/automation" class="btn btn-secondary">Cancel</a>
</div>

<form method="POST" action="/automation/create">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
    <div style="display:flex;flex-direction:column;gap:20px;">

      <div class="card">
        <div class="card-header"><h3 class="card-title">1. Rule Details</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group"><label class="form-label">Rule Name <span style="color:var(--danger)">*</span></label><input type="text" name="name" class="form-control" required placeholder="e.g. Alert on Critical Risk"></div>
          <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_active" checked>
              <span class="form-label" style="margin:0;">Active (enable immediately)</span>
            </label>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">2. Trigger</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Trigger Event</label>
            <select name="trigger_type" class="form-control" id="triggerSelect">
              <?php foreach ($triggerLabels as $v => $l): ?>
              <option value="<?= $v ?>"><?= Security::h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="cfg_risk_score_high" class="trigger-cfg" style="display:none;">
            <div class="form-group"><label class="form-label">Score Threshold</label><input type="number" name="risk_threshold" class="form-control" value="15" min="1" max="25" placeholder="Trigger when risk score ≥ this value"></div>
          </div>
          <div id="cfg_vendor_contract_expiring" class="trigger-cfg" style="display:none;">
            <div class="form-group"><label class="form-label">Days Before Expiry</label><input type="number" name="days_before" class="form-control" value="30" min="1"></div>
          </div>
        </div>
      </div>

    </div>
    <div style="display:flex;flex-direction:column;gap:20px;">

      <div class="card">
        <div class="card-header"><h3 class="card-title">3. Action</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Action to Take</label>
            <select name="action_type" class="form-control" id="actionSelect">
              <?php foreach ($actionLabels as $v => $l): ?>
              <option value="<?= $v ?>"><?= Security::h($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="act_create_issue" class="action-cfg">
            <div class="form-group"><label class="form-label">Issue Title Template</label><input type="text" name="issue_title_template" class="form-control" placeholder="e.g. High-risk item detected: {entity}"></div>
            <div class="form-group"><label class="form-label">Severity</label>
              <select name="issue_severity" class="form-control"><option value="critical">Critical</option><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select>
            </div>
          </div>
          <div id="act_send_webhook" class="action-cfg" style="display:none;">
            <div class="form-group"><label class="form-label">Webhook URL</label><input type="url" name="webhook_url" class="form-control" placeholder="https://..."></div>
          </div>
          <div id="act_send_email_notification" class="action-cfg" style="display:none;">
            <div class="form-group"><label class="form-label">Recipients (comma-separated emails)</label><input type="text" name="email_recipients" class="form-control" placeholder="admin@org.com, manager@org.com"></div>
            <div class="form-group"><label class="form-label">Subject Template</label><input type="text" name="email_subject" class="form-control" placeholder="AEGIS Alert: {trigger}"></div>
          </div>
          <div id="act_assign_user" class="action-cfg" style="display:none;">
            <div class="form-group"><label class="form-label">Assign To</label>
              <select name="assign_user_id" class="form-control">
                <option value="">— Select user —</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">Create Automation Rule</button>
    </div>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
function updateTriggerConfig() {
  document.querySelectorAll('.trigger-cfg').forEach(el => el.style.display = 'none');
  const v = document.getElementById('triggerSelect').value;
  const el = document.getElementById('cfg_' + v);
  if (el) el.style.display = 'block';
}
function updateActionConfig() {
  document.querySelectorAll('.action-cfg').forEach(el => el.style.display = 'none');
  const v = document.getElementById('actionSelect').value;
  const el = document.getElementById('act_' + v);
  if (el) el.style.display = 'block';
}
document.getElementById('triggerSelect').addEventListener('change', updateTriggerConfig);
document.getElementById('actionSelect').addEventListener('change', updateActionConfig);
updateTriggerConfig();
</script>
