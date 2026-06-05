<?php
$isEdit       = !empty($policy);
$pageTitle    = $isEdit ? 'Edit Policy' : 'New Policy';
$activeModule = 'policy';
$breadcrumbs  = [['Policies','/policy'],[$pageTitle,null]];
ob_start();
?>

<div class="page-header">
  <h1 class="page-title"><?= $pageTitle ?></h1>
  <a href="/policy" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($_SESSION['policy_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['policy_error']) ?></div>
  <?php unset($_SESSION['policy_error']); ?>
<?php endif; ?>

<div class="form-page card">
  <div class="card-body">
    <form method="POST" action="<?= $isEdit ? '/policy/'.$policy['id'].'/update' : '/policy/create' ?>">
      <?= Security::csrfField() ?>

      <div class="form-row">
        <div class="form-group flex-2">
          <label class="form-label required">Policy Title</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Information Security Policy" value="<?= Security::h($policy['title'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Policy Number</label>
          <input type="text" name="policy_number" class="form-control" placeholder="e.g. IS-POL-001" value="<?= Security::h($policy['policy_number'] ?? '') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category</label>
          <input type="text" name="category" class="form-control" list="cat-list" placeholder="e.g. Information Security" value="<?= Security::h($policy['category'] ?? '') ?>">
          <datalist id="cat-list">
            <option>Information Security</option><option>Human Resources</option>
            <option>Physical Security</option><option>Data Privacy</option>
            <option>AI Governance</option><option>Risk Management</option>
            <option>Business Continuity</option>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Review Frequency</label>
          <select name="review_frequency" class="form-control">
            <?php foreach (['monthly'=>'Monthly','quarterly'=>'Quarterly','biannual'=>'Bi-annual','annual'=>'Annual','biennial'=>'Bi-annual'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($policy['review_frequency']??'annual')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Next Review Date</label>
          <input type="date" name="next_review_date" class="form-control" value="<?= Security::h($policy['next_review_date'] ?? '') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Policy Owner</label>
          <select name="owner_id" class="form-control">
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>" <?= ($policy['owner_id']??Auth::id())==$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Approver</label>
          <select name="approver_id" class="form-control">
            <option value="">None</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>" <?= ($policy['approver_id']??'')==$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Brief description of this policy..."><?= Security::h($policy['description'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Policy Content</label>
        <div class="editor-toolbar">
          <button type="button" data-click="fmt" data-arg="bold"><i class="bi bi-type-bold"></i></button>
          <button type="button" data-click="fmt" data-arg="italic"><i class="bi bi-type-italic"></i></button>
          <button type="button" data-click="fmt" data-arg="insertUnorderedList"><i class="bi bi-list-ul"></i></button>
          <button type="button" data-click="fmt" data-arg="insertOrderedList"><i class="bi bi-list-ol"></i></button>
          <button type="button" data-click="fmt" data-arg="justifyLeft"><i class="bi bi-justify-left"></i></button>
          <span class="editor-sep">|</span>
          <button type="button" data-click="fmt" data-args='["formatBlock","h2"]'>H2</button>
          <button type="button" data-click="fmt" data-args='["formatBlock","h3"]'>H3</button>
          <button type="button" data-click="fmt" data-args='["formatBlock","p"]'>¶</button>
        </div>
        <div id="editor" class="rich-editor" contenteditable="true"><?= $policy['content'] ?? '' ?></div>
        <textarea name="content" id="contentInput" style="display:none"><?= Security::h($policy['content'] ?? '') ?></textarea>
      </div>

      <?php if ($isEdit): ?>
      <div class="form-row" style="background:var(--bg-secondary);padding:12px;border-radius:8px;border:1px solid var(--border)">
        <div class="form-group">
          <label class="form-label">New Version Number</label>
          <input type="text" name="new_version" class="form-control" placeholder="e.g. 1.1 (leave blank to not version)">
        </div>
        <div class="form-group flex-2">
          <label class="form-label">Change Summary</label>
          <input type="text" name="change_summary" class="form-control" placeholder="Brief description of changes...">
        </div>
      </div>
      <?php endif; ?>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> <?= $isEdit ? 'Update' : 'Create' ?> Policy</button>
        <a href="/policy" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function fmt(cmd, val = null) { document.execCommand(cmd, false, val); }
const editor = document.getElementById('editor');
const input  = document.getElementById('contentInput');
editor.addEventListener('input', () => { input.value = editor.innerHTML; });
document.querySelector('form').addEventListener('submit', () => { input.value = editor.innerHTML; });
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
