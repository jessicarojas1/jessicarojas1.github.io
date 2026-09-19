<?php
/**
 * Documents module view. Provided by DocumentsController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf, $zone.
 */
use Redoubt\Support\Security;
use Redoubt\Support\Documents;

$title = 'Documents';
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
  </form>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <span class="perm-key">Zone:</span>
  <a class="btn btn-sm<?= $activeZone === null ? ' btn-primary' : '' ?>" href="/app/documents?program_id=<?= (int) $programId ?>">All</a>
  <?php foreach ($zoneLabels as $z => $zl): ?>
    <a class="btn btn-sm<?= $activeZone === $z ? ' btn-primary' : '' ?>" href="/app/documents?program_id=<?= (int) $programId ?>&amp;zone=<?= $z ?>"><?= Security::h($zl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($can['create']): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:700">➕ Register document</summary>
    <form method="post" action="/app/documents" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="register">
      <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <?= $docFields() ?>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Register</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?>
    <div class="empty-state-sm">No documents visible in this view.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Title</th><th>Zone</th><th>Markings</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $d): ?>
        <tr>
          <td>
            <strong><?= Security::h($d['title'] ?: '(untitled)') ?></strong>
            <?php if ($d['company_scope']): ?><div class="perm-key">companies: <?= Security::h(implode(', ', $d['company_scope'])) ?></div><?php endif; ?>
          </td>
          <td><span class="badge b-muted"><?= Security::h($zoneLabels[$d['zone']] ?? $d['zone']) ?></span></td>
          <td>
            <?php if ($d['cui_marked']): ?><span class="badge b-warn">CUI</span> <?php endif; ?>
            <?php if ($d['export_controlled']): ?><span class="badge b-warn">ITAR/EAR</span><?php endif; ?>
            <?php if (!$d['cui_marked'] && !$d['export_controlled']): ?><span class="perm-key">—</span><?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if ($d['web_url']): ?><a class="btn btn-sm btn-primary" href="/app/documents/open?program_id=<?= (int) $programId ?>&amp;id=<?= (int) $d['id'] ?>">Open</a><?php endif; ?>
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
        <tr><td colspan="4" style="padding-top:0">
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
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
