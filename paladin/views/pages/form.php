<?php
$editing      = !empty($page);
$pageTitle    = $editing ? 'Edit Page' : 'New Page';
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces']];
if ($space) $breadcrumbs[] = [$space['name'], '/spaces/' . (int)$space['id']];
$breadcrumbs[] = [$editing ? 'Edit Page' : 'New Page', null];
$action = $editing ? '/pages/' . (int)$page['id'] . '/edit' : '/pages/create';
// Autosave/draft-recovery key + server mtime (ms) for staleness comparison.
$autosaveKey      = $editing ? ('page-' . (int)$page['id']) : ('new-' . (int)($space['id'] ?? 0));
$autosaveServerTs = ($editing && !empty($page['updated_at'])) ? strtotime((string)$page['updated_at']) * 1000 : 0;
ob_start();
?>
<div class="page-header"><div><h1 class="page-title"><?= $editing ? 'Edit Page' : 'Create Page' ?></h1></div></div>

<div class="card">
  <div class="card-body">
    <div id="draft-recovery" class="banner" hidden style="margin-bottom:14px;background:var(--card-bg);border:1px solid var(--warning)">
      <i class="bi bi-clock-history" style="color:var(--warning)"></i>
      <div class="banner-body">Unsaved changes from <strong data-draft-when>earlier</strong> were found on this device.
        <button type="button" class="btn btn-sm btn-primary" data-draft-restore><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
        <button type="button" class="btn btn-sm btn-ghost" data-draft-discard>Discard</button>
      </div>
    </div>
    <div id="draft-status" class="form-hint" hidden style="margin-bottom:8px"><i class="bi bi-cloud-check"></i> <span data-draft-status-text>Draft saved on this device</span></div>
    <form method="POST" action="<?= $action ?>" data-autosave="<?= Security::h($autosaveKey) ?>" data-autosave-server-ts="<?= (int)$autosaveServerTs ?>">
      <?= Security::csrfField() ?>
      <div class="form-row">
        <div class="form-group" style="flex:2"><label class="form-label">Title *</label>
          <input type="text" name="title" class="form-control" required value="<?= Security::h($page['title'] ?? '') ?>" placeholder="Page title">
        </div>
        <div class="form-group" style="flex:1"><label class="form-label">Space *</label>
          <select name="space_id" class="form-select" required <?= $editing ? 'disabled' : '' ?>>
            <option value="">Select…</option>
            <?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (($page['space_id'] ?? ($space['id'] ?? 0))==$s['id'])?'selected':'' ?>><?= Security::h($s['space_key'] . ' — ' . $s['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if ($editing): ?><input type="hidden" name="space_id" value="<?= (int)$page['space_id'] ?>"><?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:2"><label class="form-label">Parent Page</label>
          <select name="parent_id" class="form-select">
            <option value="">— Top level —</option>
            <?php foreach ($parents as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (($page['parent_id'] ?? 0)==$p['id'])?'selected':'' ?>><?= Security::h($p['title']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:1"><label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['draft'=>'Draft','in_review'=>'In Review','published'=>'Published'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($page['status'] ?? 'draft')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php if ($editing && Auth::can('page.publish')): $schedVal = !empty($page['scheduled_publish_at']) ? date('Y-m-d\TH:i', strtotime((string)$page['scheduled_publish_at'])) : ''; ?>
      <div class="form-group"><label class="form-label"><i class="bi bi-calendar-event"></i> Schedule publish <span class="form-hint">(optional — ignored when status is Published)</span></label>
        <input type="datetime-local" name="scheduled_publish_at" class="form-control" value="<?= Security::h($schedVal) ?>">
        <p class="form-hint">Leave the status as Draft/In Review and pick a future time; the page auto-publishes once that time passes.</p>
      </div>
      <?php endif; ?>
      <?php if (!$editing && !empty($templates)): ?>
      <div class="form-group"><label class="form-label">Start from template (optional)</label>
        <select class="form-select" id="tplPicker">
          <option value="">— Blank page —</option>
          <?php foreach ($templates as $t): ?><option value="<?= Security::h($t['body']) ?>"><?= Security::h($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group"><label class="form-label">Content</label>
        <?php $wId='pgbody'; $wName='body'; $wValue=$page['body'] ?? ''; require PALADIN_ROOT . '/views/partials/wysiwyg.php'; ?>
      </div>
      <?php if ($editing): ?>
      <div class="form-group"><label class="form-label">Change note</label><input type="text" name="change_note" class="form-control" placeholder="What changed in this revision?"></div>
      <?php endif; ?>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save Revision' : 'Create Page' ?></button>
        <a href="<?= $editing ? '/pages/' . (int)$page['id'] : '/spaces' ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php if (!$editing && !empty($templates)): ?>
<script nonce="<?= Security::nonce() ?>">
(function(){
  var picker = document.getElementById('tplPicker');
  if(!picker) return;
  picker.addEventListener('change', function(){
    if(!picker.value) return;
    var surface = document.getElementById('pgbody-surface');
    var source  = document.getElementById('pgbody-source');
    if(surface){ surface.innerHTML = picker.value; }
    if(source){ source.value = picker.value; }
  });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
