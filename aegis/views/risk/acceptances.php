<?php
$pageTitle    = $pageTitle    ?? 'Risk Acceptance Certificates';
$activeModule = $activeModule ?? 'risk_acceptances';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Acceptance Certificates', null]];

$activeCount      = (int)($summary['active_count']      ?? 0);
$expiredCount     = (int)($summary['expired_count']     ?? 0);
$revokedCount     = (int)($summary['revoked_count']     ?? 0);
$supersededCount  = (int)($summary['superseded_count']  ?? 0);
$expiringSoon     = (int)($summary['expiring_soon_count'] ?? 0);

// Status config
$statusConfig = [
    'active'     => ['label' => 'Active',     'fg' => '#16a34a', 'bg' => '#f0fdf4', 'border' => '#86efac'],
    'expired'    => ['label' => 'Expired',    'fg' => '#64748b', 'bg' => '#f1f5f9', 'border' => '#cbd5e1'],
    'superseded' => ['label' => 'Superseded', 'fg' => 'var(--secondary)', 'bg' => 'rgba(55,65,81,.06)', 'border' => '#d1d5db'],
    'revoked'    => ['label' => 'Revoked',    'fg' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fca5a5'],
];

$levelConfig = [
    'critical' => ['label' => 'Critical', 'fg' => '#ef4444', 'bg' => '#fef2f2'],
    'high'     => ['label' => 'High',     'fg' => '#f97316', 'bg' => '#fff7ed'],
    'medium'   => ['label' => 'Medium',   'fg' => '#f59e0b', 'bg' => '#fffbeb'],
    'low'      => ['label' => 'Low',      'fg' => '#22c55e', 'bg' => '#f0fdf4'],
];

$filterStatus = $_GET['status'] ?? '';

// Apply status filter client-side via PHP
$displayed = $acceptances;
if ($filterStatus !== '') {
    $displayed = array_values(array_filter($acceptances, fn($a) => $a['status'] === $filterStatus));
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-patch-check-fill" style="color:var(--primary);margin-right:6px;"></i> Risk Acceptance Certificates</h1>
    <p class="page-subtitle">Formal documented acceptances of risk at their current level</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Risk Register</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- KPI Strip -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">

  <div class="card" style="border-left:4px solid #16a34a;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-patch-check-fill" style="font-size:20px;color:#16a34a;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:#16a34a;"><?= $activeCount ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Active</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid <?= $expiringSoon > 0 ? '#f59e0b' : '#94a3b8' ?>;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:<?= $expiringSoon > 0 ? '#fffbeb' : '#f1f5f9' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-alarm-fill" style="font-size:20px;color:<?= $expiringSoon > 0 ? '#f59e0b' : '#94a3b8' ?>;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:<?= $expiringSoon > 0 ? '#f59e0b' : '#94a3b8' ?>;"><?= $expiringSoon ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Expiring &lt;30 Days</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid #64748b;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-calendar-x-fill" style="font-size:20px;color:var(--text-muted);"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:var(--text-muted);"><?= $expiredCount ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Expired</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid #dc2626;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-x-circle-fill" style="font-size:20px;color:#dc2626;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:#dc2626;"><?= $revokedCount ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Revoked</div>
      </div>
    </div>
  </div>

</div>

<!-- Filter Bar -->
<div class="filter-bar card" style="margin-bottom:20px;">
  <form method="GET" class="filter-form" style="display:flex;align-items:center;gap:12px;padding:12px 16px;">
    <label style="font-size:13px;font-weight:500;color:var(--text-muted);white-space:nowrap;">Filter by status:</label>
    <select name="status" class="form-control form-control-sm" data-autosubmit style="width:auto;min-width:160px;">
      <option value="">All statuses</option>
      <?php foreach ($statusConfig as $sv => $sc): ?>
        <option value="<?= $sv ?>" <?= $filterStatus === $sv ? 'selected' : '' ?>><?= $sc['label'] ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterStatus): ?>
      <a href="/risk-acceptances" class="btn btn-ghost btn-sm">Clear</a>
    <?php endif; ?>
    <span style="font-size:12px;color:var(--text-muted);margin-left:auto;"><?= count($displayed) ?> result<?= count($displayed) !== 1 ? 's' : '' ?></span>
  </form>
</div>

<!-- Acceptances Table -->
<div class="card">
  <div class="card-body p0">
    <?php if (empty($displayed)): ?>
      <div class="empty-state" style="padding:3rem;text-align:center;">
        <i class="bi bi-patch-check" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:12px;"></i>
        <p style="color:var(--text-muted);margin:0;">
          <?= $filterStatus ? 'No acceptance certificates match this filter.' : 'No acceptance certificates on record.' ?>
        </p>
        <p style="font-size:13px;color:var(--text-light);margin-top:4px;">
          To issue a certificate, open a risk and click "Issue Acceptance".
        </p>
        <a href="/risk" class="btn btn-primary btn-sm" style="margin-top:12px;">Go to Risk Register</a>
      </div>
    <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Risk</th>
          <th>Accepted By</th>
          <th>Score at Acceptance</th>
          <th style="min-width:200px;">Acceptance Reason</th>
          <th>Valid Until</th>
          <th>Status</th>
          <th>Days Remaining</th>
          <th style="width:130px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($displayed as $acc):
          $sc = $statusConfig[$acc['status']] ?? ['label' => ucfirst($acc['status']), 'fg' => '#64748b', 'bg' => '#f1f5f9', 'border' => '#e2e8f0'];
          $lc = $levelConfig[strtolower($acc['risk_level_at_acceptance'] ?? '')] ?? null;

          $acceptanceScore = (int)($acc['risk_score_at_acceptance'] ?? 0);
          $hasScore = $acceptanceScore > 0;

          $validUntil = $acc['valid_until'] ?? null;
          $daysRemaining = null;
          $daysDisplay   = '—';
          $daysStyle     = '';
          if ($validUntil) {
              $daysRemaining = (int)ceil((strtotime($validUntil) - strtotime('today')) / 86400);
              if ($daysRemaining > 0) {
                  if ($daysRemaining <= 7) {
                      $daysDisplay = $daysRemaining . ' day' . ($daysRemaining !== 1 ? 's' : '') . ' left';
                      $daysStyle   = 'color:#dc2626;font-weight:600;';
                  } elseif ($daysRemaining <= 30) {
                      $daysDisplay = $daysRemaining . ' days left';
                      $daysStyle   = 'color:#d97706;font-weight:600;';
                  } else {
                      $daysDisplay = $daysRemaining . ' days';
                  }
              } else {
                  $expired_ago = abs($daysRemaining);
                  $daysDisplay = 'Expired ' . $expired_ago . ' day' . ($expired_ago !== 1 ? 's' : '') . ' ago';
                  $daysStyle   = 'color:#dc2626;font-size:12px;';
              }
          }

          $rowId = 'acc-row-' . (int)$acc['id'];
        ?>
        <tr>
          <td>
            <a href="/risk/<?= (int)$acc['risk_id'] ?>" class="text-link fw-500">
              <?= Security::h($acc['risk_title'] ?? 'Unknown Risk') ?>
            </a>
            <?php if (!empty($acc['risk_code'])): ?>
              <div class="text-xs text-muted mono"><?= Security::h($acc['risk_code']) ?></div>
            <?php endif; ?>
          </td>

          <td class="text-sm"><?= Security::h($acc['acceptor_name'] ?? '—') ?></td>

          <td>
            <?php if ($hasScore): ?>
              <?php if ($lc): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:2px 10px;border-radius:20px;background:<?= $lc['bg'] ?>;color:<?= $lc['fg'] ?>;">
                  <?= $acceptanceScore ?> &mdash; <?= $lc['label'] ?>
                </span>
              <?php else: ?>
                <span style="font-size:13px;font-weight:600;"><?= $acceptanceScore ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted text-sm">—</span>
            <?php endif; ?>
          </td>

          <td class="text-sm" style="max-width:220px;">
            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:220px;" title="<?= Security::h($acc['acceptance_reason']) ?>">
              <?= Security::h(mb_strimwidth($acc['acceptance_reason'] ?? '', 0, 80, '…')) ?>
            </div>
            <?php if (!empty($acc['conditions'])): ?>
              <button type="button"
                      class="btn btn-ghost btn-sm"
                      style="font-size:11px;padding:1px 6px;margin-top:2px;"
                      data-click="toggleConditions" data-arg="<?= $rowId ?>">
                <i class="bi bi-chevron-down" id="chevron-<?= $rowId ?>"></i> Conditions
              </button>
            <?php endif; ?>
          </td>

          <td class="text-sm" style="white-space:nowrap;">
            <?= $validUntil ? date('M j, Y', strtotime($validUntil)) : '—' ?>
          </td>

          <td>
            <span style="display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;border:1px solid <?= $sc['border'] ?? $sc['bg'] ?>;">
              <?= $sc['label'] ?>
            </span>
            <?php if (!empty($acc['renewal_required']) && $acc['renewal_required'] !== 'f' && $acc['renewal_required'] !== false): ?>
              <div style="font-size:10px;color:var(--text-muted);margin-top:2px;"><i class="bi bi-arrow-clockwise"></i> Renewal req.</div>
            <?php endif; ?>
          </td>

          <td class="text-sm" style="<?= $daysStyle ?>;white-space:nowrap;"><?= Security::h($daysDisplay) ?></td>

          <td style="white-space:nowrap;text-align:right;">
            <?php if ($acc['status'] === 'active' && Auth::can('risk.write')): ?>
              <a href="/risk-acceptances/<?= (int)$acc['id'] ?>/renew" class="btn btn-ghost btn-sm" title="Renew">
                <i class="bi bi-arrow-clockwise"></i>
              </a>
            <?php endif; ?>
            <?php if ($acc['status'] === 'expired' && Auth::can('risk.write')): ?>
              <a href="/risk-acceptances/<?= (int)$acc['id'] ?>/renew" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-clockwise"></i> Renew
              </a>
            <?php endif; ?>
            <?php if ($acc['status'] === 'active' && Auth::can('risk.write')): ?>
              <button type="button"
                      class="btn btn-sm"
                      style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;"
                      data-click="toggleRevoke" data-arg="revoke-<?= (int)$acc['id'] ?>"
                      title="Revoke">
                <i class="bi bi-x-circle"></i> Revoke
              </button>
            <?php endif; ?>
          </td>
        </tr>

        <!-- Conditions expandable row -->
        <?php if (!empty($acc['conditions'])): ?>
        <tr id="<?= $rowId ?>" style="display:none;">
          <td colspan="8" style="background:#f8fafc;padding:12px 20px 14px;border-top:none;">
            <div style="display:flex;gap:8px;align-items:flex-start;">
              <i class="bi bi-bookmark-fill" style="color:var(--secondary);font-size:14px;flex-shrink:0;margin-top:2px;"></i>
              <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px;">Conditions of Acceptance</div>
                <div style="font-size:13px;line-height:1.6;white-space:pre-wrap;"><?= Security::h($acc['conditions']) ?></div>
              </div>
            </div>
          </td>
        </tr>
        <?php endif; ?>

        <!-- Revoke inline form -->
        <?php if ($acc['status'] === 'active' && Auth::can('risk.write')): ?>
        <tr id="revoke-<?= (int)$acc['id'] ?>" style="display:none;">
          <td colspan="8" style="background:#fef2f2;padding:14px 20px 16px;border-top:1px solid #fca5a5;">
            <form method="POST" action="/risk-acceptances/<?= (int)$acc['id'] ?>/revoke"
                  data-confirm="Are you sure you want to revoke this acceptance certificate? This action cannot be undone.">
              <?= Security::csrfField() ?>
              <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <div style="flex:1;min-width:240px;">
                  <label style="font-size:12px;font-weight:600;color:#dc2626;display:block;margin-bottom:4px;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Revocation Reason
                  </label>
                  <textarea name="revocation_reason" class="form-control" rows="2"
                            style="border-color:#fca5a5;"
                            placeholder="Explain why this acceptance is being revoked…"></textarea>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;">
                  <button type="button" class="btn btn-ghost btn-sm"
                          data-click="toggleRevoke" data-arg="revoke-<?= (int)$acc['id'] ?>">Cancel</button>
                  <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;">
                    <i class="bi bi-x-circle-fill"></i> Confirm Revoke
                  </button>
                </div>
              </div>
            </form>
          </td>
        </tr>
        <?php endif; ?>

        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<style nonce="<?= Security::nonce() ?>">
.filter-bar { border-radius: 8px; }
.filter-form { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
</style>

<script nonce="<?= Security::nonce() ?>">
function toggleConditions(rowId) {
  var row    = document.getElementById(rowId);
  var btn    = document.querySelector('[data-click="toggleConditions"][data-arg="' + rowId + '"]');
  var chev   = document.getElementById('chevron-' + rowId);
  if (!row) return;
  var isHidden = row.style.display === 'none' || row.style.display === '';
  row.style.display = isHidden ? 'table-row' : 'none';
  if (chev) {
    chev.className = isHidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
  }
}

function toggleRevoke(rowId) {
  var row = document.getElementById(rowId);
  if (!row) return;
  row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}
</script>
