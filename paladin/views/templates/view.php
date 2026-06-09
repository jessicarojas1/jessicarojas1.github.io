<?php
$pageTitle    = $template['name'];
$activeModule = 'templates';
$breadcrumbs  = [['Templates', '/templates'], [$template['name'], null]];
ob_start();

$catLabels = [
    'document' => 'Documents', 'page' => 'Pages', 'process' => 'Processes',
    'meeting' => 'Meetings', 'project' => 'Projects', 'risk' => 'Risk', 'audit' => 'Audit',
];
$catLabel = $catLabels[$template['category']] ?? ucfirst((string)$template['category']);
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($template['name']) ?></h1>
    <p class="page-subtitle"><span class="chip"><?= Security::h($catLabel) ?></span><?php if (!empty($template['doc_type'])): ?> <span class="chip"><?= Security::h(View::docTypeLabel($template['doc_type'])) ?></span><?php endif; ?></p>
  </div>
  <div class="page-actions">
    <?php if ($template['category'] === 'document'): ?>
      <a href="/documents/create" class="btn btn-primary"><i class="bi bi-file-earmark-plus"></i> Use in new document</a>
    <?php elseif ($template['category'] === 'page'): ?>
      <a href="/pages/create" class="btn btn-primary"><i class="bi bi-file-richtext"></i> Use in new page</a>
    <?php else: ?>
      <button type="button" class="btn btn-primary" id="copyBody"><i class="bi bi-clipboard"></i> Copy to clipboard</button>
    <?php endif; ?>
    <?php if (Auth::can('template.manage')): ?>
    <form method="POST" action="/templates/<?= (int)$template['id'] ?>/delete" style="margin:0" data-confirm="Delete this template?">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
  <div>
    <?php if ($template['description']): ?>
    <div class="card" style="margin-bottom:18px"><div class="card-body"><p style="margin:0;color:var(--text-muted)"><?= Security::h($template['description']) ?></p></div></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-file-text"></i> Template Body</span></div></div>
      <div class="card-body"><div class="prose" id="tplBody"><?= $template['body'] ?: '<p style="color:var(--text-muted)">This template has no body content.</p>' ?></div></div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Details</span></div></div>
      <div class="card-body">
        <div class="form-group"><label class="form-label">Category</label><div><span class="chip"><?= Security::h($catLabel) ?></span></div></div>
        <?php if (!empty($template['doc_type'])): ?>
        <div class="form-group"><label class="form-label">Document Type</label><div><?= Security::h(View::docTypeLabel($template['doc_type'])) ?></div></div>
        <?php endif; ?>
        <div class="form-group"><label class="form-label">Created by</label><div style="display:flex;align-items:center;gap:8px"><?= View::avatar($template['creator_name'] ?? '?', 'sm') ?> <?= Security::h($template['creator_name'] ?: '—') ?></div></div>
        <div class="form-group" style="margin-bottom:0"><label class="form-label">Created</label><div><?= Security::h(View::fmtDate($template['created_at'])) ?> <span class="form-hint">(<?= Security::h(View::timeAgo($template['created_at'])) ?>)</span></div></div>
      </div>
    </div>
  </div>
</div>

<?php if (!in_array($template['category'], ['document', 'page'], true)): ?>
<script nonce="<?= Security::nonce() ?>">
(function(){
  var btn = document.getElementById('copyBody');
  var body = document.getElementById('tplBody');
  if (!btn || !body) return;
  btn.addEventListener('click', function(){
    var text = body.innerText || body.textContent || '';
    var done = function(){ var o = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied'; setTimeout(function(){ btn.innerHTML = o; }, 1800); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function(){});
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch(e){}
      document.body.removeChild(ta);
    }
  });
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
