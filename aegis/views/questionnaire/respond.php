<?php
$pageTitle    = 'Respond: ' . Security::h($assignment['questionnaire_title']);
$activeModule = 'questionnaire';
$breadcrumbs  = [
    ['Questionnaires', '/questionnaire'],
    ['Respond', null],
];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Respond to Questionnaire</h1>
    <p class="page-subtitle"><?= Security::h($assignment['questionnaire_title']) ?></p>
  </div>
  <a href="/questionnaire" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if ($assignment['due_date']): ?>
  <div class="alert-box info" style="margin-bottom:1.25rem">
    <i class="bi bi-calendar3"></i>
    This questionnaire is due by <strong><?= Security::h(date('F j, Y', strtotime($assignment['due_date']))) ?></strong>.
  </div>
<?php endif; ?>

<form method="POST" action="/questionnaire/assignment/<?= (int)$assignment['id'] ?>/submit" id="respondForm">
  <?= Security::csrfField() ?>

  <?php foreach ($sections as $sectionName => $sectionQuestions): ?>
    <div class="card" style="margin-bottom:1.5rem">
      <div class="card-header">
        <h2 class="card-title" style="font-size:1rem">
          <i class="bi bi-folder2-open"></i> <?= Security::h($sectionName) ?>
        </h2>
        <span class="badge badge-secondary"><?= count($sectionQuestions) ?> question<?= count($sectionQuestions) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="card-body" style="padding:.5rem 0">

        <?php foreach ($sectionQuestions as $qi => $q): ?>
          <?php
            $qId          = (int)$q['id'];
            $isRequired   = (bool)$q['is_required'];
            $existingVal  = $existingAnswers[$qId] ?? null;
          ?>
          <div class="respond-question"
               style="padding:1.25rem 1.5rem;<?= $qi < count($sectionQuestions) - 1 ? 'border-bottom:1px solid var(--border-light,#f0f0f0)' : '' ?>">
            <div style="margin-bottom:.75rem;font-weight:500;line-height:1.5">
              <?= Security::h($q['question_text']) ?>
              <?php if ($isRequired): ?>
                <span style="color:var(--danger)" title="Required"> *</span>
              <?php endif; ?>
              <span class="badge badge-secondary" style="font-size:.7rem;margin-left:.5rem;font-weight:400">
                Weight: <?= (int)$q['weight'] ?>
              </span>
            </div>

            <?php if ($q['question_type'] === 'text'): ?>
              <textarea name="answers[<?= $qId ?>]"
                        class="form-control"
                        rows="3"
                        placeholder="Enter your response…"
                        <?= $isRequired ? 'required' : '' ?>
              ><?= Security::h($existingVal ?? '') ?></textarea>

            <?php elseif ($q['question_type'] === 'scale'): ?>
              <div style="display:flex;align-items:center;gap:1rem;max-width:400px">
                <span class="text-muted text-sm" style="white-space:nowrap">1 (Low)</span>
                <input type="range"
                       name="answers[<?= $qId ?>]"
                       id="scale_<?= $qId ?>"
                       class="form-range"
                       min="1" max="5" step="1"
                       value="<?= (int)($existingVal ?? 3) ?>"
                       data-value-display="scale_val_<?= $qId ?>"
                       style="flex:1"
                       <?= $isRequired ? 'required' : '' ?>>
                <span class="text-muted text-sm" style="white-space:nowrap">5 (High)</span>
                <span id="scale_val_<?= $qId ?>"
                      style="min-width:2rem;text-align:center;font-size:1.1rem;font-weight:700;color:var(--primary)">
                  <?= (int)($existingVal ?? 3) ?>
                </span>
              </div>

            <?php elseif ($q['question_type'] === 'boolean'): ?>
              <div style="display:flex;gap:1.5rem">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500">
                  <input type="radio"
                         name="answers[<?= $qId ?>]"
                         value="yes"
                         <?= ($existingVal === 'yes') ? 'checked' : '' ?>
                         <?= $isRequired ? 'required' : '' ?>>
                  Yes
                </label>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500">
                  <input type="radio"
                         name="answers[<?= $qId ?>]"
                         value="no"
                         <?= ($existingVal === 'no') ? 'checked' : '' ?>
                         <?= $isRequired ? 'required' : '' ?>>
                  No
                </label>
              </div>

            <?php elseif ($q['question_type'] === 'choice'): ?>
              <?php $opts = json_decode($q['options'] ?? '[]', true) ?? []; ?>
              <?php if ($opts): ?>
                <div style="display:flex;flex-direction:column;gap:.5rem">
                  <?php foreach ($opts as $opt): ?>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                      <input type="radio"
                             name="answers[<?= $qId ?>]"
                             value="<?= Security::h($opt) ?>"
                             <?= ($existingVal === $opt) ? 'checked' : '' ?>
                             <?= $isRequired ? 'required' : '' ?>>
                      <?= Security::h($opt) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="text-muted text-sm">No options defined for this question.</p>
              <?php endif; ?>

            <?php endif; ?>
          </div>
        <?php endforeach; ?>

      </div>
    </div>
  <?php endforeach; ?>

  <?php if (empty($sections)): ?>
    <div class="card">
      <div class="card-body">
        <div class="empty-state">
          <i class="bi bi-journal-x" style="font-size:2rem;opacity:.3"></i>
          <p class="text-muted">This questionnaire has no questions yet.</p>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($sections)): ?>
    <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:.5rem">
      <a href="/questionnaire" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-success">
        <i class="bi bi-check-circle"></i> Submit Response
      </button>
    </div>
  <?php endif; ?>
</form>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
