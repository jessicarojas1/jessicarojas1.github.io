<?php
$pageTitle    = 'Choose a Template';
$activeModule = 'spaces';
$breadcrumbs  = $space
    ? [['Spaces', '/spaces'], [$space['space_key'], '/spaces/' . (int)$space['id']], ['New Page', null]]
    : [['Spaces', '/spaces'], ['New Page', null]];
ob_start();
$sp = $space ? '?space=' . (int)$space['id'] : '';
$spAmp = $space ? '&space=' . (int)$space['id'] : '';
?>
<div class="page-header">
  <div><h1 class="page-title">Create a Page</h1><p class="page-subtitle">Start from a blueprint, one of your space templates, or a blank page.</p></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-grid-1x2-fill"></i> Blueprints</span></div></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <a href="/pages/create<?= $sp ?>" class="blueprint-card" style="display:block;border:1px solid var(--border-light);border-radius:10px;padding:16px;text-decoration:none;color:inherit">
        <div style="width:38px;height:38px;border-radius:9px;background:var(--text-light);color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:10px"><i class="bi bi-file-earmark"></i></div>
        <div style="font-weight:700">Blank page</div>
        <div class="form-hint">Start from scratch.</div>
      </a>
      <?php foreach ($blueprints as $key => $bp): ?>
        <a href="/pages/create?blueprint=<?= Security::h($key) . $spAmp ?>" class="blueprint-card" style="display:block;border:1px solid var(--border-light);border-radius:10px;padding:16px;text-decoration:none;color:inherit">
          <div style="width:38px;height:38px;border-radius:9px;background:<?= Security::h($bp['color']) ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:10px"><i class="bi <?= Security::h($bp['icon']) ?>"></i></div>
          <div style="font-weight:700"><?= Security::h($bp['name']) ?></div>
          <div class="form-hint"><?= Security::h($bp['desc']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($templates): ?>
<div class="card">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-files"></i> Space Templates</span></div></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <?php foreach ($templates as $t): ?>
        <a href="/pages/create?template=<?= (int)$t['id'] . $spAmp ?>" class="blueprint-card" style="display:block;border:1px solid var(--border-light);border-radius:10px;padding:16px;text-decoration:none;color:inherit">
          <div style="width:38px;height:38px;border-radius:9px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:10px"><i class="bi bi-file-earmark-text"></i></div>
          <div style="font-weight:700"><?= Security::h($t['name']) ?></div>
          <div class="form-hint"><?= Security::h(mb_strimwidth((string)($t['description'] ?? ''), 0, 90, '…')) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
