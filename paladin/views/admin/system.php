<?php
$pageTitle    = 'System Information';
$activeModule = 'admin_system';
$breadcrumbs  = [['Administration', '/admin'], ['System Information', null]];
ob_start();
?>
<div class="page-header"><div><h1 class="page-title">System Information</h1><p class="page-subtitle">Environment, database, extensions &amp; content counts</p></div></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-cpu"></i> Environment</span></div></div>
    <div class="card-body"><table class="table" style="margin:0"><tbody>
      <?php foreach ($env as $k => $v): ?>
        <tr><td style="width:160px;color:var(--text-muted)"><?= Security::h($k) ?></td><td style="word-break:break-word"><?= Security::h($v) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-puzzle"></i> PHP Extensions</span></div></div>
    <div class="card-body">
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($extStatus as $e => $ok): ?>
          <span class="badge <?= $ok ? 'badge-green' : 'badge-red' ?>"><i class="bi bi-<?= $ok ? 'check-lg' : 'x-lg' ?>"></i> <?= Security::h($e) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-bar-chart"></i> Content Counts</span></div></div>
    <div class="card-body"><table class="table" style="margin:0"><tbody>
      <?php foreach ($counts as $t => $n): ?>
        <tr><td style="color:var(--text-muted)"><?= Security::h(ucwords(str_replace('_', ' ', $t))) ?></td><td style="text-align:right"><span class="badge badge-gray"><?= (int)$n ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> Migrations</span></div></div>
    <div class="card-body">
      <p class="form-hint" style="margin-top:0">Applied idempotently on every deploy (<?= count($appliedMigrations) ?> files).</p>
      <ul style="margin:0;padding-left:18px;font-family:ui-monospace,monospace;font-size:.82rem;color:var(--text-muted)">
        <?php foreach ($appliedMigrations as $m): ?><li><?= Security::h($m) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
