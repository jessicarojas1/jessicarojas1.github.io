<?php
$pageTitle    = $pageTitle    ?? 'Risk Exceptions & Waivers';
$activeModule = $activeModule ?? 'risk_exceptions';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Exceptions & Waivers', null]];

$pendingCount     = (int)($stats['pending_count']      ?? 0);
$approvedCount    = (int)($stats['approved_count']     ?? 0);
$expiringSoonCount = (int)($stats['expiring_soon_count'] ?? 0);

$isMgr = in_array(Auth::role(), ['admin', 'manager'], true);

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Exceptions &amp; Waivers</h1>
    <p class="page-subtitle">Formal risk acceptance and waiver management</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Risk Register</a>
  </div>
</div>

<?php if (!empty($_SESSION['exception_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['exception_success']) ?></div>
  <?php unset($_SESSION['exception_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['exception_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['exception_error']) ?></div>
  <?php unset($_SESSION['exception_error']); ?>
<?php endif; ?>

<!-- Summary stat cards -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">

  <div class="card" style="border-left:4px solid var(--warning);">
    <div class="card-body" style="padding:20px;display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--warning-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-hourglass-split" style="font-size:22px;color:var(--warning);"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;color:var(--warning);"><?= $pendingCount ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Pending Review</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid var(--success);">
    <div class="card-body" style="padding:20px;display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--success-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-shield-check" style="font-size:22px;color:var(--success);"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;color:var(--success);"><?= $approvedCount ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Approved</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid var(--danger);">
    <div class="card-body" style="padding:20px;display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--danger-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-calendar-x" style="font-size:22px;color:var(--danger);"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;color:var(--danger);"><?= $expiringSoonCount ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Expiring Within 30 Days</div>
      </div>
    </div>
  </div>

</div>

<!-- Exceptions table -->
<div class="card">
  <div class="card-body p0">
    <?php if (empty($exceptions)): ?>
      <div class="empty-state" style="padding:3rem;text-align:center;">
        <i class="bi bi-shield-slash" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:12px;"></i>
        <p style="color:var(--text-muted);">No risk exceptions found.</p>
        <p style="font-size:13px;color:var(--text-light);">To request an exception, open a risk and click &ldquo;Request Exception&rdquo;.</p>
        <a href="/risk" class="btn btn-primary btn-sm" style="margin-top:8px;">Go to Risk Register</a>
      </div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Risk</th>
            <th>Type</th>
            <th>Requested By</th>
            <th>Status</th>
            <th>Expiry Date</th>
            <th style="width:120px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($exceptions as $ex):
            // Status badge colours
            $statusStyles = [
                'pending'  => ['bg' => '#fffbeb', 'fg' => 'var(--warning)'],
                'approved' => ['bg' => '#f0fdf4', 'fg' => 'var(--primary)'],
                'rejected' => ['bg' => '#fef2f2', 'fg' => 'var(--danger)'],
                'expired'  => ['bg' => '#f9fafb', 'fg' => '#71717a'],
            ];
            $sStyle = $statusStyles[$ex['status']] ?? ['bg' => '#f4f4f5', 'fg' => '#71717a'];

            // Type labels
            $typeLabels = ['accept' => 'Accept', 'transfer' => 'Transfer', 'defer' => 'Defer'];
            $typeLabel  = $typeLabels[$ex['exception_type']] ?? ucfirst($ex['exception_type']);

            // Expiry highlight
            $expiryDisplay = '—';
            $expiryStyle   = '';
            if ($ex['expiry_date']) {
                $daysLeft = (int)((strtotime($ex['expiry_date']) - strtotime('today')) / 86400);
                $expiryDisplay = date('M j, Y', strtotime($ex['expiry_date']));
                if ($daysLeft < 0) {
                    $expiryStyle = 'color:var(--danger);font-weight:600;';
                } elseif ($daysLeft <= 30) {
                    $expiryStyle = 'color:var(--warning);font-weight:600;';
                }
            }
          ?>
            <tr>
              <td>
                <a href="/risk/<?= (int)$ex['risk_db_id'] ?>" class="text-link fw-500">
                  <?= Security::h($ex['risk_title'] ?? 'Unknown Risk') ?>
                </a>
                <?php if (!empty($ex['risk_code'])): ?>
                  <div class="text-xs text-muted mono"><?= Security::h($ex['risk_code']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-size:12px;font-weight:600;"><?= Security::h($typeLabel) ?></span>
              </td>
              <td class="text-sm"><?= Security::h($ex['requester_name'] ?? '—') ?></td>
              <td>
                <span style="
                  display:inline-block;
                  padding:2px 10px;
                  border-radius:99px;
                  font-size:12px;
                  font-weight:600;
                  background:<?= $sStyle['fg'] ?>18;
                  color:<?= $sStyle['fg'] ?>;
                ">
                  <?= Security::h(ucfirst($ex['status'])) ?>
                </span>
              </td>
              <td class="text-sm" style="<?= $expiryStyle ?>"><?= Security::h($expiryDisplay) ?></td>
              <td class="text-right" style="white-space:nowrap;">
                <a href="/risk/exception/<?= (int)$ex['id'] ?>" class="btn btn-ghost btn-sm">
                  <i class="bi bi-eye"></i> View
                </a>
                <?php if ($isMgr && $ex['status'] === 'pending'): ?>
                  <a href="/risk/exception/<?= (int)$ex['id'] ?>" class="btn btn-sm btn-primary" style="margin-left:4px;">
                    <i class="bi bi-gavel"></i> Review
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
