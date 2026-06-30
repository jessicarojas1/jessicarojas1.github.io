<?php
$breadcrumbs = $breadcrumbs ?? [['Compliance', '/compliance'], ['Crosswalk', null]];
$MAPPING_LABELS = [
    'equivalent' => 'Equivalent', 'partial' => 'Partial',
    'related' => 'Related', 'superset' => 'Superset', 'subset' => 'Subset',
];
$bothSelected = $sourcePkg > 0 && $targetPkg > 0 && $sourcePkg !== $targetPkg;
$srcName = ''; $tgtName = '';
foreach ($packages as $p) {
    if ((int)$p['id'] === $sourcePkg) $srcName = $p['name'];
    if ((int)$p['id'] === $targetPkg) $tgtName = $p['name'];
}
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-diagram-3-fill" style="color:var(--info);margin-right:8px"></i>Control Crosswalk</h1>
    <p class="page-subtitle">Map equivalent control objectives across frameworks (e.g. CMMC ↔ NIST 800-171 ↔ ISO 27001).</p>
  </div>
  <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Compliance</a>
</div>

<div class="card" style="margin-bottom:16px">
  <form method="GET" action="/compliance/crosswalk" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0">
      <label class="form-label">Source framework</label>
      <select name="source" class="form-control">
        <option value="">Select…</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $sourcePkg ? 'selected' : '' ?>>
            <?= Security::h(($p['standard_code'] ? $p['standard_code'] . ' — ' : '') . $p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="padding-bottom:10px"><i class="bi bi-arrow-left-right" style="color:var(--text-muted)"></i></div>
    <div class="form-group" style="margin:0">
      <label class="form-label">Target framework</label>
      <select name="target" class="form-control">
        <option value="">Select…</option>
        <?php foreach ($packages as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $targetPkg ? 'selected' : '' ?>>
            <?= Security::h(($p['standard_code'] ? $p['standard_code'] . ' — ' : '') . $p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-diagram-3"></i> View Crosswalk</button>
  </form>
</div>

<?php if (!$bothSelected): ?>
  <div class="card">
    <div class="empty-state-sm">
      <i class="bi bi-diagram-3"></i>
      <p>Select two different frameworks above to view and author their control crosswalk.</p>
    </div>
  </div>
<?php else: ?>

  <div class="card" style="margin-bottom:16px;display:flex;align-items:center;gap:10px">
    <i class="bi bi-bullseye" style="font-size:20px;color:var(--primary)"></i>
    <div>
      <strong><?= (int)$mappedCount ?></strong> of <strong><?= (int)($pagination['total'] ?? 0) ?></strong>
      <?= Security::h($srcName) ?> controls have at least one mapping to <?= Security::h($tgtName) ?>.
    </div>
  </div>

  <?php if ($canEdit): ?>
  <div class="card" style="margin-bottom:16px">
    <h3 class="card-title" style="margin-bottom:12px"><i class="bi bi-plus-circle"></i> Add mapping</h3>
    <form method="POST" action="/compliance/crosswalk/map" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <?= Security::csrfField() ?>
      <input type="hidden" name="source_pkg" value="<?= $sourcePkg ?>">
      <input type="hidden" name="target_pkg" value="<?= $targetPkg ?>">
      <div class="form-group" style="margin:0;min-width:220px">
        <label class="form-label"><?= Security::h($srcName) ?> control</label>
        <select name="source_obj_id" class="form-control" required>
          <option value="">Select…</option>
          <?php foreach ($allSourceControls as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= Security::h($c['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:260px">
        <label class="form-label"><?= Security::h($tgtName) ?> control</label>
        <select name="target_obj_id" class="form-control" required>
          <option value="">Select…</option>
          <?php foreach ($targetControls as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= Security::h($c['code'] . ' — ' . mb_strimwidth((string)$c['title'], 0, 50, '…')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Type</label>
        <select name="mapping_type" class="form-control">
          <?php foreach ($MAPPING_LABELS as $val => $lbl): ?>
            <option value="<?= $val ?>"><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Map</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:22%"><?= Security::h($srcName) ?> control</th>
            <th>Mapped <?= Security::h($tgtName) ?> controls</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sourceControls)): ?>
            <tr><td colspan="2" class="empty-row">
              <div class="empty-state-sm"><i class="bi bi-inbox"></i><p>No controls in this framework.</p></div>
            </td></tr>
          <?php else: foreach ($sourceControls as $sc): $cid = (int)$sc['id']; $maps = $mappingsByControl[$cid] ?? []; ?>
            <tr>
              <td>
                <strong><?= Security::h($sc['code']) ?></strong>
                <div style="font-size:12px;color:var(--text-muted)"><?= Security::h(mb_strimwidth((string)$sc['title'], 0, 70, '…')) ?></div>
              </td>
              <td>
                <?php if (empty($maps)): ?>
                  <span style="color:var(--text-light);font-size:13px">— not mapped —</span>
                <?php else: foreach ($maps as $mp): ?>
                  <span class="tag" style="background:var(--info-subtle);border-color:var(--moderate-border);display:inline-flex;align-items:center;gap:6px;margin:2px 4px 2px 0">
                    <strong><?= Security::h($mp['code']) ?></strong>
                    <span style="font-size:10px;text-transform:uppercase;color:var(--text-muted)"><?= Security::h($MAPPING_LABELS[$mp['type']] ?? $mp['type']) ?></span>
                    <?php if ($canEdit): ?>
                      <form method="POST" action="/compliance/crosswalk/unmap" style="display:inline" data-confirm="Remove this mapping?">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="mapping_id" value="<?= (int)$mp['id'] ?>">
                        <input type="hidden" name="source_pkg" value="<?= $sourcePkg ?>">
                        <input type="hidden" name="target_pkg" value="<?= $targetPkg ?>">
                        <button type="submit" class="btn-unstyled" style="cursor:pointer;color:var(--danger);line-height:1" title="Remove mapping" aria-label="Remove mapping"><i class="bi bi-x-lg" style="font-size:11px"></i></button>
                      </form>
                    <?php endif; ?>
                  </span>
                <?php endforeach; endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($pagination)): ?><?= Pagination::render($pagination, '/compliance/crosswalk', ['source' => $sourcePkg, 'target' => $targetPkg]) ?><?php endif; ?>
  </div>

<?php endif; ?>
