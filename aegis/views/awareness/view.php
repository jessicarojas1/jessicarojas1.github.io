<?php ob_start();
$myAssignment = null;
foreach ($assignments as $a) {
    if ((int)$a['user_id'] === Auth::id()) { $myAssignment = $a; break; }
}
$total     = count($assignments);
$completed = count(array_filter($assignments, fn($a) => $a['completed']));
$pct       = $total > 0 ? round(($completed / $total) * 100) : 0;
$color     = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($program['title']) ?></h1>
    <p class="page-subtitle">Awareness training program</p>
  </div>
  <div class="page-actions">
    <a href="/awareness" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
    <?php if (Auth::can('compliance.write')): ?>
    <form method="POST" action="/awareness/<?= (int)$program['id'] ?>/delete"
          data-confirm="Delete this program and all assignment records?">
      <?= Security::csrfField() ?>
      <button class="btn btn-danger btn-sm"><i class="bi bi-trash3-fill"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

  <!-- Main content -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- My assignment card (if assigned) -->
    <?php if ($myAssignment): ?>
    <div class="card" style="border:2px solid <?= $myAssignment['completed'] ? '#059669' : 'var(--primary)' ?>">
      <div class="card-header" style="background:<?= $myAssignment['completed'] ? '#f0fdf4' : 'rgba(11,97,4,.06)' ?>">
        <div class="card-header-left">
          <i class="bi bi-<?= $myAssignment['completed'] ? 'check-circle-fill' : 'bell-fill' ?>"
             style="color:<?= $myAssignment['completed'] ? '#059669' : 'var(--primary)' ?>"></i>
          <span class="card-title"><?= $myAssignment['completed'] ? 'You completed this program' : 'Action Required — Mark as Complete' ?></span>
        </div>
      </div>
      <?php if (!$myAssignment['completed']): ?>
      <div class="card-body">
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
          Review the training content below, then mark this program as complete to confirm acknowledgement.
        </p>
        <form method="POST" action="/awareness/<?= (int)$program['id'] ?>/complete">
          <?= Security::csrfField() ?>
          <div class="form-group" style="margin-bottom:10px">
            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes or comments…"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-circle-fill"></i> Mark as Complete</button>
        </form>
      </div>
      <?php else: ?>
      <div class="card-body">
        <p style="color:var(--text-muted);font-size:13px;margin:0">
          Completed <?= $myAssignment['completed_at'] ? date('M j, Y', strtotime($myAssignment['completed_at'])) : '' ?>
          <?= $myAssignment['notes'] ? ' — ' . Security::h($myAssignment['notes']) : '' ?>
        </p>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Program content -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left"><i class="bi bi-book-fill" style="color:var(--primary)"></i><span class="card-title">Training Content</span></div>
      </div>
      <div class="card-body">
        <?php if ($program['description']): ?>
          <p style="color:var(--text-muted);margin-bottom:16px"><?= nl2br(Security::h($program['description'])) ?></p>
        <?php endif; ?>
        <?php if ($program['content_url']): ?>
          <div style="margin-bottom:16px">
            <a href="<?= Security::h($program['content_url']) ?>" target="_blank" rel="noopener"
               class="btn btn-primary"><i class="bi bi-box-arrow-up-right"></i> Open Training Material</a>
          </div>
        <?php endif; ?>
        <?php if ($program['content_body']): ?>
          <div style="background:var(--bg);padding:16px;border-radius:8px;font-size:13px;line-height:1.7;white-space:pre-wrap"><?= Security::h($program['content_body']) ?></div>
        <?php endif; ?>
        <?php if (!$program['content_body'] && !$program['content_url'] && !$program['description']): ?>
          <p style="color:var(--text-muted)">No content added to this program yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Assign more users (admin) -->
    <?php if (Auth::can('compliance.write')): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-left"><i class="bi bi-person-plus-fill" style="color:var(--primary)"></i><span class="card-title">Add Users</span></div>
      </div>
      <div class="card-body">
        <form method="POST" action="/awareness/<?= (int)$program['id'] ?>/assign">
          <?= Security::csrfField() ?>
          <?php
          $assignedIds = array_column($assignments, 'user_id');
          $unassigned  = array_filter($users, fn($u) => !in_array($u['id'], $assignedIds));
          ?>
          <?php if ($unassigned): ?>
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <select name="user_ids[]" multiple class="form-control" style="min-width:220px;height:100px">
              <?php foreach ($unassigned as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="bi bi-plus-lg"></i> Add Selected</button>
          </div>
          <small style="color:var(--text-muted);display:block;margin-top:6px">Hold Ctrl/Cmd to select multiple users.</small>
          <?php else: ?>
          <p style="color:var(--text-muted);margin:0">All active users are already assigned.</p>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Stats -->
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i><span class="card-title">Completion</span></div></div>
      <div class="card-body">
        <div style="text-align:center;margin-bottom:12px">
          <div style="font-size:36px;font-weight:800;color:<?= $color ?>"><?= $pct ?>%</div>
          <div style="font-size:12px;color:var(--text-muted)"><?= $completed ?> of <?= $total ?> assigned</div>
        </div>
        <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden">
          <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:4px;transition:width .4s"></div>
        </div>
        <?php if ($program['due_date']): ?>
        <div style="margin-top:12px;font-size:12px;color:var(--text-muted)">
          <i class="bi bi-calendar3"></i> Due <?= date('M j, Y', strtotime($program['due_date'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Assignment list -->
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-people-fill" style="color:var(--primary)"></i><span class="card-title">Assignments (<?= count($assignments) ?>)</span></div></div>
      <div class="card-body" style="padding:0;max-height:400px;overflow-y:auto">
        <?php foreach ($assignments as $a): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-size:13px;font-weight:600"><?= Security::h($a['user_name']) ?></div>
            <?php if ($a['completed'] && $a['completed_at']): ?>
            <div style="font-size:11px;color:var(--success)"><?= date('M j, Y', strtotime($a['completed_at'])) ?></div>
            <?php endif; ?>
          </div>
          <?php if ($a['completed']): ?>
          <i class="bi bi-check-circle-fill" style="color:var(--success)" title="Complete"></i>
          <?php else: ?>
          <i class="bi bi-clock" style="color:var(--text-muted)" title="Pending"></i>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$assignments): ?>
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">No users assigned</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
