<?php
$breadcrumbs = $breadcrumbs ?? [['Dashboards', '/dashboards'], ['Dashboard', null]];
$csrf = Security::generateCsrfToken();
$isOwner = $dashboard['owner_id'] === Auth::id();
$metricOptions = [
    'open_risks'             => 'Open Risks',
    'critical_risks'         => 'Critical Risks',
    'non_compliant_controls' => 'Non-Compliant Controls',
    'open_incidents'         => 'Open Incidents',
    'open_issues'            => 'Open Issues',
    'overdue_policies'       => 'Overdue Policies',
    'pending_approvals'      => 'Pending Approvals',
    'overdue_treatments'     => 'Overdue Treatment Actions',
    'total_assets'           => 'Total Assets',
    'open_poams'             => 'Open POA&Ms',
    'overdue_audits'         => 'Overdue Audits',
];
$widgetTypes = [
    'stat_card'          => 'Stat Card (Single Metric)',
    'recent_risks'       => 'Recent High Risks',
    'recent_incidents'   => 'Recent Incidents',
    'compliance_summary' => 'Compliance by Framework',
    'open_issues'        => 'Open Issues',
    'overdue_items'      => 'Overdue Items Summary',
    'audit_status'       => 'Audit Status Breakdown',
    'policy_due'         => 'Policies Due for Review',
    'vendor_risk'        => 'Vendor Risk Ratings',
    'kri_status'         => 'KRI Status',
    'poam_tracker'       => 'POA&M Tracker',
    'treatment_actions'  => 'Risk Treatment Actions',
    'asset_criticality'  => 'Asset Criticality Breakdown',
    'risk_by_category'   => 'Risks by Category',
    'top_risks_heatmap'  => 'Top Risks Table',
    'incident_heatmap'   => 'Incident Severity Breakdown',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($dashboard['name']) ?></h1>
    <?php if ($dashboard['description']): ?>
    <p class="page-subtitle"><?= Security::h($dashboard['description']) ?></p>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <?php if ($dashboard['is_shared']): ?><span class="badge badge-info">Shared</span><?php endif; ?>
    <?php if ($isOwner): ?>
    <button id="btnOpenWidget" class="btn btn-secondary btn-sm" data-show-modal="addWidgetModal"><i class="bi bi-plus-lg"></i> Add Widget</button>
    <form method="POST" action="/dashboards/<?= (int)$dashboard['id'] ?>/delete" data-confirm="Delete this dashboard?" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
    </form>
    <?php endif; ?>
    <a href="/dashboards" class="btn btn-secondary btn-sm">Back</a>
  </div>
</div>

<?php if (empty($widgets)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-grid-3x3-gap" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No Widgets Yet</h3>
  <?php if ($isOwner): ?>
  <p style="color:var(--text-muted);margin-bottom:20px;">Add widgets to build your custom view.</p>
  <button class="btn btn-primary" id="btnOpenWidget2" data-show-modal="addWidgetModal">Add First Widget</button>
  <?php else: ?>
  <p style="color:var(--text-muted);">This dashboard has no widgets.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;">
  <?php foreach ($widgets as $w): ?>
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title"><?= Security::h($w['title']) ?></h3>
      <?php if ($isOwner): ?>
      <form method="POST" action="/dashboards/<?= (int)$dashboard['id'] ?>/widget/<?= (int)$w['id'] ?>/remove" style="margin:0;" data-confirm="Remove widget?">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;color:var(--text-muted);"><i class="bi bi-x-lg"></i></button>
      </form>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($w['widget_type'] === 'stat_card'): ?>
        <div style="font-size:2.5rem;font-weight:700;color:var(--primary);"><?= (int)($w['data'] ?? 0) ?></div>
        <div style="font-size:0.85rem;color:var(--text-muted);"><?= Security::h($metricOptions[$w['config']['metric'] ?? ''] ?? $w['config']['metric'] ?? '') ?></div>

      <?php elseif ($w['widget_type'] === 'recent_risks' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No open risks.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $r): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <a href="/risk/<?= (int)$r['id'] ?>" style="font-size:0.875rem;font-weight:500;"><?= Security::h($r['title']) ?></a>
            <span class="badge badge-danger"><?= (int)$r['score'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'recent_incidents' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No incidents.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $inc): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <a href="/incident/<?= (int)$inc['id'] ?>" style="font-size:0.875rem;"><?= Security::h($inc['title']) ?></a>
            <span class="badge <?= $inc['severity']==='critical'?'badge-danger':($inc['severity']==='high'?'badge-warning':'badge-secondary') ?>"><?= ucfirst($inc['severity']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'compliance_summary' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No compliance packages.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach ($w['data'] as $cp):
            $pct = $cp['total'] > 0 ? round($cp['compliant']/$cp['total']*100) : 0;
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span style="font-size:0.8rem;font-weight:500;"><?= Security::h($cp['name']) ?></span>
              <span style="font-size:0.8rem;color:var(--text-muted);"><?= $pct ?>%</span>
            </div>
            <div style="background:var(--border);border-radius:4px;height:6px;">
              <div style="width:<?= $pct ?>%;background:<?= $pct>=80?'var(--success)':($pct>=50?'var(--warning)':'var(--danger)') ?>;height:100%;border-radius:4px;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'open_issues' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No open issues.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $iss): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <a href="/issue/<?= (int)$iss['id'] ?>" style="font-size:0.875rem;"><?= Security::h($iss['title']) ?></a>
            <span class="badge <?= $iss['severity']==='critical'?'badge-danger':($iss['severity']==='high'?'badge-warning':'badge-info') ?>"><?= ucfirst($iss['severity']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'overdue_items' && is_array($w['data'])): ?>
        <?php $od = $w['data']; ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center;">
          <?php foreach ([['Risks',$od['risks'],'var(--danger)'],['Policies',$od['policies'],'var(--warning)'],['Audits',$od['audits'],'#8b5cf6']] as [$lbl,$val,$clr]): ?>
          <div style="background:var(--bg-secondary);border-radius:8px;padding:14px 8px;">
            <div style="font-size:1.75rem;font-weight:700;color:<?= $clr ?>"><?= (int)$val ?></div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= $lbl ?></div>
          </div>
          <?php endforeach; ?>
        </div>

      <?php elseif ($w['widget_type'] === 'audit_status' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No audits.</p>
        <?php else: ?>
        <?php $statusColors=['planned'=>'#6366f1','in_progress'=>'var(--warning)','completed'=>'var(--success)','cancelled'=>'#6b7280','on_hold'=>'#f97316']; ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $row): $clr=$statusColors[$row['status']] ?? 'var(--text-muted)'; ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);">
            <span style="font-size:0.875rem;text-transform:capitalize;"><?= Security::h(str_replace('_',' ',$row['status'])) ?></span>
            <span style="background:<?= $clr ?>20;color:<?= $clr ?>;border-radius:12px;padding:2px 10px;font-size:0.8rem;font-weight:600;"><?= (int)$row['count'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'policy_due' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No policies due within 30 days.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $pol):
            $daysLeft = $pol['next_review_date'] ? (int)((strtotime($pol['next_review_date'])-time())/86400) : 999;
            $urgColor = $daysLeft <= 7 ? 'var(--danger)' : ($daysLeft <= 14 ? 'var(--warning)' : '#6b7280');
          ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);">
            <a href="/policy/<?= (int)$pol['id'] ?>" style="font-size:0.875rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= Security::h($pol['title']) ?></a>
            <span style="font-size:0.75rem;color:<?= $urgColor ?>;font-weight:600;white-space:nowrap;">
              <?= $daysLeft <= 0 ? 'Overdue' : $daysLeft.'d' ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'vendor_risk' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No vendors.</p>
        <?php else: ?>
        <?php $ratingColors=['critical'=>'badge-danger','high'=>'badge-warning','medium'=>'badge-info','low'=>'badge-secondary']; ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $v): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);">
            <a href="/vendor/<?= (int)$v['id'] ?>" style="font-size:0.875rem;"><?= Security::h($v['name']) ?></a>
            <span class="badge <?= $ratingColors[$v['risk_tier']] ?? 'badge-secondary' ?>"><?= ucfirst($v['risk_tier'] ?? 'N/A') ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'kri_status' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No KRIs configured.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $kri):
            $val = (float)($kri['latest_value'] ?? 0);
            $red = (float)($kri['threshold_red'] ?? 0);
            $yel = (float)($kri['threshold_amber'] ?? 0);
            $higherWorse = str_contains($kri['direction'] ?? '', 'higher');
            if ($higherWorse) {
                $status = $val >= $red ? 'red' : ($val >= $yel ? 'yellow' : 'green');
            } else {
                $status = $val <= $red ? 'red' : ($val <= $yel ? 'yellow' : 'green');
            }
            $statusColor = ['red'=>'var(--danger)','yellow'=>'var(--warning)','green'=>'var(--success)'][$status];
          ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);">
            <a href="/kri/<?= (int)$kri['id'] ?>" style="font-size:0.875rem;max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= Security::h($kri['title']) ?></a>
            <span style="font-size:0.8rem;font-weight:600;color:<?= $statusColor ?>">
              <?= number_format($val,1) ?><?= $kri['unit'] ? ' '.Security::h($kri['unit']) : '' ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'poam_tracker' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No POA&Ms.</p>
        <?php else: ?>
        <?php $poamColors=['open'=>'var(--danger)','in_progress'=>'var(--warning)','completed'=>'var(--success)','closed'=>'#6b7280','on_hold'=>'#f97316']; ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $row): $clr=$poamColors[$row['status']] ?? 'var(--text-muted)'; ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);">
            <span style="font-size:0.875rem;text-transform:capitalize;"><?= Security::h(str_replace('_',' ',$row['status'])) ?></span>
            <span style="background:<?= $clr ?>20;color:<?= $clr ?>;border-radius:12px;padding:2px 10px;font-size:0.8rem;font-weight:600;"><?= (int)$row['count'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'treatment_actions' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No pending treatment actions.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $rt):
            $isOverdue = $rt['due_date'] && strtotime($rt['due_date']) < time();
          ?>
          <div style="padding:6px 0;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
              <span style="font-size:0.875rem;font-weight:500;max-width:200px;"><?= Security::h($rt['title']) ?></span>
              <?php if ($rt['due_date']): ?>
              <span style="font-size:0.75rem;color:<?= $isOverdue?'var(--danger)':'var(--text-muted)' ?>;white-space:nowrap;">
                <?= date('M j', strtotime($rt['due_date'])) ?>
              </span>
              <?php endif; ?>
            </div>
            <?php if ($rt['risk_title']): ?>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;"><?= Security::h($rt['risk_title']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'asset_criticality' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No assets.</p>
        <?php else: ?>
        <?php $critColors=['critical'=>'var(--danger)','high'=>'#f97316','medium'=>'var(--warning)','low'=>'var(--success)'];
              $total = array_sum(array_column($w['data'],'count')); ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach ($w['data'] as $row):
            $pct = $total > 0 ? round($row['count']/$total*100) : 0;
            $clr = $critColors[$row['criticality']] ?? 'var(--text-muted)';
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span style="font-size:0.8rem;font-weight:500;text-transform:capitalize;"><?= Security::h($row['criticality'] ?? 'Unset') ?></span>
              <span style="font-size:0.8rem;color:var(--text-muted);"><?= (int)$row['count'] ?> (<?= $pct ?>%)</span>
            </div>
            <div style="background:var(--border);border-radius:4px;height:6px;">
              <div style="width:<?= $pct ?>%;background:<?= $clr ?>;height:100%;border-radius:4px;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'risk_by_category' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No categories.</p>
        <?php else: ?>
        <?php $maxCat = max(array_column($w['data'],'count') ?: [1]); ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $row): $pct = $maxCat > 0 ? round($row['count']/$maxCat*100) : 0; ?>
          <div>
            <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
              <span style="font-size:0.8rem;"><?= Security::h($row['category']) ?></span>
              <span style="font-size:0.8rem;font-weight:600;"><?= (int)$row['count'] ?></span>
            </div>
            <div style="background:var(--border);border-radius:3px;height:5px;">
              <div style="width:<?= $pct ?>%;background:var(--primary);height:100%;border-radius:3px;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'top_risks_heatmap' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No open risks.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table style="width:100%;font-size:0.8rem;border-collapse:collapse;">
            <thead><tr style="color:var(--text-muted);">
              <th style="text-align:left;padding:4px 6px;font-weight:600;">Risk</th>
              <th style="padding:4px 6px;font-weight:600;">L</th>
              <th style="padding:4px 6px;font-weight:600;">I</th>
              <th style="padding:4px 6px;font-weight:600;">Score</th>
            </tr></thead>
            <tbody>
            <?php foreach ($w['data'] as $r):
              $s = (int)($r['inherent_score'] ?? $r['score'] ?? 0);
              $sClr = $s >= 15 ? 'var(--danger)' : ($s >= 10 ? '#f97316' : ($s >= 5 ? 'var(--warning)' : 'var(--success)'));
            ?>
            <tr style="border-top:1px solid var(--border);">
              <td style="padding:5px 6px;"><a href="/risk/<?= (int)$r['id'] ?>" style="max-width:160px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= Security::h($r['title']) ?></a></td>
              <td style="text-align:center;padding:5px 6px;"><?= (int)($r['likelihood'] ?? 0) ?></td>
              <td style="text-align:center;padding:5px 6px;"><?= (int)($r['impact'] ?? 0) ?></td>
              <td style="text-align:center;padding:5px 6px;font-weight:700;color:<?= $sClr ?>"><?= $s ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'incident_heatmap' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No incidents.</p>
        <?php else: ?>
        <?php $sevColors=['critical'=>'var(--danger)','high'=>'#f97316','medium'=>'var(--warning)','low'=>'var(--success)']; ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $row): $clr=$sevColors[$row['severity']] ?? 'var(--text-muted)'; ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);">
            <span style="font-size:0.875rem;text-transform:capitalize;font-weight:500;color:<?= $clr ?>"><?= Security::h($row['severity']) ?></span>
            <div style="text-align:right;">
              <span style="font-size:0.875rem;font-weight:700;"><?= (int)$row['count'] ?> total</span>
              <span style="font-size:0.75rem;color:var(--text-muted);margin-left:8px;"><?= (int)$row['open_count'] ?> open</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php else: ?>
        <p style="color:var(--text-muted);font-size:0.875rem;">No data available.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isOwner): ?>
