<?php
$pageTitle    = 'Import Markdown';
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces'], ['Import Markdown', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Import a Page from Markdown</h1><p class="page-subtitle">Paste Markdown — it's converted to a sanitized page. Headings, lists, links, code, blockquotes and emphasis are supported.</p></div>
</div>

<div class="card" style="max-width:820px">
  <div class="card-body">
    <form method="POST" action="/pages/import">
      <?= Security::csrfField() ?>
      <div class="form-row" style="gap:16px">
        <div class="form-group" style="flex:1">
          <label class="form-label" for="space_id">Space</label>
          <select id="space_id" name="space_id" class="form-select" required>
            <option value="">Select a space…</option>
            <?php foreach ($spaces as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (isset($space['id']) && (int)$space['id'] === (int)$s['id']) ? 'selected' : '' ?>><?= Security::h($s['name']) ?> (<?= Security::h($s['space_key']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($parents): ?>
        <div class="form-group" style="flex:1">
          <label class="form-label" for="parent_id">Parent page (optional)</label>
          <select id="parent_id" name="parent_id" class="form-select">
            <option value="">— Top level —</option>
            <?php foreach ($parents as $p): ?><option value="<?= (int)$p['id'] ?>"><?= Security::h($p['title']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label" for="title">Page title</label>
        <input type="text" id="title" name="title" class="form-control" maxlength="255" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="markdown">Markdown</label>
        <textarea id="markdown" name="markdown" class="form-control" rows="16" style="font-family:ui-monospace,monospace;font-size:.88rem" placeholder="# My page&#10;&#10;Some **bold** text and a [link](https://example.com).&#10;&#10;- item one&#10;- item two" required></textarea>
      </div>
      <?php if (Auth::can('page.publish')): ?>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="publish" value="1"> Publish immediately (otherwise saved as draft)</label>
      </div>
      <?php endif; ?>
      <div class="form-actions">
        <a href="/spaces" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-markdown-fill"></i> Import Page</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
