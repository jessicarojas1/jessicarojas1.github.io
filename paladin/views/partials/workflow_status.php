<?php
/**
 * Workflow status panel for content. Expects:
 *   $wfType ('page'|'document'), $wfId, $wfCanEdit (bool),
 *   $wfStatus (current status row|null), $wfTransitions (from current state),
 *   $wfHistory, $wfApplicable (templates that can be applied), and
 *   $wfEsign (bool — e-signature required).
 */
$wfEsign = $wfEsign ?? false;
?>
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3-fill"></i> Workflow</span></div>
    <?php if ($wfStatus): ?><span class="wf-chip" style="background:<?= Security::h($wfStatus['state_color']) ?>"><?= Security::h($wfStatus['state_name']) ?></span><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($wfStatus): ?>
      <div class="form-hint" style="margin-top:0">Workflow: <strong><?= Security::h($wfStatus['workflow_name']) ?></strong> · updated <?= View::timeAgo($wfStatus['updated_at']) ?><?= $wfStatus['updated_by_name'] ? ' by ' . Security::h($wfStatus['updated_by_name']) : '' ?></div>

      <?php if ($wfCanEdit && $wfTransitions): ?>
      <form method="POST" action="/workflow/transition" style="margin-top:12px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="entity_type" value="<?= Security::h($wfType) ?>">
        <input type="hidden" name="entity_id" value="<?= (int)$wfId ?>">
        <div class="form-group" style="margin:0 0 8px"><textarea name="comment" class="form-control" rows="1" placeholder="Comment (optional)"></textarea></div>
        <?php if ($wfEsign): ?>
        <div class="form-group" style="margin:0 0 8px"><label class="form-label"><i class="bi bi-pen"></i> E-signature — confirm your password</label><input type="password" name="esign_password" class="form-control" autocomplete="current-password" required></div>
        <?php endif; ?>
        <div class="form-row" style="flex-wrap:wrap;gap:8px">
          <?php foreach ($wfTransitions as $tr): if (!Workflow::canAct($tr)) continue;
            $k = $tr['to_kind']; $cls = in_array($k,['approved','final'],true) ? 'btn-success' : ($k==='rejected' ? 'btn-danger' : 'btn-primary'); ?>
            <button class="btn btn-sm <?= $cls ?>" type="submit" name="transition_id" value="<?= (int)$tr['id'] ?>"><?= Security::h($tr['action_label']) ?> <i class="bi bi-arrow-right"></i> <?= Security::h($tr['to_name']) ?></button>
          <?php endforeach; ?>
        </div>
        <?php if (!array_filter($wfTransitions, fn($t) => Workflow::canAct($t))): ?>
          <div class="form-hint">Awaiting action from another role for the available transitions.</div>
        <?php endif; ?>
      </form>
      <?php elseif (!$wfTransitions): ?>
        <div class="form-hint" style="margin-top:8px">This is a terminal state — no further transitions.</div>
      <?php endif; ?>

      <?php if ($wfHistory): ?>
        <hr style="border:none;border-top:1px solid var(--border-light);margin:12px 0">
        <ul class="tl">
          <?php foreach (array_slice($wfHistory, 0, 8) as $h): ?>
          <li><span class="tl-dot"><i class="bi bi-dot"></i></span>
            <div class="tl-title"><?= Security::h($h['action_label'] ?: 'Moved') ?><?= $h['to_name'] ? ' → ' . Security::h($h['to_name']) : '' ?><?php if ($h['signed']): ?> <span class="badge badge-green" title="Electronically signed"><i class="bi bi-pen"></i> signed</span><?php endif; ?></div>
            <div class="tl-meta"><?= Security::h($h['user_name'] ?: 'System') ?> · <?= View::timeAgo($h['created_at']) ?></div>
            <?php if ($h['comment']): ?><div class="tl-body"><?= Security::h($h['comment']) ?></div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($wfCanEdit): ?>
        <form method="POST" action="/workflow/remove" style="margin-top:8px" data-confirm="Remove this workflow from the content?"><?= Security::csrfField() ?><input type="hidden" name="entity_type" value="<?= Security::h($wfType) ?>"><input type="hidden" name="entity_id" value="<?= (int)$wfId ?>"><button class="btn btn-sm btn-ghost btn-danger" type="submit"><i class="bi bi-x-circle"></i> Remove workflow</button></form>
      <?php endif; ?>

    <?php elseif ($wfCanEdit && $wfApplicable): ?>
      <form method="POST" action="/workflow/apply" class="form-row" style="gap:8px;margin:0">
        <?= Security::csrfField() ?>
        <input type="hidden" name="entity_type" value="<?= Security::h($wfType) ?>">
        <input type="hidden" name="entity_id" value="<?= (int)$wfId ?>">
        <select name="template_id" class="form-select" style="flex:1"><?php foreach ($wfApplicable as $t): ?><option value="<?= (int)$t['id'] ?>"><?= Security::h($t['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-play-fill"></i> Apply</button>
      </form>
    <?php else: ?>
      <div class="empty-state-sm">No workflow applied.<?= $wfCanEdit ? ' No stateful workflow is available for this space yet.' : '' ?></div>
    <?php endif; ?>
  </div>
</div>
