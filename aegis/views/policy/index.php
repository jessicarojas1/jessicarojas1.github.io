<?php
$pageTitle    = 'Policies';
$activeModule = 'policy';
$breadcrumbs  = [['Policies', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Policy Library</h1>
    <p class="page-subtitle">Manage, review, and map policies to compliance frameworks</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="/policy/mapping" class="btn btn-secondary"><i class="bi bi-diagram-2-fill"></i> Control Mapping</a>
    <a href="/policy/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Policy</a>
  </div>
</div>

<div class="stats-row">
  <div class="stat-mini"><i class="bi bi-file-earmark-text" style="color:#94a3b8"></i><span class="stat-mini-num"><?= $summary['drafts'] ?? 0 ?></span><span>Drafts</span></div>
  <div class="stat-mini"><i class="bi bi-eye" style="color:#0284c7"></i><span class="stat-mini-num"><?= $summary['under_review'] ?? 0 ?></span><span>Under Review</span></div>
  <div class="stat-mini"><i class="bi bi-check-circle" style="color:#059669"></i><span class="stat-mini-num"><?= $summary['published'] ?? 0 ?></span><span>Published</span></div>
  <div class="stat-mini"><i class="bi bi-exclamation-circle" style="color:#dc2626"></i><span class="stat-mini-num"><?= $summary['overdue'] ?? 0 ?></span><span>Review Overdue</span></div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;padding:14px 16px;">
  <form method="GET" action="/policy" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div>
      <label style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;font-weight:600;">STATUS</label>
      <div style="display:flex;gap:6px;">
        <?php foreach ([''=>'All','draft'=>'Draft','under_review'=>'Under Review','published'=>'Published'] as $k=>$l): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['status'=>$k])) ?>" class="btn btn-sm <?= ($status??'')===$k?'btn-primary':'btn-secondary' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <label style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;font-weight:600;">REVIEW DATE</label>
      <select name="review" class="form-control" style="min-width:160px;height:32px;font-size:0.85rem;" onchange="this.form.submit()">
        <option value="">All dates</option>
        <option value="overdue"     <?= ($reviewFilter??'')==='overdue'?'selected':'' ?>>Overdue</option>
        <option value="this_month"  <?= ($reviewFilter??'')==='this_month'?'selected':'' ?>>Due in 30 days</option>
        <option value="this_quarter"<?= ($reviewFilter??'')==='this_quarter'?'selected':'' ?>>Due in 90 days</option>
        <option value="no_date"     <?= ($reviewFilter??'')==='no_date'?'selected':'' ?>>No review date</option>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;font-weight:600;">COMPLIANCE PACKAGE</label>
      <select name="package" class="form-control" style="min-width:180px;height:32px;font-size:0.85rem;" onchange="this.form.submit()">
        <option value="">All packages</option>
        <?php foreach ($packages as $pkg): ?>
        <option value="<?= (int)$pkg['id'] ?>" <?= ($packageId??0)===(int)$pkg['id']?'selected':'' ?>><?= Security::h($pkg['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!empty($status) || !empty($reviewFilter) || !empty($packageId)): ?>
    <a href="/policy" class="btn btn-sm btn-secondary" style="align-self:flex-end;"><i class="bi bi-x-circle"></i> Clear</a>
    <?php endif; ?>
    <input type="hidden" name="status" value="<?= Security::h($status ?? '') ?>">
  </form>
</div>

<div class="policy-grid">
<?php if ($policies): foreach ($policies as $policy):
  $overdue = $policy['next_review_date'] && strtotime($policy['next_review_date']) < time() && $policy['status'] === 'published';
?>
  <div class="policy-card <?= $overdue ? 'policy-overdue' : '' ?>">
    <div class="policy-card-header">
      <span class="badge badge-<?= $policy['status'] ?>"><?= ucfirst(str_replace('_',' ',$policy['status'])) ?></span>
      <?php if ($policy['policy_number']): ?>
        <span class="mono text-muted"><?= Security::h($policy['policy_number']) ?></span>
      <?php endif; ?>
      <?php if ($overdue): ?><span class="badge badge-danger">Review Overdue</span><?php endif; ?>
    </div>

    <h3 class="policy-title"><a href="/policy/<?= $policy['id'] ?>"><?= Security::h($policy['title']) ?></a></h3>

    <?php if ($policy['description']): ?>
      <p class="policy-desc"><?= Security::h(substr($policy['description'],0,100)) ?>...</p>
    <?php endif; ?>

    <div class="policy-meta">
      <?php if ($policy['category']): ?><span class="tag"><?= Security::h($policy['category']) ?></span><?php endif; ?>
      <span class="policy-meta-item"><i class="bi bi-person"></i> <?= Security::h($policy['owner_name'] ?? 'Unassigned') ?></span>
      <span class="policy-meta-item"><i class="bi bi-link-45deg"></i> <?= $policy['mapping_count'] ?> mappings</span>
    </div>

    <div class="policy-footer">
      <div>
        <?php if ($policy['next_review_date']): ?>
          <?php $daysUntil = (int)ceil((strtotime($policy['next_review_date']) - time()) / 86400); ?>
          <div style="font-size:0.78rem;<?= $overdue ? 'color:var(--danger);font-weight:600;' : ($daysUntil <= 30 ? 'color:var(--warning);font-weight:600;' : 'color:var(--text-muted);') ?>">
            <i class="bi bi-<?= $overdue ? 'exclamation-circle-fill' : 'calendar3' ?>"></i>
            Next Review: <?= date('M j, Y', strtotime($policy['next_review_date'])) ?>
            <?php if ($overdue): ?>
              (<?= abs($daysUntil) ?> days overdue)
            <?php elseif ($daysUntil <= 30): ?>
              (in <?= $daysUntil ?> days)
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="text-muted text-sm">v<?= Security::h($policy['version']) ?> &mdash; No review date set</div>
        <?php endif; ?>
      </div>
      <div class="policy-actions">
        <a href="/policy/<?= $policy['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i></a>
        <a href="/policy/<?= $policy['id'] ?>/edit" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i></a>
      </div>
    </div>
  </div>
<?php endforeach; else: ?>
  <div class="empty-state card" style="grid-column:1/-1">
    <div class="empty-icon"><i class="bi bi-file-earmark-x"></i></div>
    <h3>No policies yet</h3>
    <p>Create your first policy and map it to compliance controls.</p>
    <a href="/policy/create" class="btn btn-primary">Create Policy</a>
  </div>
<?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
