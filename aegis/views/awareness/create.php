<?php
ob_start();
$breadcrumbs = [['Awareness', '/awareness'], ['New Program', null]];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Awareness Program</h1>
    <p class="page-subtitle">Create a security awareness training or acknowledgement campaign</p>
  </div>
  <div class="page-actions">
    <a href="/awareness" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Cancel</a>
  </div>
</div>

<div class="card" style="max-width:760px">
  <div class="card-header">
    <div class="card-header-left"><i class="bi bi-mortarboard" style="color:var(--primary)"></i><span class="card-title">Program Details</span></div>
  </div>
  <div class="card-body">
    <form method="POST" action="/awareness/create">
      <?= Security::csrfField() ?>

      <div class="form-group">
        <label class="form-label required">Title</label>
        <input type="text" name="title" class="form-control" required maxlength="255"
               placeholder="e.g. Annual Security Awareness Training 2026">
      </div>

      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"
                  placeholder="What does this program cover? Why is it required?"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Content Type</label>
        <select name="content_type" class="form-control" id="contentTypeSelect" data-change="toggleContentFields">
          <option value="document">Document / Policy</option>
          <option value="video">Video</option>
          <option value="quiz">Quiz / Test</option>
          <option value="policy">Policy Acknowledgement</option>
        </select>
      </div>

      <div class="form-group" id="contentUrlGroup">
        <label class="form-label">Content URL</label>
        <input type="url" name="content_url" class="form-control" placeholder="https://…">
        <small style="color:var(--text-muted)">Link to the training material, video, or document.</small>
      </div>

      <div class="form-group">
        <label class="form-label">Content / Instructions</label>
        <textarea name="content_body" class="form-control" rows="5"
                  placeholder="Paste training content, quiz questions, or instructions here…"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Due Date</label>
        <input type="date" name="due_date" class="form-control" style="max-width:220px">
      </div>

      <hr style="margin:20px 0;border-color:var(--border)">
      <h4 style="font-size:14px;font-weight:700;margin-bottom:12px">Assign Users</h4>

      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
          <input type="checkbox" name="assign_all" value="1" id="assignAllCheck" data-change="toggleUserPicker">
          <span>Assign to all active users</span>
        </label>
      </div>

      <div class="form-group" id="userPickerGroup">
        <label class="form-label">Or select specific users</label>
        <div style="border:1px solid var(--border);border-radius:8px;max-height:200px;overflow-y:auto;padding:8px">
          <?php foreach ($users as $u): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 6px;cursor:pointer;border-radius:4px;font-size:13px">
            <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>">
            <span><?= Security::h($u['name']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border)">
        <a href="/awareness" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Create Program</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function toggleUserPicker() {
  document.getElementById('userPickerGroup').style.display = this.checked ? 'none' : 'block';
}
function toggleContentFields() {
  var t = document.getElementById('contentTypeSelect').value;
  document.getElementById('contentUrlGroup').style.display = (t === 'video' || t === 'document') ? 'block' : 'none';
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
