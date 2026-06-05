<?php
$pageTitle    = $pageTitle    ?? 'Schedule Risk Review';
$activeModule = $activeModule ?? 'risk';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Review Sessions', '/risk/reviews'], ['Schedule', null]];

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Schedule Risk Review</h1>
    <p class="page-subtitle">Define the scope, type, and schedule for a structured risk review</p>
  </div>
  <a href="/risk/reviews" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Reviews</a>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form method="POST" action="/risk/reviews/create">
  <?= Security::csrfField() ?>

  <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

    <!-- Main Form Column -->
    <div style="flex:2;min-width:300px;">

      <!-- Core Details -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-info-circle"></i> Review Details</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label required">Review Title</label>
            <input type="text" name="title" class="form-control" required
                   value="<?= Security::h($_POST['title'] ?? '') ?>"
                   placeholder="e.g. Q2 2026 Periodic Risk Review">
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
              <label class="form-label required">Review Type</label>
              <select name="review_type" class="form-control" id="reviewTypeSelect" data-change="updateTypeDesc">
                <option value="periodic"  <?= ($_POST['review_type'] ?? 'periodic') === 'periodic'  ? 'selected' : '' ?>>Periodic</option>
                <option value="triggered" <?= ($_POST['review_type'] ?? '') === 'triggered' ? 'selected' : '' ?>>Triggered</option>
                <option value="ad_hoc"   <?= ($_POST['review_type'] ?? '') === 'ad_hoc'    ? 'selected' : '' ?>>Ad Hoc</option>
                <option value="board"    <?= ($_POST['review_type'] ?? '') === 'board'     ? 'selected' : '' ?>>Board Review</option>
              </select>
              <div id="typeDescBox" style="margin-top:6px;font-size:12px;color:var(--text-muted);padding:8px;background:var(--surface-alt);border-radius:6px;"></div>
            </div>

            <div class="form-group">
              <label class="form-label required">Scheduled Date</label>
              <input type="date" name="scheduled_date" class="form-control" required
                     value="<?= Security::h($_POST['scheduled_date'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Lead Reviewer</label>
            <select name="lead_reviewer_id" class="form-control">
              <option value="">— Select reviewer —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)($_POST['lead_reviewer_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                  <?= Security::h($u['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Scope Description</label>
            <textarea name="scope_description" class="form-control" rows="3"
                      placeholder="Describe the purpose, trigger, or context for this review…"><?= Security::h($_POST['scope_description'] ?? '') ?></textarea>
          </div>

        </div>
      </div>

      <!-- Scope Filter -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-funnel"></i> Scope — Which Risks to Include</h3>
        </div>
        <div class="card-body">

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="form-group">
              <label class="form-label">Category Filter</label>
              <select name="category_id" class="form-control">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= (int)($_POST['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= Security::h($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint">Leave blank to include all categories</div>
            </div>

            <div class="form-group">
              <label class="form-label">Risk Owner Filter</label>
              <select name="owner_id" class="form-control">
                <option value="">All owners</option>
                <?php foreach ($owners as $o): ?>
                  <option value="<?= (int)$o['id'] ?>" <?= (int)($_POST['owner_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>>
                    <?= Security::h($o['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Minimum Score</label>
            <select name="min_score" class="form-control">
              <option value=""  <?= empty($_POST['min_score']) ? 'selected' : '' ?>>Any score</option>
              <option value="5"  <?= ($_POST['min_score'] ?? '') === '5'  ? 'selected' : '' ?>>5+ (Medium and above)</option>
              <option value="10" <?= ($_POST['min_score'] ?? '') === '10' ? 'selected' : '' ?>>10+ (High and above)</option>
              <option value="15" <?= ($_POST['min_score'] ?? '') === '15' ? 'selected' : '' ?>>15+ (Critical only)</option>
            </select>
            <div class="form-hint">Filter risks by minimum inherent score</div>
          </div>

          <div class="form-group">
            <label class="form-label">Include Risk Statuses</label>
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:6px;">
              <?php
              $selectedStatuses = $_POST['status_filter'] ?? ['open', 'in_review', 'monitoring'];
              $statusOpts = ['open' => 'Open', 'in_review' => 'In Review', 'monitoring' => 'Monitoring'];
              foreach ($statusOpts as $sv => $sl):
              ?>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="status_filter[]" value="<?= $sv ?>"
                       <?= in_array($sv, (array)$selectedStatuses, true) ? 'checked' : '' ?>>
                <?= $sl ?>
              </label>
              <?php endforeach; ?>
            </div>
            <div class="form-hint">Risks in these statuses will be added to the review queue</div>
          </div>

        </div>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end;">
        <a href="/risk/reviews" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-calendar-plus"></i> Schedule Review
        </button>
      </div>

    </div>

    <!-- Help Sidebar -->
    <div style="flex:1;min-width:240px;max-width:320px;">

      <div class="card" style="margin-bottom:16px;border-left:4px solid var(--primary);">
        <div class="card-header">
          <h3 class="card-title" style="font-size:13px;"><i class="bi bi-question-circle"></i> Review Types</h3>
        </div>
        <div class="card-body" style="padding:14px 16px;">
          <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
              <div style="font-weight:600;font-size:12px;color:#2563eb;">Periodic</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Scheduled regular reviews (monthly, quarterly, annual) of the risk register.</div>
            </div>
            <div>
              <div style="font-weight:600;font-size:12px;color:var(--warning);">Triggered</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Initiated by a specific event — incident, change, or external threat.</div>
            </div>
            <div>
              <div style="font-weight:600;font-size:12px;color:var(--secondary);">Ad Hoc</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Unplanned review of a specific subset of risks.</div>
            </div>
            <div>
              <div style="font-weight:600;font-size:12px;color:#0891b2;">Board Review</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Formal board-level review requiring executive sign-off.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3 class="card-title" style="font-size:13px;"><i class="bi bi-magic"></i> What Happens on Completion</h3>
        </div>
        <div class="card-body" style="padding:14px 16px;">
          <ul style="margin:0;padding-left:16px;font-size:12px;color:var(--text-muted);line-height:1.7;">
            <li>Review dates are auto-updated on all reviewed risks</li>
            <li>Critical risks (score &gt;14): next review in 90 days</li>
            <li>High risks (score &gt;9): next review in 180 days</li>
            <li>All others: next review in 365 days</li>
            <li>Score changes are written to risk score history</li>
            <li>Sign-off is permanently recorded for audit trail</li>
          </ul>
        </div>
      </div>

    </div>

  </div>
</form>

<style nonce="<?= Security::nonce() ?>">
.required::after { content: ' *'; color: var(--danger); }
</style>
<script nonce="<?= Security::nonce() ?>">
var typeDescs = {
  periodic:  'Scheduled regular review (monthly, quarterly, or annual) to assess the current state of the risk register.',
  triggered: 'Initiated by a specific event such as an incident, regulatory change, or emerging threat.',
  ad_hoc:    'Unplanned review of a targeted subset of risks as needed.',
  board:     'Formal board-level review with executive sign-off and governance documentation.'
};

function updateTypeDesc() {
  var sel = document.getElementById('reviewTypeSelect');
  var box = document.getElementById('typeDescBox');
  box.textContent = typeDescs[sel.value] || '';
}

updateTypeDesc();
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
