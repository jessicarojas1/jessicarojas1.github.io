<?php
/**
 * Documents module view. Provided by DocumentsController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf, $zone.
 */
use Redoubt\Support\Security;
use Redoubt\Support\Documents;
use Redoubt\Support\ReviewWorkflow;

$title = 'Documents';
$reviewStatus = $reviewStatus ?? [];
$stageClass = ['concept' => 'b-muted', 'wip' => 'b-warn', 'approved' => 'b-ok'];
$navActive = 'documents';
$breadcrumbs = ['Home' => '/app', 'Documents' => null];
require __DIR__ . '/partials/app_header.php';

$zoneLabels = [
    'project' => 'Project', 'subcontractor_shared' => 'Sub-shared', 'company' => 'My Company',
    'customer' => 'Customer/COR', 'contracts' => 'Contracts', 'financial' => 'Financial',
];
$activeZone = $zone ?? null;
$docFields = function (array $d = []) {
    ob_start(); ?>
    <label>Title</label>
    <input type="text" name="title" required maxlength="200" value="<?= Security::h($d['title'] ?? '') ?>">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div><label>Zone</label>
        <select name="zone">
          <?php foreach (Documents::ZONES as $z): ?>
            <option value="<?= $z ?>"<?= ($d['zone'] ?? 'project') === $z ? ' selected' : '' ?>><?= Security::h($z) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Company scope (ids, comma-sep; blank = all)</label>
        <input type="text" name="company_scope" value="<?= Security::h(implode(',', $d['company_scope'] ?? [])) ?>" placeholder="e.g. 7,9">
      </div>
      <div><label>Flags</label>
        <span style="display:inline-flex;gap:12px">
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="cui_marked" style="width:auto"<?= !empty($d['cui_marked']) ? ' checked' : '' ?>> CUI</label>
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="export_controlled" style="width:auto"<?= !empty($d['export_controlled']) ? ' checked' : '' ?>> ITAR/EAR (US-person only)</label>
        </span>
      </div>
    </div>
    <label>SharePoint web URL</label>
    <input type="url" name="web_url" value="<?= Security::h($d['web_url'] ?? '') ?>" placeholder="https://tenant.sharepoint.us/...">
    <label>SharePoint item id (optional, for Graph resolve)</label>
    <input type="text" name="sp_item_id" value="<?= Security::h($d['sp_item_id'] ?? '') ?>">
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Documents</h1>
  <form method="get" action="/app/documents" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Switch</button>
    <a class="btn btn-sm" href="/app/export?type=documents&amp;program_id=<?= (int) $programId ?>">⬇ CSV</a>
  </form>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <span class="perm-key">Zone:</span>
  <a class="btn btn-sm<?= $activeZone === null ? ' btn-primary' : '' ?>" href="/app/documents?program_id=<?= (int) $programId ?>">All</a>
  <?php foreach ($zoneLabels as $z => $zl): ?>
    <a class="btn btn-sm<?= $activeZone === $z ? ' btn-primary' : '' ?>" href="/app/documents?program_id=<?= (int) $programId ?>&amp;zone=<?= $z ?>"><?= Security::h($zl) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!empty($flash) && ($flash['type'] ?? '') === 'error'): ?>
<div class="card" style="border-color:var(--risk)"><span class="badge b-risk">Upload rejected</span> <?= Security::h((string) $flash['text']) ?></div>
<?php endif; ?>

