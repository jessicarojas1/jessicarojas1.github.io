<?php
$pageTitle    = 'New Questionnaire';
$activeModule = 'questionnaire';
$breadcrumbs  = [['Questionnaires', '/questionnaire'], ['New Questionnaire', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Questionnaire</h1>
    <p class="page-subtitle">Define the questionnaire metadata and build your question set</p>
  </div>
  <a href="/questionnaire" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($_SESSION['q_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['q_error']) ?></div>
  <?php unset($_SESSION['q_error']); ?>
<?php endif; ?>

<form method="POST" action="/questionnaire/create" id="questionnaireForm">
  <?= Security::csrfField() ?>

  <!-- Metadata Card -->
  <div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
      <h2 class="card-title"><i class="bi bi-info-circle"></i> Questionnaire Details</h2>
    </div>
    <div class="card-body">
      <div class="form-row">
        <div class="form-group flex-2">
          <label class="form-label required">Title</label>
          <input type="text" name="title" class="form-control"
                 placeholder="e.g. Annual Vendor Security Assessment" required>
        </div>
        <div class="form-group">
          <label class="form-label required">Entity Type</label>
          <select name="entity_type" class="form-control" required>
            <option value="general">General</option>
            <option value="vendor">Vendor</option>
            <option value="audit">Audit</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"
                  placeholder="Describe the purpose and scope of this questionnaire…"></textarea>
      </div>
    </div>
  </div>

  <!-- Question Builder Card -->
  <div class="card" style="margin-bottom:1.5rem">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <h2 class="card-title"><i class="bi bi-list-check"></i> Questions</h2>
      <button type="button" class="btn btn-secondary btn-sm" id="addQuestionBtn">
        <i class="bi bi-plus-lg"></i> Add Question
      </button>
    </div>
    <div class="card-body" style="padding:.5rem 1.25rem">

      <div id="questionsContainer">

        <!-- Question Row Template (index 0) -->
        <div class="question-row" data-index="0"
             style="border:1px solid var(--border);border-radius:.5rem;padding:1rem;margin-bottom:1rem;background:var(--surface-alt,#f9fafb)">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
            <span class="fw-600 text-sm text-muted">Question #<span class="q-num">1</span></span>
            <button type="button" class="btn btn-ghost btn-sm remove-question" style="color:var(--danger)">
              <i class="bi bi-trash3"></i>
            </button>
          </div>

          <div class="form-row" style="flex-wrap:wrap;gap:.75rem">
            <div class="form-group" style="flex:1;min-width:140px">
              <label class="form-label">Section</label>
              <input type="text" name="questions[0][section]" class="form-control" value="General"
                     placeholder="Section name">
            </div>
            <div class="form-group" style="flex:1;min-width:100px">
              <label class="form-label required">Type</label>
              <select name="questions[0][question_type]" class="form-control q-type-select" required>
                <option value="text">Text</option>
                <option value="scale">Scale (1–5)</option>
                <option value="boolean">Boolean (Yes/No)</option>
                <option value="choice">Choice</option>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 80px">
              <label class="form-label">Weight</label>
              <input type="number" name="questions[0][weight]" class="form-control" value="1" min="1" max="5">
            </div>
            <div class="form-group" style="flex:0 0 90px;display:flex;flex-direction:column;justify-content:flex-end">
              <label class="form-label">&nbsp;</label>
              <label class="checkbox-label" style="display:flex;align-items:center;gap:.4rem;margin-top:.3rem">
                <input type="checkbox" name="questions[0][is_required]" value="1"> Required
              </label>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label required">Question Text</label>
            <textarea name="questions[0][question_text]" class="form-control" rows="2"
                      placeholder="Enter your question here…" required></textarea>
          </div>

          <div class="form-group q-options-row" style="display:none">
            <label class="form-label">Answer Options <span class="text-muted">(comma-separated)</span></label>
            <input type="text" name="questions[0][options_raw]" class="form-control"
                   placeholder="e.g. Option A, Option B, Option C">
          </div>
        </div>

      </div><!-- /questionsContainer -->

    </div>
  </div>

  <div style="display:flex;gap:1rem;justify-content:flex-end">
    <a href="/questionnaire" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-save"></i> Save Questionnaire
    </button>
  </div>
</form>

<script>
(function () {
  'use strict';

  var container = document.getElementById('questionsContainer');
  var addBtn    = document.getElementById('addQuestionBtn');

  function updateNumbers() {
    var rows = container.querySelectorAll('.question-row');
    rows.forEach(function (row, i) {
      row.dataset.index = i;
      row.querySelector('.q-num').textContent = i + 1;
      // Re-index all named inputs / selects / textareas
      row.querySelectorAll('[name]').forEach(function (el) {
        el.name = el.name.replace(/questions\[\d+\]/, 'questions[' + i + ']');
      });
    });
  }

  function bindRowEvents(row) {
    // Show/hide options field based on type selection
    var typeSelect  = row.querySelector('.q-type-select');
    var optionsRow  = row.querySelector('.q-options-row');

    function toggleOptions() {
      optionsRow.style.display = typeSelect.value === 'choice' ? '' : 'none';
    }
    typeSelect.addEventListener('change', toggleOptions);
    toggleOptions();

    // Remove button
    var removeBtn = row.querySelector('.remove-question');
    removeBtn.addEventListener('click', function () {
      if (container.querySelectorAll('.question-row').length > 1) {
        row.parentNode.removeChild(row);
        updateNumbers();
      }
    });
  }

  // Bind events on initial row
  container.querySelectorAll('.question-row').forEach(bindRowEvents);

  addBtn.addEventListener('click', function () {
    var rows    = container.querySelectorAll('.question-row');
    var lastRow = rows[rows.length - 1];
    var newRow  = lastRow.cloneNode(true);
    var newIdx  = rows.length;

    newRow.dataset.index = newIdx;
    newRow.querySelector('.q-num').textContent = newIdx + 1;

    // Reset cloned values
    newRow.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(function (el) {
      if (el.name && el.name.indexOf('[section]') !== -1) {
        el.value = 'General';
      } else if (el.name && el.name.indexOf('[weight]') !== -1) {
        el.value = '1';
      } else {
        el.value = '';
      }
    });
    newRow.querySelectorAll('select').forEach(function (el) {
      el.selectedIndex = 0;
    });
    newRow.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
      el.checked = false;
    });

    // Re-index names
    newRow.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/questions\[\d+\]/, 'questions[' + newIdx + ']');
    });

    container.appendChild(newRow);
    bindRowEvents(newRow);

    // Scroll into view
    newRow.scrollIntoView({behavior: 'smooth', block: 'nearest'});
  });
})();
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
