<?php
$pageTitle    = $risk['title'];
$activeModule = 'risk';
$breadcrumbs  = [['Risk Register', '/risk'], [$risk['risk_id'] ?? 'Risk', null]];

$score      = (int)$risk['inherent_score'];
$resScore   = (int)($risk['residual_score'] ?? $score);
$level      = $score > 14 ? 'Critical' : ($score > 9 ? 'High' : ($score > 4 ? 'Medium' : 'Low'));
$resLevel   = $resScore > 14 ? 'Critical' : ($resScore > 9 ? 'High' : ($resScore > 4 ? 'Medium' : 'Low'));
$levelColors = ['Critical' => '#ef4444', 'High' => '#f97316', 'Medium' => '#f59e0b', 'Low' => '#22c55e'];
$lc = $levelColors[$level];

$strategyLabels = ['mitigate' => 'Mitigate', 'accept' => 'Accept', 'transfer' => 'Transfer', 'avoid' => 'Avoid'];
$strategyColors = [
    'mitigate' => ['bg' => '#3b82f615', 'border' => '#3b82f640', 'text' => '#2563eb'],
    'accept'   => ['bg' => '#f59e0b15', 'border' => '#f59e0b40', 'text' => '#b45309'],
    'transfer' => ['bg' => '#8b5cf615', 'border' => '#8b5cf640', 'text' => '#7c3aed'],
    'avoid'    => ['bg' => '#ef444415', 'border' => '#ef444440', 'text' => '#dc2626'],
];
$statusLabels = [
    'open'        => 'Open',
    'in_review'   => 'In Review',
    'monitoring'  => 'Monitoring',
    'accepted'    => 'Accepted',
    'closed'      => 'Closed',
    'transferred' => 'Transferred',
];
$actionStatusLabels = ['planned' => 'Planned', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

// Score-based strategy suggestion
$suggested = [];
if ($score >= 20)      $suggested = ['mitigate', 'transfer'];
elseif ($score >= 15)  $suggested = ['mitigate'];
elseif ($score >= 10)  $suggested = ['mitigate', 'accept'];
elseif ($score >= 5)   $suggested = ['accept', 'mitigate'];
else                   $suggested = ['accept'];

ob_start();
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
<?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($risk['title']) ?></h1>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:4px">
      <span class="mono" style="font-size:13px;color:var(--text-muted)"><?= Security::h($risk['risk_id'] ?? '') ?></span>
      <span style="color:var(--text-muted)">·</span>
      <span><?= Security::h($risk['category_name'] ?? 'Uncategorized') ?></span>
      <span style="color:var(--text-muted)">·</span>
      <span><?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></span>

      <!-- Status badge -->
      <?php $st = $risk['status']; ?>
      <span class="status-chip" style="<?= match($st) {
        'open'        => 'background:#fef2f220;color:#dc2626;border:1px solid #fca5a5',
        'in_review'   => 'background:#eff6ff20;color:#2563eb;border:1px solid #93c5fd',
        'monitoring'  => 'background:#f0fdf420;color:#16a34a;border:1px solid #86efac',
        'accepted'    => 'background:#fffbeb20;color:#d97706;border:1px solid #fcd34d',
        'closed'      => 'background:#f1f5f920;color:#64748b;border:1px solid #cbd5e1',
        'transferred' => 'background:#faf5ff20;color:#7c3aed;border:1px solid #c4b5fd',
        default       => 'background:var(--bg-secondary);color:var(--text-muted);border:1px solid var(--border)',
      } ?>"><?= Security::h($statusLabels[$st] ?? ucfirst($st)) ?></span>

      <!-- Strategy tags -->
      <?php foreach ($risk['treatment_strategies_arr'] as $strat): ?>
        <?php $sc = $strategyColors[$strat] ?? $strategyColors['mitigate']; ?>
        <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border:1px solid <?= $sc['border'] ?>">
          <?= Security::h($strategyLabels[$strat] ?? ucfirst($strat)) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="page-actions">
    <span class="risk-badge-lg risk-<?= strtolower($level) ?>"><?= $level ?> · <?= $score ?></span>
    <a href="/risk/<?= $risk['id'] ?>/exception/create" class="btn btn-warning btn-sm"><i class="bi bi-shield-exclamation"></i> Exception</a>
    <a href="/risk" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div class="risk-layout">
  <!-- Main: Assessment edit form -->
  <div class="risk-main">

    <!-- Risk Assessment Card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-sliders"></i> Risk Assessment</h3>
      </div>
      <div class="card-body">
        <?php if ($risk['description']): ?>
          <div class="risk-description-box" style="margin-bottom:18px">
            <p><?= Security::h($risk['description']) ?></p>
          </div>
        <?php endif; ?>

        <?php if (Auth::can('risk.write')): ?>
        <form method="POST" action="/risk/<?= $risk['id'] ?>/update">
          <?= Security::csrfField() ?>

          <!-- Scoring Grid -->
          <div class="risk-scoring-grid">
            <div class="scoring-col inherent">
              <h4>Inherent Risk</h4>
              <div class="form-group">
                <label class="form-label">Likelihood</label>
                <input type="range" name="likelihood" min="1" max="5" value="<?= $risk['likelihood'] ?>" oninput="updateScores()" id="likelihood" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="lVal"><?= $risk['likelihood'] ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Impact</label>
                <input type="range" name="impact" min="1" max="5" value="<?= $risk['impact'] ?>" oninput="updateScores()" id="impact" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="iVal"><?= $risk['impact'] ?></div>
              </div>
              <div class="score-chip" id="inherentChip">
                <div id="inherentScore"><?= $score ?></div>
                <div id="inherentLevel"><?= $level ?></div>
              </div>
            </div>

            <div class="scoring-arrow"><i class="bi bi-arrow-right"></i><span>Treatment</span></div>

            <div class="scoring-col residual">
              <h4>Residual Risk</h4>
              <div class="form-group">
                <label class="form-label">Residual Likelihood</label>
                <input type="range" name="residual_likelihood" min="1" max="5" value="<?= $risk['residual_likelihood'] ?? $risk['likelihood'] ?>" oninput="updateScores()" id="resLikelihood" class="risk-slider res-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="rlVal"><?= $risk['residual_likelihood'] ?? $risk['likelihood'] ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Residual Impact</label>
                <input type="range" name="residual_impact" min="1" max="5" value="<?= $risk['residual_impact'] ?? $risk['impact'] ?>" oninput="updateScores()" id="resImpact" class="risk-slider res-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="riVal"><?= $risk['residual_impact'] ?? $risk['impact'] ?></div>
              </div>
              <div class="score-chip residual-chip" id="residualChip">
                <div id="residualScore"><?= $resScore ?></div>
                <div id="residualLevel"><?= $resLevel ?></div>
              </div>
            </div>
          </div>

          <!-- Status & Owner -->
          <div class="form-row" style="margin-top:16px">
            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <?php foreach ($statusLabels as $v => $l): ?>
                  <option value="<?= $v ?>" <?= $risk['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Risk Owner</label>
              <select name="owner_id" class="form-control">
                <option value="">Unassigned</option>
                <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= (int)($risk['owner_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Review Date</label>
              <input type="date" name="review_date" class="form-control" value="<?= Security::h($risk['review_date'] ?? '') ?>">
            </div>
          </div>

          <!-- Response Strategy — multi-select checkboxes -->
          <div class="form-group" style="margin-top:4px">
            <label class="form-label" style="display:flex;align-items:center;gap:8px">
              Response Strategy
              <span id="stratSuggestion" class="form-hint" style="margin:0;font-style:italic"></span>
            </label>
            <div class="strategy-grid">
              <?php foreach ($strategyLabels as $key => $label):
                $sc = $strategyColors[$key];
                $checked = in_array($key, $risk['treatment_strategies_arr'], true);
                $isSuggested = in_array($key, $suggested, true);
              ?>
              <label class="strategy-option <?= $isSuggested ? 'suggested' : '' ?>" id="strat_lbl_<?= $key ?>">
                <input type="checkbox" name="treatment_strategies[]" value="<?= $key ?>" <?= $checked ? 'checked' : '' ?>
                       onchange="updateStrategyHint()">
                <span class="strategy-icon">
                  <?= match($key) {
                    'mitigate' => '<i class="bi bi-shield-fill-check"></i>',
                    'accept'   => '<i class="bi bi-check-circle-fill"></i>',
                    'transfer' => '<i class="bi bi-arrow-left-right"></i>',
                    'avoid'    => '<i class="bi bi-x-octagon-fill"></i>',
                  } ?>
                </span>
                <span class="strategy-label"><?= $label ?></span>
                <span class="strategy-hint"><?= match($key) {
                  'mitigate' => 'Reduce likelihood or impact',
                  'accept'   => 'Formally accept as-is',
                  'transfer' => 'Insurance / third party',
                  'avoid'    => 'Eliminate the risk source',
                } ?></span>
                <?php if ($isSuggested): ?><span class="strategy-tag">Suggested</span><?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Treatment Notes -->
          <div class="form-group">
            <label class="form-label">Treatment Notes</label>
            <textarea name="treatment_description" class="form-control" rows="3" placeholder="Describe how the selected strategies are being applied..."><?= Security::h($risk['treatment_description'] ?? '') ?></textarea>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> Save Changes</button>
            <form method="POST" action="/risk/<?= $risk['id'] ?>/delete" style="display:inline">
              <?= Security::csrfField() ?>
              <button class="btn btn-ghost text-danger" onclick="return confirm('Delete this risk permanently?')"><i class="bi bi-trash"></i> Delete</button>
            </form>
          </div>
        </form>
        <?php else: ?>
          <!-- Read-only summary for viewers -->
          <div class="form-row">
            <div class="form-group"><label class="form-label">Status</label><div class="form-static"><?= Security::h($statusLabels[$risk['status']] ?? ucfirst($risk['status'])) ?></div></div>
            <div class="form-group"><label class="form-label">Likelihood</label><div class="form-static"><?= $risk['likelihood'] ?></div></div>
            <div class="form-group"><label class="form-label">Impact</label><div class="form-static"><?= $risk['impact'] ?></div></div>
            <div class="form-group"><label class="form-label">Score</label><div class="form-static"><?= $score ?> (<?= $level ?>)</div></div>
          </div>
          <?php if (!empty($risk['treatment_strategies_arr'])): ?>
          <div class="form-group">
            <label class="form-label">Response Strategy</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
              <?php foreach ($risk['treatment_strategies_arr'] as $strat):
                $sc = $strategyColors[$strat] ?? $strategyColors['mitigate'];
              ?>
              <span style="padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border:1px solid <?= $sc['border'] ?>">
                <?= Security::h($strategyLabels[$strat] ?? ucfirst($strat)) ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Response Actions Card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-list-check"></i> Response Actions</h3>
        <span class="badge" style="background:var(--bg-secondary);color:var(--text-muted)"><?= count($responseActions) ?> action<?= count($responseActions) !== 1 ? 's' : '' ?></span>
      </div>

      <?php if (empty($responseActions)): ?>
        <div class="card-body" style="text-align:center;padding:28px;color:var(--text-muted);font-size:13px">
          No response actions recorded yet.
        </div>
      <?php else: ?>
        <div class="card-body" style="padding:0">
          <?php foreach ($responseActions as $ra):
            $sc = $strategyColors[$ra['treatment_type']] ?? $strategyColors['mitigate'];
            $raStatusStyle = match($ra['status']) {
              'completed'   => 'color:#059669;background:#d1fae5;border-color:#a7f3d0',
              'in_progress' => 'color:#2563eb;background:#dbeafe;border-color:#93c5fd',
              'cancelled'   => 'color:#64748b;background:#f1f5f9;border-color:#cbd5e1',
              default       => 'color:#d97706;background:#fef3c7;border-color:#fcd34d',
            };
          ?>
          <div class="response-action-item <?= $ra['status'] === 'completed' ? 'ra-done' : '' ?>">
            <div class="ra-left">
              <span class="ra-type-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-color:<?= $sc['border'] ?>">
                <?= Security::h($strategyLabels[$ra['treatment_type']] ?? ucfirst($ra['treatment_type'])) ?>
              </span>
            </div>
            <div class="ra-body">
              <div class="ra-desc"><?= Security::h($ra['description']) ?></div>
              <div class="ra-meta">
                <?= Security::h($ra['owner_name'] ?? 'Unassigned') ?>
                <?php if ($ra['due_date']): ?>
                  <span class="ra-sep">·</span>
                  <?php $overdue = $ra['due_date'] < date('Y-m-d') && $ra['status'] !== 'completed'; ?>
                  <span style="color:<?= $overdue ? '#dc2626' : 'inherit' ?>">Due <?= date('M j, Y', strtotime($ra['due_date'])) ?><?= $overdue ? ' ⚠' : '' ?></span>
                <?php endif; ?>
                <?php if ($ra['effort']): ?><span class="ra-sep">·</span><?= Security::h($ra['effort']) ?><?php endif; ?>
                <?php if ($ra['completion_notes']): ?>
                  <span class="ra-sep">·</span><em><?= Security::h($ra['completion_notes']) ?></em>
                <?php endif; ?>
              </div>
            </div>
            <?php if (Auth::can('risk.write')): ?>
            <div class="ra-right">
              <form method="POST" action="/risk/response-action/<?= $ra['id'] ?>/update" style="display:flex;align-items:center;gap:6px">
                <?= Security::csrfField() ?>
                <select name="status" class="form-control form-control-sm" style="font-size:12px;padding:3px 6px;width:auto">
                  <?php foreach ($actionStatusLabels as $sv => $sl): ?>
                    <option value="<?= $sv ?>" <?= $ra['status'] === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-ghost" title="Update status" style="padding:3px 8px"><i class="bi bi-check2"></i></button>
              </form>
            </div>
            <?php else: ?>
            <div class="ra-right">
              <span class="status-chip" style="font-size:11px;<?= $raStatusStyle ?>"><?= Security::h($actionStatusLabels[$ra['status']] ?? ucfirst($ra['status'])) ?></span>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Add Response Action form -->
      <?php if (Auth::can('risk.write')): ?>
      <div class="card-body" style="border-top:1px solid var(--border);padding-top:16px">
        <h4 style="font-size:13px;font-weight:600;margin-bottom:12px"><i class="bi bi-plus-circle"></i> Add Response Action</h4>
        <form method="POST" action="/risk/<?= $risk['id'] ?>/response-action">
          <?= Security::csrfField() ?>
          <div class="form-row" style="align-items:flex-end;gap:10px">
            <div class="form-group" style="flex:0 0 130px">
              <label class="form-label">Type</label>
              <select name="action_type" class="form-control">
                <?php foreach ($strategyLabels as $k => $l): ?>
                  <option value="<?= $k ?>"><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group flex-3">
              <label class="form-label">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" class="form-control" placeholder="e.g. Implement MFA on all admin accounts" required>
            </div>
            <div class="form-group" style="flex:0 0 140px">
              <label class="form-label">Assign To</label>
              <select name="assigned_to" class="form-control">
                <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= $u['id'] === Auth::id() ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 140px">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" class="form-control">
            </div>
            <div class="form-group" style="flex:0 0 100px">
              <label class="form-label">Effort</label>
              <select name="effort" class="form-control">
                <option value="">—</option>
                <option>Low</option><option>Medium</option><option>High</option>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 auto;padding-top:0">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="btn btn-primary" style="white-space:nowrap"><i class="bi bi-plus-lg"></i> Add</button>
            </div>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Treatment Plans (linked formal plans) -->
    <?php
    $treatmentPlans = Database::fetchAll(
        "SELECT tp.*, u.name as owner_name,
                COUNT(tm.id) as total_milestones,
                COUNT(tm.completed_at) as completed_milestones
         FROM treatment_plans tp
         LEFT JOIN users u ON u.id = tp.owner_id
         LEFT JOIN treatment_milestones tm ON tm.plan_id = tp.id
         WHERE tp.risk_id = ?
         GROUP BY tp.id, u.name
         ORDER BY tp.created_at DESC",
        [$risk['id']]
    );
    $tpStrategyColors = [
        'mitigate' => ['bg'=>'#3b82f620','color'=>'#3b82f6','border'=>'#3b82f640'],
        'transfer' => ['bg'=>'#8b5cf620','color'=>'#8b5cf6','border'=>'#8b5cf640'],
        'accept'   => ['bg'=>'#f59e0b20','color'=>'#f59e0b','border'=>'#f59e0b40'],
        'avoid'    => ['bg'=>'#ef444420','color'=>'#ef4444','border'=>'#ef444440'],
    ];
    $tpStatusStyles = [
        'draft'     => ['bg'=>'#94a3b820','color'=>'#94a3b8','border'=>'#94a3b840'],
        'active'    => ['bg'=>'#6366f120','color'=>'#6366f1','border'=>'#6366f140'],
        'completed' => ['bg'=>'#05966920','color'=>'#059669','border'=>'#05966940'],
        'cancelled' => ['bg'=>'#94a3b820','color'=>'#94a3b8','border'=>'#94a3b840'],
    ];
    ?>
    <?php if (!empty($treatmentPlans)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-kanban-fill"></i> Treatment Plans</h3>
        <?php if (Auth::can('risk.write')): ?>
          <a href="/risk/<?= (int)$risk['id'] ?>/treatment/create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add Plan</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:0">
        <table class="data-table">
          <thead><tr><th>Plan</th><th>Strategy</th><th>Status</th><th>Progress</th><th>Owner</th><th>Target</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($treatmentPlans as $tp):
              $tpSc = $tpStrategyColors[$tp['strategy']] ?? $tpStrategyColors['mitigate'];
              $tpSt = $tpStatusStyles[$tp['status']] ?? $tpStatusStyles['draft'];
              $tot  = (int)$tp['total_milestones'];
              $done = (int)$tp['completed_milestones'];
              $pct  = $tot > 0 ? (int)round(($done / $tot) * 100) : 0;
              $ov   = $tp['target_date'] && $tp['target_date'] < date('Y-m-d') && $tp['status'] === 'active';
            ?>
            <tr>
              <td style="font-weight:500"><?= Security::h($tp['title']) ?></td>
              <td><span class="status-chip" style="background:<?= $tpSc['bg'] ?>;color:<?= $tpSc['color'] ?>;border:1px solid <?= $tpSc['border'] ?>"><?= ucfirst(Security::h($tp['strategy'])) ?></span></td>
              <td><span class="status-chip" style="background:<?= $tpSt['bg'] ?>;color:<?= $tpSt['color'] ?>;border:1px solid <?= $tpSt['border'] ?>"><?= ucfirst(Security::h($tp['status'])) ?></span></td>
              <td style="min-width:100px">
                <?php if ($tot > 0): ?>
                <div style="display:flex;align-items:center;gap:6px">
                  <div style="flex:1;height:5px;background:var(--border);border-radius:4px;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#059669':'#6366f1' ?>;border-radius:4px"></div></div>
                  <span style="font-size:11px;color:var(--text-muted)"><?= $done ?>/<?= $tot ?></span>
                </div>
                <?php else: ?><span style="font-size:12px;color:var(--text-muted)">No milestones</span><?php endif; ?>
              </td>
              <td class="text-sm"><?= Security::h($tp['owner_name'] ?? '—') ?></td>
              <td class="text-sm" style="color:<?= $ov?'#dc2626':'inherit' ?>"><?= $tp['target_date'] ? date('M j, Y', strtotime($tp['target_date'])) : '—' ?></td>
              <td><a href="/treatment/<?= (int)$tp['id'] ?>" class="btn btn-sm btn-secondary"><i class="bi bi-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:8px 0 4px">
      <?php if (Auth::can('risk.write')): ?>
        <a href="/risk/<?= (int)$risk['id'] ?>/treatment/create" class="btn btn-sm btn-secondary"><i class="bi bi-kanban-fill"></i> Create Treatment Plan</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar -->
  <div class="risk-sidebar">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Risk Info</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Risk ID</span><strong class="mono"><?= Security::h($risk['risk_id'] ?? '—') ?></strong></div>
        <div class="detail-row">
          <span>Category</span>
          <?php if ($risk['category_name']): ?>
            <span style="display:flex;align-items:center;gap:6px">
              <span class="category-dot" style="background:<?= Security::h($risk['category_color'] ?? '#666') ?>"></span>
              <?= Security::h($risk['category_name']) ?>
            </span>
          <?php else: ?><strong>Uncategorized</strong><?php endif; ?>
        </div>
        <div class="detail-row"><span>Owner</span><strong><?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></strong></div>
        <div class="detail-row"><span>Status</span><strong><?= Security::h($statusLabels[$risk['status']] ?? ucfirst($risk['status'])) ?></strong></div>
        <div class="detail-row"><span>Inherent Score</span><strong><?= $score ?> <span class="risk-badge risk-<?= strtolower($level) ?>"><?= $level ?></span></strong></div>
        <?php if ($risk['residual_likelihood']): ?>
        <div class="detail-row"><span>Residual Score</span><strong><?= $resScore ?> <span class="risk-badge risk-<?= strtolower($resLevel) ?>"><?= $resLevel ?></span></strong></div>
        <?php endif; ?>
        <div class="detail-row"><span>Identified</span><strong><?= date('M j, Y', strtotime($risk['identified_date'])) ?></strong></div>
        <div class="detail-row"><span>Last Updated</span><strong><?= date('M j, Y', strtotime($risk['updated_at'])) ?></strong></div>
        <?php if ($risk['review_date']): ?>
        <div class="detail-row">
          <span>Review Due</span>
          <?php $reviewOverdue = $risk['review_date'] < date('Y-m-d'); ?>
          <strong style="color:<?= $reviewOverdue?'#dc2626':'inherit' ?>"><?= date('M j, Y', strtotime($risk['review_date'])) ?><?= $reviewOverdue?' ⚠':'' ?></strong>
        </div>
        <?php endif; ?>
        <div class="detail-row"><span>Created By</span><strong><?= Security::h($risk['created_by_name'] ?? '—') ?></strong></div>

        <!-- Open/in-progress actions summary -->
        <?php
        $openActions = array_filter($responseActions, fn($a) => in_array($a['status'], ['planned','in_progress'], true));
        $doneActions = array_filter($responseActions, fn($a) => $a['status'] === 'completed');
        ?>
        <?php if (!empty($responseActions)): ?>
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
          <div class="detail-row"><span>Actions Planned</span><strong><?= count($openActions) ?></strong></div>
          <div class="detail-row"><span>Actions Completed</span><strong style="color:#059669"><?= count($doneActions) ?></strong></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Risk Appetite comparison -->
    <?php
    $appetite = null;
    try {
      $catName = $risk['category_name'] ?? '';
      if ($catName) {
        $appetite = Database::fetchOne("SELECT * FROM risk_appetite WHERE category = ?", [$catName]);
      }
    } catch (Throwable $e) {}
    if ($appetite):
      $appetiteColors = ['zero'=>'#dc2626','low'=>'#d97706','moderate'=>'#2563eb','high'=>'#16a34a'];
      $ac = $appetiteColors[$appetite['appetite']] ?? '#64748b';
    ?>
    <div class="card" style="margin-top:0">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-speedometer2"></i> Risk Appetite</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Appetite Level</span><strong style="color:<?= $ac ?>"><?= ucfirst($appetite['appetite']) ?></strong></div>
        <?php if ($appetite['max_score']): ?>
        <div class="detail-row"><span>Max Score</span><strong><?= $appetite['max_score'] ?></strong></div>
        <div style="margin-top:8px">
          <?php $exceeds = $score > (int)$appetite['max_score']; ?>
          <?php if ($exceeds): ?>
            <div class="alert-box error" style="margin:0;padding:8px 12px;font-size:12px"><i class="bi bi-exclamation-triangle-fill"></i> Inherent score exceeds appetite</div>
          <?php else: ?>
            <div class="alert-box success" style="margin:0;padding:8px 12px;font-size:12px"><i class="bi bi-check-circle-fill"></i> Within appetite threshold</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<style nonce="<?= Security::nonce() ?>">
.risk-layout { display:grid; grid-template-columns:1fr 280px; gap:16px; align-items:start; }
.risk-main   { display:flex; flex-direction:column; gap:16px; }
.risk-sidebar { display:flex; flex-direction:column; gap:16px; }
@media(max-width:900px){ .risk-layout{grid-template-columns:1fr} .risk-sidebar{order:-1} }

/* Strategy grid */
.strategy-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-top:6px; }
@media(max-width:700px){ .strategy-grid{grid-template-columns:1fr 1fr} }
.strategy-option {
  display:flex; flex-direction:column; align-items:center; gap:6px;
  padding:14px 10px; border-radius:10px; border:2px solid var(--border);
  background:var(--bg-secondary); cursor:pointer; transition:all .15s; text-align:center; position:relative;
}
.strategy-option:has(input:checked) {
  border-color:var(--primary); background:color-mix(in srgb, var(--primary) 8%, transparent);
}
.strategy-option.suggested { border-style:dashed; }
.strategy-option input { position:absolute; opacity:0; pointer-events:none; }
.strategy-icon  { font-size:20px; color:var(--text-muted); }
.strategy-option:has(input:checked) .strategy-icon { color:var(--primary); }
.strategy-label { font-weight:600; font-size:13px; }
.strategy-hint  { font-size:11px; color:var(--text-muted); line-height:1.3; }
.strategy-tag   { font-size:10px; font-weight:700; background:var(--primary); color:#fff; border-radius:20px; padding:1px 7px; }

/* Response action items */
.response-action-item {
  display:flex; align-items:flex-start; gap:12px;
  padding:14px 16px; border-bottom:1px solid var(--border);
}
.response-action-item:last-child { border-bottom:none; }
.response-action-item.ra-done { opacity:.65; }
.ra-left { flex-shrink:0; padding-top:2px; }
.ra-type-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; border:1px solid; display:inline-block; white-space:nowrap; }
.ra-body { flex:1; min-width:0; }
.ra-desc { font-size:14px; font-weight:500; line-height:1.4; }
.ra-meta { font-size:12px; color:var(--text-muted); margin-top:3px; }
.ra-sep  { margin:0 4px; }
.ra-right { flex-shrink:0; }

/* Score chips */
.risk-scoring-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:16px; align-items:start; margin-bottom:16px; }
@media(max-width:650px){ .risk-scoring-grid{grid-template-columns:1fr} }
.scoring-arrow { display:flex; flex-direction:column; align-items:center; justify-content:center; padding-top:32px; color:var(--text-muted); font-size:12px; gap:4px; }
.score-chip { border-radius:10px; padding:12px; text-align:center; background:var(--bg-secondary); border:1px solid var(--border); margin-top:8px; }
.score-chip div:first-child { font-size:28px; font-weight:700; }
.score-chip div:last-child  { font-size:12px; font-weight:600; }
.risk-slider { width:100%; }
.slider-markers { display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-top:2px; }
.slider-val { text-align:center; font-weight:700; font-size:15px; margin-top:4px; }

/* Misc */
.form-static { font-weight:600; padding:8px 12px; background:var(--bg-secondary); border-radius:6px; }
.detail-row  { display:flex; justify-content:space-between; padding:6px 0; font-size:13px; border-bottom:1px solid var(--border); }
.detail-row:last-child { border-bottom:none; }
</style>

<script nonce="<?= Security::nonce() ?>">
const levelColors = { Critical:'#ef4444', High:'#f97316', Medium:'#f59e0b', Low:'#22c55e' };
function getLevel(s){ return s>14?'Critical':s>9?'High':s>4?'Medium':'Low'; }

function updateScores() {
  const l  = parseInt(document.getElementById('likelihood').value);
  const i  = parseInt(document.getElementById('impact').value);
  const rl = parseInt(document.getElementById('resLikelihood').value);
  const ri = parseInt(document.getElementById('resImpact').value);
  document.getElementById('lVal').textContent  = l;
  document.getElementById('iVal').textContent  = i;
  document.getElementById('rlVal').textContent = rl;
  document.getElementById('riVal').textContent = ri;
  const iScore = l*i, rScore = rl*ri;
  const iLevel = getLevel(iScore), rLevel = getLevel(rScore);
  document.getElementById('inherentScore').textContent = iScore;
  document.getElementById('inherentLevel').textContent = iLevel;
  document.getElementById('inherentChip').style.background = levelColors[iLevel]+'20';
  document.getElementById('inherentChip').style.color = levelColors[iLevel];
  document.getElementById('residualScore').textContent = rScore;
  document.getElementById('residualLevel').textContent = rLevel;
  document.getElementById('residualChip').style.background = levelColors[rLevel]+'20';
  document.getElementById('residualChip').style.color = levelColors[rLevel];
  updateStrategyHint(iScore);
}

function updateStrategyHint(score) {
  score = score ?? parseInt(document.getElementById('inherentScore').textContent);
  const el = document.getElementById('stratSuggestion');
  if (!el) return;
  if (score >= 20)      el.textContent = '— Score ≥20: suggest Mitigate + Transfer';
  else if (score >= 15) el.textContent = '— Score ≥15: suggest Mitigate';
  else if (score >= 10) el.textContent = '— Score ≥10: suggest Mitigate or Accept';
  else if (score >= 5)  el.textContent = '— Score 5–9: Accept or Mitigate';
  else                  el.textContent = '— Score <5: Accept is appropriate';
}

updateScores();
</script>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
