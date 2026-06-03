<?php
$strategyColors = [
    'mitigate' => ['bg' => '#3b82f620', 'color' => '#3b82f6', 'border' => '#3b82f640', 'label' => 'Mitigate'],
    'transfer' => ['bg' => '#8b5cf620', 'color' => '#8b5cf6', 'border' => '#8b5cf640', 'label' => 'Transfer'],
    'accept'   => ['bg' => '#f59e0b20', 'color' => '#f59e0b', 'border' => '#f59e0b40', 'label' => 'Accept'],
    'avoid'    => ['bg' => '#ef444420', 'color' => '#ef4444', 'border' => '#ef444440', 'label' => 'Avoid'],
];
$statusStyles = [
    'draft'     => ['bg' => '#94a3b820', 'color' => '#94a3b8', 'border' => '#94a3b840'],
    'active'    => ['bg' => '#6366f120', 'color' => '#6366f1', 'border' => '#6366f140'],
    'completed' => ['bg' => '#05966920', 'color' => '#059669', 'border' => '#05966940'],
    'cancelled' => ['bg' => '#94a3b820', 'color' => '#94a3b8', 'border' => '#94a3b840'],
];
$sc  = $strategyColors[$plan['strategy']] ?? $strategyColors['mitigate'];
$st  = $statusStyles[$plan['status']] ?? $statusStyles['draft'];
$today = date('Y-m-d');
$overduePlan = $plan['target_date'] && $plan['target_date'] < $today && $plan['status'] === 'active';

// Days remaining
$daysLabel = '';
if ($plan['target_date']) {
    $diff = (new DateTime($today))->diff(new DateTime($plan['target_date']));
    $days = (int)$diff->days;
    if ($plan['target_date'] < $today) {
        $daysLabel = $days . ' day' . ($days !== 1 ? 's' : '') . ' overdue';
    } elseif ($days === 0) {
        $daysLabel = 'Due today';
    } else {
        $daysLabel = $days . ' day' . ($days !== 1 ? 's' : '') . ' remaining';
    }
}
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Page header -->
<div class="page-header">
  <div>
    <h1 class="page-title" style="margin-bottom:8px"><?= Security::h($plan['title']) ?></h1>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="status-chip" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['border'] ?>">
        <i class="bi bi-shield-half"></i> <?= $sc['label'] ?>
      </span>
      <span class="status-chip" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
        <?= ucfirst(Security::h($plan['status'])) ?>
      </span>
    </div>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('risk.write')): ?>
      <button type="button" class="btn btn-secondary" id="toggle-edit-btn">
        <i class="bi bi-pencil"></i> Edit Plan
      </button>
    <?php endif; ?>
    <a href="/treatment" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All Plans</a>
  </div>
</div>

