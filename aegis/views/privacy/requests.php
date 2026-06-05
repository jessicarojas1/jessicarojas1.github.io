<?php ob_start();
$typeLabels = ['access'=>'Access','erasure'=>'Erasure','rectification'=>'Rectification','portability'=>'Portability','objection'=>'Objection','restriction'=>'Restriction'];
$typeColors = ['access'=>'blue','erasure'=>'red','rectification'=>'yellow','portability'=>'indigo','objection'=>'orange','restriction'=>'purple'];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Data Subject Requests</h1>
    <p class="page-subtitle">GDPR data subject rights — access, erasure, rectification and more</p>
  </div>
  <div class="page-actions">
    <a href="/privacy" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Privacy</a>
    <button class="btn btn-primary" data-click="openNewRequest"><i class="bi bi-plus-lg"></i> New Request</button>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if ($requests): ?>
    <table class="table">
      <thead>
        <tr><th>Type</th><th>Subject</th><th>Assigned To</th><th>Due</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r):
          $sc = ['open'=>'red','in_progress'=>'yellow','completed'=>'green','rejected'=>'gray'][$r['status']] ?? 'gray';
        ?>
        <tr>
          <td><span class="badge badge-blue"><?= Security::h($typeLabels[$r['request_type']] ?? $r['request_type']) ?></span></td>
          <td>
            <div style="font-weight:600"><?= Security::h($r['subject_name'] ?: '—') ?></div>
            <?php if ($r['subject_email']): ?>
              <div style="font-size:12px;color:var(--text-muted)"><?= Security::h($r['subject_email']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= Security::h($r['assigned_name'] ?? 'Unassigned') ?></td>
          <td>
            <?php if ($r['due_date']): ?>
              <?php $overdue = strtotime($r['due_date']) < time() && $r['status'] !== 'completed'; ?>
              <span style="color:<?= $overdue ? 'var(--danger)' : 'inherit' ?>"><?= date('M j, Y', strtotime($r['due_date'])) ?></span>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          <td>
            <button class="btn btn-ghost btn-sm" data-click="openUpdate"
                    data-args='[<?= (int)$r['id'] ?>,"<?= Security::h($r['status']) ?>","<?= Security::h($r['notes'] ?? '') ?>",<?= (int)($r['assigned_to'] ?? 0) ?>]'>
              <i class="bi bi-pencil-fill"></i> Update
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:60px 20px">
      <div class="empty-icon"><i class="bi bi-person-lines-fill"></i></div>
      <h3>No data subject requests</h3>
      <p>Log incoming GDPR data subject requests to track them through to resolution.</p>
      <button class="btn btn-primary" data-click="openNewRequest"><i class="bi bi-plus-lg"></i> New Request</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- New request modal -->
<div id="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center"></div>

<div id="modal-new-request" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:501;background:var(--card-bg);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:500px;overflow:hidden">
  <div class="modal-header">
    <h3 style="font-size:15px;font-weight:700;margin:0"><i class="bi bi-person-lines-fill" style="color:var(--primary)"></i> New Data Subject Request</h3>
    <button class="modal-close" data-click="closeModals"><i class="bi bi-x-lg"></i></button>
  </div>
  <form method="POST" action="/privacy/requests/create">
    <?= Security::csrfField() ?>
    <div style="padding:20px;display:flex;flex-direction:column;gap:12px">
      <div class="form-group" style="margin:0">
        <label class="form-label required">Request Type</label>
        <select name="request_type" class="form-control" required>
          <?php foreach ($typeLabels as $val => $label): ?>
          <option value="<?= $val ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row" style="margin:0">
        <div class="form-group" style="flex:1;margin:0">
          <label class="form-label">Subject Name</label>
          <input type="text" name="subject_name" class="form-control" placeholder="Full name">
        </div>
        <div class="form-group" style="flex:1;margin:0">
          <label class="form-label">Subject Email</label>
          <input type="email" name="subject_email" class="form-control" placeholder="email@example.com">
        </div>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Details of the request…"></textarea>
      </div>
      <div class="form-row" style="margin:0">
        <div class="form-group" style="flex:1;margin:0">
          <label class="form-label">Due Date</label>
          <input type="date" name="due_date" class="form-control">
        </div>
        <div class="form-group" style="flex:1;margin:0">
          <label class="form-label">Assign To</label>
          <select name="assigned_to" class="form-control">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg)">
      <button type="button" class="btn btn-secondary btn-sm" data-click="closeModals">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Log Request</button>
    </div>
  </form>
</div>

<!-- Update modal -->
<div id="modal-update" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:501;background:var(--card-bg);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:420px;overflow:hidden">
  <div class="modal-header">
    <h3 style="font-size:15px;font-weight:700;margin:0">Update Request</h3>
    <button class="modal-close" data-click="closeModals"><i class="bi bi-x-lg"></i></button>
  </div>
  <form id="updateForm" method="POST">
    <?= Security::csrfField() ?>
    <div style="padding:20px;display:flex;flex-direction:column;gap:12px">
      <div class="form-group" style="margin:0">
        <label class="form-label">Status</label>
        <select name="status" class="form-control" id="updateStatus">
          <option value="open">Open</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Assign To</label>
        <select name="assigned_to" class="form-control" id="updateAssigned">
          <option value="">— Unassigned —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="3" id="updateNotes" placeholder="Resolution notes, actions taken…"></textarea>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg)">
      <button type="button" class="btn btn-secondary btn-sm" data-click="closeModals">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button>
    </div>
  </form>
</div>

<script nonce="<?= Security::nonce() ?>">
var overlay = document.getElementById('modal-overlay');
function openNewRequest() {
  overlay.style.display = 'flex';
  document.getElementById('modal-new-request').style.display = 'block';
}
function openUpdate(el, id, status, notes, assignedTo) {
  document.getElementById('updateForm').action = '/privacy/requests/' + id + '/update';
  document.getElementById('updateStatus').value   = status;
  document.getElementById('updateNotes').value    = notes;
  document.getElementById('updateAssigned').value = assignedTo || '';
  overlay.style.display = 'flex';
  document.getElementById('modal-update').style.display = 'block';
}
function closeModals() {
  overlay.style.display = 'none';
  document.getElementById('modal-new-request').style.display = 'none';
  document.getElementById('modal-update').style.display = 'none';
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
