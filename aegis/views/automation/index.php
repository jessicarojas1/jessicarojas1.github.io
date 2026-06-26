<?php
$breadcrumbs = $breadcrumbs ?? [['Automation', null]];
$csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Automation Rules</h1>
    <p class="page-subtitle">Define automated responses to GRC events — create issues, send notifications, trigger webhooks</p>
  </div>
  <a href="/automation/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Rule</a>
</div>

<?php if (empty($rules)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-lightning-fill" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No Automation Rules</h3>
  <p style="color:var(--text-muted);margin-bottom:20px;">Create rules to automatically respond when GRC events occur — like creating an issue when a risk score exceeds a threshold.</p>
  <a href="/automation/create" class="btn btn-primary">Create First Rule</a>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead><tr><th scope="col">Name</th><th scope="col">Trigger</th><th scope="col">Action</th><th scope="col">Status</th><th scope="col">Last Triggered</th><th scope="col">Triggers</th><th scope="col">7-day Activity</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $rule): ?>
      <tr>
        <td><a href="/automation/<?= (int)$rule['id'] ?>" style="font-weight:600;"><?= Security::h($rule['name']) ?></a></td>
        <td><span style="font-size:0.83rem;"><?= Security::h($triggerLabels[$rule['trigger_type']] ?? $rule['trigger_type']) ?></span></td>
        <td><span style="font-size:0.83rem;"><?= Security::h($actionLabels[$rule['action_type']] ?? $rule['action_type']) ?></span></td>
        <td>
          <form method="POST" action="/automation/<?= (int)$rule['id'] ?>/toggle" style="margin:0;display:inline;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" class="badge <?= $rule['is_active'] ? 'badge-success' : 'badge-secondary' ?>" style="border:none;cursor:pointer;">
              <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
            </button>
          </form>
        </td>
        <td style="font-size:0.83rem;color:var(--text-muted);"><?= $rule['last_triggered_at'] ? date('M j, Y', strtotime($rule['last_triggered_at'])) : '—' ?></td>
        <td><?= (int)$rule['trigger_count'] ?></td>
        <td>
          <?php if ($rule['recent_success'] > 0 || $rule['recent_failed'] > 0): ?>
            <span style="color:var(--success);font-size:0.8rem;">✓<?= (int)$rule['recent_success'] ?></span>
            <?php if ($rule['recent_failed'] > 0): ?>
            <span style="color:var(--danger);font-size:0.8rem;margin-left:4px;">✗<?= (int)$rule['recent_failed'] ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:var(--text-muted);font-size:0.8rem;">—</span>
          <?php endif; ?>
        </td>
        <td style="display:flex;gap:6px;">
          <a href="/automation/<?= (int)$rule['id'] ?>" class="btn btn-sm btn-secondary">View</a>
          <form method="POST" action="/automation/<?= (int)$rule['id'] ?>/delete" data-confirm="Delete this rule?" style="margin:0">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
