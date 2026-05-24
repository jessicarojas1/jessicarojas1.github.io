<?php
$sevColors = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#0284c7','low'=>'#059669'];
$statusColors = ['open'=>'#dc2626','investigating'=>'#d97706','contained'=>'#7c3aed','resolved'=>'#059669','closed'=>'#64748b'];
$sevColor = $sevColors[$incident['severity']] ?? '#64748b';
$stColor  = $statusColors[$incident['status']] ?? '#64748b';
$pageTitle    = 'Incident: ' . $incident['incident_number'];
$activeModule = 'incident';
$breadcrumbs  = [['Incidents','/incident'],[$incident['incident_number'],null]];
ob_start();
?>
<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <h1 class="page-title" style="margin:0"><?= Security::h($incident['incident_number']) ?>: <?= Security::h($incident['title']) ?></h1>
      <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40;"><?= ucfirst(Security::h($incident['severity'])) ?></span>
      <span class="status-chip" style="background:<?= $stColor ?>20;color:<?= $stColor ?>;border:1px solid <?= $stColor ?>40;"><?= ucfirst(str_replace('_',' ',Security::h($incident['status']))) ?></span>
    </div>
    <p class="page-subtitle">Reported <?= date('M j, Y g:ia', strtotime($incident['created_at'])) ?><?= $incident['detected_at'] ? ' · Detected ' . date('M j, Y g:ia', strtotime($incident['detected_at'])) : '' ?></p>
  </div>
  <div class="page-actions">
    <?php if (in_array($incident['status'], ['open','investigating','contained'])): ?>
      <?php if (Auth::can('incident.write')): ?>
        <button onclick="showModal('editModal')" class="btn btn-secondary"><i class="bi bi-pencil"></i> Edit</button>
        <?php if ($incident['status'] !== 'closed'): ?>
          <form method="post" action="/incident/<?= $incident['id'] ?>/close" style="display:inline" onsubmit="return confirm('Close this incident?')">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Close</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="two-col-layout">
  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <?php if ($incident['description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-file-text" style="color:var(--primary)"></i><span class="card-title">Description</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['description']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['affected_systems']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-hdd-network" style="color:#d97706"></i><span class="card-title">Affected Systems</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['affected_systems']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['impact_description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-exclamation-triangle" style="color:#dc2626"></i><span class="card-title">Impact</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['impact_description']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['root_cause']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-search" style="color:#7c3aed"></i><span class="card-title">Root Cause</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['root_cause']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['lessons_learned']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-lightbulb" style="color:#059669"></i><span class="card-title">Lessons Learned</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['lessons_learned']) ?></p></div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-clock-history" style="color:var(--primary)"></i><span class="card-title">Timeline</span></div></div>
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
                <?php $typeColors=['status_change'=>'#7c3aed','containment'=>'#d97706','resolution'=>'#059669','assignment'=>'#0284c7','comment'=>'#64748b']; ?>
                <span style="font-size:10px;padding:1px 6px;border-radius:3px;background:<?= ($typeColors[$upd['update_type']]??'#64748b') ?>20;color:<?= ($typeColors[$upd['update_type']]??'#64748b') ?>"><?= ucfirst(str_replace('_',' ',$upd['update_type'])) ?></span>
              </div>
              <p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($upd['content']) ?></p>
            </div>
          </div>
        <?php endforeach; else: ?>
          <p style="color:var(--text-muted);text-align:center;padding:20px 0">No updates yet.</p>
        <?php endif; ?>

        <?php if (Auth::check()): ?>
        <form method="post" action="/incident/<?= $incident['id'] ?>/add-update" style="margin-top:16px;">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label">Add Update</label>
            <textarea name="content" class="form-control" rows="3" placeholder="Describe the update, action taken, or finding…" required></textarea>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;">
            <select name="update_type" class="form-control" style="width:auto">
              <option value="comment">Comment</option>
              <option value="status_change">Status Change</option>
              <option value="containment">Containment Action</option>
              <option value="resolution">Resolution</option>
              <option value="assignment">Assignment</option>
            </select>
            <select name="new_status" class="form-control" style="width:auto">
              <option value="">— Keep current status —</option>
              <option value="open">Open</option>
              <option value="investigating">Investigating</option>
              <option value="contained">Contained</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
            </select>
            <button type="submit" class="btn btn-primary">Post Update</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">Incident Details</span></div></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <?php $rows=[
            ['Severity', '<span class="status-chip" style="background:'.$sevColor.'20;color:'.$sevColor.'">' . ucfirst(Security::h($incident['severity'])) . '</span>'],
            ['Status',   '<span class="status-chip" style="background:'.$stColor.'20;color:'.$stColor.'">' . ucfirst(str_replace('_',' ',Security::h($incident['status']))) . '</span>'],
            ['Category', Security::h($incident['category'] ?? '—')],
            ['Reported by', Security::h($incident['reported_by_name'] ?? '—')],
            ['Assigned to', Security::h($incident['assigned_to_name'] ?? 'Unassigned')],
            ['Detected', $incident['detected_at'] ? date('M j, Y g:ia', strtotime($incident['detected_at'])) : '—'],
            ['Contained', $incident['contained_at'] ? date('M j, Y g:ia', strtotime($incident['contained_at'])) : '—'],
            ['Resolved', $incident['resolved_at'] ? date('M j, Y g:ia', strtotime($incident['resolved_at'])) : '—'],
            ['Created', date('M j, Y g:ia', strtotime($incident['created_at']))],
            ['Last updated', date('M j, Y g:ia', strtotime($incident['updated_at']))],
          ]; foreach ($rows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:120px"><?= $label ?></td>
            <td style="padding:8px 0"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<?php if (Auth::can('incident.write')): ?>
<div class="modal-overlay" id="editModal" style="display:none">
  <div class="modal" style="max-width:640px;width:100%">
    <div class="modal-header">
      <span>Edit Incident</span>
      <button onclick="closeModal('editModal')" style="background:none;border:none;cursor:pointer;font-size:18px">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/incident/<?= $incident['id'] ?>/update">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-row">
          <div class="flex-2">
            <div class="form-group"><label class="form-label">Title *</label><input name="title" class="form-control" value="<?= Security::h($incident['title']) ?>" required></div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Severity</label>
            <select name="severity" class="form-control">
              <?php foreach (['critical','high','medium','low'] as $s): ?>
                <option value="<?= $s ?>" <?= $incident['severity']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['open','investigating','contained','resolved','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= $incident['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Assigned To</label>
            <select name="assigned_to" class="form-control">
              <option value="">Unassigned</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($incident['assigned_to']==$u['id'])?'selected':'' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= Security::h($incident['description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Affected Systems</label><textarea name="affected_systems" class="form-control" rows="2"><?= Security::h($incident['affected_systems'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Impact Description</label><textarea name="impact_description" class="form-control" rows="2"><?= Security::h($incident['impact_description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Root Cause</label><textarea name="root_cause" class="form-control" rows="2"><?= Security::h($incident['root_cause'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Lessons Learned</label><textarea name="lessons_learned" class="form-control" rows="2"><?= Security::h($incident['lessons_learned'] ?? '') ?></textarea></div>
        <div class="modal-footer"><button type="button" onclick="closeModal('editModal')" class="btn btn-secondary">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
