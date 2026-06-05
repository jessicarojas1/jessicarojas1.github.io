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
  <div class="page-actions">
    <a href="/policy/mapping" class="btn btn-secondary"><i class="bi bi-diagram-2-fill"></i> Control Mapping</a>
    <a href="/policy/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Policy</a>
  </div>
</div>

<div class="stats-row">
  <div class="stat-mini"><i class="bi bi-file-earmark-text" style="color:var(--text-muted)"></i><span class="stat-mini-num"><?= $summary['drafts'] ?? 0 ?></span><span>Drafts</span></div>
  <div class="stat-mini"><i class="bi bi-eye" style="color:var(--info)"></i><span class="stat-mini-num"><?= $summary['under_review'] ?? 0 ?></span><span>Under Review</span></div>
  <div class="stat-mini"><i class="bi bi-check-circle" style="color:var(--success)"></i><span class="stat-mini-num"><?= $summary['published'] ?? 0 ?></span><span>Published</span></div>
  <div class="stat-mini"><i class="bi bi-exclamation-circle" style="color:var(--danger)"></i><span class="stat-mini-num"><?= $summary['overdue'] ?? 0 ?></span><span>Review Overdue</span></div>
</div>

<!-- Filters -->
<?php $polFilterCount = (int)!empty($reviewFilter) + (int)!empty($packageId); ?>
<div class="filter-toolbar">
  <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <?php foreach ([''=>'All','draft'=>'Draft','under_review'=>'Under Review','published'=>'Published'] as $k=>$l): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['status'=>$k])) ?>" class="btn btn-sm <?= ($status??'')===$k?'btn-primary':'btn-secondary' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <form method="GET" action="/policy" class="filter-popover-wrap">
    <input type="hidden" name="status" value="<?= Security::h($status ?? '') ?>">
    <button type="button" class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#polFilterPopover">
      <i class="bi bi-funnel-fill"></i> Filters
      <?php if ($polFilterCount > 0): ?>
        <span class="filter-active-count"><?= $polFilterCount ?></span>
      <?php endif; ?>
    </button>
    <div id="polFilterPopover" class="filter-popover">
      <div class="form-group" style="margin:0">
        <label class="form-label">Review Date</label>
        <select name="review" class="form-control" data-autosubmit>
          <option value="">All dates</option>
          <option value="overdue"      <?= ($reviewFilter??'')==='overdue'?'selected':'' ?>>Overdue</option>
          <option value="this_month"   <?= ($reviewFilter??'')==='this_month'?'selected':'' ?>>Due in 30 days</option>
          <option value="this_quarter" <?= ($reviewFilter??'')==='this_quarter'?'selected':'' ?>>Due in 90 days</option>
          <option value="no_date"      <?= ($reviewFilter??'')==='no_date'?'selected':'' ?>>No review date</option>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Compliance Package</label>
        <select name="package" class="form-control" data-autosubmit>
          <option value="">All packages</option>
          <?php foreach ($packages as $pkg): ?>
          <option value="<?= (int)$pkg['id'] ?>" <?= ($packageId??0)===(int)$pkg['id']?'selected':'' ?>><?= Security::h($pkg['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($polFilterCount > 0): ?>
      <div class="filter-popover-footer">
        <a href="/policy<?= $status ? '?status='.urlencode($status) : '' ?>" class="btn btn-ghost btn-sm"><i class="bi bi-x-circle"></i> Clear filters</a>
      </div>
      <?php endif; ?>
    </div>
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
