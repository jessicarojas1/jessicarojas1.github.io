<?php
$breadcrumbs = $breadcrumbs ?? [['Automation', '/automation'], ['Rule', null]];
$csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($rule['name']) ?></h1>
    <p class="page-subtitle">Automation Rule</p>
  </div>
  <div style="display:flex;gap:10px;">
    <form method="POST" action="/automation/<?= (int)$rule['id'] ?>/toggle" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-secondary"><?= $rule['is_active'] ? 'Disable' : 'Enable' ?></button>
    </form>
    <form method="POST" action="/automation/<?= (int)$rule['id'] ?>/delete" data-confirm="Delete this rule?" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;">
  <div style="display:flex;flex-direction:column;gap:20px;">

    <div class="card">
      <div class="card-header"><h3 class="card-title">Rule Configuration</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
        <?php if ($rule['description']): ?><div><div style="font-size:0.75rem;color:var(--text-muted);">Description</div><div><?= Security::h($rule['description']) ?></div></div><?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div><div style="font-size:0.75rem;color:var(--text-muted);">Trigger</div><div style="font-weight:600;"><?= Security::h($triggerLabels[$rule['trigger_type']] ?? $rule['trigger_type']) ?></div></div>
          <div><div style="font-size:0.75rem;color:var(--text-muted);">Action</div><div style="font-weight:600;"><?= Security::h($actionLabels[$rule['action_type']] ?? $rule['action_type']) ?></div></div>
        </div>
        <?php if (!empty($rule['trigger_config'])): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Trigger Config</div>
          <pre style="background:var(--bg);border-radius:6px;padding:10px;font-size:0.8rem;margin:4px 0 0;"><?= Security::h(json_encode($rule['trigger_config'], JSON_PRETTY_PRINT)) ?></pre>
        </div>
        <?php endif; ?>
        <?php if (!empty($rule['action_config'])): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Action Config</div>
          <pre style="background:var(--bg);border-radius:6px;padding:10px;font-size:0.8rem;margin:4px 0 0;"><?= Security::h(json_encode($rule['action_config'], JSON_PRETTY_PRINT)) ?></pre>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Test Run -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">Dry Run Test</h3>
        <button id="btnTestRule" class="btn btn-sm btn-secondary"><i class="bi bi-play-fill"></i> Run Test</button>
      </div>
      <div class="card-body">
        <p style="font-size:0.875rem;color:var(--text-muted);margin-bottom:12px;">Simulates the trigger without executing any actions. Shows what would be affected.</p>
        <pre id="testResult" style="background:var(--bg);border-radius:6px;padding:12px;font-size:0.8rem;min-height:60px;white-space:pre-wrap;display:none;"></pre>
      </div>
    </div>

    <!-- Logs -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Recent Execution Logs</h3></div>
      <?php if (empty($logs)): ?>
      <div class="card-body"><p style="color:var(--text-muted);font-size:0.875rem;">No execution history yet.</p></div>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>Time</th><th>Status</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td style="font-size:0.83rem;"><?= date('M j, Y g:ia', strtotime($log['triggered_at'])) ?></td>
            <td><span class="badge <?= $log['status']==='success'?'badge-success':($log['status']==='failed'?'badge-danger':'badge-secondary') ?>"><?= ucfirst($log['status']) ?></span></td>
            <td style="font-size:0.83rem;"><?= Security::h($log['details'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="height:fit-content;">
    <div class="card-header"><h3 class="card-title">Stats</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Status</div><span class="badge <?= $rule['is_active']?'badge-success':'badge-secondary' ?>"><?= $rule['is_active']?'Active':'Inactive' ?></span></div>
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Total Triggers</div><div style="font-weight:700;font-size:1.25rem;"><?= (int)$rule['trigger_count'] ?></div></div>
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Last Triggered</div><div><?= $rule['last_triggered_at'] ? date('M j, Y', strtotime($rule['last_triggered_at'])) : 'Never' ?></div></div>
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Created By</div><div><?= Security::h($rule['created_by_name'] ?? '—') ?></div></div>
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Created</div><div><?= date('M j, Y', strtotime($rule['created_at'])) ?></div></div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
document.getElementById('btnTestRule').addEventListener('click', testRule);
let csrf = <?= json_encode(Security::generateCsrfToken()) ?>;
async function testRule() {
  const pre = document.getElementById('testResult');
  pre.style.display = 'block';
  pre.textContent = 'Running test…';
  try {
    const res = await fetch('/automation/<?= (int)$rule['id'] ?>/test', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: csrf }),
    });
    const data = await res.json();
    if (data.csrf) csrf = data.csrf;
    pre.textContent = JSON.stringify(data.result, null, 2);
  } catch(e) {
    pre.textContent = 'Error: ' + e.message;
  }
}
</script>
