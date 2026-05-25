<?php
$pageTitle    = Security::h($questionnaire['title']);
$activeModule = 'questionnaire';
$breadcrumbs  = [['Questionnaires', '/questionnaire'], [Security::h($questionnaire['title']), null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($questionnaire['title']) ?></h1>
    <p class="page-subtitle">
      <?php
        $etLabels = ['general' => 'General', 'vendor' => 'Vendor', 'audit' => 'Audit'];
        $etColors = ['general' => 'secondary', 'vendor' => 'info', 'audit' => 'primary'];
        $et = $questionnaire['entity_type'];
      ?>
      <span class="badge badge-<?= $etColors[$et] ?? 'secondary' ?>">
        <?= Security::h($etLabels[$et] ?? ucfirst($et)) ?>
      </span>
      &nbsp;Created by <?= Security::h($questionnaire['creator_name'] ?? 'Unknown') ?>
      on <?= Security::h(date('M j, Y', strtotime($questionnaire['created_at']))) ?>
    </p>
  </div>
  <a href="/questionnaire" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if ($questionnaire['description']): ?>
  <div class="card" style="margin-bottom:1.5rem">
    <div class="card-body">
      <p style="margin:0;line-height:1.6"><?= nl2br(Security::h($questionnaire['description'])) ?></p>
    </div>
  </div>
<?php endif; ?>

<div class="two-col-layout" style="align-items:flex-start;gap:1.5rem">

  <!-- Questions by Section -->
  <div style="flex:3">
    <div class="card" style="margin-bottom:1.5rem">
      <div class="card-header">
        <h2 class="card-title"><i class="bi bi-list-check"></i> Questions</h2>
        <span class="badge badge-secondary">
          <?= array_sum(array_map('count', $sections)) ?> questions
          &middot; <?= count($sections) ?> section<?= count($sections) !== 1 ? 's' : '' ?>
        </span>
      </div>
      <div class="card-body" style="padding:.5rem 0">

        <?php if (empty($sections)): ?>
          <div class="empty-state" style="padding:2rem">
            <p class="text-muted">No questions added yet.</p>
          </div>
        <?php else: ?>
          <?php foreach ($sections as $sectionName => $sectionQuestions): ?>
            <details open class="section-accordion" style="margin-bottom:.5rem">
              <summary style="padding:.75rem 1.25rem;cursor:pointer;font-weight:600;background:var(--surface-alt,#f9fafb);border-bottom:1px solid var(--border);list-style:none;display:flex;align-items:center;gap:.5rem">
                <i class="bi bi-chevron-down" style="font-size:.8rem;transition:transform .2s"></i>
                <?= Security::h($sectionName) ?>
                <span class="badge badge-secondary" style="margin-left:.5rem"><?= count($sectionQuestions) ?></span>
              </summary>
              <div style="padding:.5rem 0">
                <?php foreach ($sectionQuestions as $i => $q): ?>
                  <div style="padding:.75rem 1.5rem;<?= $i < count($sectionQuestions) - 1 ? 'border-bottom:1px solid var(--border-light,#f0f0f0)' : '' ?>">
                    <div style="display:flex;gap:.75rem;align-items:flex-start">
                      <span style="min-width:1.5rem;font-weight:600;color:var(--text-muted)"><?= ($i + 1) ?>.</span>
                      <div style="flex:1">
                        <div style="line-height:1.5">
                          <?= Security::h($q['question_text']) ?>
                          <?php if ($q['is_required']): ?>
                            <span style="color:var(--danger);margin-left:.25rem" title="Required">*</span>
                          <?php endif; ?>
                        </div>
                        <div style="margin-top:.35rem;display:flex;gap:.5rem;flex-wrap:wrap">
                          <?php
                            $typeColors = [
                                'text'    => 'secondary',
                                'scale'   => 'info',
                                'boolean' => 'primary',
                                'choice'  => 'warning',
                            ];
                            $typeColor = $typeColors[$q['question_type']] ?? 'secondary';
                          ?>
                          <span class="badge badge-<?= $typeColor ?>" style="font-size:.7rem">
                            <?= Security::h(ucfirst($q['question_type'])) ?>
                          </span>
                          <span class="badge badge-secondary" style="font-size:.7rem">
                            Weight: <?= (int)$q['weight'] ?>
                          </span>
                          <?php if ($q['question_type'] === 'choice' && $q['options']): ?>
                            <?php $opts = json_decode($q['options'], true) ?? []; ?>
                            <span class="text-sm text-muted" style="font-size:.75rem">
                              Options: <?= Security::h(implode(', ', $opts)) ?>
                            </span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>

    <!-- Assignments Table -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title"><i class="bi bi-person-lines-fill"></i> Assignments</h2>
        <span class="badge badge-secondary"><?= count($assignments) ?></span>
      </div>
      <div class="card-body p0">
        <?php if (empty($assignments)): ?>
          <div class="empty-state" style="padding:2rem">
            <p class="text-muted">No assignments yet.</p>
          </div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Assigned To</th>
                <th>Entity</th>
                <th>Due Date</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($assignments as $a): ?>
                <?php
                  $statusColors = [
                      'pending'     => 'warning',
                      'in_progress' => 'info',
                      'submitted'   => 'success',
                  ];
                  $statusColor = $statusColors[$a['status']] ?? 'secondary';
                ?>
                <tr>
                  <td><?= Security::h($a['assignee_name'] ?? '—') ?></td>
                  <td class="text-sm text-muted">
                    <?= $a['entity_type'] ? Security::h(ucfirst($a['entity_type'])) : '—' ?>
                    <?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?>
                  </td>
                  <td class="text-sm">
                    <?= $a['due_date'] ? Security::h(date('M j, Y', strtotime($a['due_date']))) : '—' ?>
                  </td>
                  <td>
                    <span class="badge badge-<?= $statusColor ?>">
                      <?= Security::h(str_replace('_', ' ', ucfirst($a['status']))) ?>
                    </span>
                  </td>
                  <td class="text-right">
                    <?php if ($a['status'] === 'submitted' && $a['response_id']): ?>
                      <a href="/questionnaire/response/<?= (int)$a['response_id'] ?>" class="btn btn-ghost btn-sm">
                        <i class="bi bi-eye"></i> Review
                      </a>
                    <?php elseif ($a['assigned_to'] == Auth::id()): ?>
                      <a href="/questionnaire/assignment/<?= (int)$a['id'] ?>/respond" class="btn btn-primary btn-sm">
                        <i class="bi bi-pencil-fill"></i> Respond
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Assign Panel (admin/policy.write) -->
  <?php if (Auth::can('policy.write')): ?>
    <div class="card" style="flex:1;min-width:260px">
      <div class="card-header">
        <h2 class="card-title"><i class="bi bi-person-plus"></i> Assign</h2>
      </div>
      <div class="card-body">
        <form method="POST" action="/questionnaire/<?= (int)$questionnaire['id'] ?>/assign">
          <?= Security::csrfField() ?>

          <div class="form-group">
            <label class="form-label required">Assign To</label>
            <select name="assigned_to" class="form-control" required>
              <option value="">— Select user —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Entity Type <span class="text-muted">(optional)</span></label>
            <select name="entity_type" class="form-control">
              <option value="">— None —</option>
              <option value="vendor">Vendor</option>
              <option value="audit">Audit</option>
              <option value="general">General</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Entity ID <span class="text-muted">(optional)</span></label>
            <input type="number" name="entity_id" class="form-control" min="1" placeholder="e.g. 42">
          </div>

          <div class="form-group">
            <label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-control">
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%">
            <i class="bi bi-send"></i> Assign Questionnaire
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
// Animate accordion chevrons
document.querySelectorAll('.section-accordion').forEach(function (el) {
  el.addEventListener('toggle', function () {
    var icon = el.querySelector('summary i.bi-chevron-down, summary i.bi-chevron-up');
    if (!icon) return;
    if (el.open) {
      icon.className = 'bi bi-chevron-down';
      icon.style.transform = 'rotate(0deg)';
    } else {
      icon.style.transform = 'rotate(-90deg)';
    }
  });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
