<?php
/** Review & approval workflow. In scope: $user,$NONCE,$programs,$programId,$items,$history,$perms,$csrf,$stage. */
use Redoubt\Support\Security;
use Redoubt\Support\ReviewWorkflow;

$title = 'Reviews';
$navActive = 'reviews';
$breadcrumbs = ['Home' => '/app', 'Reviews' => null];
require __DIR__ . '/partials/app_header.php';

$stageBadge = static function (string $s): string {
    $map = ['concept' => 'b-muted', 'wip' => 'b-warn', 'approved' => 'b-ok'];
    return '<span class="badge ' . ($map[$s] ?? 'b-muted') . '">' . Security::h(ReviewWorkflow::STAGES[$s] ?? $s) . '</span>';
};
// Routing chain visual: author → peer → qa → exec, highlighting the current step.
$chain = static function (array $it): string {
    $order = ['author', 'peer', 'qa', 'exec'];
    $labels = ['author' => 'Author', 'peer' => 'Peer*', 'qa' => 'QA', 'exec' => 'Exec'];
    $curIdx = array_search($it['current_step'], $order, true);
    $done = $it['stage'] === 'approved';
    $out = '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12px">';
    foreach ($order as $i => $k) {
        $isCur = !$done && $it['current_step'] === $k;
        $isPast = $done || ($curIdx !== false && $i < $curIdx);
        $color = $isCur ? 'var(--warn)' : ($isPast ? 'var(--ok)' : 'var(--muted)');
        $dot = $isCur ? '◉' : ($isPast ? '●' : '○');
        $out .= '<span style="color:' . $color . ';font-weight:' . ($isCur ? '700' : '500') . '">' . $dot . ' ' . Security::h($labels[$k]) . '</span>';
        if ($i < 3) { $out .= '<span style="color:var(--muted)">→</span>'; }
    }
    return $out . '</span>';
};
$actLabel = ['submit' => 'Submit for review', 'peer_approve' => 'Peer: Approve', 'peer_skip' => 'Peer: Skip (optional)',
    'qa_approve' => 'QA: Approve', 'qa_reject' => 'QA: Reject', 'exec_approve' => 'Authorize ✔', 'exec_reject' => 'Exec: Reject', 'reopen' => 'Reopen'];
$actPerm = ['submit' => 'submit', 'peer_approve' => 'peer', 'peer_skip' => 'peer', 'qa_approve' => 'qa', 'qa_reject' => 'qa', 'exec_approve' => 'authorize', 'exec_reject' => 'authorize', 'reopen' => 'submit'];
$needsComment = ['qa_reject', 'exec_reject'];
$base = '/app/reviews?program_id=' . (int) $programId;
?>
<div class="page-header">
  <h1 class="page-title">Review &amp; Approval</h1>
  <form method="get" action="/app/reviews" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <span class="perm-key">Stage:</span>
  <a class="btn btn-sm<?= $stage === null ? ' btn-primary' : '' ?>" href="<?= $base ?>">All</a>
  <?php foreach (ReviewWorkflow::STAGES as $k => $lbl): ?>
    <a class="btn btn-sm<?= $stage === $k ? ' btn-primary' : '' ?>" href="<?= $base ?>&amp;stage=<?= $k ?>"><?= Security::h($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($perms['submit']): ?>
<div class="card"><details><summary style="cursor:pointer;font-weight:700">➕ New review item</summary>
  <form method="post" action="/app/reviews" style="margin-top:12px">
    <?= Security::csrfField() ?><input type="hidden" name="action" value="create"><input type="hidden" name="program_id" value="<?= (int) $programId ?>">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:220px"><label>Title</label><input type="text" name="title" required maxlength="200"></div>
      <div><label>Kind</label><select name="kind"><?php foreach (ReviewWorkflow::KINDS as $k): ?><option value="<?= $k ?>"><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
    </div>
    <label>Description</label><textarea name="description" rows="2"></textarea>
    <label>Reference URL (optional)</label><input type="url" name="ref_url" placeholder="https://…">
    <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Create (Concept)</button></div>
  </form>
</details></div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?><div class="empty-state-sm">No review items in this view.</div>
  <?php else: foreach ($items as $it): $actions = ReviewWorkflow::availableActions($it); ?>
    <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
        <div>
          <strong><?= Security::h($it['title']) ?></strong> <span class="perm-key"><?= Security::h($it['kind']) ?></span>
          <div style="margin-top:4px"><?= $stageBadge((string) $it['stage']) ?> &nbsp; <?= $chain($it) ?></div>
          <div class="perm-key" style="margin-top:3px">author: <?= Security::h($it['author'] ?: '—') ?><?php if (!empty($it['document_id'])): ?> · 📄 <a href="/app/documents/open?program_id=<?= (int) $programId ?>&amp;id=<?= (int) $it['document_id'] ?>"><?= Security::h($it['doc_title'] ?: 'linked document') ?></a><?php endif; ?><?php if ($it['ref_url']): ?> · <a href="<?= Security::h($it['ref_url']) ?>" target="_blank" rel="noopener">reference ↗</a><?php endif; ?></div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start">
          <?php foreach ($actions as $a): if (empty($perms[$actPerm[$a]])) { continue; } ?>
            <form method="post" action="/app/reviews" style="display:flex;gap:4px;align-items:center">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="<?= Security::h($a) ?>">
              <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
              <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <?php if (in_array($a, $needsComment, true)): ?><input type="text" name="comment" placeholder="reason" style="width:120px"><?php endif; ?>
              <button class="btn btn-sm <?= in_array($a, ['exec_approve', 'submit', 'qa_approve', 'peer_approve'], true) ? 'btn-primary' : '' ?>" type="submit"><?= Security::h($actLabel[$a]) ?></button>
            </form>
          <?php endforeach; ?>
          <?php if ($perms['submit'] && ($it['created_by'] === ($user['id'] ?? -1) || $perms['authorize'])): ?>
            <form method="post" action="/app/reviews"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($it['description']) || !empty($history[$it['id']])): ?>
      <details style="margin-top:8px"><summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Details &amp; history</summary>
        <?php if (!empty($it['description'])): ?><div style="margin-top:8px;white-space:pre-wrap"><?= Security::h($it['description']) ?></div><?php endif; ?>
        <?php foreach ($history[$it['id']] ?? [] as $h): ?>
          <div class="feed-row" style="padding:4px 0">
            <span class="perm-key" style="width:130px"><?= Security::h((string) $h['at']) ?></span>
            <span class="feed-title"><strong><?= Security::h((string) $h['step']) ?></strong> <?= Security::h((string) $h['action']) ?> — <?= Security::h((string) ($h['actor'] ?: 'system')) ?><?php if (!empty($h['comment'])): ?> · “<?= Security::h((string) $h['comment']) ?>”<?php endif; ?></span>
          </div>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
