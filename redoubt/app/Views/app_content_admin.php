<?php
/**
 * Content Administration console. Provided by ContentAdminController::index().
 * In scope: $user, $NONCE, $programs, $programId, $cards.
 */
use Redoubt\Support\Security;

$title = 'Content Administration';
$navActive = 'content';
$breadcrumbs = ['Home' => '/app', 'Content Administration' => null];
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <h1 class="page-title">Content Administration</h1>
  <form method="get" action="/app/admin/content" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<div class="card">
  <p class="perm-key" style="margin:0">Manage routine program content yourself — no developer or ticket required. You see only the content types you are authorized to author.</p>
</div>

<div class="grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
  <?php foreach ($cards as $c): ?>
    <a class="card" href="<?= Security::h($c['url']) ?>?program_id=<?= (int) $programId ?>" style="display:block;text-decoration:none;color:inherit">
      <div style="font-size:26px"><?= Security::h($c['icon']) ?></div>
      <div style="font-weight:800;margin-top:6px"><?= Security::h($c['label']) ?></div>
      <div class="perm-key" style="margin-top:4px"><?= (int) $c['count'] ?> item<?= $c['count'] === 1 ? '' : 's' ?> you can see</div>
      <div class="btn btn-sm btn-primary" style="margin-top:10px">Manage →</div>
    </a>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
