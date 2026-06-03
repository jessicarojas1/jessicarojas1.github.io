<?php ob_start(); ?>
<div class="page-header">
  <h1 class="page-title">New BCP Plan</h1>
</div>
<form method="POST" action="/bcp/create" class="card" style="max-width:800px">
  <?= Security::csrfField() ?>
  <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label class="form-label">Title <span class="required">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. IT Disaster Recovery Plan">
      </div>
      <div class="form-group">
        <label class="form-label">Version</label>
        <input type="text" name="version" class="form-control" value="1.0">
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="draft" selected>Draft</option>
          <option value="active">Active</option>
          <option value="archived">Archived</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3"></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">RTO (hours)</label>
        <input type="number" name="rto_hours" class="form-control" min="0" placeholder="4">
      </div>
      <div class="form-group">
        <label class="form-label">RPO (hours)</label>
        <input type="number" name="rpo_hours" class="form-control" min="0" placeholder="1">
      </div>
      <div class="form-group">
        <label class="form-label">Owner</label>
        <select name="owner_id" class="form-control">
          <option value="">— Select —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Next Test Date</label>
        <input type="date" name="next_test_date" class="form-control">
      </div>
    </div>

    <hr>
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0">Plan Sections</h3>
      <button type="button" class="btn btn-sm btn-secondary" data-click="addSection"><i class="bi bi-plus-lg"></i> Add Section</button>
    </div>
    <div id="sectionsContainer">
      <div class="section-row card" style="padding:16px;margin-bottom:8px;background:#f8f9fa">
        <input type="hidden" name="sections[0][sort_order]" value="0">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="sections[0][section_type]" class="form-control">
              <?php foreach (['scope','threats','procedures','contacts','recovery','dependencies'] as $t): ?>
                <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:2">
            <label class="form-label">Section Title</label>
            <input type="text" name="sections[0][title]" class="form-control" placeholder="Section title">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Content</label>
          <textarea name="sections[0][content]" class="form-control" rows="4"></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="card-footer">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Plan</button>
    <a href="/bcp" class="btn btn-secondary">Cancel</a>
  </div>
</form>
<script nonce="<?= Security::nonce() ?>">
var sectionIdx = 1;
function addSection() {
  var c = document.getElementById('sectionsContainer');
  var tpl = c.querySelector('.section-row').cloneNode(true);
  tpl.querySelectorAll('[name]').forEach(function(el) {
    el.name = el.name.replace(/\[\d+\]/, '[' + sectionIdx + ']');
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') el.value = '';
    if (el.name.includes('sort_order')) el.value = sectionIdx;
  });
  c.appendChild(tpl);
  sectionIdx++;
}
</script>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
