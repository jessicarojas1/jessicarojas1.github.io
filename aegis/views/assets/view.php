<?php
// $asset, $linkedRisks, $allRisks, $users are set by AssetController::view()

$criticality = $asset['criticality'] ?? 'low';
$critColors  = [
    'critical' => ['#fef2f2','#dc2626'],
    'high'     => ['#fff7ed','#ea580c'],
    'medium'   => ['#fffbeb','#d97706'],
    'low'      => ['#f0fdf4','#16a34a'],
];
[$critBg, $critColor] = $critColors[$criticality] ?? ['#f4f4f5','#71717a'];

$statusColors = [
    'active'         => ['#f0fdf4','#16a34a'],
    'decommissioned' => ['#f9fafb','#71717a'],
    'maintenance'    => ['#fffbeb','#d97706'],
];
[$sBg, $sColor] = $statusColors[$asset['status'] ?? ''] ?? ['#f4f4f5','#71717a'];

$typeLabels = [
    'server'      => 'Server',
    'workstation' => 'Workstation',
    'application' => 'Application',
    'database'    => 'Database',
    'network'     => 'Network Device',
    'cloud'       => 'Cloud Resource',
    'mobile'      => 'Mobile Device',
    'iot'         => 'IoT Device',
    'saas'        => 'SaaS',
];
$typeIcons = [
    'server'      => 'bi-server',
    'workstation' => 'bi-laptop',
    'application' => 'bi-window',
    'database'    => 'bi-database',
    'network'     => 'bi-diagram-3',
    'cloud'       => 'bi-cloud-fill',
    'mobile'      => 'bi-phone',
    'iot'         => 'bi-cpu',
    'saas'        => 'bi-box-arrow-up-right',
];

// Decode tags JSON
$tags = [];
if (!empty($asset['tags'])) {
    $decoded = json_decode($asset['tags'], true);
    if (is_array($decoded)) $tags = $decoded;
}

