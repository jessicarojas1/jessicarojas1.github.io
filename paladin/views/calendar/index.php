<?php
$pageTitle    = 'Calendar';
$activeModule = 'calendar';
$breadcrumbs  = [['Calendar', null]];
ob_start();
$typeColor = ['task' => 'var(--primary)', 'review' => 'var(--warning)', 'approval' => 'var(--info)'];
$typeIcon  = ['task' => 'bi-list-task', 'review' => 'bi-file-earmark-text', 'approval' => 'bi-check2-square'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-calendar3"></i> Calendar</h1>
    <p class="page-subtitle"><?= (int)$totalEvents ?> dated item(s) in <?= Security::h($label) ?></p>
  </div>
  <div class="page-actions">
    <a href="/calendar?m=<?= Security::h($prev) ?>" class="btn btn-ghost"><i class="bi bi-chevron-left"></i></a>
    <a href="/calendar" class="btn btn-ghost">Today</a>
    <a href="/calendar?m=<?= Security::h($next) ?>" class="btn btn-ghost"><i class="bi bi-chevron-right"></i></a>
  </div>
</div>

<div class="card" style="margin-bottom:14px"><div class="card-body" style="display:flex;gap:18px;flex-wrap:wrap;padding:10px 16px">
  <span class="form-hint"><i class="bi bi-circle-fill" style="color:var(--primary)"></i> Task due</span>
  <span class="form-hint"><i class="bi bi-circle-fill" style="color:var(--warning)"></i> Document review</span>
  <span class="form-hint"><i class="bi bi-circle-fill" style="color:var(--info)"></i> Approval due</span>
</div></div>

<div class="card"><div class="card-body" style="padding:0;overflow-x:auto">
  <table class="cal-table" style="width:100%;border-collapse:collapse;table-layout:fixed;min-width:760px">
    <thead><tr>
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
        <th style="padding:8px;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);border-bottom:1px solid var(--border)"><?= $dow ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($weeks as $week): ?>
      <tr>
        <?php foreach ($week as $cell): ?>
          <td style="vertical-align:top;height:104px;width:14.28%;padding:6px;border:1px solid var(--border-light);<?= $cell['in_month'] ? '' : 'background:var(--bg-secondary);opacity:.6;' ?><?= $cell['is_today'] ? 'outline:2px solid var(--primary);outline-offset:-2px;' : '' ?>">
            <div style="font-size:.8rem;font-weight:<?= $cell['is_today'] ? '700' : '500' ?>;color:<?= $cell['is_today'] ? 'var(--primary)' : 'var(--text-light)' ?>;margin-bottom:4px"><?= (int)$cell['day'] ?></div>
            <?php foreach (array_slice($cell['events'], 0, 4) as $ev): ?>
              <a href="<?= Security::h($ev['url']) ?>" title="<?= Security::h($ev['label']) ?>" style="display:flex;align-items:center;gap:4px;text-decoration:none;font-size:.74rem;line-height:1.4;color:var(--text);<?= $ev['overdue'] ? 'font-weight:600;' : '' ?>margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <i class="bi <?= $typeIcon[$ev['type']] ?>" style="color:<?= $typeColor[$ev['type']] ?>;flex-shrink:0"></i>
                <span style="overflow:hidden;text-overflow:ellipsis"><?= Security::h($ev['label']) ?></span>
              </a>
            <?php endforeach; ?>
            <?php if (count($cell['events']) > 4): ?>
              <div class="form-hint" style="font-size:.7rem">+<?= count($cell['events']) - 4 ?> more</div>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