<?php if ($can['create']): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:700">➕ Register / upload document</summary>
    <form method="post" action="/app/documents" enctype="multipart/form-data" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="register">
      <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <?= $docFields() ?>
      <label>Upload a file (optional — e.g. a diagram; png, jpg, gif, webp, svg, pdf, txt, csv, drawio, vsdx, docx, xlsx, pptx; max 25&nbsp;MB)</label>
      <input type="file" name="file" accept=".png,.jpg,.jpeg,.gif,.webp,.svg,.pdf,.txt,.csv,.drawio,.vsdx,.docx,.xlsx,.pptx">
      <p class="perm-key" style="margin:6px 0 0">Field reference: <strong>file</strong> — a single uploaded file stored in the portal. Leave blank to register a SharePoint link only. Stored outside the web root under a randomized name; served only through the authorized download endpoint.</p>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Register / upload</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?>
    <div class="empty-state-sm">No documents visible in this view.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Title</th><th>Zone</th><th>Markings</th><th>Review</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $d): $cmts = $comments[$d['id']] ?? []; ?>
        <tr id="doc-<?= (int) $d['id'] ?>">
          <td>
            <strong><?= Security::h($d['title'] ?: '(untitled)') ?></strong>
            <?php if (!empty($d['has_file'])): ?>
              <div class="perm-key">📎 <?= Security::h((string) ($d['original_name'] ?? 'file')) ?><?php if (!empty($d['size_bytes'])): ?> · <?= (int) ceil($d['size_bytes'] / 1024) ?> KB<?php endif; ?></div>
            <?php endif; ?>
            <?php if (!empty($d['previewable'])): ?>
              <a href="/app/documents/preview?program_id=<?= (int) $programId ?>&amp;id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener" title="Open full image in a new tab">
                <img src="/app/documents/preview?program_id=<?= (int) $programId ?>&amp;id=<?= (int) $d['id'] ?>" alt="Preview of <?= Security::h((string) ($d['original_name'] ?? 'image')) ?>" loading="lazy" style="max-width:140px;max-height:100px;border:1px solid var(--line);border-radius:6px;margin-top:6px;display:block">
              </a>
            <?php endif; ?>
            <?php if ($d['company_scope']): ?><div class="perm-key">companies: <?= Security::h(implode(', ', $d['company_scope'])) ?></div><?php endif; ?>
          </td>
          <td><span class="badge b-muted"><?= Security::h($zoneLabels[$d['zone']] ?? $d['zone']) ?></span></td>
          <td>
            <?php if ($d['cui_marked']): ?><span class="badge b-warn">CUI</span> <?php endif; ?>
            <?php if ($d['export_controlled']): ?><span class="badge b-warn">ITAR/EAR</span><?php endif; ?>
            <?php if (!$d['cui_marked'] && !$d['export_controlled']): ?><span class="perm-key">—</span><?php endif; ?>
          </td>
          <td>
            <?php $rs = $reviewStatus[$d['id']] ?? null; ?>
            <?php if ($rs !== null): ?>
              <a href="/app/reviews?program_id=<?= (int) $programId ?>" title="Open in Review &amp; Approval">
                <span class="badge <?= $stageClass[$rs['stage']] ?? 'b-muted' ?>"><?= Security::h(ReviewWorkflow::STAGES[$rs['stage']] ?? $rs['stage']) ?></span>
              </a>
              <?php if ($rs['stage'] !== 'approved'): ?>
                <span class="perm-key">at <?= Security::h(ReviewWorkflow::STEPS[$rs['current_step']] ?? $rs['current_step']) ?></span>
              <?php elseif ($can['review']): ?>
                <form method="post" action="/app/documents" style="display:inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="start_review">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm" type="submit" title="Start a new approval cycle">Re-review</button>
                </form>
              <?php endif; ?>
            <?php elseif ($can['review']): ?>
              <form method="post" action="/app/documents" style="display:inline">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="start_review">
                <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="btn btn-sm" type="submit">Submit for review</button>
              </form>
            <?php else: ?>
              <span class="perm-key">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if (!empty($d['has_file'])): ?><a class="btn btn-sm btn-primary" href="/app/documents/open?program_id=<?= (int) $programId ?>&amp;id=<?= (int) $d['id'] ?>">Download</a>
              <?php elseif ($d['web_url']): ?><a class="btn btn-sm btn-primary" href="/app/documents/open?program_id=<?= (int) $programId ?>&amp;id=<?= (int) $d['id'] ?>">Open</a><?php endif; ?>
              <?php if ($can['edit']): ?>
                <form method="post" action="/app/documents">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ($can['edit']): ?>
        <tr><td colspan="5" style="padding-top:0">
          <details>
            <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Edit metadata</summary>
            <form method="post" action="/app/documents" style="margin-top:10px">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <?= $docFields($d) ?>
              <div style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit">Save</button></div>
            </form>
          </details>
        </td></tr>
        <?php endif; ?>
        <tr><td colspan="5" style="padding-top:0">
          <details<?= $cmts !== [] ? ' open' : '' ?>>
            <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">💬 Comments (<?= count($cmts) ?>)</summary>
            <div style="margin-top:8px">
              <?php if ($cmts === []): ?><div class="empty-state-sm">No comments yet.</div><?php endif; ?>
              <?php foreach ($cmts as $c): ?>
                <div class="feed-row" style="padding:6px 0;align-items:flex-start">
                  <span class="perm-key" style="width:150px;flex:0 0 auto"><strong><?= Security::h($c['author']) ?></strong><br><?= Security::h($c['at']) ?></span>
                  <span class="feed-title" style="white-space:pre-wrap"><?= Security::h($c['body']) ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (!empty($can['comment'])): ?>
                <form method="post" action="/app/documents" style="margin-top:8px;display:flex;gap:8px;align-items:flex-end">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="comment">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <div style="flex:1"><label style="font-size:12px">Add a comment</label><textarea name="body" rows="2" maxlength="4000" required placeholder="Share a note or question about this document…"></textarea></div>
                  <button class="btn btn-sm btn-primary" type="submit">Comment</button>
                </form>
              <?php endif; ?>
            </div>
          </details>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