<!-- Progress Hero Card -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body">
    <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
          <span style="font-weight:600;font-size:14px">Milestone Progress</span>
          <span id="progress-label" style="font-size:22px;font-weight:700;color:var(--primary)"><?= $progressPct ?>%</span>
        </div>
        <div style="height:12px;background:var(--border);border-radius:8px;overflow:hidden">
          <div id="progress-bar" style="height:100%;width:<?= $progressPct ?>%;background:<?= $progressPct >= 100 ? '#059669' : '#6366f1' ?>;border-radius:8px;transition:width .4s ease"></div>
        </div>
        <div style="margin-top:6px;font-size:13px;color:var(--text-muted)">
          <span id="progress-text"><?= $completedMilestones ?> of <?= $totalMilestones ?> milestone<?= $totalMilestones !== 1 ? 's' : '' ?> completed</span>
        </div>
      </div>
      <?php if ($daysLabel): ?>
      <div style="text-align:center;padding:12px 20px;background:<?= $overduePlan ? '#ef444415' : 'var(--bg-secondary)' ?>;border-radius:10px;border:1px solid <?= $overduePlan ? '#ef444430' : 'var(--border)' ?>">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Target Date</div>
        <div style="font-weight:700;color:<?= $overduePlan ? '#ef4444' : 'var(--text-primary)' ?>"><?= date('M j, Y', strtotime($plan['target_date'])) ?></div>
        <div style="font-size:12px;color:<?= $overduePlan ? '#ef4444' : 'var(--text-muted)' ?>"><?= Security::h($daysLabel) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">

  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Plan Details Card -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-info-circle" style="color:var(--primary)"></i>
          <span class="card-title">Plan Details</span>
        </div>
      </div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <?php $rows = [
            ['Risk',         '<a href="/risk/' . (int)$plan['risk_id'] . '" style="color:var(--primary)">' . Security::h($plan['risk_title']) . '</a>'],
            ['Strategy',     '<span class="status-chip" style="background:' . $sc['bg'] . ';color:' . $sc['color'] . ';border:1px solid ' . $sc['border'] . '">' . $sc['label'] . '</span>'],
            ['Status',       '<span class="status-chip" style="background:' . $st['bg'] . ';color:' . $st['color'] . ';border:1px solid ' . $st['border'] . '">' . ucfirst(Security::h($plan['status'])) . '</span>'],
            ['Target Score', $plan['target_score'] ? (int)$plan['target_score'] : '<span style="color:var(--text-muted)">—</span>'],
            ['Owner',        Security::h($plan['owner_name'] ?? '—')],
            ['Start Date',   $plan['start_date'] ? date('M j, Y', strtotime($plan['start_date'])) : '<span style="color:var(--text-muted)">—</span>'],
            ['Target Date',  $plan['target_date'] ? '<span style="color:' . ($overduePlan ? '#ef4444' : 'inherit') . '">' . date('M j, Y', strtotime($plan['target_date'])) . ($overduePlan ? ' <i class="bi bi-exclamation-circle-fill"></i>' : '') . '</span>' : '<span style="color:var(--text-muted)">—</span>'],
            ['Created',      date('M j, Y', strtotime($plan['created_at']))],
            ['Updated',      date('M j, Y', strtotime($plan['updated_at']))],
          ]; foreach ($rows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:120px;vertical-align:top"><?= $label ?></td>
            <td style="padding:8px 0"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php if ($plan['description']): ?>
          <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-light)">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:6px">Description</div>
            <p style="margin:0;white-space:pre-wrap;font-size:14px"><?= Security::h($plan['description']) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Edit form (collapsible) -->
    <?php if (Auth::can('risk.write')): ?>
    <div class="card" id="edit-plan-card" style="display:none">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-pencil" style="color:var(--primary)"></i>
          <span class="card-title">Edit Plan</span>
        </div>
      </div>
      <div class="card-body">
        <form method="POST" action="/treatment/<?= (int)$plan['id'] ?>/update">
          <?= Security::csrfField() ?>
          <div style="display:flex;flex-direction:column;gap:16px">
            <div class="form-group">
              <label class="form-label">Title <span style="color:var(--danger)">*</span></label>
              <input type="text" name="title" class="form-control" required maxlength="255" value="<?= Security::h($plan['title']) ?>">
            </div>
            <div class="form-row">
              <div class="form-group" style="flex:1">
                <label class="form-label">Strategy</label>
                <select name="strategy" class="form-control">
                  <?php foreach (['mitigate'=>'Mitigate','transfer'=>'Transfer','accept'=>'Accept','avoid'=>'Avoid'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $plan['strategy']===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                  <?php foreach (['draft'=>'Draft','active'=>'Active','completed'=>'Completed','cancelled'=>'Cancelled'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $plan['status']===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">Target Score</label>
                <input type="number" name="target_score" class="form-control" min="1" max="25" value="<?= Security::h($plan['target_score'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group" style="flex:1">
                <label class="form-label">Owner</label>
                <select name="owner_id" class="form-control">
                  <option value="">— Unassigned —</option>
                  <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ($plan['owner_id']==$u['id'])?'selected':'' ?>>
                      <?= Security::h($u['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= Security::h($plan['start_date'] ?? '') ?>">
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">Target Date</label>
                <input type="date" name="target_date" class="form-control" value="<?= Security::h($plan['target_date'] ?? '') ?>">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="4"><?= Security::h($plan['description'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:10px">
              <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> Save Changes</button>
              <button type="button" class="btn btn-ghost" id="cancel-edit-btn">Cancel</button>
            </div>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Milestones Section -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-list-check" style="color:var(--primary)"></i>
          <span class="card-title">Milestones</span>
        </div>
        <div class="card-header-right">
          <span style="font-size:12px;color:var(--text-muted)" id="milestone-count-label">
            <?= $completedMilestones ?>/<?= $totalMilestones ?> completed
          </span>
        </div>
      </div>
      <div class="card-body" style="padding:0">

        <?php if (empty($milestones)): ?>
          <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">
            No milestones yet. Add one below.
          </div>
        <?php else: ?>
          <?php foreach ($milestones as $idx => $m):
            $mDone    = $m['completed_at'] !== null;
            $mOverdue = !$mDone && $m['due_date'] && $m['due_date'] < $today;
          ?>
          <div class="milestone-item" id="milestone-item-<?= (int)$m['id'] ?>"
               style="padding:14px 20px;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>;display:flex;align-items:flex-start;gap:14px;<?= $mDone ? 'opacity:.7' : '' ?>">

            <!-- Checkbox (AJAX toggle) -->
            <?php if (Auth::can('risk.write')): ?>
            <form class="complete-form" data-milestone-id="<?= (int)$m['id'] ?>" style="margin:0;flex-shrink:0;margin-top:2px">
              <?= Security::csrfField() ?>
              <button type="submit" class="milestone-checkbox <?= $mDone ? 'checked' : '' ?>"
                      style="width:22px;height:22px;border-radius:50%;border:2px solid <?= $mDone ? '#059669' : 'var(--border)' ?>;background:<?= $mDone ? '#059669' : 'transparent' ?>;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;color:#fff;transition:all .2s"
                      title="<?= $mDone ? 'Mark incomplete' : 'Mark complete' ?>">
                <?php if ($mDone): ?><i class="bi bi-check" style="font-size:13px"></i><?php endif; ?>
              </button>
            </form>
            <?php else: ?>
            <div style="width:22px;height:22px;border-radius:50%;border:2px solid <?= $mDone ? '#059669' : 'var(--border)' ?>;background:<?= $mDone ? '#059669' : 'transparent' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;color:#fff">
              <?php if ($mDone): ?><i class="bi bi-check" style="font-size:13px"></i><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Content -->
            <div style="flex:1;min-width:0">
              <div style="font-weight:500;<?= $mDone ? 'text-decoration:line-through;color:var(--text-muted)' : '' ?>">
                <?= Security::h($m['title']) ?>
              </div>
              <?php if ($m['description']): ?>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;white-space:pre-wrap"><?= Security::h($m['description']) ?></p>
              <?php endif; ?>
              <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;font-size:12px">
                <?php if ($m['due_date']): ?>
                  <span style="color:<?= $mOverdue ? '#ef4444' : 'var(--text-muted)' ?>">
                    <i class="bi bi-calendar<?= $mOverdue ? '-x' : '' ?>"></i>
                    Due <?= date('M j, Y', strtotime($m['due_date'])) ?>
                    <?php if ($mOverdue): ?><strong>(overdue)</strong><?php endif; ?>
                  </span>
                <?php endif; ?>
                <?php if ($mDone): ?>
                  <span style="color:#059669">
                    <i class="bi bi-check-circle"></i>
                    Completed <?= date('M j, Y', strtotime($m['completed_at'])) ?>
                    <?php if ($m['completed_by_name']): ?> by <?= Security::h($m['completed_by_name']) ?><?php endif; ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Delete (incomplete only) -->
            <?php if (!$mDone && Auth::can('risk.write')): ?>
            <form method="POST" action="/treatment/milestone/<?= (int)$m['id'] ?>/delete"
                  onsubmit="return confirm('Delete this milestone?')" style="flex-shrink:0">
              <?= Security::csrfField() ?>
              <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--danger);padding:4px 8px" title="Delete milestone">
                <i class="bi bi-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <!-- Add Milestone inline form -->
        <?php if (Auth::can('risk.write')): ?>
        <div style="padding:16px 20px;border-top:1px solid var(--border);background:var(--bg-secondary)">
          <div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:12px">
            <i class="bi bi-plus-circle"></i> Add Milestone
          </div>
          <form method="POST" action="/treatment/<?= (int)$plan['id'] ?>/milestone/add">
            <?= Security::csrfField() ?>
            <div class="form-row" style="align-items:flex-end">
              <div class="form-group" style="flex:2;margin:0">
                <label class="form-label text-sm">Title <span style="color:var(--danger)">*</span></label>
                <input type="text" name="title" class="form-control form-control-sm" required maxlength="255" placeholder="Milestone title…">
              </div>
              <div class="form-group" style="flex:2;margin:0">
                <label class="form-label text-sm">Description</label>
                <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional description…">
              </div>
              <div class="form-group" style="flex:1;margin:0">
                <label class="form-label text-sm">Due Date</label>
                <input type="date" name="due_date" class="form-control form-control-sm">
              </div>
              <div style="flex-shrink:0;margin:0">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-plus-lg"></i> Add
                </button>
              </div>
            </div>
          </form>
        </div>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /left col -->

  <!-- Right sidebar -->
  <div style="display:flex;flex-direction:column;gap:20px">
    <div class="card" style="border-left:4px solid <?= $sc['color'] ?>">
      <div class="card-body">
        <div style="display:flex;gap:10px;align-items:flex-start">
          <i class="bi bi-info-circle-fill" style="color:<?= $sc['color'] ?>;font-size:18px;flex-shrink:0;margin-top:1px"></i>
          <div>
            <div style="font-weight:600;margin-bottom:4px">Strategy: <?= $sc['label'] ?></div>
            <?php
            $stratHints = [
                'mitigate' => 'Implement controls to reduce the likelihood or impact of this risk.',
                'transfer' => 'Shift risk to a third party via insurance, contracts, or outsourcing.',
                'accept'   => 'Formally accept the risk and monitor it without further action.',
                'avoid'    => 'Eliminate the risk by stopping or redesigning the activity.',
            ];
            ?>
            <p style="font-size:13px;color:var(--text-muted);margin:0"><?= $stratHints[$plan['strategy']] ?? '' ?></p>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-bar-chart-steps" style="color:var(--primary)"></i>
          <span class="card-title">Quick Stats</span>
        </div>
      </div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <?php $qrows = [
            ['Total Milestones',     $totalMilestones],
            ['Completed',            $completedMilestones],
            ['Remaining',            $totalMilestones - $completedMilestones],
            ['Progress',             $progressPct . '%'],
          ]; foreach ($qrows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted)"><?= $label ?></td>
            <td style="padding:8px 0;font-weight:600;text-align:right"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

</div><!-- /grid -->

<script nonce="<?= Security::nonce() ?>">
(function () {
  'use strict';

  // Edit panel toggle
  var editCard   = document.getElementById('edit-plan-card');
  var toggleBtn  = document.getElementById('toggle-edit-btn');
  var cancelBtn  = document.getElementById('cancel-edit-btn');

  if (toggleBtn && editCard) {
    toggleBtn.addEventListener('click', function () {
      editCard.style.display = editCard.style.display === 'none' ? 'block' : 'none';
      editCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  if (cancelBtn && editCard) {
    cancelBtn.addEventListener('click', function () {
      editCard.style.display = 'none';
    });
  }

  // AJAX milestone toggle
  var totalMilestones    = <?= (int)$totalMilestones ?>;
  var completedMilestones = <?= (int)$completedMilestones ?>;

  function updateUI(progress, total) {
    var pct = total > 0 ? Math.round((progress / total) * 100) : 0;
    var bar  = document.getElementById('progress-bar');
    var lbl  = document.getElementById('progress-label');
    var txt  = document.getElementById('progress-text');
    var cnt  = document.getElementById('milestone-count-label');
    if (bar) {
      bar.style.width = pct + '%';
      bar.style.background = pct >= 100 ? '#059669' : '#6366f1';
    }
    if (lbl) lbl.textContent = pct + '%';
    if (txt) txt.textContent = progress + ' of ' + total + ' milestone' + (total !== 1 ? 's' : '') + ' completed';
    if (cnt) cnt.textContent = progress + '/' + total + ' completed';
  }

  document.querySelectorAll('.complete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var milestoneId = form.dataset.milestoneId;
      var csrfToken   = form.querySelector('input[name="csrf_token"]').value;
      var btn         = form.querySelector('.milestone-checkbox');
      var row         = document.getElementById('milestone-item-' + milestoneId);

      fetch('/treatment/milestone/' + milestoneId + '/complete', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'csrf_token=' + encodeURIComponent(csrfToken),
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        var isDone = btn.classList.contains('checked');

        if (isDone) {
          // Uncomplete
          btn.classList.remove('checked');
          btn.style.borderColor = 'var(--border)';
          btn.style.background  = 'transparent';
          btn.innerHTML         = '';
          btn.title             = 'Mark complete';
          if (row) {
            row.style.opacity = '1';
            var titleEl = row.querySelector('[style*="line-through"]');
            if (titleEl) { titleEl.style.textDecoration = ''; titleEl.style.color = ''; }
          }
        } else {
          // Complete
          btn.classList.add('checked');
          btn.style.borderColor = '#059669';
          btn.style.background  = '#059669';
          btn.innerHTML         = '<i class="bi bi-check" style="font-size:13px"></i>';
          btn.title             = 'Mark incomplete';
          if (row) row.style.opacity = '.7';
        }

        // Refresh CSRF token from meta tag
        var newToken = document.querySelector('meta[name="csrf-token"]');
        if (newToken) form.querySelector('input[name="csrf_token"]').value = newToken.content;

        updateUI(data.progress, data.total);
      })
      .catch(function () {
        alert('Something went wrong. Please refresh the page.');
      });
    });
  });
}());
</script>