<!-- Add Widget Modal -->
<div class="um-overlay" id="addWidgetModal">
  <div class="um-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Add Widget</h3>
      <button id="btnCloseWidget" data-close-modal="addWidgetModal" style="background:none;border:none;cursor:pointer;font-size:1.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/dashboards/<?= (int)$dashboard['id'] ?>/add-widget">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group">
        <label class="form-label">Widget Type</label>
        <select name="widget_type" class="form-control" id="widgetTypeSelect">
          <?php foreach ($widgetTypes as $v => $l): ?>
          <option value="<?= $v ?>"><?= Security::h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Widget Title</label>
        <input type="text" name="title" class="form-control" placeholder="Display title">
      </div>
      <div class="form-group" id="metricGroup">
        <label class="form-label">Metric</label>
        <select name="metric" class="form-control">
          <?php foreach ($metricOptions as $v => $l): ?>
          <option value="<?= $v ?>"><?= Security::h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Add Widget</button>
        <button type="button" id="btnCancelWidget" data-close-modal="addWidgetModal" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
function toggleMetric() {
  var t = document.getElementById('widgetTypeSelect').value;
  document.getElementById('metricGroup').style.display = t === 'stat_card' ? 'block' : 'none';
}
document.getElementById('widgetTypeSelect').addEventListener('change', toggleMetric);
toggleMetric();
</script>
<?php endif; ?>
