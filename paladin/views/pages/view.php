<?php
$pageTitle    = $page['title'];
$activeModule = 'spaces';
$breadcrumbs  = array_merge([['Spaces', '/spaces'], [$page['space_name'], '/spaces/' . (int)$page['space_id']]], $crumbs, [[$page['title'], null]]);
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($page['title']) ?> <?= View::statusBadge($page['status']) ?><?php if (!empty($restrictions)): ?> <span class="badge badge-gray" title="This page has access restrictions"><i class="bi bi-lock-fill"></i> Restricted</span><?php endif; ?></h1>
    <p class="page-subtitle">In <a href="/spaces/<?= (int)$page['space_id'] ?>"><?= Security::h($page['space_name']) ?></a> · Owner: <?= Security::h($page['owner_name'] ?: '—') ?> · v<?= (int)$page['current_version'] ?> · Updated <?= View::timeAgo($page['updated_at']) ?></p>
  </div>
  <div class="page-actions">
    <?php $likeType='page'; $likeId=(int)$page['id']; $likeData=$pageLike; require PALADIN_ROOT . '/views/partials/like.php'; ?>
    <?php $shareType='page'; $shareId=(int)$page['id']; $sharePath='/pages/'.(int)$page['id']; require PALADIN_ROOT . '/views/partials/share.php'; ?>
    <form method="POST" action="/pages/<?= (int)$page['id'] ?>/favorite" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-ghost" type="submit" title="<?= ($isFav ?? false)?'Favorited':'Add to favorites' ?>"><i class="bi bi-star<?= ($isFav ?? false)?'-fill':'' ?>"></i></button></form>
    <form method="POST" action="/pages/<?= (int)$page['id'] ?>/watch" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-ghost" type="submit" title="<?= $isWatching?'Watching — you’ll be alerted on changes':'Watch for changes' ?>"><i class="bi bi-eye<?= $isWatching?'-fill':'' ?>"></i></button></form>
    <a href="/pages/<?= (int)$page['id'] ?>/history" class="btn btn-ghost"><i class="bi bi-clock-history"></i> History (<?= $versionCount ?>)</a>
    <a href="/pages/<?= (int)$page['id'] ?>/print" target="_blank" rel="noopener" class="btn btn-ghost"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    <?php if (Auth::can('page.publish') && $page['status'] !== 'published'): ?>
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/publish" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-success" type="submit"><i class="bi bi-send-check"></i> Publish</button></form>
    <?php endif; ?>
    <?php if (Auth::can('page.edit')): ?><a href="/pages/<?= (int)$page['id'] ?>/edit" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start">
  <div>
    <div class="card"><div class="card-body"><div class="prose"><?= $page['body'] ?: '<p style="color:var(--text-muted)">This page has no content yet.</p>' ?></div></div></div>

    <div class="card" style="margin-top:18px" id="comments">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-chat-left-text"></i> Comments (<?= count($comments) ?>)</span></div></div>
      <div class="card-body">
        <?php
          $cEntityType = 'page'; $cEntityId = (int)$page['id'];
          $cAction = '/pages/' . (int)$page['id'] . '/comment';
          $cCanComment = Auth::can('page.comment');
          require PALADIN_ROOT . '/views/partials/comments.php';
        ?>
      </div>
    </div>
  </div>

  <!-- Workflow -->
  <?php $wfType='page'; $wfId=(int)$page['id']; $wfCanEdit=$canEditPage; require PALADIN_ROOT . '/views/partials/workflow_status.php'; ?>

  <!-- Labels -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-tags-fill"></i> Labels</span></div></div>
    <div class="card-body">
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($labels as $lb): ?>
          <span class="chip" style="border-left:3px solid <?= Security::h($lb['color']) ?>">
            <a href="/labels/<?= (int)$lb["id"] ?>" style="text-decoration:none;color:inherit"><?= Security::h($lb["name"]) ?></a>
            <?php if (Auth::can('page.edit')): ?><form method="POST" action="/pages/<?= (int)$page['id'] ?>/labels/<?= (int)$lb['id'] ?>/delete" style="display:inline;margin:0"><?= Security::csrfField() ?><button type="submit" class="btn-unstyled" style="border:none;background:none;cursor:pointer;color:var(--text-light);padding:0 0 0 4px" title="Remove label"><i class="bi bi-x"></i></button></form><?php endif; ?>
          </span>
        <?php endforeach; ?>
        <?php if (!$labels): ?><span class="form-hint">No labels yet.</span><?php endif; ?>
      </div>
      <?php if (Auth::can('page.edit') && $allTags): ?>
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/labels" class="form-row" style="gap:6px;margin-top:10px">
        <?= Security::csrfField() ?>
        <select name="tag_id" class="form-select" style="flex:1">
          <?php foreach ($allTags as $tg): ?><option value="<?= (int)$tg['id'] ?>"><?= Security::h($tg['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-plus-lg"></i></button>
      </form>
      <div class="form-hint" style="margin-top:6px">Manage the label vocabulary in <a href="/admin/tags">Admin → Tags</a>.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Restrictions -->
  <?php if ($canEditPage): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-lock-fill"></i> Restrictions</span></div></div>
    <div class="card-body">
      <?php if ($restrictions): ?>
        <?php foreach ($restrictions as $r): ?>
          <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border-light)">
            <span class="badge <?= $r['mode']==='edit'?'badge-orange':'badge-blue' ?>"><?= $r['mode']==='edit'?'Can edit':'Can view' ?></span>
            <span style="flex:1;font-size:.85rem"><?= $r['principal_type']==='user' ? Security::h($r['user_name'] ?: ('User #' . $r['principal'])) : Security::h(Auth::roleLabel($r['principal'])) . ' <span class="form-hint">(role)</span>' ?></span>
            <form method="POST" action="/pages/<?= (int)$page['id'] ?>/restrictions/<?= (int)$r['id'] ?>/delete" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger btn-unstyled" type="submit" style="border:none;background:none;color:var(--danger)" title="Remove"><i class="bi bi-x"></i></button></form>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="form-hint">Open to everyone with space access. Add a restriction to limit who can view or edit.</div>
      <?php endif; ?>
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/restrictions" style="margin-top:10px">
        <?= Security::csrfField() ?>
        <div class="form-row" style="gap:6px">
          <select name="mode" class="form-select" style="flex:0 0 100px"><option value="view">Can view</option><option value="edit">Can edit</option></select>
          <select name="principal_type" class="form-select" style="flex:0 0 90px"><option value="user">User</option><option value="role">Role</option></select>
        </div>
        <div class="form-row" style="gap:6px;margin-top:6px">
          <select name="principal_user" class="form-select" style="flex:1"><option value="">— user —</option><?php foreach ($allUsers as $au): ?><option value="<?= (int)$au['id'] ?>"><?= Security::h($au['name']) ?></option><?php endforeach; ?></select>
          <select name="principal_role" class="form-select" style="flex:1"><option value="">— role —</option><?php foreach (Auth::allRoleOptions() as $rk=>$rl): if($rk==='admin') continue; ?><option value="<?= Security::h($rk) ?>"><?= Security::h($rl) ?></option><?php endforeach; ?></select>
          <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-plus-lg"></i></button>
        </div>
        <div class="form-hint" style="margin-top:6px">Pick <strong>User</strong> or <strong>Role</strong> above, then the matching dropdown. Admins &amp; the owner always retain access.</div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Attachments -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-paperclip"></i> Attachments (<?= count($attachments) ?>)</span></div></div>
    <div class="card-body">
      <?php foreach ($attachments as $att): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border-light)">
          <i class="bi bi-file-earmark"></i>
          <div style="flex:1;min-width:0">
            <a href="/attachments/<?= (int)$att['id'] ?>/download" class="table-link" style="word-break:break-all"><?= Security::h($att['original_name']) ?></a>
            <div class="form-hint"><?= $att['file_size'] ? round($att['file_size']/1024) . ' KB' : '' ?> · <?= View::timeAgo($att['created_at']) ?></div>
          </div>
          <?php if (Auth::can('page.edit')): ?><form method="POST" action="/attachments/<?= (int)$att['id'] ?>/delete" style="margin:0" data-confirm="Remove this attachment?"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger btn-unstyled" type="submit" style="border:none;background:none;color:var(--danger)"><i class="bi bi-trash"></i></button></form><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$attachments): ?><div class="empty-state-sm">No attachments.</div><?php endif; ?>
      <?php if (Auth::can('page.edit')): ?>
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/attachments" enctype="multipart/form-data" style="margin-top:10px">
        <?= Security::csrfField() ?>
        <input type="file" name="file" class="form-control" required>
        <button class="btn btn-sm btn-primary btn-full" type="submit" style="margin-top:8px"><i class="bi bi-upload"></i> Upload</button>
        <div class="form-hint" style="margin-top:4px">Field: <code>file</code> — type/size limits from Admin → Settings.</div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3"></i> Child Pages</span></div></div>
    <div class="card-body">
      <?php if ($children): ?>
        <ul class="page-tree">
        <?php foreach ($children as $ch): ?><li><a href="/pages/<?= (int)$ch['id'] ?>"><i class="bi bi-file-richtext"></i> <?= Security::h($ch['title']) ?> <?= View::statusBadge($ch['status']) ?></a></li><?php endforeach; ?>
        </ul>
      <?php else: ?><div class="empty-state-sm">No child pages.</div><?php endif; ?>
      <?php if (Auth::can('page.create')): ?><a href="/pages/create?space=<?= (int)$page['space_id'] ?>&parent=<?= (int)$page['id'] ?>" class="btn btn-sm btn-ghost" style="margin-top:10px"><i class="bi bi-plus"></i> Add child page</a><?php endif; ?>
    </div>
    <?php if (Auth::can('page.delete')): ?>
    <div class="card-body" style="border-top:1px solid var(--border-light)">
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/delete" style="margin:0" data-confirm="Delete this page and all its versions? This cannot be undone."><?= Security::csrfField() ?><button class="btn btn-sm btn-danger btn-full" type="submit"><i class="bi bi-trash"></i> Delete Page</button></form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
