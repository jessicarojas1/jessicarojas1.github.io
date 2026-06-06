<?php
$breadcrumbs  = $breadcrumbs  ?? [['Change Requests', '/change'], ['Change Request', null]];
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
    'submitted'=>['under_review'=>'Start Review'],
    'under_review'=>['approved'=>'Approve','rejected'=>'Reject'],
    'approved'=>['implementing'=>'Mark Implementing'],
    'implementing'=>['implemented'=>'Mark Implemented'],
    'implemented'=>['closed'=>'Close'],
];
$available = ($isSubmitter && $change['status'] === 'draft') ? ($transitions['draft'] ?? []) :
             ($isAdmin ? ($transitions[$change['status']] ?? []) : []);

// Separate CAB votes from regular activity
$cabVotes = array_values(array_filter($updates, fn($upd) => $upd['update_type'] === 'cab_vote'));
$activity = array_values(array_filter($updates, fn($upd) => $upd['update_type'] !== 'cab_vote'));

$approveCount = count(array_filter($cabVotes, fn($v) => str_starts_with($v['content'], 'APPROVE')));
$rejectCount  = count($cabVotes) - $approveCount;

$myVote = null;
foreach ($cabVotes as $v) {
    if ((int)$v['user_id'] === (int)$u['id']) {
        $myVote = str_starts_with($v['content'], 'APPROVE') ? 'approve' : 'reject';
        break;
    }
}

$canVote = $isAdmin && in_array($change['status'], ['submitted', 'under_review'], true);

