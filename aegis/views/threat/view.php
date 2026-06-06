<?php
$breadcrumbs = $breadcrumbs ?? [['Threat Register', '/threat'], ['Threat', null]];
// $threat, $linkedRisks, $unlinkdRisks, $users provided by ThreatController::view()

$catConfig = [
    'people'     => ['label' => 'People',     'color' => 'var(--secondary)', 'bg' => 'rgba(55,65,81,.05)', 'icon' => 'bi-person-fill'],
    'process'    => ['label' => 'Process',    'color' => '#2563eb', 'bg' => '#eff6ff', 'icon' => 'bi-diagram-3-fill'],
    'technology' => ['label' => 'Technology', 'color' => 'var(--primary)', 'bg' => 'rgba(11,97,4,.06)', 'icon' => 'bi-cpu-fill'],
    'natural'    => ['label' => 'Natural',    'color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'bi-cloud-lightning-rain-fill'],
    'regulatory' => ['label' => 'Regulatory', 'color' => '#ea580c', 'bg' => '#fff7ed', 'icon' => 'bi-file-earmark-ruled-fill'],
    'financial'  => ['label' => 'Financial',  'color' => '#ca8a04', 'bg' => '#fefce8', 'icon' => 'bi-currency-dollar'],
];

$statusConfig = [
    'active'    => ['label' => 'Active',    'color' => '#16a34a', 'bg' => '#f0fdf4'],
    'mitigated' => ['label' => 'Mitigated', 'color' => '#2563eb', 'bg' => '#eff6ff'],
    'accepted'  => ['label' => 'Accepted',  'color' => '#d97706', 'bg' => '#fffbeb'],
    'retired'   => ['label' => 'Retired',   'color' => '#71717a', 'bg' => '#f9fafb'],
];

$cat     = $threat['category'] ?? 'technology';
$catCfg  = $catConfig[$cat] ?? ['label' => ucfirst($cat), 'color' => '#71717a', 'bg' => '#f4f4f5', 'icon' => 'bi-question'];
$status  = $threat['status'] ?? 'active';
$stCfg   = $statusConfig[$status] ?? ['label' => ucfirst($status), 'color' => '#71717a', 'bg' => '#f9fafb'];

$likelihood = (int)($threat['likelihood'] ?? 0);
$impact     = (int)($threat['impact'] ?? 0);
$score      = $likelihood * $impact;

function threatViewScoreColor(int $score): string {
    if ($score <= 4)  return '#16a34a';
    if ($score <= 9)  return '#d97706';
    if ($score <= 16) return '#ea580c';
    return '#dc2626';
}
function threatViewScoreBg(int $score): string {
    if ($score <= 4)  return '#f0fdf4';
    if ($score <= 9)  return '#fffbeb';
    if ($score <= 16) return '#fff7ed';
    return '#fef2f2';
}
function threatViewScoreLabel(int $score): string {
    if ($score <= 4)  return 'Low';
    if ($score <= 9)  return 'Medium';
    if ($score <= 16) return 'High';
    return 'Critical';
}

$scoreColor = threatViewScoreColor($score);
$scoreBg    = threatViewScoreBg($score);
$scoreLabel = threatViewScoreLabel($score);

$likelihoodLabels = [1=>'Rare',2=>'Unlikely',3=>'Possible',4=>'Likely',5=>'Almost Certain'];
$impactLabels     = [1=>'Negligible',2=>'Minor',3=>'Moderate',4=>'Major',5=>'Catastrophic'];
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Page header -->
<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
      <h1 class="page-title" style="margin:0">
        <i class="bi <?= $catCfg['icon'] ?>" style="margin-right:8px;color:<?= $catCfg['color'] ?>;"></i>
        <?= Security::h($threat['title']) ?>
      </h1>
      <?php if (!empty($threat['threat_number'])): ?>
        <span class="badge" style="background:var(--info-subtle);color:var(--info);border:1px solid var(--border);font-family:monospace;font-size:13px;padding:4px 10px"><?= Security::h($threat['threat_number']) ?></span>
      <?php endif; ?>
    </div>
    <p class="page-subtitle">
      Owner: <?= Security::h($threat['owner_name'] ?? 'Unassigned') ?>
    </p>
  </div>
  <div class="page-actions">
    <span style="background:<?= $catCfg['color'] ?>18;color:<?= $catCfg['color'] ?>;border:1px solid <?= $catCfg['color'] ?>33;padding:4px 14px;border-radius:99px;font-size:12px;font-weight:600;">
      <i class="bi <?= $catCfg['icon'] ?>"></i> <?= $catCfg['label'] ?>
    </span>
    <span style="background:<?= $stCfg['color'] ?>18;color:<?= $stCfg['color'] ?>;border:1px solid <?= $stCfg['color'] ?>33;padding:4px 14px;border-radius:99px;font-size:12px;font-weight:600;">
      <?= $stCfg['label'] ?>
    </span>
    <?php if (Auth::can('risk.write')): ?>
      <button class="btn btn-ghost" data-toggle-class="d-none" data-target="#editPanel">
        <i class="bi bi-pencil-square"></i> Edit
      </button>
    <?php endif; ?>
    <a href="/threats" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

  <!-- ── Left column ───────────────────────────────────────────── -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Details card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-info-circle"></i> Threat Details</h3>
      </div>
      <div class="card-body">
        <dl style="display:grid;grid-template-columns:180px 1fr;gap:0;margin:0;">
          <?php
          $rows = [
              ['Category',    null],
              ['Status',      null],
              ['Source',      $threat['source'] ?? ''],
              ['Owner',       $threat['owner_name'] ?? '—'],
              ['Created By',  $threat['created_by_name'] ?? '—'],
              ['Created',     !empty($threat['created_at']) ? date('M j, Y g:i A', strtotime($threat['created_at'])) : '—'],
              ['Last Updated',!empty($threat['updated_at']) ? date('M j, Y g:i A', strtotime($threat['updated_at'])) : '—'],
          ];
          foreach ($rows as $i => [$label, $value]):
              $bg = $i % 2 === 0 ? 'var(--bg-secondary)' : 'var(--card-bg)';
          ?>
            <dt style="background:<?= $bg ?>;padding:10px 12px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--border-light);">
              <?= $label ?>
            </dt>
            <dd style="background:<?= $bg ?>;padding:10px 16px;font-size:13px;margin:0;border-bottom:1px solid var(--border-light);">
              <?php if ($label === 'Category'): ?>
                <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $catCfg['color'] ?>18;color:<?= $catCfg['color'] ?>;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                  <i class="bi <?= $catCfg['icon'] ?>"></i> <?= $catCfg['label'] ?>
                </span>
              <?php elseif ($label === 'Status'): ?>
                <span style="background:<?= $stCfg['color'] ?>18;color:<?= $stCfg['color'] ?>;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                  <?= $stCfg['label'] ?>
                </span>
              <?php else: ?>
                <?= $value ? Security::h((string)$value) : '<span style="color:var(--text-light);">—</span>' ?>
              <?php endif; ?>
            </dd>
          <?php endforeach; ?>

          <!-- Description -->
          <dt style="padding:10px 12px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;background:var(--bg-secondary);border-bottom:1px solid var(--border-light);">Description</dt>
          <dd style="padding:10px 16px;font-size:13px;margin:0;background:var(--bg-secondary);border-bottom:1px solid var(--border-light);">
            <?= !empty($threat['description'])
                ? nl2br(Security::h($threat['description']))
                : '<span style="color:var(--text-light);">—</span>' ?>
          </dd>

          <!-- Mitigations -->
          <dt style="padding:10px 12px;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">Mitigations</dt>
          <dd style="padding:10px 16px;font-size:13px;margin:0;">
            <?= !empty($threat['mitigations'])
                ? nl2br(Security::h($threat['mitigations']))
                : '<span style="color:var(--text-light);">—</span>' ?>
          </dd>
        </dl>
      </div>
    </div>

    <!-- Edit form (collapsible) -->
    <?php if (Auth::can('risk.write')): ?>
    <div class="card">
      <div id="editPanel" class="d-none">
        <div class="card-header" style="cursor:pointer;" data-add-class="d-none" data-target="#editPanel">
          <h3 class="card-title"><i class="bi bi-pencil-square"></i> Edit Threat</h3>
          <span style="font-size:12px;color:var(--text-muted);">Click header to collapse</span>
        </div>
        <div class="card-body">
          <form method="POST" action="/threats/<?= (int)$threat['id'] ?>/update" id="editThreatForm">
            <?= Security::csrfField() ?>

            <!-- Title -->
            <div class="form-group">
              <label class="form-label required">Title <span style="color:#ef4444;">*</span></label>
              <input type="text" name="title" class="form-control" value="<?= Security::h($threat['title']) ?>" required>
            </div>

            <!-- Category + Status -->
            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:1;">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                  <?php
                  $catOpts = ['people'=>'People','process'=>'Process','technology'=>'Technology','natural'=>'Natural','regulatory'=>'Regulatory','financial'=>'Financial'];
                  foreach ($catOpts as $val => $lbl):
                  ?>
                    <option value="<?= $val ?>" <?= ($threat['category'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                  <?php
                  $stOpts = ['active'=>'Active','mitigated'=>'Mitigated','accepted'=>'Accepted','retired'=>'Retired'];
                  foreach ($stOpts as $val => $lbl):
                  ?>
                    <option value="<?= $val ?>" <?= ($threat['status'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Likelihood + Impact -->
            <div class="form-row" style="display:flex;gap:16px;">
              <div class="form-group" style="flex:1;">
                <label class="form-label">Likelihood</label>
                <select name="likelihood" id="editLikelihood" class="form-control" data-change="updateEditScore">
                  <?php foreach ([1=>'1 — Rare',2=>'2 — Unlikely',3=>'3 — Possible',4=>'4 — Likely',5=>'5 — Almost Certain'] as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ((int)$threat['likelihood'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:1;">
                <label class="form-label">Impact</label>
                <select name="impact" id="editImpact" class="form-control" data-change="updateEditScore">
                  <?php foreach ([1=>'1 — Negligible',2=>'2 — Minor',3=>'3 — Moderate',4=>'4 — Major',5=>'5 — Catastrophic'] as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ((int)$threat['impact'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:0 0 100px;text-align:center;">
                <label class="form-label">Score</label>
                <div id="editScoreDisplay" style="font-size:28px;font-weight:800;line-height:1;padding:6px 0;color:<?= $scoreColor ?>;">
                  <?= $score ?>
                </div>
              </div>
            </div>

            <!-- Source -->
            <div class="form-group">
              <label class="form-label">Source</label>
              <input type="text" name="source" class="form-control" value="<?= Security::h($threat['source'] ?? '') ?>"
                     placeholder="e.g. MITRE ATT&amp;CK, threat intelligence feed">
            </div>

            <!-- Description -->
            <div class="form-group">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="3"><?= Security::h($threat['description'] ?? '') ?></textarea>
            </div>

            <!-- Mitigations -->
            <div class="form-group">
              <label class="form-label">Mitigations</label>
              <textarea name="mitigations" class="form-control" rows="3"><?= Security::h($threat['mitigations'] ?? '') ?></textarea>
            </div>

            <!-- Owner -->
            <div class="form-group">
              <label class="form-label">Owner</label>
              <select name="owner_id" class="form-control">
                <option value="">Unassigned</option>
                <?php foreach ($users as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" <?= ((int)($threat['owner_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
                    <?= Security::h($u['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
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

    <!-- Linked Risks section -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-shield-exclamation"></i> Linked Risks</h3>
        <?php if (Auth::can('risk.write') && !empty($unlinkdRisks)): ?>
          <button class="btn btn-primary btn-sm" data-toggle-class="d-none" data-target="#linkRiskPanel">
            <i class="bi bi-plus-lg"></i> Link Risk
          </button>
        <?php endif; ?>
      </div>

      <?php if (Auth::can('risk.write')): ?>
      <div id="linkRiskPanel" class="d-none" style="padding:16px;border-bottom:1px solid var(--border-light);background:var(--bg-secondary);">
        <form method="POST" action="/threats/<?= (int)$threat['id'] ?>/link-risk" style="display:flex;gap:12px;align-items:flex-end;">
          <?= Security::csrfField() ?>
          <div class="form-group" style="flex:1;margin:0;">
            <label class="form-label" style="margin-bottom:4px;">Select Risk to Link</label>
            <select name="risk_id" class="form-control" required>
              <option value="">— Choose a risk —</option>
              <?php foreach ($unlinkdRisks as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= Security::h($r['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="flex-shrink:0;">
            <i class="bi bi-link-45deg"></i> Link
          </button>
          <button type="button" class="btn btn-ghost btn-sm" style="flex-shrink:0;"
                  data-add-class="d-none" data-target="#linkRiskPanel">Cancel</button>
        </form>
      </div>
      <?php endif; ?>

      <div class="card-body p0">
        <?php if ($linkedRisks): ?>
          <table class="table" style="font-size:13px;">
            <thead>
              <tr>
                <th>Risk</th>
                <th style="text-align:center;">Score</th>
                <th>Status</th>
                <?php if (Auth::can('risk.write')): ?><th style="width:60px;"></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($linkedRisks as $r):
                $rs = (int)($r['inherent_score'] ?? 0);
                if ($rs > 16)     { $rc = '#dc2626'; }
                elseif ($rs > 9)  { $rc = '#ea580c'; }
                elseif ($rs > 4)  { $rc = '#d97706'; }
                else              { $rc = '#16a34a'; }
                $rStColors = ['open'=>'#16a34a','in_progress'=>'#2563eb','mitigated'=>'#7c3aed','accepted'=>'#d97706','closed'=>'#64748b'];
                $rStColor = $rStColors[$r['status'] ?? ''] ?? '#64748b';
              ?>
                <tr>
                  <td>
                    <a href="/risk/<?= (int)$r['id'] ?>" class="table-link">
                      <?= Security::h($r['title']) ?>
                    </a>
                  </td>
                  <td style="text-align:center;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:<?= $rc ?>18;color:<?= $rc ?>;font-weight:700;font-size:13px;border:2px solid <?= $rc ?>33;">
                      <?= $rs ?>
                    </span>
                  </td>
                  <td>
                    <span style="background:<?= $rStColor ?>18;color:<?= $rStColor ?>;border:1px solid <?= $rStColor ?>33;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                      <?= ucfirst(str_replace('_', ' ', Security::h($r['status'] ?? ''))) ?>
                    </span>
                  </td>
                  <?php if (Auth::can('risk.write')): ?>
                    <td>
                      <form method="POST" action="/threats/<?= (int)$threat['id'] ?>/unlink-risk/<?= (int)$r['id'] ?>"
                            data-confirm="Unlink this risk from the threat?">
                        <?= Security::csrfField() ?>
                        <button type="submit" class="btn btn-ghost btn-sm" title="Unlink risk">
                          <i class="bi bi-x-lg" style="color:#ef4444;"></i>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty-state-sm" style="padding:28px 24px;text-align:center;">
            <i class="bi bi-shield-check" style="font-size:32px;color:var(--text-light);display:block;margin-bottom:8px;"></i>
            <p style="color:var(--text-muted);margin:0;">No risks linked to this threat yet.</p>
            <?php if (Auth::can('risk.write') && !empty($unlinkdRisks)): ?>
              <button class="btn btn-primary btn-sm" style="margin-top:12px;"
                      data-remove-class="d-none" data-target="#linkRiskPanel">
                <i class="bi bi-plus-lg"></i> Link a Risk
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /left column -->

  <!-- ── Right column ──────────────────────────────────────────── -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Threat Score card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-speedometer2"></i> Threat Score</h3>
      </div>
      <div class="card-body" style="text-align:center;">

        <!-- Large score circle -->
        <div style="display:inline-flex;align-items:center;justify-content:center;width:120px;height:120px;border-radius:50%;background:<?= $scoreColor ?>18;border:4px solid <?= $scoreColor ?>44;margin:8px auto 16px;">
          <div>
            <div style="font-size:48px;font-weight:900;color:<?= $scoreColor ?>;line-height:1;"><?= $score ?: '—' ?></div>
          </div>
        </div>
        <div style="font-size:16px;font-weight:700;color:<?= $scoreColor ?>;margin-bottom:4px;"><?= $scoreLabel ?> Risk</div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:20px;">Likelihood × Impact</div>

        <!-- L and I breakdown -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:10px;padding:14px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Likelihood</div>
            <div style="font-size:28px;font-weight:800;color:var(--text);line-height:1;"><?= $likelihood ?: '—' ?></div>
            <?php if ($likelihood > 0): ?>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= $likelihoodLabels[$likelihood] ?? '' ?></div>
            <?php endif; ?>
          </div>
          <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:10px;padding:14px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px;">Impact</div>
            <div style="font-size:28px;font-weight:800;color:var(--text);line-height:1;"><?= $impact ?: '—' ?></div>
            <?php if ($impact > 0): ?>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= $impactLabels[$impact] ?? '' ?></div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <!-- Quick summary card -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-bar-chart"></i> Summary</h4>
      </div>
      <div class="card-body" style="font-size:13px;">
        <div class="detail-row">
          <span>Linked Risks</span>
          <strong><?= count($linkedRisks) ?></strong>
        </div>
        <?php
        $risksByLevel = ['Critical'=>0,'High'=>0,'Medium'=>0,'Low'=>0];
        foreach ($linkedRisks as $r) {
            $rs = (int)($r['inherent_score'] ?? 0);
            if ($rs > 16)    $risksByLevel['Critical']++;
            elseif ($rs > 9) $risksByLevel['High']++;
            elseif ($rs > 4) $risksByLevel['Medium']++;
            else             $risksByLevel['Low']++;
        }
        $lvlColors = ['Critical'=>'#dc2626','High'=>'#ea580c','Medium'=>'#d97706','Low'=>'#16a34a'];
        foreach ($risksByLevel as $lvl => $cnt):
            if ($cnt === 0) continue;
        ?>
          <div class="detail-row">
            <span style="color:<?= $lvlColors[$lvl] ?>;">&#9632; <?= $lvl ?></span>
            <strong><?= $cnt ?></strong>
          </div>
        <?php endforeach; ?>
        <div class="detail-row" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-light);">
          <span>Available to link</span>
          <strong><?= count($unlinkdRisks) ?></strong>
        </div>
      </div>
    </div>

  </div><!-- /right column -->
</div>

<style>
.d-none { display: none !important; }
</style>

<script nonce="<?= Security::nonce() ?>">
(function() {
    function scoreColor(s) {
        if (s <= 4)  return '#16a34a';
        if (s <= 9)  return '#d97706';
        if (s <= 16) return '#ea580c';
        return '#dc2626';
    }
    window.updateEditScore = function() {
        var l = parseInt(document.getElementById('editLikelihood')?.value, 10) || 0;
        var i = parseInt(document.getElementById('editImpact')?.value, 10) || 0;
        var s = l * i;
        var d = document.getElementById('editScoreDisplay');
        if (d) {
            d.textContent = s;
            d.style.color = scoreColor(s);
        }
    };
})();
</script>
