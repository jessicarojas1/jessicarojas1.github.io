<?php
$pageTitle    = $pageTitle    ?? 'Risk Exception';
$activeModule = $activeModule ?? 'risk_exceptions';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Exceptions & Waivers', '/risk/exceptions'], ['Exception', null]];

$role    = Auth::role();
$isMgr   = in_array($role, ['admin', 'manager'], true);
$canDecide = $isMgr && $exception['status'] === 'pending';

// Status colours
$statusStyles = [
    'pending'  => ['bg' => 'var(--warning-subtle)', 'fg' => 'var(--warning)', 'icon' => 'bi-hourglass-split'],
    'approved' => ['bg' => 'var(--success-subtle)', 'fg' => 'var(--primary)', 'icon' => 'bi-shield-check'],
    'rejected' => ['bg' => 'var(--danger-subtle)', 'fg' => 'var(--danger)', 'icon' => 'bi-x-circle'],
    'expired'  => ['bg' => 'var(--surface-alt)', 'fg' => 'var(--neutral)', 'icon' => 'bi-clock-history'],
];
$sStyle = $statusStyles[$exception['status']] ?? ['bg' => 'var(--neutral-subtle)', 'fg' => 'var(--neutral)', 'icon' => 'bi-question-circle'];

$typeLabels = ['accept' => 'Accept Risk', 'transfer' => 'Transfer Risk', 'defer' => 'Defer Risk'];
$typeLabel  = $typeLabels[$exception['exception_type']] ?? ucfirst($exception['exception_type']);

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Exception #<?= (int)$exception['id'] ?></h1>
    <p class="page-subtitle">
      <span style="
        display:inline-flex;align-items:center;gap:5px;
        padding:3px 12px;border-radius:99px;font-size:12px;font-weight:600;
        background:<?= $sStyle['bg'] ?>;color:<?= $sStyle['fg'] ?>;
      ">
        <i class="bi <?= $sStyle['icon'] ?>"></i>
        <?= Security::h(ucfirst($exception['status'])) ?>
      </span>
      &nbsp;
      <span style="font-size:13px;color:var(--text-muted);"><?= Security::h($typeLabel) ?></span>
    </p>
  </div>
  <div class="page-actions">
    <a href="/risk/exceptions" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All Exceptions</a>
    <?php if (!empty($exception['risk_db_id'])): ?>
      <a href="/risk/<?= (int)$exception['risk_db_id'] ?>" class="btn btn-ghost">
        <i class="bi bi-exclamation-triangle"></i> View Risk
      </a>
    <?php endif; ?>
  </div>
</div>

