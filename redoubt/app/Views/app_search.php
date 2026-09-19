<?php
/**
 * Global search view. Provided by SearchController::index().
 * In scope: $user, $NONCE, $programs, $programId, $q, $results.
 */
use Redoubt\Support\Security;

$title = 'Search';
$navActive = 'search';
$breadcrumbs = ['Home' => '/app', 'Search' => null];
require __DIR__ . '/partials/app_header.php';

$typeBadge = static fn (string $t): string => '<span class="badge b-muted">' . Security::h($t) . '</span>';
?>
<div class="page-header"><h1 class="page-title">Search</h1></div>

<div class="card">
  <form method="get" action="/app/search" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= Security::h($q ?? '') ?>" placeholder="Search announcements, documents, task orders, jobs, contacts…" style="flex:1;min-width:220px" autofocus>
    <button class="btn btn-primary" type="submit">Search</button>
  </form>
  <p class="perm-key" style="margin:10px 0 0">Results are permission-trimmed — you only see what you are authorized to access.</p>
</div>

<?php if (($q ?? '') !== ''): ?>
<div class="card">
  <?php if ($results === []): ?>
    <div class="empty-state-sm">No matching results you can access.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th style="width:1%">Type</th><th>Result</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= $typeBadge($r['type']) ?></td>
          <td><strong><?= Security::h($r['title']) ?></strong><?php if ($r['subtitle']): ?> <span class="perm-key"><?= Security::h($r['subtitle']) ?></span><?php endif; ?></td>
          <td><a class="btn btn-sm" href="<?= Security::h($r['url']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
