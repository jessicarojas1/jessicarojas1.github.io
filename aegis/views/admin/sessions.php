<?php ob_start();

function parseBrowser(string $ua): string {
    if (str_contains($ua, 'Edg/'))       return 'Edge';
    if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) return 'Opera';
    if (str_contains($ua, 'Chrome/'))    return 'Chrome';
    if (str_contains($ua, 'Firefox/'))   return 'Firefox';
    if (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome')) return 'Safari';
    if (str_contains($ua, 'MSIE') || str_contains($ua, 'Trident')) return 'IE';
    if (str_contains($ua, 'curl/'))      return 'cURL';
    return 'Unknown';
}

function parseOS(string $ua): string {
    if (str_contains($ua, 'Windows NT')) return 'Windows';
    if (str_contains($ua, 'Mac OS X'))   return 'macOS';
    if (str_contains($ua, 'Linux'))      return 'Linux';
    if (str_contains($ua, 'Android'))    return 'Android';
    if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'iOS';
    return 'Unknown';
}
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Active Sessions</h1>
    <p class="page-subtitle">Users with activity in the last 2 hours</p>
  </div>
</div>

<!-- Stat chips -->
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">
  <div class="card" style="flex:1;min-width:160px;padding:16px 20px">
    <div style="font-size:2rem;font-weight:700;color:var(--primary)"><?= $totalCount ?></div>
    <div style="font-size:0.83rem;color:var(--text-muted);margin-top:2px">Active Sessions</div>
  </div>
  <div class="card" style="flex:1;min-width:160px;padding:16px 20px">
    <div style="font-size:2rem;font-weight:700;color:var(--success)"><?= $uniqueUsers ?></div>
    <div style="font-size:0.83rem;color:var(--text-muted);margin-top:2px">Unique Users</div>
  </div>
</div>

<?php if (empty($activeSessions)): ?>
  <div class="card" style="text-align:center;padding:48px 24px;color:var(--text-muted)">
    <i class="bi bi-people" style="font-size:2.5rem;display:block;margin-bottom:12px"></i>
    No active sessions in the last 2 hours.
  </div>
<?php else: ?>
<div class="card" style="overflow:hidden">
  <div style="overflow-x:auto">
    <table class="data-table" id="sessionsTable">
      <thead>
        <tr>
          <th>User</th>
          <th>Role</th>
          <th>IP Address</th>
          <th>Browser / OS</th>
          <th>Last Seen</th>
          <th>Session Age</th>
          <th style="width:100px">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activeSessions as $s):
          $isMe        = ($s['id'] === session_id());
          $lastSeen    = strtotime($s['last_seen_at']);
          $created     = strtotime($s['created_at']);
          $ageSeconds  = time() - $created;
          $ageStr      = $ageSeconds < 3600
              ? floor($ageSeconds / 60) . 'm'
              : round($ageSeconds / 3600, 1) . 'h';
          $browser     = parseBrowser($s['user_agent'] ?? '');
          $os          = parseOS($s['user_agent'] ?? '');
        ?>
          <tr id="sess-<?= Security::h($s['id']) ?>" style="<?= $isMe ? 'background:rgba(99,102,241,.06)' : '' ?>">
            <td>
              <div style="font-weight:600"><?= Security::h($s['user_name']) ?></div>
              <div style="font-size:0.78rem;color:var(--text-muted)"><?= Security::h($s['email']) ?></div>
              <?php if ($isMe): ?>
                <span class="badge badge-primary" style="font-size:0.68rem;margin-top:2px">You</span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-secondary"><?= Security::h(ucfirst($s['role'])) ?></span></td>
            <td style="font-family:monospace;font-size:0.85rem"><?= Security::h($s['ip_address'] ?? '—') ?></td>
            <td>
              <span style="font-size:0.85rem"><?= Security::h($browser) ?></span>
              <span style="font-size:0.78rem;color:var(--text-muted)"> / <?= Security::h($os) ?></span>
            </td>
            <td style="font-size:0.83rem;color:var(--text-muted)"><?= Security::h(date('M j, g:ia', $lastSeen)) ?></td>
            <td style="font-size:0.83rem"><?= Security::h($ageStr) ?> ago</td>
            <td>
              <?php if ($isMe): ?>
                <button class="btn btn-secondary btn-sm" disabled title="Cannot terminate your own session" style="opacity:.5;cursor:not-allowed">
                  <i class="bi bi-x-circle"></i> Kill
                </button>
              <?php else: ?>
                <button class="btn btn-danger btn-sm"
                        data-click="killSession" data-args='["<?= Security::h($s['id']) ?>"]'
                        title="Terminate this session">
                  <i class="bi bi-x-circle"></i> Kill
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px">
  <div class="card-body" style="font-size:0.85rem;color:var(--text-muted)">
    <i class="bi bi-info-circle" style="margin-right:4px"></i>
    Sessions are tracked automatically on each authenticated request. A session is considered active if its last activity was within the last 2 hours.
    Terminating a session removes its tracking entry — the user will be logged out on their next page load.
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
var _csrfToken = document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').getAttribute('content') : '';

function killSession(sessionId, btn) {
    if (!confirm('Terminate this session? The user will be logged out on their next request.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    fetch('/admin/sessions/' + sessionId + '/kill', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(_csrfToken)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            var row = document.getElementById('sess-' + sessionId);
            if (row) {
                row.style.transition = 'opacity .3s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); }, 350);
            }
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-circle"></i> Kill';
            alert(d.error || 'Failed to terminate session.');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-x-circle"></i> Kill';
        alert('Request failed.');
    });
}
</script>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
