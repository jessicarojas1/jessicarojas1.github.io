<?php
$pageTitle   = 'Webhook Deliveries — ' . ($endpoint['name'] ?? '');
$breadcrumbs = [['Admin', '/admin'], ['Webhooks', '/admin/webhooks'], [Security::h($endpoint['name'] ?? ''), null]];
ob_start();

$providerIcons = [
    'slack'      => 'bi-slack',
    'jira'       => 'bi-bug',
    'pagerduty'  => 'bi-bell-fill',
    'servicenow' => 'bi-layers',
    'generic'    => 'bi-globe2',
];
$icon = $providerIcons[$endpoint['provider'] ?? 'generic'] ?? 'bi-globe2';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">
      <i class="bi <?= $icon ?>"></i>
      Delivery Log — <?= Security::h($endpoint['name']) ?>
    </h1>
    <p class="text-muted text-sm" style="margin-top:4px">
      <?= Security::h($endpoint['url']) ?>
    </p>
  </div>
  <a href="/admin/webhooks" class="btn btn-ghost">
    <i class="bi bi-arrow-left"></i> Back to Webhooks
  </a>
</div>

<!-- Delivery stats summary -->
<?php
$total     = count($deliveries);
$delivered = count(array_filter($deliveries, fn($d) => $d['status'] === 'delivered'));
$pending   = count(array_filter($deliveries, fn($d) => $d['status'] === 'pending'));
$failed    = count(array_filter($deliveries, fn($d) => $d['status'] === 'failed'));
?>
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  <div class="stat-card card">
    <div class="card-body" style="padding:16px">
      <div class="stat-value"><?= $total ?></div>
      <div class="stat-label text-muted text-sm">Total (last 50)</div>
    </div>
  </div>
  <div class="stat-card card">
    <div class="card-body" style="padding:16px">
      <div class="stat-value" style="color:var(--green)"><?= $delivered ?></div>
      <div class="stat-label text-muted text-sm">Delivered</div>
    </div>
  </div>
  <div class="stat-card card">
    <div class="card-body" style="padding:16px">
      <div class="stat-value" style="color:var(--yellow)"><?= $pending ?></div>
      <div class="stat-label text-muted text-sm">Pending / Retry</div>
    </div>
  </div>
  <div class="stat-card card">
    <div class="card-body" style="padding:16px">
      <div class="stat-value" style="color:var(--red)"><?= $failed ?></div>
      <div class="stat-label text-muted text-sm">Failed</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-list-ul"></i> Recent Deliveries</h3>
    <span class="text-muted text-sm">Showing up to 50 most recent</span>
  </div>
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Event Type</th>
          <th>Status</th>
          <th>Attempts</th>
          <th>Response Code</th>
          <th>Created</th>
          <th>Delivered At</th>
          <th>Next Retry</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($deliveries): foreach ($deliveries as $d): ?>
          <tr>

            <td>
              <span class="tag" style="font-size:12px"><?= Security::h($d['event_type']) ?></span>
            </td>

            <td>
              <?php if ($d['status'] === 'delivered'): ?>
                <span class="badge badge-green"><i class="bi bi-check-circle"></i> delivered</span>
              <?php elseif ($d['status'] === 'failed'): ?>
                <span class="badge badge-red"><i class="bi bi-x-circle"></i> failed</span>
              <?php else: ?>
                <span class="badge badge-yellow"><i class="bi bi-clock"></i> pending</span>
              <?php endif; ?>
            </td>

            <td>
              <span <?= (int) $d['attempts'] >= 3 ? 'style="color:var(--orange);font-weight:600"' : '' ?>>
                <?= (int) $d['attempts'] ?>
              </span>
              <?php if ((int) $d['attempts'] >= 5): ?>
                <span class="text-muted text-sm"> (max)</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($d['response_code']): ?>
                <?php $code = (int) $d['response_code']; ?>
                <code class="mono" style="color:<?= $code >= 200 && $code < 300 ? 'var(--green)' : 'var(--red)' ?>">
                  <?= $code ?>
                </code>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <td class="text-sm text-muted">
              <?= date('M j, Y g:ia', strtotime($d['created_at'])) ?>
            </td>

            <td class="text-sm text-muted">
              <?= $d['delivered_at'] ? date('M j, Y g:ia', strtotime($d['delivered_at'])) : '—' ?>
            </td>

            <td class="text-sm text-muted">
              <?php if ($d['status'] === 'pending' && $d['next_retry_at']): ?>
                <?= date('M j, g:ia', strtotime($d['next_retry_at'])) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>

            <td>
              <?php if ($d['response_body']): ?>
                <button class="btn btn-ghost btn-sm"
                        data-click="showResponseModal" data-arg="<?= (int)$d['id'] ?>"
                        title="View response body">
                  <i class="bi bi-eye"></i>
                </button>
                <div id="resp-<?= (int) $d['id'] ?>" style="display:none">
                  <?= Security::h(substr($d['response_body'], 0, 4000)) ?>
                </div>
              <?php endif; ?>
            </td>

          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="8" class="empty-row">
              <div class="empty-state-sm">
                <i class="bi bi-inbox"></i>
                <p>No deliveries recorded for this endpoint yet.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Response body modal -->
<div class="modal-overlay" id="responseModal" style="display:none">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <h3><i class="bi bi-code-square"></i> Response Body</h3>
      <button data-close-modal="responseModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <pre id="responseBodyContent"
           style="background:var(--surface-2,#1e293b);color:#a5f3fc;padding:16px;border-radius:8px;
                  font-size:13px;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow:auto;margin:0"></pre>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function showModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
document.getElementById('responseModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal('responseModal');
});

function showResponseModal(deliveryId) {
  const body = document.getElementById('resp-' + deliveryId);
  if (!body) return;
  document.getElementById('responseBodyContent').textContent = body.textContent.trim();
  showModal('responseModal');
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
