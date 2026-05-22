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
  <a href="/policy/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Policy</a>
</div>

<div class="stats-row">
  <div class="stat-mini"><i class="bi bi-file-earmark-text" style="color:#94a3b8"></i><span class="stat-mini-num"><?= $summary['drafts'] ?? 0 ?></span><span>Drafts</span></div>
  <div class="stat-mini"><i class="bi bi-eye" style="color:#0284c7"></i><span class="stat-mini-num"><?= $summary['under_review'] ?? 0 ?></span><span>Under Review</span></div>
  <div class="stat-mini"><i class="bi bi-check-circle" style="color:#059669"></i><span class="stat-mini-num"><?= $summary['published'] ?? 0 ?></span><span>Published</span></div>
  <div class="stat-mini"><i class="bi bi-exclamation-circle" style="color:#dc2626"></i><span class="stat-mini-num"><?= $summary['overdue'] ?? 0 ?></span><span>Review Overdue</span></div>
</div>

<!-- Filter tabs -->
<div class="filter-bar card">
  <a href="/policy" class="btn btn-sm <?= !($status??'') ? 'btn-primary' : 'btn-ghost' ?>">All</a>
  <a href="/policy?status=draft" class="btn btn-sm <?= ($status??'')==='draft'?'btn-primary':'btn-ghost' ?>">Draft</a>
  <a href="/policy?status=under_review" class="btn btn-sm <?= ($status??'')==='under_review'?'btn-primary':'btn-ghost' ?>">Under Review</a>
  <a href="/policy?status=published" class="btn btn-sm <?= ($status??'')==='published'?'btn-primary':'btn-ghost' ?>">Published</a>
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
        <div class="text-muted text-sm">
          <?php if ($policy['next_review_date']): ?>
            Review: <?= date('M j, Y', strtotime($policy['next_review_date'])) ?>
          <?php else: ?>
            v<?= Security::h($policy['version']) ?>
          <?php endif; ?>
        </div>
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
