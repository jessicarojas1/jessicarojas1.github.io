<?php
$pageTitle    = 'Questionnaires';
$activeModule = 'questionnaire';
$breadcrumbs  = [['Questionnaires', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Questionnaires</h1>
    <p class="page-subtitle">Build and assign assessment questionnaires across the organization</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('policy.write')): ?>
      <a href="/questionnaire/create" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> New Questionnaire
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['q_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['q_success']) ?></div>
  <?php unset($_SESSION['q_success']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:3fr 2fr;gap:20px;align-items:start">

  <!-- Questionnaire Library -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-journal-text"></i> Questionnaire Library</h2>
      <span class="badge badge-secondary"><?= count($questionnaires) ?> total</span>
    </div>
    <div class="card-body p0">
      <?php if (empty($questionnaires)): ?>
        <div class="empty-state">
          <i class="bi bi-journal-x" style="font-size:2.5rem;opacity:.3"></i>
          <p>No questionnaires yet.</p>
          <?php if (Auth::can('policy.write')): ?>
            <a href="/questionnaire/create" class="btn btn-primary btn-sm">Create First Questionnaire</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Entity Type</th>
              <th class="text-center">Questions</th>
              <th class="text-center">Assignments</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($questionnaires as $q): ?>
              <tr>
                <td>
                  <a href="/questionnaire/<?= (int)$q['id'] ?>" class="text-link fw-500">
                    <?= Security::h($q['title']) ?>
                  </a>
                  <?php if ($q['description']): ?>
                    <div class="text-muted text-sm mt-1"><?= Security::h(mb_strimwidth($q['description'], 0, 80, '…')) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $etColors = ['general' => 'secondary', 'vendor' => 'info', 'audit' => 'primary'];
                    $etColor  = $etColors[$q['entity_type']] ?? 'secondary';
                  ?>
                  <span class="badge badge-<?= $etColor ?>"><?= Security::h(ucfirst($q['entity_type'])) ?></span>
                </td>
                <td class="text-center">
                  <span class="fw-600"><?= (int)$q['question_count'] ?></span>
                </td>
                <td class="text-center">
                  <span class="fw-600"><?= (int)$q['assignment_count'] ?></span>
                </td>
                <td class="text-muted text-sm">
                  <?= Security::h(date('M j, Y', strtotime($q['created_at']))) ?>
                </td>
                <td class="text-right">
                  <a href="/questionnaire/<?= (int)$q['id'] ?>" class="btn btn-ghost btn-sm">
                    <i class="bi bi-eye"></i> View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- My Assignments -->
  <div class="card">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-person-check"></i> My Assignments</h2>
      <?php $pendingCount = count($myAssignments); ?>
      <?php if ($pendingCount > 0): ?>
        <span class="badge badge-warning"><?= $pendingCount ?> pending</span>
      <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($myAssignments)): ?>
        <div class="empty-state" style="padding:2rem">
          <i class="bi bi-check2-all" style="font-size:2rem;opacity:.3"></i>
          <p class="text-muted">No pending assignments.</p>
        </div>
      <?php else: ?>
        <ul class="assignment-list" style="list-style:none;margin:0;padding:0">
          <?php foreach ($myAssignments as $a): ?>
            <?php
              $statusColors = [
                  'pending'     => 'warning',
                  'in_progress' => 'info',
                  'submitted'   => 'success',
              ];
              $statusColor = $statusColors[$a['status']] ?? 'secondary';
            ?>
            <li style="padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem">
                <div style="flex:1;min-width:0">
                  <div class="fw-500 text-truncate"><?= Security::h($a['questionnaire_title']) ?></div>
                  <div class="text-sm text-muted" style="margin-top:.2rem">
                    <span class="badge badge-<?= $statusColor ?>" style="font-size:.7rem">
                      <?= Security::h(str_replace('_', ' ', ucfirst($a['status']))) ?>
                    </span>
                    <?php if ($a['due_date']): ?>
                      <span style="margin-left:.5rem">
                        <i class="bi bi-calendar3"></i>
                        Due <?= Security::h(date('M j, Y', strtotime($a['due_date']))) ?>
                      </span>
                    <?php else: ?>
                      <span style="margin-left:.5rem">No due date</span>
                    <?php endif; ?>
                  </div>
                </div>
                <a href="/questionnaire/assignment/<?= (int)$a['id'] ?>/respond"
                   class="btn btn-primary btn-sm" style="white-space:nowrap">
                  <i class="bi bi-pencil-fill"></i> Fill Out
                </a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
