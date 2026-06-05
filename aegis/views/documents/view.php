<?php
$breadcrumbs  = $breadcrumbs  ?? [['Documents', '/documents'], ['Document', null]];
$statusColors = ['draft'=>'#6b7280','under_review'=>'var(--warning)','approved'=>'#3b82f6','published'=>'var(--success)','archived'=>'#9ca3af','expired'=>'var(--danger)'];
$classColors  = ['public'=>'var(--success)','internal'=>'#3b82f6','confidential'=>'var(--warning)','restricted'=>'var(--danger)'];
$canEdit = Auth::can('policy.write');
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($doc['title']) ?></h1>
    <p class="page-subtitle">
      <?php if ($doc['doc_number']): ?><span class="text-muted"><?= Security::h($doc['doc_number']) ?></span> &mdash;<?php endif; ?>
      <span class="badge" style="background:<?= $classColors[$doc['classification']] ?? '#6b7280' ?>20;color:<?= $classColors[$doc['classification']] ?? '#6b7280' ?>">
        <?= Security::h(ucfirst($doc['classification'])) ?>
      </span>
      <span class="badge" style="background:<?= $statusColors[$doc['status']] ?? '#6b7280' ?>20;color:<?= $statusColors[$doc['status']] ?? '#6b7280' ?>">
        <?= Security::h(ucfirst(str_replace('_',' ',$doc['status']))) ?>
      </span>
    </p>
  </div>
  <?php if ($canEdit): ?>
  <div>
    <button class="btn btn-secondary" data-toggle-class="hidden" data-target="#editPanel">
      <i class="bi bi-pencil"></i> Edit
    </button>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($_GET['saved']) || !empty($_GET['uploaded'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Saved successfully.</div>
<?php endif; ?>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<!-- Details -->
<div style="flex:1;min-width:280px">
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Details</h3></div>
    <div class="card-body">
      <table class="desc-table">
        <tr><th>Owner</th><td><?= Security::h($doc['owner_name'] ?? '—') ?></td></tr>
        <tr><th>Approver</th><td><?= Security::h($doc['approver_name'] ?? '—') ?></td></tr>
        <tr><th>Version</th><td><?= Security::h($doc['current_version']) ?></td></tr>
        <tr><th>Review Frequency</th><td><?= Security::h(ucfirst($doc['review_frequency'] ?? '')) ?></td></tr>
        <tr><th>Next Review</th><td><?= $doc['next_review_date'] ? date('M j, Y', strtotime($doc['next_review_date'])) : '—' ?></td></tr>
        <tr><th>Expiry</th><td><?= $doc['expiry_date'] ? date('M j, Y', strtotime($doc['expiry_date'])) : '—' ?></td></tr>
        <?php if (!empty(json_decode($doc['tags'] ?? '[]', true))): ?>
        <tr><th>Tags</th><td>
          <?php foreach (json_decode($doc['tags'], true) as $tag): ?>
            <span class="badge badge-gray" style="margin-right:4px"><?= Security::h($tag) ?></span>
          <?php endforeach; ?>
        </td></tr>
        <?php endif; ?>
      </table>
      <?php if ($doc['description']): ?>
        <p style="margin-top:12px;color:var(--text)"><?= Security::h($doc['description']) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Version history -->
<div style="flex:2;min-width:320px">
  <div class="card" style="margin-bottom:16px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <h3>Version History</h3>
      <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-primary" data-show-modal="uploadModal">
          <i class="bi bi-upload"></i> Upload Version
        </button>
      <?php endif; ?>
    </div>
    <?php if (empty($versions)): ?>
      <div class="card-body text-muted">No versions uploaded yet.</div>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Version</th><th>File</th><th>Size</th><th>Uploaded By</th><th>Date</th><th>Summary</th></tr></thead>
        <tbody>
          <?php foreach ($versions as $v): ?>
            <tr>
              <td class="fw-600"><?= Security::h($v['version']) ?></td>
              <td class="text-sm"><?= Security::h($v['file_name'] ?? '—') ?></td>
              <td class="text-sm text-muted"><?= $v['file_size'] ? number_format($v['file_size'] / 1024, 0) . ' KB' : '—' ?></td>
              <td class="text-sm"><?= Security::h($v['uploader_name'] ?? '—') ?></td>
              <td class="text-sm text-muted"><?= date('M j, Y', strtotime($v['uploaded_at'])) ?></td>
              <td class="text-sm text-muted"><?= Security::h($v['change_summary'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</div>

<!-- Edit panel (hidden by default) -->
<?php if ($canEdit): ?>
<div id="editPanel" class="card hidden" style="margin-top:16px;max-width:760px">
  <div class="card-header"><h3>Edit Document</h3></div>
  <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/update">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
      <div class="form-group">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" value="<?= Security::h($doc['title']) ?>">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <?php foreach (['draft','under_review','approved','published','archived','expired'] as $s): ?>
              <option value="<?= $s ?>" <?= $doc['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Classification</label>
          <select name="classification" class="form-control">
            <?php foreach (['public','internal','confidential','restricted'] as $c): ?>
              <option value="<?= $c ?>" <?= $doc['classification'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Next Review</label>
          <input type="date" name="next_review_date" class="form-control" value="<?= Security::h($doc['next_review_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Expiry Date</label>
          <input type="date" name="expiry_date" class="form-control" value="<?= Security::h($doc['expiry_date'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Tags</label>
        <input type="text" name="tags" class="form-control" value="<?= Security::h(implode(', ', json_decode($doc['tags'] ?? '[]', true) ?: [])) ?>">
      </div>
    </div>
    <div class="card-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div>

<!-- Upload version modal -->
<div id="uploadModal" class="modal-overlay">
  <div class="modal-card" style="max-width:480px">
    <div class="modal-header">
      <h3>Upload New Version</h3>
      <button data-close-modal="uploadModal" class="btn-icon"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/upload-version" enctype="multipart/form-data">
      <?= Security::csrfField() ?>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:16px">
        <div class="form-group">
          <label class="form-label">Version Number</label>
          <input type="text" name="version" class="form-control" placeholder="e.g. 2.0">
        </div>
        <div class="form-group">
          <label class="form-label">File <span class="required">*</span></label>
          <label class="file-drop" id="fileDropDoc" for="docUploadFile">
            <i class="bi bi-file-earmark-arrow-up" style="font-size:2rem;color:var(--primary)"></i>
            <p>Drag &amp; drop or <strong>click to upload</strong></p>
            <p class="text-muted">PDF, Word, Excel, plain text · max 50MB</p>
          </label>
          <input type="file" id="docUploadFile" name="document_file" required style="display:none"
                 data-change="showFileChange" data-drop-id="fileDropDoc" data-name-id="docFileName" data-color="var(--primary)">
          <div id="docFileName" style="margin-top:8px;color:var(--primary);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          <!-- File Upload Reference -->
          <div style="margin-top:12px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:0.8rem;">
            <div style="font-weight:600;color:var(--text);margin-bottom:6px;"><i class="bi bi-info-circle" style="color:var(--primary)"></i> Accepted File Formats</div>
            <table style="width:100%;border-collapse:collapse;">
              <thead><tr style="color:var(--text-muted);font-size:0.75rem;">
                <th style="text-align:left;padding:3px 8px;">Format</th>
                <th style="text-align:left;padding:3px 8px;">Extension</th>
                <th style="text-align:left;padding:3px 8px;">Notes</th>
              </tr></thead>
              <tbody style="color:var(--text);">
                <tr><td style="padding:3px 8px;">PDF Document</td><td style="padding:3px 8px;font-family:monospace">.pdf</td><td style="padding:3px 8px;color:var(--text-muted);">Preferred for finalized policies</td></tr>
                <tr><td style="padding:3px 8px;">Word Document</td><td style="padding:3px 8px;font-family:monospace">.docx / .doc</td><td style="padding:3px 8px;color:var(--text-muted);">Editable drafts</td></tr>
                <tr><td style="padding:3px 8px;">Excel Spreadsheet</td><td style="padding:3px 8px;font-family:monospace">.xlsx / .xls</td><td style="padding:3px 8px;color:var(--text-muted);">Data attachments</td></tr>
                <tr><td style="padding:3px 8px;">Plain Text</td><td style="padding:3px 8px;font-family:monospace">.txt</td><td style="padding:3px 8px;color:var(--text-muted);">Simple text documents</td></tr>
              </tbody>
            </table>
            <div style="margin-top:6px;color:var(--text-muted);">Max file size: <strong>50 MB</strong></div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Change Summary</label>
          <textarea name="change_summary" class="form-control" rows="3" placeholder="Describe what changed in this version…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-upload"></i> Upload Version</button>
      </div>
    </form>
  </div>
</div>

<style>
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-card { background:var(--card-bg); border:1px solid var(--border); border-radius:12px; width:100%; max-height:90vh; overflow-y:auto; }
.modal-header { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
.modal-body { padding:24px; }
.modal-footer { padding:16px 24px; border-top:1px solid var(--border); display:flex; gap:8px; justify-content:flex-end; }
.hidden { display:none !important; }
</style>
<?php endif; ?>
