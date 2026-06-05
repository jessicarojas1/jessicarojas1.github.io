<?php
$breadcrumbs = $breadcrumbs ?? [['Documents', '/documents'], ['Upload', null]];
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">New Document</h1>
  </div>
</div>

<form method="POST" action="/documents/create" class="card" style="max-width:760px">
  <?= Security::csrfField() ?>
  <div class="card-body" style="display:flex;flex-direction:column;gap:16px">

    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label class="form-label">Title <span class="required">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. Information Security Policy">
      </div>
      <div class="form-group">
        <label class="form-label">Document Number</label>
        <input type="text" name="doc_number" class="form-control" placeholder="POL-001">
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="3"></textarea>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Category</label>
        <input type="text" name="category" class="form-control" placeholder="Policy / Procedure / Standard…">
      </div>
      <div class="form-group">
        <label class="form-label">Classification</label>
        <select name="classification" class="form-control">
          <option value="public">Public</option>
          <option value="internal" selected>Internal</option>
          <option value="confidential">Confidential</option>
          <option value="restricted">Restricted</option>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Owner</label>
        <select name="owner_id" class="form-control">
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Approver</label>
        <select name="approver_id" class="form-control">
          <option value="">— None —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Review Frequency</label>
        <select name="review_frequency" class="form-control">
          <?php foreach (['monthly','quarterly','biannual','annual','biennial'] as $f): ?>
            <option value="<?= $f ?>" <?= $f === 'annual' ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Next Review Date</label>
        <input type="date" name="next_review_date" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Expiry Date</label>
        <input type="date" name="expiry_date" class="form-control">
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Tags <span class="text-muted text-xs">(comma-separated)</span></label>
      <input type="text" name="tags" class="form-control" placeholder="gdpr, access-control, encryption">
    </div>
  </div>
  <div class="card-footer">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Document</button>
    <a href="/documents" class="btn btn-secondary">Cancel</a>
  </div>
</form>
<?php $content = ob_get_clean(); ?>
