<?php
$breadcrumbs = [['Account Reviews', '/account-review'], ['New Review', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Account Review</h1>
    <p class="page-subtitle">Create an access certification campaign</p>
  </div>
  <div class="page-actions">
    <a href="/account-reviews" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Cancel</a>
  </div>
</div>

<div class="card" style="max-width:700px">
  <div class="card-header">
    <div class="card-header-left"><i class="bi bi-person-check-fill" style="color:var(--primary)"></i><span class="card-title">Campaign Details</span></div>
  </div>
  <div class="card-body">
    <form method="POST" action="/account-reviews/create">
      <?= Security::csrfField() ?>

      <div class="form-group">
        <label class="form-label required">Campaign Title</label>
        <input type="text" name="title" class="form-control" required maxlength="255"
               placeholder="e.g. Q2 2026 Privileged Access Review">
      </div>

      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"
                  placeholder="Purpose and context of this review…"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Scope</label>
        <textarea name="scope" class="form-control" rows="2"
                  placeholder="e.g. All privileged accounts in production systems, VPN access, AWS IAM users…"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label class="form-label">Reviewer</label>
          <select name="reviewer_id" class="form-control">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Due Date</label>
          <input type="date" name="due_date" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
          <input type="checkbox" name="auto_populate" value="1">
          <span>Auto-populate from current AEGIS user list</span>
        </label>
        <small style="color:var(--text-muted);display:block;margin-top:4px">
          Creates a review item for every active platform user (account, name, role). You can add custom accounts after creating.
        </small>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border)">
        <a href="/account-reviews" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Create Campaign</button>
      </div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
