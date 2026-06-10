<?php
$pageTitle    = 'Notifications';
$activeModule = 'profile_notifications';
$breadcrumbs  = [['My Profile', null], ['Notifications', null]];
ob_start();
$severityMeta = [
    'info'     => ['bi-info-circle',          'var(--info)'],
    'warning'  => ['bi-exclamation-triangle', 'var(--warning)'],
    'critical' => ['bi-exclamation-octagon',  'var(--danger)'],
];
$hasUnread = false;
foreach ($alerts as $a) { if (!$a['is_read']) { $hasUnread = true; break; } }
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Notifications</h1>
    <p class="page-subtitle">Your recent alerts and activity.</p>
  </div>
  <div class="page-actions">
    <?php if ($hasUnread): ?>
    <form method="POST" action="/alerts/read-all" style="margin:0">
      <?= Security::csrfField() ?>
      <button class="btn btn-ghost" type="submit"><i class="bi bi-check2-all"></i> Mark all read</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-envelope-paper"></i> Email digest</span></div></div>
  <div class="card-body">
    <form method="POST" action="/profile/notifications" class="form-row" style="align-items:flex-end;gap:12px;margin:0">
      <?= Security::csrfField() ?>
      <div class="form-group" style="margin:0;max-width:240px">
        <label class="form-label">Send me a digest email</label>
        <select name="digest_frequency" class="form-select">
          <?php foreach (['off'=>'Never','daily'=>'Daily','weekly'=>'Weekly'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= (($digestFrequency ?? 'off')===$k)?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save</button>
      <span class="form-hint" style="flex:1">A digest summarises your unread alerts since the last one was sent.</span>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$alerts): ?>
      <div class="empty-state"><i class="bi bi-bell-slash"></i><p>You have no notifications.</p></div>
    <?php else: ?>
      <div class="notif-list">
        <?php foreach ($alerts as $a):
          $sev = $a['severity'] ?? 'info';
          [$icon, $color] = $severityMeta[$sev] ?? $severityMeta['info'];
        ?>
        <?php
          $title = Security::h($a['title']);
          $row   = '<div class="notif-icon"><i class="bi ' . $icon . '" style="color:' . $color . '"></i></div>'
                 . '<div class="notif-body">'
                 . '<div class="notif-title">' . $title . '</div>'
                 . ($a['body'] ? '<div class="notif-text">' . Security::h($a['body']) . '</div>' : '')
                 . '<div class="notif-time">' . Security::h(View::timeAgo($a['created_at'])) . '</div>'
                 . '</div>';
        ?>
        <?php if (!empty($a['link'])): ?>
          <a href="<?= Security::h($a['link']) ?>" class="notif-item<?= $a['is_read'] ? '' : ' unread' ?>" style="text-decoration:none;color:inherit"><?= $row ?></a>
        <?php else: ?>
          <div class="notif-item<?= $a['is_read'] ? '' : ' unread' ?>"><?= $row ?></div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
/* Notifications list — display only; actions are server-rendered forms/links. */
</script>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
