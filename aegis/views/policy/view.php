<?php
$pageTitle    = $policy['title'];
$activeModule = 'policy';
$breadcrumbs  = [['Policies','/policy'],[$policy['title'],null]];
ob_start();
?>

<?php if (!empty($_GET['saved'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Policy updated successfully.</div><?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($policy['title']) ?></h1>
    <p class="page-subtitle">
      <?= $policy['policy_number'] ? Security::h($policy['policy_number']).' · ' : '' ?>
      v<?= Security::h($policy['version']) ?> ·
      <?= Security::h($policy['owner_name'] ?? 'No owner') ?>
    </p>
  </div>
  <div class="page-actions">
    <form method="POST" action="/policy/<?= $policy['id'] ?>/update" style="display:inline">
      <?= Security::csrfField() ?>
      <?php if ($policy['status'] === 'draft'): ?>
        <input type="hidden" name="action" value="submit_review">
        <button class="btn btn-primary"><i class="bi bi-send"></i> Submit for Review</button>
      <?php elseif ($policy['status'] === 'under_review' && Auth::can('admin')): ?>
        <input type="hidden" name="action" value="approve">
        <button class="btn btn-success"><i class="bi bi-check-lg"></i> Approve & Publish</button>
      <?php endif; ?>
    </form>
    <a href="/policy/<?= $policy['id'] ?>/attest" class="btn btn-primary"><i class="bi bi-pen-fill"></i> Attest Policy</a>
    <a href="/policy/<?= $policy['id'] ?>/edit" class="btn btn-ghost"><i class="bi bi-pencil"></i> Edit</a>
  </div>
</div>

<!-- Status bar -->
<div class="policy-status-bar card">
  <?php $steps = ['draft'=>0,'under_review'=>1,'published'=>2,'archived'=>3]; $curStep = $steps[$policy['status']] ?? 0; ?>
  <?php foreach (['Draft','Under Review','Published','Archived'] as $i => $step): ?>
    <div class="status-step <?= $i < $curStep ? 'done' : ($i == $curStep ? 'current' : '') ?>">
      <div class="step-circle"><?= $i < $curStep ? '<i class="bi bi-check-lg"></i>' : ($i+1) ?></div>
      <div class="step-label"><?= $step ?></div>
    </div>
    <?php if ($i < 3): ?><div class="step-line <?= $i < $curStep ? 'done' : '' ?>"></div><?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="policy-layout">
  <!-- Main content -->
  <div class="policy-main">
    <!-- Content -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-file-earmark-text"></i> Policy Content</h3>
        <?php if ($policy['approved_at']): ?>
          <span class="text-muted text-sm"><i class="bi bi-check-circle" style="color:#059669"></i> Approved <?= date('M j, Y', strtotime($policy['approved_at'])) ?> by <?= Security::h($policy['approver_name'] ?? 'unknown') ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="policy-content">
          <?= $policy['content'] ? nl2br(Security::h($policy['content'])) : '<p class="text-muted">No content yet. <a href="/policy/'.Security::h((string)$policy['id']).'/edit">Add content</a>.</p>' ?>
        </div>
      </div>
    </div>

    <!-- Mapped Controls -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-link-45deg"></i> Mapped Controls</h3>
        <span class="badge"><?= count($mappings) ?></span>
      </div>
      <div class="card-body">
        <!-- Map new control -->
        <form method="POST" action="/policy/<?= $policy['id'] ?>/map" class="map-form">
          <?= Security::csrfField() ?>
          <div class="form-row">
            <select name="objective_id" class="form-control" required>
              <option value="">— Select a control to map —</option>
              <?php
              $lastPkg = '';
              foreach ($availableObjectives as $obj):
                if ($obj['package_name'] !== $lastPkg): $lastPkg = $obj['package_name']; ?>
                  <optgroup label="<?= Security::h($obj['package_name']) ?>">
              <?php endif; ?>
                <option value="<?= $obj['id'] ?>"><?= Security::h($obj['code']) ?> — <?= Security::h(substr($obj['title'],0,60)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="notes" class="form-control" placeholder="Notes (optional)">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Map</button>
          </div>
        </form>

        <!-- Existing mappings -->
        <?php if ($mappings): ?>
        <table class="table" style="margin-top:16px">
          <thead><tr><th>Code</th><th>Control</th><th>Package</th><th>Notes</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($mappings as $m): ?>
              <tr>
                <td><span class="mono"><?= Security::h($m['code']) ?></span></td>
                <td><a href="/compliance/<?= $m['package_id'] ?>/objective/<?= $m['objective_id'] ?>"><?= Security::h(substr($m['objective_title'],0,60)) ?></a></td>
                <td><?= Security::h($m['package_name']) ?></td>
                <td class="text-muted"><?= Security::h($m['notes'] ?? '') ?></td>
                <td>
                  <form method="POST" action="/policy/<?= $policy['id'] ?>/unmap/<?= $m['id'] ?>">
                    <?= Security::csrfField() ?>
                    <button class="btn btn-ghost btn-sm text-danger unmap-btn" data-confirm="Remove mapping?"><i class="bi bi-x-lg"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <p class="text-muted" style="margin-top:12px">No controls mapped yet. Use the form above to map this policy to compliance controls.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="policy-sidebar">
    <!-- Info card -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Details</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Category</span><strong><?= Security::h($policy['category'] ?? 'Uncategorized') ?></strong></div>
        <div class="detail-row"><span>Version</span><strong><?= Security::h($policy['version']) ?></strong></div>
        <div class="detail-row"><span>Owner</span><strong><?= Security::h($policy['owner_name'] ?? 'Unassigned') ?></strong></div>
        <div class="detail-row"><span>Frequency</span><strong><?= ucfirst($policy['review_frequency'] ?? 'annual') ?></strong></div>
        <div class="detail-row"><span>Next Review</span><strong <?= $policy['next_review_date'] && strtotime($policy['next_review_date']) < time() ? 'style="color:#dc2626"' : '' ?>><?= $policy['next_review_date'] ? date('M j, Y', strtotime($policy['next_review_date'])) : 'Not set' ?></strong></div>
        <div class="detail-row"><span>Mappings</span><strong><?= count($mappings) ?> controls</strong></div>
      </div>
    </div>

    <!-- Version history -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-clock-history"></i> Version History</h3></div>
      <div class="card-body p0">
        <?php foreach ($versions as $v): ?>
          <div class="version-item">
            <div class="version-num">v<?= Security::h($v['version']) ?></div>
            <div class="version-meta">
              <div><?= Security::h($v['change_summary'] ?? 'No summary') ?></div>
              <div class="text-muted text-sm"><?= Security::h($v['author'] ?? 'Unknown') ?> · <?= date('M j, Y', strtotime($v['created_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php
// Attestation summary for this policy
$attestCount = Database::fetchOne(
    "SELECT COUNT(*) as total,
            MAX(attested_at) as last_attested
     FROM policy_attestations WHERE policy_id=?",
    [$policy['id']]
);
$totalUsers = Database::fetchOne("SELECT COUNT(*) as cnt FROM users WHERE is_active=TRUE");
?>

<!-- Attestation Summary -->
<div class="card" style="margin-top:16px">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-pen-fill"></i> Attestations</h3>
    <a href="/policy/<?= $policy['id'] ?>/attest" class="btn btn-primary btn-sm"><i class="bi bi-pen-fill"></i> Attest Now</a>
  </div>
  <div class="card-body">
    <?php $cnt = (int)($attestCount['total'] ?? 0); $total = (int)($totalUsers['cnt'] ?? 0); $pct = $total > 0 ? round($cnt / $total * 100) : 0; ?>
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px">
      <div style="font-size:2rem;font-weight:700;color:var(--primary)"><?= $cnt ?></div>
      <div>
        <div style="font-weight:600">of <?= $total ?> active users attested</div>
        <?php if ($attestCount['last_attested']): ?>
          <div class="text-muted text-sm">Last attested <?= date('M j, Y g:i A', strtotime($attestCount['last_attested'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div style="background:#e5e7eb;border-radius:999px;height:8px;overflow:hidden">
      <div style="width:<?= $pct ?>%;background:<?= $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626') ?>;height:100%;border-radius:999px;transition:width .3s"></div>
    </div>
    <div class="text-muted text-sm" style="margin-top:6px"><?= $pct ?>% completion</div>
    <?php if (Auth::can('policy.write')): ?>
      <div style="margin-top:12px">
        <a href="/policy/attestations" class="btn btn-ghost btn-sm"><i class="bi bi-people"></i> Manage Campaigns</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();

// Inject nonce'd script for confirm dialogs before layout renders
$content .= '<script nonce="' . Security::nonce() . '">'
  . 'document.querySelectorAll(\'.unmap-btn\').forEach(function(btn) {'
  . '  btn.closest(\'form\').addEventListener(\'submit\', function(e) {'
  . '    if (!confirm(btn.getAttribute(\'data-confirm\'))) { e.preventDefault(); }'
  . '  });'
  . '});'
  . '</script>';

require AEGIS_ROOT . '/views/layout.php';
