<?php
/**
 * Notification center. Provided by NotificationsController::index().
 * In scope: $user, $NONCE, $items, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Notifications';
$navActive = 'notifications';
$breadcrumbs = ['Home' => '/app', 'Notifications' => null];
require __DIR__ . '/partials/app_header.php';

$typeLabel = static fn (string $t): string => ucwords(str_replace(['.', '_'], ' ', $t));
?>
<div class="page-header">
  <h1 class="page-title">Notifications</h1>
  <?php if ($items !== []): ?>
  <form method="post" action="/app/notifications">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="mark_all">
    <button class="btn btn-sm" type="submit">Mark all read</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <?php if ($items === []): ?>
    <div class="empty-state-sm">No notifications.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <tbody>
      <?php foreach ($items as $n): ?>
        <tr<?= $n['read'] ? ' style="opacity:.6"' : '' ?>>
          <td style="width:1%"><?php if (!$n['read']): ?><span class="dot dot-grant" title="unread"></span><?php else: ?><span class="dot dot-none"></span><?php endif; ?></td>
          <td>
            <span class="badge b-muted"><?= Security::h($typeLabel($n['type'])) ?></span>
            <strong style="margin-left:6px"><?= Security::h($n['title']) ?></strong>
            <div class="perm-key"><?= Security::h((string) $n['created']) ?></div>
          </td>
          <td style="width:1%">
            <form method="post" action="/app/notifications">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="open">
              <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button class="btn btn-sm btn-primary" type="submit">Open</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
