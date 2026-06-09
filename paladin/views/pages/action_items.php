<?php
$pageTitle    = 'My Action Items';
$activeModule = 'action_items';
$breadcrumbs  = [['My Action Items', null]];
ob_start();
$open = array_filter($tasks, fn($t) => !in_array(strtolower((string)$t['done']), ['1','t','true'], true));
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-check2-square"></i> My Action Items</h1><p class="page-subtitle"><?= count($open) ?> open task(s) assigned to you across pages.</p></div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th style="width:40px"></th><th>Task</th><th>Page</th><th>Due</th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): $tdone = in_array(strtolower((string)$t['done']), ['1','t','true'], true);
        $overdue = !$tdone && $t['due_date'] && strtotime($t['due_date']) < strtotime('today'); ?>
        <tr>
          <td>
            <form method="POST" action="/tasks-inline/<?= (int)$t['id'] ?>/toggle" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="return" value="/action-items"><button type="submit" class="btn-unstyled" style="border:none;background:none;cursor:pointer;color:<?= $tdone ? 'var(--success)' : 'var(--text-light)' ?>;font-size:1.1rem" title="<?= $tdone ? 'Mark not done' : 'Mark done' ?>"><i class="bi <?= $tdone ? 'bi-check-square-fill' : 'bi-square' ?>"></i></button></form>
          </td>
          <td><span style="<?= $tdone ? 'text-decoration:line-through;color:var(--text-muted)' : '' ?>"><?= Security::h($t['text']) ?></span></td>
          <td><a href="/pages/<?= (int)$t['page_id'] ?>#action-items"><?= Security::h($t['page_title']) ?></a> <span class="form-hint"><?= Security::h($t['space_name'] ?? '') ?></span></td>
          <td class="form-hint"><?= $t['due_date'] ? Security::h(View::fmtDate($t['due_date'])) : '—' ?><?= $overdue ? ' <span class="badge badge-red">overdue</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tasks): ?>
        <tr><td colspan="4" class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-all"></i><p>No action items assigned to you. Nice and clear.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