ob_start(); ?>
<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
      <h1 class="page-title" style="margin:0"><?= Security::h($change['title']) ?></h1>
      <?php if (!empty($change['change_number'])): ?>
        <span class="badge" style="background:var(--info-subtle);color:var(--info);border:1px solid var(--border);font-family:monospace;font-size:13px;padding:4px 10px"><?= Security::h($change['change_number']) ?></span>
      <?php endif; ?>
    </div>
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
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if (($isSubmitter && $change['status'] === 'draft') || $isAdmin): ?>
      <button class="btn btn-secondary" data-show-modal="editChangeModal">
        <i class="bi bi-pencil"></i> Edit
      </button>
    <?php endif; ?>
    <?php if (!empty($available)): ?>
      <?php foreach ($available as $newStatus => $label): ?>
        <form method="POST" action="/change/<?= (int)$change['id'] ?>/update" style="display:inline-flex;gap:8px;align-items:center">
          <?= Security::csrfField() ?>
          <input type="hidden" name="status" value="<?= Security::h($newStatus) ?>">
          <?php if (in_array($newStatus, ['approved','rejected'])): ?>
            <input type="text" name="note" placeholder="Notes (optional)" class="form-control" style="width:200px">
          <?php endif; ?>
          <button class="btn <?= $newStatus === 'rejected' ? 'btn-danger' : 'btn-primary' ?>" type="submit">
            <?= Security::h($label) ?>
          </button>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <form method="POST" action="/change/<?= (int)$change['id'] ?>/update" style="display:inline-flex;gap:6px;align-items:center">
        <?= Security::csrfField() ?>
        <select name="status" class="form-control" style="width:160px;font-size:13px">
          <?php foreach (['draft','submitted','under_review','approved','rejected','implementing','implemented','closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $change['status'] === $s ? 'selected' : '' ?>>
              <?= Security::h(ucfirst(str_replace('_',' ',$s))) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary" type="submit" style="font-size:13px;white-space:nowrap">
          <i class="bi bi-shield-fill-exclamation"></i> Force Status
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['change_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['change_success']) ?></div>
  <?php unset($_SESSION['change_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['change_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['change_error']) ?></div>
  <?php unset($_SESSION['change_error']); ?>
<?php endif; ?>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

  <!-- Left: Details -->
  <div style="flex:1;min-width:280px">
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><h3>Details</h3></div>
      <div class="card-body">
        <table class="desc-table">
          <tr><th>Submitted By</th><td><?= Security::h($change['submitter_name'] ?? '—') ?></td></tr>
          <tr><th>Reviewer</th><td><?= Security::h($change['reviewer_name'] ?? '—') ?></td></tr>
          <tr><th>Implementation Date</th><td><?= $change['implementation_date'] ? date('M j, Y g:ia', strtotime($change['implementation_date'])) : '—' ?></td></tr>
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
    <?php if ($change['testing_plan']): ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><h3>Testing Plan</h3></div>
      <div class="card-body"><p style="white-space:pre-wrap"><?= Security::h($change['testing_plan']) ?></p></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: CAB Review + Activity -->
  <div style="flex:2;min-width:320px">

    <!-- CAB Review Panel -->
    <div class="card" style="margin-bottom:16px" id="cab">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3 style="margin:0"><i class="bi bi-people-fill" style="margin-right:6px;color:var(--primary)"></i>CAB Review</h3>
        <div style="display:flex;gap:10px;font-size:13px">
          <?php if ($approveCount > 0): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:var(--success-subtle);color:var(--success);padding:3px 10px;border-radius:99px;font-weight:700">
              <i class="bi bi-check-circle-fill"></i> <?= $approveCount ?> Approve
            </span>
          <?php endif; ?>
          <?php if ($rejectCount > 0): ?>
            <span style="display:inline-flex;align-items:center;gap:5px;background:var(--danger-subtle);color:var(--danger);padding:3px 10px;border-radius:99px;font-weight:700">
              <i class="bi bi-x-circle-fill"></i> <?= $rejectCount ?> Reject
            </span>
          <?php endif; ?>
          <?php if (!$cabVotes): ?>
            <span style="color:var(--text-muted);font-size:12px">No votes yet</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <?php if ($cabVotes): ?>
          <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
            <?php foreach ($cabVotes as $v):
              $isApprove = str_starts_with($v['content'], 'APPROVE');
              $voteColor = $isApprove ? '#16a34a' : '#dc2626';
              $voteBg    = $isApprove ? '#dcfce7' : '#fee2e2';
              $voteLabel = $isApprove ? 'Approved' : 'Rejected';
              $voteIcon  = $isApprove ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
              $notePart  = strpos($v['content'], ': ') !== false ? substr($v['content'], strpos($v['content'], ': ') + 2) : '';
            ?>
              <div style="display:flex;align-items:flex-start;gap:12px;padding:12px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border)">
                <div class="user-avatar" style="flex-shrink:0"><?= strtoupper(substr($v['author_name'] ?? '?', 0, 1)) ?></div>
                <div style="flex:1;min-width:0">
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
                    <strong style="font-size:14px"><?= Security::h($v['author_name'] ?? 'Unknown') ?></strong>
                    <div style="display:flex;align-items:center;gap:8px">
                      <span style="display:inline-flex;align-items:center;gap:4px;background:<?= $voteBg ?>;color:<?= $voteColor ?>;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:700">
                        <i class="bi <?= $voteIcon ?>"></i> <?= $voteLabel ?>
                      </span>
                      <span class="text-xs text-muted"><?= date('M j, g:ia', strtotime($v['created_at'])) ?></span>
                    </div>
                  </div>
                  <?php if ($notePart): ?>
                    <p style="margin:6px 0 0;font-size:13px;color:var(--text-secondary)"><?= Security::h($notePart) ?></p>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php elseif (!$canVote): ?>
          <p class="text-muted">No CAB votes recorded yet.</p>
        <?php endif; ?>

        <?php if ($canVote): ?>
          <form method="POST" action="/change/<?= (int)$change['id'] ?>/cab-vote">
            <?= Security::csrfField() ?>
            <div style="border-top:<?= $cabVotes ? '1px solid var(--border)' : 'none' ?>;padding-top:<?= $cabVotes ? '16px' : '0' ?>">
              <p style="font-size:13px;font-weight:600;margin:0 0 10px;color:var(--text)">
                <?= $myVote ? 'Update your vote:' : 'Cast your CAB vote:' ?>
              </p>
              <div class="form-group" style="margin-bottom:10px">
                <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Add rationale or conditions…"></textarea>
              </div>
              <div style="display:flex;gap:10px">
                <button type="submit" name="vote" value="approve"
                  class="btn btn-sm"
                  style="background:var(--success);color:var(--card-bg);border:none;display:inline-flex;align-items:center;gap:6px">
                  <i class="bi bi-check-circle-fill"></i> Approve
                </button>
                <button type="submit" name="vote" value="reject"
                  class="btn btn-sm btn-danger"
                  style="display:inline-flex;align-items:center;gap:6px">
                  <i class="bi bi-x-circle-fill"></i> Reject
                </button>
              </div>
              <?php if ($myVote): ?>
                <p style="margin-top:8px;font-size:12px;color:var(--text-muted)">
                  <i class="bi bi-info-circle"></i> Your current vote: <strong><?= ucfirst($myVote) ?></strong>. Submitting will replace it.
                </p>
              <?php endif; ?>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Activity Feed -->
    <div class="card" id="updates">
      <div class="card-header"><h3>Activity</h3></div>
      <div class="card-body">
        <?php if (empty($activity)): ?>
          <p class="text-muted">No activity yet.</p>
        <?php else: foreach ($activity as $upd): ?>
          <div style="display:flex;gap:12px;margin-bottom:16px;align-items:flex-start">
            <div class="user-avatar" style="flex-shrink:0"><?= strtoupper(substr($upd['author_name'] ?? '?', 0, 1)) ?></div>
            <div style="flex:1">
              <div style="display:flex;justify-content:space-between">
                <strong><?= Security::h($upd['author_name'] ?? 'System') ?></strong>
                <span class="text-xs text-muted"><?= date('M j, g:ia', strtotime($upd['created_at'])) ?></span>
              </div>
              <?php if ($upd['update_type'] === 'status_change'): ?>
                <p style="color:var(--indigo);font-style:italic;margin:4px 0 0"><?= Security::h($upd['content']) ?></p>
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

// Append Edit modal and inline script to $content
ob_start(); ?>

<!-- Edit Change Modal -->
<?php if (($isSubmitter && $change['status'] === 'draft') || $isAdmin): ?>
<div class="um-overlay" id="editChangeModal" style="display:none">
  <div class="um-dialog" style="max-width:680px;width:100%">
    <div class="um-header">
      <span>Edit Change Request</span>
      <button class="um-close" data-close-modal="editChangeModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <form method="POST" action="/change/<?= (int)$change['id'] ?>/edit">
        <?= Security::csrfField() ?>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Title <span style="color:var(--danger)">*</span></label>
          <input type="text" name="title" class="form-control" value="<?= Security::h($change['title']) ?>" required>
        </div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px">
          <div class="form-group" style="flex:1;min-width:160px">
            <label class="form-label">Change Type</label>
            <select name="change_type" class="form-control">
              <?php foreach (['normal','emergency','standard'] as $ct): ?>
                <option value="<?= $ct ?>" <?= $change['change_type'] === $ct ? 'selected' : '' ?>><?= ucfirst($ct) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;min-width:160px">
            <label class="form-label">Risk Level</label>
            <select name="risk_level" class="form-control">
              <?php foreach (['low','medium','high','critical'] as $rl): ?>
                <option value="<?= $rl ?>" <?= $change['risk_level'] === $rl ? 'selected' : '' ?>><?= ucfirst($rl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;min-width:200px">
            <label class="form-label">Implementation Date</label>
            <input type="datetime-local" name="implementation_date" class="form-control"
              value="<?= $change['implementation_date'] ? date('Y-m-d\TH:i', strtotime($change['implementation_date'])) : '' ?>">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3"><?= Security::h($change['description'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Impact Analysis</label>
          <textarea name="impact_analysis" class="form-control" rows="3"><?= Security::h($change['impact_analysis'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Rollback Plan</label>
          <textarea name="rollback_plan" class="form-control" rows="3"><?= Security::h($change['rollback_plan'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin-bottom:18px">
          <label class="form-label">Testing Plan</label>
          <textarea name="testing_plan" class="form-control" rows="3"><?= Security::h($change['testing_plan'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
          <button type="button" class="btn btn-secondary" data-close-modal="editChangeModal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
(function() {
  var el = document.getElementById('editChangeModal');
  if (el) {
    el.addEventListener('click', function(e) {
      if (e.target === el) closeModal('editChangeModal');
    });
  }
}());
</script>
<?php endif; ?>

<?php $content .= ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