function riskScoreLevel(int $score): string {
    return $score > 14 ? 'Critical' : ($score > 9 ? 'High' : ($score > 4 ? 'Medium' : 'Low'));
}
?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Asset updated successfully.</div>
<?php endif; ?>
<?php if (!empty($_GET['linked'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk linked to asset.</div>
<?php endif; ?>
<?php if (!empty($_GET['unlinked'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk unlinked from asset.</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
      <h1 class="page-title" style="margin:0">
        <i class="bi <?= $typeIcons[$asset['asset_type'] ?? ''] ?? 'bi-hdd' ?>" style="margin-right:8px;"></i>
        <?= Security::h($asset['name']) ?>
      </h1>
      <?php if (!empty($asset['asset_code'])): ?>
        <span class="badge" style="background:var(--info-subtle);color:var(--info);border:1px solid var(--border);font-family:monospace;font-size:13px;padding:4px 10px"><?= Security::h($asset['asset_code']) ?></span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle">
      <?= Security::h($typeLabels[$asset['asset_type'] ?? ''] ?? ucfirst($asset['asset_type'] ?? '')) ?>
      · Owner: <?= Security::h($asset['owner_name'] ?? 'Unassigned') ?>
      <?php if (!empty($asset['location'])): ?> · <?= Security::h($asset['location']) ?><?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <span style="background:<?= $critColor ?>18;color:<?= $critColor ?>;border:1px solid <?= $critColor ?>33;padding:4px 14px;border-radius:99px;font-size:12px;font-weight:600;">
      <?= ucfirst($criticality) ?> Criticality
    </span>
    <span style="background:<?= $sColor ?>18;color:<?= $sColor ?>;border:1px solid <?= $sColor ?>33;padding:4px 14px;border-radius:99px;font-size:12px;font-weight:600;">
      <?= ucfirst(Security::h($asset['status'] ?? '')) ?>
    </span>
    <a href="/assets" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">

  <!-- ── Left column: details ─────────────────────────────────────────────── -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Asset metadata card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-info-circle"></i> Asset Details</h3>
      </div>
      <div class="card-body">
        <dl style="display:grid;grid-template-columns:180px 1fr;gap:0;margin:0;">
          <?php
          $rows = [
            ['Asset Type',       $typeLabels[$asset['asset_type'] ?? ''] ?? ucfirst($asset['asset_type'] ?? '—')],
            ['Criticality',      null], // rendered manually
            ['Classification',   $asset['classification'] ? ucfirst(str_replace('_',' ',$asset['classification'])) : '—'],
            ['Status',           null], // rendered manually
            ['Owner',            $asset['owner_name'] ?? '—'],
            ['Location',         $asset['location'] ?? '—'],
            ['IP Address',       $asset['ip_address'] ?? '—'],
            ['Hostname / FQDN',  $asset['hostname'] ?? '—'],
            ['Vendor',           $asset['vendor'] ?? '—'],
            ['Version / Model',  $asset['version'] ?? '—'],
            ['Last Scanned',     !empty($asset['last_scanned']) ? date('M j, Y', strtotime($asset['last_scanned'])) : '—'],
            ['Created By',       $asset['created_by_name'] ?? '—'],
            ['Created At',       !empty($asset['created_at']) ? date('M j, Y g:i A', strtotime($asset['created_at'])) : '—'],
            ['Last Updated',     !empty($asset['updated_at']) ? date('M j, Y g:i A', strtotime($asset['updated_at'])) : '—'],
          ];
          foreach ($rows as $i => [$label, $value]):
            $bg = $i % 2 === 0 ? 'var(--bg-secondary)' : 'var(--card-bg)';
          ?>
            <dt style="background:<?= $bg ?>;padding:10px 12px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--border-light);">
              <?= $label ?>
            </dt>
            <dd style="background:<?= $bg ?>;padding:10px 16px;font-size:13px;margin:0;border-bottom:1px solid var(--border-light);">
              <?php if ($label === 'Criticality'): ?>
                <span style="background:<?= $critColor ?>18;color:<?= $critColor ?>;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;"><?= ucfirst($criticality) ?></span>
              <?php elseif ($label === 'Status'): ?>
                <span style="background:<?= $sColor ?>18;color:<?= $sColor ?>;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;"><?= ucfirst(Security::h($asset['status'] ?? '')) ?></span>
              <?php else: ?>
                <?= Security::h((string)$value) ?>
              <?php endif; ?>
            </dd>
          <?php endforeach; ?>

          <!-- Description -->
          <dt style="padding:10px 12px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;background:var(--bg-secondary);">Description</dt>
          <dd style="padding:10px 16px;font-size:13px;margin:0;background:var(--bg-secondary);">
            <?= !empty($asset['description']) ? nl2br(Security::h($asset['description'])) : '<span style="color:var(--text-light);">—</span>' ?>
          </dd>

          <!-- Tags -->
          <dt style="padding:10px 12px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">Tags</dt>
          <dd style="padding:10px 16px;font-size:13px;margin:0;">
            <?php if ($tags): foreach ($tags as $tag): ?>
              <span style="display:inline-block;background:rgba(79,70,229,.08);color:var(--primary);border-radius:99px;padding:2px 10px;font-size:11px;font-weight:500;margin:2px 4px 2px 0;"><?= Security::h($tag) ?></span>
            <?php endforeach; else: ?>
              <span style="color:var(--text-light);">—</span>
            <?php endif; ?>
          </dd>
        </dl>
      </div>
    </div>

    <!-- Edit panel (collapsible, policy.write only) -->
    <?php if (Auth::can('asset.edit')): ?>
    <div class="card">
      <div class="card-header" style="cursor:pointer;" data-toggle-class="d-none" data-target="#editPanel">
        <h3 class="card-title"><i class="bi bi-pencil-square"></i> Edit Asset</h3>
        <span style="font-size:12px;color:var(--text-muted);">Click to expand / collapse</span>
      </div>
      <div id="editPanel" class="d-none">
        <div class="card-body">
          <form method="POST" action="/assets/<?= (int)$asset['id'] ?>/update">
            <?= Security::csrfField() ?>

            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:2;">
                <label class="form-label required">Name</label>
                <input type="text" name="name" class="form-control" value="<?= Security::h($asset['name']) ?>" required>
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Asset Type</label>
                <select name="asset_type" class="form-control">
                  <?php foreach ($typeLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($asset['asset_type'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:1;">
                <label class="form-label">Criticality</label>
                <select name="criticality" class="form-control">
                  <?php foreach (['critical','high','medium','low'] as $c): ?>
                    <option value="<?= $c ?>" <?= ($asset['criticality'] === $c) ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Classification</label>
                <select name="classification" class="form-control">
                  <option value="">None</option>
                  <?php foreach (['public','internal','confidential','restricted','top_secret'] as $c): ?>
                    <option value="<?= $c ?>" <?= ($asset['classification'] === $c) ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$c)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                  <?php foreach (['active'=>'Active','maintenance'=>'Maintenance','decommissioned'=>'Decommissioned'] as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($asset['status'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:1;">
                <label class="form-label">Owner</label>
                <select name="owner_id" class="form-control">
                  <option value="">Unassigned</option>
                  <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((int)$asset['owner_id'] === (int)$u['id']) ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Location</label>
                <input type="text" name="location" class="form-control" value="<?= Security::h($asset['location'] ?? '') ?>">
              </div>
            </div>

            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:1;">
                <label class="form-label">IP Address</label>
                <input type="text" name="ip_address" class="form-control" value="<?= Security::h($asset['ip_address'] ?? '') ?>">
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Hostname</label>
                <input type="text" name="hostname" class="form-control" value="<?= Security::h($asset['hostname'] ?? '') ?>">
              </div>
            </div>

            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:1;">
                <label class="form-label">Vendor</label>
                <input type="text" name="vendor" class="form-control" value="<?= Security::h($asset['vendor'] ?? '') ?>">
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Version</label>
                <input type="text" name="version" class="form-control" value="<?= Security::h($asset['version'] ?? '') ?>">
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Last Scanned</label>
                <input type="date" name="last_scanned" class="form-control" value="<?= Security::h($asset['last_scanned'] ?? '') ?>">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="3"><?= Security::h($asset['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
              <label class="form-label">Tags <span style="font-size:12px;font-weight:400;color:var(--text-muted);">(comma-separated)</span></label>
              <input type="text" name="tags" class="form-control" value="<?= Security::h(implode(', ', $tags)) ?>">
            </div>

            <div style="display:flex;gap:12px;margin-top:8px;">
              <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
              <button type="button" class="btn btn-ghost" data-add-class="d-none" data-target="#editPanel">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Right column ─────────────────────────────────────────────────────── -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Linked Risks card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-shield-exclamation"></i> Linked Risks</h3>
        <?php if (Auth::can('asset.edit')): ?>
          <button class="btn btn-primary btn-sm" data-show-modal="linkRiskModal">
            <i class="bi bi-plus-lg"></i> Link Risk
          </button>
        <?php endif; ?>
      </div>
      <div class="card-body p0">
        <?php if ($linkedRisks): ?>
          <table class="table" style="font-size:13px;">
            <thead>
              <tr>
                <th>Risk</th>
                <th style="text-align:center;">Score</th>
                <th>Status</th>
                <?php if (Auth::can('asset.edit')): ?><th style="width:50px;"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linkedRisks as $r):
                $rScore = (int)$r['inherent_score'];
                $rLevel = riskScoreLevel($rScore);
                $rLevelColors = ['Critical'=>'var(--danger)','High'=>'var(--orange)','Medium'=>'var(--warning)','Low'=>'var(--primary-light)'];
                $rLc = $rLevelColors[$rLevel] ?? '#71717a';
              ?>
                <tr>
                  <td>
                    <a href="/risk/<?= (int)$r['id'] ?>" class="table-link">
                      <?= Security::h($r['title']) ?>
                    </a>
                    <?php if (!empty($r['risk_id'])): ?>
                      <div style="font-size:11px;color:var(--text-muted);"><?= Security::h($r['risk_id']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:center;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:<?= $rLc ?>1a;color:<?= $rLc ?>;font-weight:700;font-size:13px;">
                      <?= $rScore ?>
                    </span>
                  </td>
                  <td><span class="badge badge-<?= Security::h($r['status']) ?>"><?= ucfirst(Security::h($r['status'])) ?></span></td>
                  <?php if (Auth::can('asset.edit')): ?>
                    <td>
                      <form method="POST" action="/assets/<?= (int)$asset['id'] ?>/unlink-risk/<?= (int)$r['id'] ?>"
                            data-confirm="Unlink this risk from the asset?">
                        <?= Security::csrfField() ?>
                        <button type="submit" class="btn btn-ghost btn-sm" title="Unlink risk">
                          <i class="bi bi-x-lg" style="color:var(--danger);"></i>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty-state-sm" style="padding:24px;">
            <i class="bi bi-shield-check" style="font-size:28px;color:var(--text-light);"></i>
            <p style="color:var(--text-muted);margin:8px 0 0;">No risks linked to this asset yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Asset summary card -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-bar-chart"></i> Quick Summary</h4>
      </div>
      <div class="card-body" style="font-size:13px;">
        <div class="detail-row">
          <span>Total linked risks</span>
          <strong><?= count($linkedRisks) ?></strong>
        </div>
        <?php
        $risksByLevel = ['Critical'=>0,'High'=>0,'Medium'=>0,'Low'=>0];
        foreach ($linkedRisks as $r) {
            $l = riskScoreLevel((int)$r['inherent_score']);
            $risksByLevel[$l]++;
        }
        foreach ($risksByLevel as $lvl => $cnt):
          if ($cnt === 0) continue;
          $lvlColors = ['Critical'=>'var(--danger)','High'=>'var(--orange)','Medium'=>'var(--warning)','Low'=>'var(--primary-light)'];
        ?>
          <div class="detail-row">
            <span style="color:<?= $lvlColors[$lvl] ?? 'var(--text-muted)' ?>;">■ <?= $lvl ?></span>
            <strong><?= $cnt ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<!-- Link Risk Modal -->
<?php if (Auth::can('asset.edit')): ?>
<div id="linkRiskModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;align-items:center;justify-content:center;">
  <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;width:100%;max-width:520px;box-shadow:0 8px 32px rgba(0,0,0,.2);padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--border);">
      <h3 style="margin:0;font-size:16px;font-weight:600;"><i class="bi bi-shield-plus" style="margin-right:8px;color:var(--primary);"></i> Link Risk to Asset</h3>
      <button data-close-modal="linkRiskModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/assets/<?= (int)$asset['id'] ?>/link-risk">
      <?= Security::csrfField() ?>
      <div style="padding:24px;">
        <div class="form-group">
          <label class="form-label">Select Risk</label>
          <select name="risk_id" class="form-control" required>
            <option value="">— Choose a risk —</option>
            <?php foreach ($allRisks as $r): ?>
              <option value="<?= (int)$r['id'] ?>">
                <?php if (!empty($r['risk_id'])): ?>[<?= Security::h($r['risk_id']) ?>] <?php endif; ?>
                <?= Security::h($r['title']) ?>
                (Score: <?= (int)$r['inherent_score'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($allRisks)): ?>
            <p style="margin-top:8px;font-size:12px;color:var(--text-muted);">All available risks are already linked to this asset.</p>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;border-top:1px solid var(--border);background:var(--bg-secondary);">
        <button type="button" data-close-modal="linkRiskModal" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-primary" <?= empty($allRisks) ? 'disabled' : '' ?>>
          <i class="bi bi-link-45deg"></i> Link Risk
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.d-none { display: none !important; }
</style>
