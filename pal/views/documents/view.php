<?php
$pageTitle    = $doc['document_code'] . ' — ' . $doc['title'];
$activeModule = 'documents';
$breadcrumbs  = [['Documents', '/documents'], [$doc['document_code'], null]];
ob_start();
$checkedOutByMe = $doc['checked_out_by'] && (int)$doc['checked_out_by'] === Auth::id();
$relLabels = ['related_process'=>'Process','related_risk'=>'Risk','related_control'=>'Control','related_system'=>'System','related_policy'=>'Policy','related_procedure'=>'Procedure'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($doc['title']) ?> <?= View::statusBadge($doc['status']) ?></h1>
    <p class="page-subtitle"><span class="chip"><?= Security::h($doc['document_code']) ?></span> <?= View::docTypeLabel($doc['doc_type']) ?> · Revision <?= Security::h($doc['revision']) ?> · <?= Security::h(ucfirst($doc['classification'])) ?></p>
  </div>
  <div class="page-actions">
    <?php if ($doc['file_stored_name']): ?><a href="/documents/<?= (int)$doc['id'] ?>/download" class="btn btn-ghost"><i class="bi bi-download"></i> Download</a><?php endif; ?>
    <?php if (Auth::can('document.edit') && (!$doc['checked_out_by'] || $checkedOutByMe)): ?><a href="/documents/<?= (int)$doc['id'] ?>/edit" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
  </div>
</div>

<?php if ($doc['checked_out_by']): ?>
<div class="banner warn"><i class="bi bi-lock-fill"></i><div class="banner-body"><strong>Checked out</strong> by <?= Security::h($doc['checked_out_name'] ?: 'a user') ?> on <?= View::fmtDate($doc['checked_out_at'], 'M j, Y g:ia') ?>.</div>
  <?php if (($checkedOutByMe || Auth::role()==='admin') && Auth::can('document.checkout')): ?>
  <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/checkin" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-unlock"></i> Check in</button></form>
  <?php endif; ?>
</div>
<?php elseif (Auth::can('document.checkout')): ?>
<div class="banner info"><i class="bi bi-unlock"></i><div class="banner-body">This document is available for check-out before editing.</div>
  <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/checkout" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-lock"></i> Check out</button></form>
</div>
<?php endif; ?>

<?php if ($doc['requires_ack'] && $doc['status']==='published'): ?>
  <?php if ($myAck): ?>
    <div class="banner ok"><i class="bi bi-patch-check-fill"></i><div class="banner-body">You have <strong>acknowledged</strong> revision <?= Security::h($doc['revision']) ?> of this document.</div></div>
  <?php elseif (Auth::can('document.acknowledge')): ?>
    <div class="banner warn"><i class="bi bi-exclamation-circle"></i><div class="banner-body"><strong>Acknowledgement required.</strong> Please read this document and confirm you have understood it.</div>
      <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/acknowledge" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2-circle"></i> I acknowledge</button></form>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
  <div>
    <?php if ($doc['description']): ?><div class="card" style="margin-bottom:18px"><div class="card-body"><p style="margin:0;color:var(--text-muted)"><?= Security::h($doc['description']) ?></p></div></div><?php endif; ?>

    <?php if (trim((string)$doc['body']) !== ''): ?>
    <div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-file-text"></i> Document Body</span></div></div><div class="card-body"><div class="prose"><?= $doc['body'] ?></div></div></div>
    <?php endif; ?>

    <?php if ($doc['file_stored_name']): ?>
    <div class="card" style="margin-bottom:18px"><div class="card-body" style="display:flex;align-items:center;gap:14px"><i class="bi bi-file-earmark-arrow-down" style="font-size:2rem;color:var(--primary)"></i><div style="flex:1"><div style="font-weight:600"><?= Security::h($doc['file_original_name']) ?></div><div class="form-hint"><?= Security::h($doc['file_mime']) ?> · <?= $doc['file_size'] ? round($doc['file_size']/1024) . ' KB' : '' ?></div></div><a href="/documents/<?= (int)$doc['id'] ?>/download" class="btn btn-sm btn-primary"><i class="bi bi-download"></i> Download</a></div></div>
    <?php endif; ?>

    <!-- Lifecycle / workflow actions -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-arrow-left-right"></i> Lifecycle</span></div></div>
      <div class="card-body">
        <p class="form-hint" style="margin-top:0">Current status: <?= View::statusBadge($doc['status']) ?></p>
        <?php if ($transitions && Auth::can('document.edit')): ?>
        <div class="form-row" style="flex-wrap:wrap">
          <?php foreach ($transitions as $to): ?>
            <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/transition" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="to" value="<?= $to ?>"><button class="btn btn-sm <?= $to==='published'?'btn-success':($to==='rejected'?'btn-danger':'btn-ghost') ?>" type="submit"><i class="bi bi-arrow-right-short"></i> <?= ucfirst(str_replace('_',' ',$to)) ?></button></form>
          <?php endforeach; ?>
        </div>
        <?php else: ?><div class="empty-state-sm">No actions available for this status.</div><?php endif; ?>

        <?php if (Auth::can('approval.view') && in_array($doc['status'], ['draft','in_review'], true)): ?>
          <hr style="border:none;border-top:1px solid var(--border-light);margin:14px 0">
          <a href="/approvals/start?entity_type=document&entity_id=<?= (int)$doc['id'] ?>&title=<?= urlencode($doc['document_code'] . ' — ' . $doc['title']) ?>" class="btn btn-sm btn-ghost"><i class="bi bi-send"></i> Route for approval</a>
        <?php endif; ?>
        <?php if (Auth::can('document.edit') && in_array($doc['status'], ['published','approved'], true)): ?>
          <hr style="border:none;border-top:1px solid var(--border-light);margin:14px 0">
          <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/revise" class="form-row" style="align-items:flex-end;gap:10px;margin:0">
            <?= Security::csrfField() ?>
            <div class="form-group" style="margin:0;flex:0 0 120px"><label class="form-label">New revision</label><input type="text" name="revision" class="form-control" placeholder="2.0" required></div>
            <div class="form-group" style="margin:0;flex:1"><label class="form-label">Change summary</label><input type="text" name="change_summary" class="form-control" placeholder="Reason for the new revision"></div>
            <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-file-earmark-plus"></i> Start revision</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Comments -->
    <div class="card" id="comments">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-chat-left-text"></i> Discussion (<?= count($comments) ?>)</span></div></div>
      <div class="card-body">
        <?php foreach ($comments as $c): ?>
        <div class="comment"><?= View::avatar($c['user_name']) ?><div class="comment-body"><div class="comment-head"><span class="comment-author"><?= Security::h($c['user_name'] ?: 'User') ?></span><span class="comment-time"><?= View::timeAgo($c['created_at']) ?></span></div><div class="comment-text"><?= Security::h($c['body']) ?></div></div></div>
        <?php endforeach; ?>
        <?php if (!$comments): ?><div class="empty-state-sm">No comments yet.</div><?php endif; ?>
        <form method="POST" action="/documents/<?= (int)$doc['id'] ?>/comment" style="margin-top:14px"><?= Security::csrfField() ?><div class="form-group" style="margin:0"><textarea name="body" class="form-control" rows="2" placeholder="Add a comment or review note…" required></textarea></div><div style="margin-top:8px"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send"></i> Comment</button></div></form>
      </div>
    </div>
  </div>

  <!-- Sidebar: metadata -->
  <div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Metadata</span></div></div>
      <div class="card-body">
        <div class="meta-grid" style="grid-template-columns:1fr 1fr">
          <div class="meta-item"><div class="meta-label">Owner</div><div class="meta-value"><?= Security::h($doc['owner_name'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Reviewer</div><div class="meta-value"><?= Security::h($doc['reviewer_name'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Approver</div><div class="meta-value"><?= Security::h($doc['approver_name'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Space</div><div class="meta-value"><?= $doc['space_id'] ? '<a href="/spaces/' . (int)$doc['space_id'] . '">' . Security::h($doc['space_key']) . '</a>' : '—' ?></div></div>
          <div class="meta-item"><div class="meta-label">Department</div><div class="meta-value"><?= Security::h($doc['department'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Business Unit</div><div class="meta-value"><?= Security::h($doc['business_unit'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Effective</div><div class="meta-value"><?= View::fmtDate($doc['effective_date']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Review Due</div><div class="meta-value"><?= View::fmtDate($doc['review_date']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Expires</div><div class="meta-value"><?= View::fmtDate($doc['expiration_date']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Created</div><div class="meta-value"><?= View::fmtDate($doc['created_at']) ?></div></div>
        </div>
      </div>
    </div>

    <?php if ($relations): ?>
    <div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Relationships</span></div></div><div class="card-body">
      <?php foreach ($relations as $r): ?><div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border-light)"><span class="form-hint"><?= Security::h($relLabels[$r['relation_type']] ?? $r['relation_type']) ?></span><span style="font-size:.85rem;font-weight:500"><?= Security::h($r['target_label']) ?></span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-patch-check"></i> Acknowledgements (<?= count($acks) ?>)</span></div></div><div class="card-body">
      <?php foreach (array_slice($acks, 0, 8) as $a): ?><div style="display:flex;align-items:center;gap:8px;padding:4px 0"><?= View::avatar($a['user_name'], 'sm') ?><div style="flex:1"><div style="font-size:.83rem;font-weight:500"><?= Security::h($a['user_name']) ?></div><div class="form-hint">rev <?= Security::h($a['revision']) ?> · <?= View::timeAgo($a['acknowledged_at']) ?></div></div></div><?php endforeach; ?>
      <?php if (!$acks): ?><div class="empty-state-sm">No acknowledgements recorded.</div><?php endif; ?>
    </div></div>

    <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> Revision History (<?= count($versions) ?>)</span></div></div><div class="card-body">
      <ul class="tl">
        <?php foreach ($versions as $v): ?><li><span class="tl-dot"><i class="bi bi-dot"></i></span><div class="tl-title">Rev <?= Security::h($v['revision']) ?> <?= $v['status'] ? View::statusBadge($v['status']) : '' ?></div><div class="tl-meta"><?= Security::h($v['author'] ?: '—') ?> · <?= View::fmtDate($v['created_at']) ?></div><?php if ($v['change_summary']): ?><div class="tl-body"><?= Security::h($v['change_summary']) ?></div><?php endif; ?></li><?php endforeach; ?>
        <?php if (!$versions): ?><li><div class="empty-state-sm">No prior revisions.</div></li><?php endif; ?>
      </ul>
    </div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
