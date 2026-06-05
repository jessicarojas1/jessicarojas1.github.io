<?php
$sevColors = ['critical'=>'var(--danger)','high'=>'var(--warning)','medium'=>'#0284c7','low'=>'var(--success)'];
$statusColors = ['open'=>'var(--danger)','in_progress'=>'var(--warning)','pending_review'=>'var(--secondary)','resolved'=>'var(--success)','closed'=>'#71717a','wont_fix'=>'#71717a'];
$sevColor = $sevColors[$issue['severity']] ?? '#71717a';
$stColor  = $statusColors[$issue['status']] ?? '#71717a';
$pageTitle    = 'Issue: ' . $issue['issue_number'];
$activeModule = 'issue';
$breadcrumbs  = [['Issues', '/issue'], [$issue['issue_number'], null]];
ob_start();
?>
<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <h1 class="page-title" style="margin:0"><?= Security::h($issue['issue_number']) ?>: <?= Security::h($issue['title']) ?></h1>
      <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40;"><?= ucfirst(Security::h($issue['severity'])) ?></span>
      <span class="status-chip" style="background:<?= $stColor ?>20;color:<?= $stColor ?>;border:1px solid <?= $stColor ?>40;"><?= ucfirst(str_replace('_',' ',Security::h($issue['status']))) ?></span>
    </div>
    <p class="page-subtitle">Created <?= date('M j, Y g:ia', strtotime($issue['created_at'])) ?><?= $issue['due_date'] ? ' · Due ' . date('M j, Y', strtotime($issue['due_date'])) : '' ?></p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('issue.write')): ?>
      <button data-show-modal="editModal" class="btn btn-secondary"><i class="bi bi-pencil"></i> Edit</button>
    <?php endif; ?>
  </div>
</div>

<div class="two-col-layout">
  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <?php if ($issue['description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-file-text" style="color:var(--primary)"></i><span class="card-title">Description</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($issue['description']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($issue['resolution']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-check-circle" style="color:var(--success)"></i><span class="card-title">Resolution</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($issue['resolution']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($issue['recurrence_prevention']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-shield-check" style="color:var(--secondary)"></i><span class="card-title">Recurrence Prevention</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($issue['recurrence_prevention']) ?></p></div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-clock-history" style="color:var(--primary)"></i><span class="card-title">Activity</span></div></div>
      <div class="card-body">
        <?php if ($updates): foreach ($updates as $upd): ?>
          <div style="display:flex;gap:12px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border);">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">
              <?= strtoupper(substr($upd['user_name'] ?? '?', 0, 1)) ?>
            </div>
            <div style="flex:1;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <strong style="font-size:13px"><?= Security::h($upd['user_name'] ?? 'System') ?></strong>
                <span style="font-size:11px;color:var(--text-muted)"><?= date('M j, Y g:ia', strtotime($upd['created_at'])) ?></span>
                <?php $typeColors=['status_change'=>'var(--secondary)','assignment'=>'#0284c7','comment'=>'#71717a']; ?>
                <span style="font-size:10px;padding:1px 6px;border-radius:3px;background:<?= ($typeColors[$upd['update_type']]??'#71717a') ?>20;color:<?= ($typeColors[$upd['update_type']]??'#71717a') ?>"><?= ucfirst(str_replace('_',' ',$upd['update_type'])) ?></span>
              </div>
              <p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($upd['content']) ?></p>
            </div>
          </div>
        <?php endforeach; else: ?>
          <p style="color:var(--text-muted);text-align:center;padding:20px 0">No activity yet.</p>
        <?php endif; ?>

        <?php if (Auth::check()): ?>
        <form method="post" action="/issue/<?= $issue['id'] ?>/add-update" style="margin-top:16px;">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label">Add Comment</label>
            <textarea name="content" class="form-control" rows="3" placeholder="Describe the action taken or update…" required></textarea>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;">
            <select name="update_type" class="form-control" style="width:auto">
              <option value="comment">Comment</option>
              <option value="status_change">Status Change</option>
              <option value="assignment">Assignment</option>
            </select>
            <select name="new_status" class="form-control" style="width:auto">
              <option value="">— Keep current status —</option>
              <option value="open">Open</option>
              <option value="in_progress">In Progress</option>
              <option value="pending_review">Pending Review</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
              <option value="wont_fix">Won't Fix</option>
            </select>
            <button type="submit" class="btn btn-primary">Post</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">Issue Details</span></div></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <?php $rows=[
            ['Severity',   '<span class="status-chip" style="background:'.$sevColor.'20;color:'.$sevColor.'">' . ucfirst(Security::h($issue['severity'])) . '</span>'],
            ['Status',     '<span class="status-chip" style="background:'.$stColor.'20;color:'.$stColor.'">' . ucfirst(str_replace('_',' ',Security::h($issue['status']))) . '</span>'],
            ['Source',     ucfirst(str_replace('_',' ',Security::h($issue['source_type'] ?? 'manual')))],
            ['Created by', Security::h($issue['created_by_name'] ?? '—')],
            ['Assigned to',Security::h($issue['assigned_to_name'] ?? 'Unassigned')],
            ['Due date',   $issue['due_date'] ? date('M j, Y', strtotime($issue['due_date'])) : '—'],
            ['Resolved',   $issue['resolved_at'] ? date('M j, Y g:ia', strtotime($issue['resolved_at'])) : '—'],
            ['Created',    date('M j, Y g:ia', strtotime($issue['created_at']))],
            ['Updated',    date('M j, Y g:ia', strtotime($issue['updated_at']))],
          ]; foreach ($rows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:110px"><?= $label ?></td>
            <td style="padding:8px 0"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<?php if (Auth::can('issue.write')): ?>
<div class="um-overlay" id="editModal" style="display:none">
  <div class="um-dialog" style="max-width:600px;width:100%">
    <div class="um-header">
      <span>Edit Issue</span>
      <button class="um-close" data-close-modal="editModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <form method="post" action="/issue/<?= $issue['id'] ?>/update">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-group"><label class="form-label">Title *</label><input name="title" class="form-control" value="<?= Security::h($issue['title']) ?>" required></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Severity</label>
            <select name="severity" class="form-control">
              <?php foreach (['critical','high','medium','low'] as $s): ?>
                <option value="<?= $s ?>" <?= $issue['severity']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['open','in_progress','pending_review','resolved','closed','wont_fix'] as $s): ?>
                <option value="<?= $s ?>" <?= $issue['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Assigned To</label>
            <select name="assigned_to" class="form-control">
              <option value="">Unassigned</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($issue['assigned_to']==$u['id'])?'selected':'' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-control" value="<?= Security::h($issue['due_date'] ? date('Y-m-d', strtotime($issue['due_date'])) : '') ?>">
          </div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= Security::h($issue['description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Resolution</label><textarea name="resolution" class="form-control" rows="2"><?= Security::h($issue['resolution'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Recurrence Prevention</label><textarea name="recurrence_prevention" class="form-control" rows="2"><?= Security::h($issue['recurrence_prevention'] ?? '') ?></textarea></div>
        <div class="modal-footer">
          <button type="button" data-close-modal="editModal" class="btn btn-secondary">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
