<?php
$pageTitle    = 'New Campaign';
$activeModule = 'campaigns';
$breadcrumbs  = [['Acknowledgement Campaigns', '/campaigns'], ['New Campaign', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">New Acknowledgement Campaign</h1><p class="page-subtitle">Assign a published document to an audience for sign-off.</p></div>
</div>

<div class="card" style="max-width:640px">
  <div class="card-body">
    <?php if (!$documents): ?>
      <div class="empty-state-sm"><i class="bi bi-file-earmark-x"></i><p>No published documents available. Publish a document first.</p></div>
    <?php else: ?>
    <form method="POST" action="/campaigns">
      <?= Security::csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="document_id">Document</label>
        <select id="document_id" name="document_id" class="form-select" required>
          <option value="">Select a published document…</option>
          <?php foreach ($documents as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= Security::h($d['document_code']) ?> — <?= Security::h($d['title']) ?> (rev <?= Security::h($d['revision']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">The current revision is captured; later revisions need a new campaign.</div>
      </div>
      <div class="form-group">
        <label class="form-label" for="title">Campaign title (optional)</label>
        <input type="text" id="title" name="title" class="form-control" maxlength="200" placeholder="Defaults to “Acknowledge: <document>”">
      </div>
      <div class="form-group">
        <label class="form-label" for="audience">Audience</label>
        <select id="audience" name="audience" class="form-select">
          <option value="all">Everyone (all active users)</option>
          <option value="role">A role (set Role below)</option>
          <option value="space">A space's members (set Space below)</option>
        </select>
        <div class="form-hint">Only the field matching your choice is used.</div>
      </div>
      <div class="form-row" style="gap:12px">
        <div class="form-group" style="flex:1">
          <label class="form-label" for="role_value">Role</label>
          <select id="role_value" name="role_value" class="form-select">
            <option value="">—</option>
            <?php foreach ($roles as $r): ?><option value="<?= Security::h($r['role']) ?>"><?= Security::h(ucwords(str_replace('_', ' ', $r['role']))) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label" for="space_value">Space</label>
          <select id="space_value" name="space_value" class="form-select">
            <option value="">—</option>
            <?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>"><?= Security::h($s['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="due_date">Due date (optional)</label>
        <input type="date" id="due_date" name="due_date" class="form-control">
      </div>
      <div class="form-actions">
        <a href="/campaigns" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-megaphone-fill"></i> Launch Campaign</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
