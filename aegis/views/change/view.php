<?php
$statusColors = [
    'draft'=>'#6b7280','submitted'=>'#3b82f6','under_review'=>'#f59e0b',
    'approved'=>'#22c55e','rejected'=>'#ef4444','implementing'=>'#8b5cf6',
    'implemented'=>'#06b6d4','closed'=>'#9ca3af'
];
$riskColors = ['low'=>'#22c55e','medium'=>'#f59e0b','high'=>'#f97316','critical'=>'#ef4444'];
$typeColors = ['normal'=>'#3b82f6','emergency'=>'#ef4444','standard'=>'#22c55e'];
$u = Auth::user();
$isAdmin = in_array($u['role'], ['admin','manager']);
$isSubmitter = (int)$change['submitter_id'] === (int)$u['id'];
$transitions = [
    'draft'=>['submitted'=>'Submit for Review'],
    'submitted'=>['under_review'=>'Start Review','rejected'=>'Reject'],
    'under_review'=>['approved'=>'Approve','rejected'=>'Reject'],
    'approved'=>['implementing'=>'Mark Implementing'],
    'implementing'=>['implemented'=>'Mark Implemented'],
    'implemented'=>['closed'=>'Close'],
];
$available = ($isSubmitter && $change['status'] === 'draft') ? ($transitions['draft'] ?? []) :
             ($isAdmin ? ($transitions[$change['status']] ?? []) : []);
ob_start(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($change['title']) ?></h1>
    <p class="page-subtitle">
      <span class="badge" style="background:<?= $typeColors[$change['change_type']] ?>20;color:<?= $typeColors[$change['change_type']] ?>">
        <?= Security::h(ucfirst($change['change_type'])) ?>
      </span>
      <span class="badge" style="background:<?= $riskColors[$change['risk_level']] ?>20;color:<?= $riskColors[$change['risk_level']] ?>">
        <?= Security::h(ucfirst($change['risk_level'])) ?> Risk
      </span>
      <span class="badge" style="background:<?= $statusColors[$change['status']] ?>20;color:<?= $statusColors[$change['status']] ?>">
        <?= Security::h(ucfirst(str_replace('_',' ',$change['status']))) ?>
      </span>
    </p>
  </div>
  <?php if (!empty($available)): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($available as $newStatus => $label): ?>
      <form method="POST" action="/change/<?= (int)$change['id'] ?>/update" style="display:inline">
        <?= Security::csrfField() ?>
        <input type="hidden" name="status" value="<?= Security::h($newStatus) ?>">
        <?php if (in_array($newStatus, ['approved','rejected'])): ?>
          <input type="text" name="review_notes" placeholder="Notes (optional)" class="form-control" style="width:220px;display:inline-block">
        <?php endif; ?>
        <button class="btn <?= $newStatus === 'rejected' ? 'btn-danger' : 'btn-primary' ?>" type="submit">
          <?= Security::h($label) ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Change request updated.</div>
<?php endif; ?>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<div style="flex:1;min-width:280px">
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Details</h3></div>
    <div class="card-body">
      <table class="desc-table">
        <tr><th>Submitted By</th><td><?= Security::h($change['submitter_name'] ?? '—') ?></td></tr>
        <tr><th>Reviewer</th><td><?= Security::h($change['reviewer_name'] ?? '—') ?></td></tr>
        <tr><th>Implementation Date</th><td><?= $change['implementation_date'] ? date('M j, Y g:ia', strtotime($change['implementation_date'])) : '—' ?></td></tr>
        <tr><th>Reviewed At</th><td><?= $change['reviewed_at'] ? date('M j, Y', strtotime($change['reviewed_at'])) : '—' ?></td></tr>
        <tr><th>Created</th><td><?= date('M j, Y', strtotime($change['created_at'])) ?></td></tr>
      </table>
    </div>
  </div>
  <?php if ($change['description']): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Description</h3></div>
    <div class="card-body"><p style="white-space:pre-wrap"><?= Security::h($change['description']) ?></p></div>
  </div>
  <?php endif; ?>
  <?php if ($change['impact_analysis']): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Impact Analysis</h3></div>
    <div class="card-body"><p style="white-space:pre-wrap"><?= Security::h($change['impact_analysis']) ?></p></div>
  </div>
  <?php endif; ?>
  <?php if ($change['rollback_plan']): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Rollback Plan</h3></div>
    <div class="card-body"><p style="white-space:pre-wrap"><?= Security::h($change['rollback_plan']) ?></p></div>
  </div>
  <?php endif; ?>
  <?php if ($change['review_notes']): ?>
  <div class="card">
    <div class="card-header"><h3>Review Notes</h3></div>
    <div class="card-body"><p><?= Security::h($change['review_notes']) ?></p></div>
  </div>
  <?php endif; ?>
</div>

<div style="flex:2;min-width:320px">
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3 id="updates">Activity</h3></div>
    <div class="card-body">
      <?php if (empty($updates)): ?>
        <p class="text-muted">No activity yet.</p>
      <?php else: foreach ($updates as $upd): ?>
        <div style="display:flex;gap:12px;margin-bottom:16px;align-items:flex-start">
          <div class="user-avatar" style="flex-shrink:0"><?= strtoupper(substr($upd['user_name'] ?? '?', 0, 1)) ?></div>
          <div style="flex:1">
            <div style="display:flex;justify-content:space-between">
              <strong><?= Security::h($upd['user_name'] ?? 'System') ?></strong>
              <span class="text-xs text-muted"><?= date('M j, g:ia', strtotime($upd['created_at'])) ?></span>
            </div>
            <?php if ($upd['update_type'] === 'status_change'): ?>
              <p style="color:#6366f1;font-style:italic;margin:4px 0 0"><?= Security::h($upd['content']) ?></p>
            <?php else: ?>
              <p style="margin:4px 0 0;white-space:pre-wrap"><?= Security::h($upd['content']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="card-footer">
      <form method="POST" action="/change/<?= (int)$change['id'] ?>/add-update">
        <?= Security::csrfField() ?>
        <div class="form-group" style="margin-bottom:8px">
          <textarea name="content" class="form-control" rows="2" placeholder="Add a comment…" required></textarea>
        </div>
        <button class="btn btn-sm btn-primary">Post Comment</button>
      </form>
    </div>
  </div>
</div>
</div>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