<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">

  <!-- Main details column -->
  <div style="flex:2;min-width:280px;">

    <!-- Risk context -->
    <div class="card" style="margin-bottom:16px;border-left:4px solid var(--primary);">
      <div class="card-body" style="padding:16px 20px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:6px;">Associated Risk</div>
        <div style="font-weight:600;font-size:15px;">
          <?php if (!empty($exception['risk_db_id'])): ?>
            <a href="/risk/<?= (int)$exception['risk_db_id'] ?>" class="text-link">
              <?= Security::h($exception['risk_title'] ?? 'Unknown') ?>
            </a>
          <?php else: ?>
            <?= Security::h($exception['risk_title'] ?? 'Unknown') ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($exception['risk_code'])): ?>
          <div class="text-sm text-muted mono"><?= Security::h($exception['risk_code']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Rationale -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-chat-square-text"></i> Rationale</h3>
      </div>
      <div class="card-body">
        <p style="white-space:pre-wrap;margin:0;"><?= Security::h($exception['rationale']) ?></p>
      </div>
    </div>

    <?php if ($exception['compensating_controls']): ?>
    <!-- Compensating Controls -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-shield-fill"></i> Compensating Controls</h3>
      </div>
      <div class="card-body">
        <p style="white-space:pre-wrap;margin:0;"><?= Security::h($exception['compensating_controls']) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($exception['status'] === 'rejected' && $exception['rejection_reason']): ?>
    <!-- Rejection Reason -->
    <div class="card" style="margin-bottom:16px;border-left:4px solid var(--danger);">
      <div class="card-header">
        <h3 class="card-title" style="color:var(--danger);"><i class="bi bi-x-octagon"></i> Rejection Reason</h3>
      </div>
      <div class="card-body">
        <p style="white-space:pre-wrap;margin:0;"><?= Security::h($exception['rejection_reason']) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($canDecide): ?>
    <!-- Decision panel for admins/managers -->
    <div class="card" style="border:2px solid var(--primary);">
      <div class="card-header" style="background:rgba(11,97,4,.06);">
        <h3 class="card-title" style="color:var(--primary);"><i class="bi bi-gavel"></i> Review Decision</h3>
      </div>
      <div class="card-body">

        <!-- Approve -->
        <form method="POST" action="/risk/exception/<?= (int)$exception['id'] ?>/decide" style="margin-bottom:16px;">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="approve">
          <button type="submit" class="btn btn-primary"
                  data-confirm-click="Approve this risk exception?">
            <i class="bi bi-check-circle"></i> Approve Exception
          </button>
        </form>

        <hr style="border-color:var(--border);margin:16px 0;">

        <!-- Reject -->
        <form method="POST" action="/risk/exception/<?= (int)$exception['id'] ?>/decide">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <div class="form-group">
            <label class="form-label">Rejection Reason <span class="text-muted">(optional)</span></label>
            <textarea name="rejection_reason" class="form-control" rows="3"
                      placeholder="Explain why this exception is being rejected…"></textarea>
          </div>
          <button type="submit" class="btn btn-danger"
                  data-confirm-click="Reject this risk exception?">
            <i class="bi bi-x-circle"></i> Reject Exception
          </button>
        </form>

      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Sidebar metadata -->
  <div style="flex:1;min-width:220px;">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Details</h3></div>
      <div class="card-body" style="padding:0;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;white-space:nowrap;">Status</th>
            <td style="padding:10px 16px;">
              <span style="
                display:inline-block;padding:2px 10px;border-radius:99px;
                font-size:12px;font-weight:600;
                background:<?= $sStyle['bg'] ?>;color:<?= $sStyle['fg'] ?>;
              "><?= Security::h(ucfirst($exception['status'])) ?></span>
            </td>
          </tr>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Type</th>
            <td style="padding:10px 16px;"><?= Security::h($typeLabel) ?></td>
          </tr>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Requested By</th>
            <td style="padding:10px 16px;"><?= Security::h($exception['requester_name'] ?? '—') ?></td>
          </tr>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Reviewed By</th>
            <td style="padding:10px 16px;"><?= Security::h($exception['approver_name'] ?? '—') ?></td>
          </tr>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Residual Risk Ack.</th>
            <td style="padding:10px 16px;">
              <?php if ($exception['residual_risk_acknowledged']): ?>
                <span style="color:var(--success);font-weight:600;"><i class="bi bi-check-circle-fill"></i> Yes</span>
              <?php else: ?>
                <span style="color:var(--text-muted);">No</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Expiry Date</th>
            <td style="padding:10px 16px;">
              <?php if ($exception['expiry_date']): ?>
                <?php
                  $daysLeft = (int)((strtotime($exception['expiry_date']) - strtotime('today')) / 86400);
                  $exStyle  = $daysLeft < 0 ? 'color:var(--danger);font-weight:600;' : ($daysLeft <= 30 ? 'color:var(--warning);font-weight:600;' : '');
                ?>
                <span style="<?= $exStyle ?>"><?= Security::h(date('M j, Y', strtotime($exception['expiry_date']))) ?></span>
                <?php if ($daysLeft >= 0): ?>
                  <div style="font-size:11px;color:var(--text-muted);"><?= $daysLeft ?> days remaining</div>
                <?php else: ?>
                  <div style="font-size:11px;color:var(--danger);">Expired <?= abs($daysLeft) ?> day<?= abs($daysLeft) !== 1 ? 's' : '' ?> ago</div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text-muted);">No expiry</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($exception['approved_at']): ?>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Approved At</th>
            <td style="padding:10px 16px;"><?= Security::h(date('M j, Y g:ia', strtotime($exception['approved_at']))) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($exception['rejected_at']): ?>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Rejected At</th>
            <td style="padding:10px 16px;"><?= Security::h(date('M j, Y g:ia', strtotime($exception['rejected_at']))) ?></td>
          </tr>
          <?php endif; ?>
          <tr style="border-bottom:1px solid var(--border-light);">
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Submitted</th>
            <td style="padding:10px 16px;"><?= Security::h(date('M j, Y', strtotime($exception['created_at']))) ?></td>
          </tr>
          <tr>
            <th style="padding:10px 16px;color:var(--text-muted);font-weight:600;text-align:left;">Last Updated</th>
            <td style="padding:10px 16px;"><?= Security::h(date('M j, Y', strtotime($exception['updated_at']))) ?></td>
          </tr>
        </table>
      </div>
    </div>
  </div>

</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
