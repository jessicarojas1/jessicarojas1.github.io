<?php ob_start(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Data Retention Policies</h1>
    <p class="page-subtitle">Configure how long data is kept before automatic deletion or archiving</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-x-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div id="runResults" style="display:none;margin-bottom:16px"></div>

<div class="card">
  <form method="POST" action="/admin/retention/save">
    <?= Security::csrfField() ?>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Entity Type</th>
            <th>Retention (days)</th>
            <th>Action</th>
            <th>Enabled</th>
            <th>Last Run</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($policies as $i => $p): ?>
            <tr>
              <td>
                <input type="hidden" name="policies[<?= $i ?>][id]" value="<?= (int)$p['id'] ?>">
                <strong><?= Security::h(ucwords(str_replace('_', ' ', $p['entity_type']))) ?></strong>
                <div style="font-size:0.78rem;color:var(--text-muted)"><?= Security::h($p['entity_type']) ?></div>
              </td>
              <td>
                <input type="number"
                       name="policies[<?= $i ?>][retention_days]"
                       class="form-control"
                       style="width:90px"
                       value="<?= (int)$p['retention_days'] ?>"
                       min="1"
                       max="3650"
                       required>
              </td>
              <td>
                <select name="policies[<?= $i ?>][action]" class="form-control" style="width:110px">
                  <option value="delete"  <?= $p['action'] === 'delete'  ? 'selected' : '' ?>>Delete</option>
                  <option value="archive" <?= $p['action'] === 'archive' ? 'selected' : '' ?>>Archive</option>
                </select>
              </td>
              <td style="text-align:center">
                <input type="checkbox"
                       name="policies[<?= $i ?>][is_enabled]"
                       value="1"
                       <?= $p['is_enabled'] ? 'checked' : '' ?>>
              </td>
              <td style="font-size:0.82rem;color:var(--text-muted)">
                <?= $p['last_run_at'] ? Security::h(date('M j, Y g:ia', strtotime($p['last_run_at']))) : '—' ?>
              </td>
              <td>
                <?php if ($p['is_enabled']): ?>
                  <span class="badge badge-success">Active</span>
                <?php else: ?>
                  <span class="badge badge-secondary">Disabled</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;gap:10px;padding:16px;border-top:1px solid var(--border)">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save"></i> Save Policies
      </button>
      <button type="button" class="btn btn-danger" id="runNowBtn" data-click="runRetentionNow">
        <i class="bi bi-play-circle"></i> Run Now
      </button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:20px">
  <div class="card-header"><h3>About Data Retention</h3></div>
  <div class="card-body" style="font-size:0.88rem;color:var(--text-secondary);line-height:1.7">
    <p>Enabling a policy will automatically remove or archive old records during the next scheduled run or when you click "Run Now".</p>
    <ul style="margin:8px 0 0 20px;padding:0">
      <li><strong>Activity Log</strong> — Audit trail entries older than the configured period.</li>
      <li><strong>Notification Log</strong> — Sent notification records.</li>
      <li><strong>Webhook Deliveries</strong> — Delivered and failed webhook delivery records.</li>
      <li><strong>Alerts</strong> — Read alert notifications.</li>
    </ul>
    <p style="margin-top:10px;color:var(--text-muted);font-size:0.82rem">Note: Policies set to "archive" currently behave the same as "delete" until an archive backend is configured.</p>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function runRetentionNow() {
    var btn = document.getElementById('runNowBtn');
    var res = document.getElementById('runResults');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Running…';
    res.style.display = 'none';

    var csrf = document.querySelector('input[name=csrf_token]').value;
    fetch('/admin/retention/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(csrf) + '&format=json'
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle"></i> Run Now';
        if (d.ok) {
            var rows = (d.results || []).map(function(r) {
                return '<tr><td>' + r.entity_type + '</td><td>' + (r.deleted || 0) + ' rows deleted</td>'
                     + (r.error ? '<td style="color:var(--danger)">' + r.error + '</td>' : '<td style="color:var(--success)">OK</td>')
                     + '</tr>';
            }).join('');
            res.innerHTML = '<div class="card"><div class="card-header"><h3>Run Results</h3></div>'
                          + '<div style="overflow-x:auto"><table class="data-table"><thead><tr><th>Entity</th><th>Result</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
        } else {
            res.innerHTML = '<div class="alert-box error"><i class="bi bi-x-circle-fill"></i> Failed to run retention.</div>';
        }
        res.style.display = 'block';
        // Refresh page to update Last Run timestamps
        setTimeout(function() { location.reload(); }, 3000);
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle"></i> Run Now';
        res.innerHTML = '<div class="alert-box error"><i class="bi bi-x-circle-fill"></i> Request failed.</div>';
        res.style.display = 'block';
    });
}
</script>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
