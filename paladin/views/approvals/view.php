<?php
$pageTitle    = 'Approval — ' . $req['title'];
$activeModule = 'approvals';
$breadcrumbs  = [['Approvals', '/approvals'], ['Request #' . (int)$req['id'], null]];
ob_start();
$entityLink = null;
if ($req['entity_type'] && $req['entity_id']) {
    $map = ['document'=>'/documents/','page'=>'/pages/','process'=>'/processes/'];
    if (isset($map[$req['entity_type']])) $entityLink = $map[$req['entity_type']] . (int)$req['entity_id'];
}
?>
<div class="page-header">
  <div><h1 class="page-title"><?= Security::h($req['title']) ?> <?= View::statusBadge($req['status']) ?></h1>
    <p class="page-subtitle">Requested by <?= Security::h($req['requester'] ?: '—') ?> · <?= View::fmtDate($req['created_at'], 'M j, Y g:ia') ?> · <?= Security::h(ucfirst($req['approval_mode'])) ?> approval</p></div>
  <div class="page-actions">
    <?php if ($entityLink): ?><a href="<?= $entityLink ?>" class="btn btn-ghost"><i class="bi bi-box-arrow-up-right"></i> View Item</a><?php endif; ?>
    <?php if (((int)$req['requested_by'] === Auth::id() || Auth::role()==='admin') && $req['status']==='pending'): ?>
      <form method="POST" action="/approvals/<?= (int)$req['id'] ?>/cancel" style="margin:0" data-confirm="Cancel this approval request?"><?= Security::csrfField() ?><button class="btn btn-ghost btn-danger" type="submit"><i class="bi bi-x-circle"></i> Cancel</button></form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">
  <div>
    <div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-ol"></i> Approval Steps</span></div></div><div class="card-body">
      <ul class="tl">
        <?php foreach ($steps as $s):
          $isCurrent = $req['status']==='pending' && ((int)$s['step_number']===(int)$req['current_step'] || in_array($req['approval_mode'],['parallel','consensus'],true)) && $s['status']==='pending';
          $dotCls = $s['status']==='approved' ? 'ok' : ($s['status']==='rejected' ? 'bad' : '');
        ?>
        <li><span class="tl-dot <?= $dotCls ?>"><?= $s['status']==='approved'?'<i class="bi bi-check"></i>':($s['status']==='rejected'?'<i class="bi bi-x"></i>':(int)$s['step_number']) ?></span>
          <div class="tl-title"><?= Security::h($s['name'] ?: ('Step ' . $s['step_number'])) ?> <?= View::statusBadge($s['status']) ?><?= $isCurrent ? ' <span class="badge badge-blue">current</span>' : '' ?></div>
          <div class="tl-meta">Approver: <?= $s['required_user_name'] ? Security::h($s['required_user_name']) : ($s['required_role'] ? Security::h(Auth::roleLabel($s['required_role'])) . ' (role)' : 'Any approver') ?><?php if ($s['decided_by_name']): ?> · decided by <?= Security::h($s['decided_by_name']) ?> <?= View::timeAgo($s['decided_at']) ?><?php endif; ?></div>
          <?php if ($s['decision_comment']): ?><div class="tl-body"><i class="bi bi-chat-quote"></i> <?= Security::h($s['decision_comment']) ?></div><?php endif; ?>
          <?php if (!empty($s['signature_name'])): ?><div class="tl-body" style="color:var(--success)"><i class="bi bi-pen-fill"></i> Electronically signed by <strong><?= Security::h($s['signature_name']) ?></strong> on <?= View::fmtDate($s['signed_at'], 'M j, Y g:ia') ?><?php if (!empty($s['signature_hash'])): ?> <span class="form-hint" title="Tamper-evident signature digest">· <?= Security::h(substr((string)$s['signature_hash'], 0, 12)) ?>…</span><?php endif; ?></div><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div></div>

    <?php if ($canDecide && (Auth::can('approval.approve') || Auth::role()==='admin')): ?>
    <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-check2-square"></i> Your Decision</span></div></div><div class="card-body">
      <form method="POST" action="/approvals/<?= (int)$req['id'] ?>/decide">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label">Comment (optional for approve, recommended for reject/return)</label><textarea name="comment" class="form-control" rows="2" placeholder="Add a decision note…"></textarea></div>
        <div class="form-group">
          <label class="form-label"><i class="bi bi-pen"></i> Electronic signature<?= $esignRequired ? ' <span style="color:var(--danger)">*</span>' : ' <span class="form-hint">(optional)</span>' ?></label>
          <input type="text" name="signature" class="form-control" placeholder="Type your full name to sign"<?= $esignRequired ? ' required' : '' ?> autocomplete="off">
          <p class="form-hint"><?= $esignRequired
            ? 'Required for approve/reject — must match your account name (' . Security::h((string)(Auth::user()['name'] ?? '')) . ').'
            : 'Optional. Typing your name records a tamper-evident signature on this decision.' ?></p>
        </div>
        <div class="form-row">
          <button class="btn btn-success" type="submit" name="decision" value="approve"><i class="bi bi-check-lg"></i> Approve</button>
          <button class="btn btn-ghost" type="submit" name="decision" value="return"><i class="bi bi-arrow-counterclockwise"></i> Return for Revision</button>
          <button class="btn btn-danger" type="submit" name="decision" value="reject"><i class="bi bi-x-lg"></i> Reject</button>
        </div>
      </form>
    </div></div>
    <?php endif; ?>
  </div>

  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> Audit Trail</span></div></div><div class="card-body">
    <ul class="tl">
      <?php foreach ($history as $h): ?>
      <li><span class="tl-dot <?= $h['action']==='approved'?'ok':($h['action']==='rejected'?'bad':'') ?>"><i class="bi bi-dot"></i></span>
        <div class="tl-title"><?= Security::h($h['user_name'] ?: 'System') ?> <span style="font-weight:400;color:var(--text-muted)"><?= Security::h(str_replace('_',' ',$h['action'])) ?></span></div>
        <div class="tl-meta"><?= View::fmtDate($h['created_at'], 'M j, Y g:ia') ?></div>
        <?php if ($h['comment']): ?><div class="tl-body"><?= Security::h($h['comment']) ?></div><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div></div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
