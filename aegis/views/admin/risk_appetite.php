<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Appetite Statement</h1>
    <p class="page-subtitle">Define the organization's tolerance for risk across key categories</p>
  </div>
  <div class="page-actions">
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px">
  <div class="card-body" style="padding:16px 20px">
    <div style="display:flex;align-items:flex-start;gap:14px">
      <i class="bi bi-shield-fill-exclamation" style="color:var(--primary);font-size:28px;flex-shrink:0;margin-top:2px"></i>
      <div>
        <p style="margin:0 0 6px;font-weight:600;color:var(--text)">What is a Risk Appetite Statement?</p>
        <p style="margin:0;color:var(--text-muted);font-size:14px;line-height:1.6">
          A Risk Appetite Statement defines how much risk the organization is willing to accept in pursuit of its objectives.
          It provides auditors and stakeholders with a clear, documented framework for evaluating risk decisions.
          Each category can have a named appetite level and a maximum acceptable risk score.
          Risks with residual scores above the maximum should require escalation or board approval.
        </p>
      </div>
    </div>
  </div>
</div>

<form method="POST" action="/admin/risk-appetite/save" id="appetiteForm">
  <?= Security::csrfField() ?>

  <div class="card">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-table" style="color:var(--primary)"></i>
        <span class="card-title">Risk Appetite Categories</span>
      </div>
    </div>
    <div class="card-body p0">
      <div style="overflow-x:auto">
        <table class="table" id="appetiteTable" style="min-width:900px">
          <thead>
            <tr>
              <th style="width:160px">Category</th>
              <th style="width:140px">Appetite Level</th>
              <th style="width:90px">Max Score</th>
              <th style="width:90px">Amber ≥</th>
              <th style="width:90px">Red ≥</th>
              <th>Statement</th>
              <th style="width:80px">Actions</th>
            </tr>
          </thead>
          <tbody id="appetiteBody">
            <?php foreach ($rows as $row): ?>
            <?php
              $app = $row['appetite'];
              $appColors = [
                'zero'     => ['bg'=>'#fef2f2','border'=>'#fca5a5','text'=>'#dc2626','label'=>'Zero'],
                'low'      => ['bg'=>'#fffbeb','border'=>'#fcd34d','text'=>'#d97706','label'=>'Low'],
                'moderate' => ['bg'=>'#eff6ff','border'=>'#93c5fd','text'=>'#2563eb','label'=>'Moderate'],
                'high'     => ['bg'=>'#f0fdf4','border'=>'#86efac','text'=>'#16a34a','label'=>'High'],
              ];
              $ac = $appColors[$app] ?? $appColors['low'];
            ?>
            <tr class="appetite-row" data-id="<?= (int)$row['id'] ?>">
              <td>
                <input type="hidden" name="id[]" value="<?= (int)$row['id'] ?>">
                <input type="text" name="category[]" class="form-control form-control-sm"
                       value="<?= Security::h($row['category']) ?>" required>
              </td>
              <td>
                <select name="appetite[]" class="form-control form-control-sm appetite-select"
                        data-change="updateRowColor">
                  <option value="zero"     <?= $app==='zero'     ? 'selected':'' ?>>Zero Tolerance</option>
                  <option value="low"      <?= $app==='low'      ? 'selected':'' ?>>Low</option>
                  <option value="moderate" <?= $app==='moderate' ? 'selected':'' ?>>Moderate</option>
                  <option value="high"     <?= $app==='high'     ? 'selected':'' ?>>High</option>
                </select>
                <span class="appetite-badge" style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?= $ac['bg'] ?>;color:<?= $ac['text'] ?>;border:1px solid <?= $ac['border'] ?>"><?= $ac['label'] ?></span>
              </td>
              <td>
                <input type="number" name="max_score[]" class="form-control form-control-sm"
                       value="<?= $row['max_score'] !== null ? (int)$row['max_score'] : '' ?>"
                       min="0" max="100" placeholder="—">
              </td>
              <td>
                <input type="number" name="amber_threshold[]" class="form-control form-control-sm"
                       value="<?= $row['amber_threshold'] !== null ? (int)$row['amber_threshold'] : '' ?>"
                       min="0" max="25" placeholder="—" title="Score at or above which risk shows amber on heat maps">
              </td>
              <td>
                <input type="number" name="red_threshold[]" class="form-control form-control-sm"
                       value="<?= $row['red_threshold'] !== null ? (int)$row['red_threshold'] : '' ?>"
                       min="0" max="25" placeholder="—" title="Score at or above which risk shows red on heat maps">
              </td>
              <td>
                <textarea name="statement[]" class="form-control form-control-sm"
                          rows="2" style="resize:vertical" required><?= Security::h($row['statement']) ?></textarea>
              </td>
              <td>
                <button type="button" class="btn btn-ghost btn-sm" title="Remove row"
                        data-click="removeRow" style="color:#dc2626">
                  <i class="bi bi-trash3"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tbody id="newRowsBody">
            <!-- New rows added via JS land here -->
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border);padding:12px 20px;display:flex;justify-content:space-between;align-items:center">
      <button type="button" class="btn btn-ghost btn-sm" data-click="addNewRow">
        <i class="bi bi-plus-circle"></i> Add Category
      </button>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy2-fill"></i> Save All Changes
      </button>
    </div>
  </div>
</form>

<template id="newRowTemplate">
  <tr class="appetite-row new-row">
    <td>
      <input type="text" name="new_category[]" class="form-control form-control-sm" placeholder="Category name" required>
    </td>
    <td>
      <select name="new_appetite[]" class="form-control form-control-sm appetite-select" data-change="updateRowColor">
        <option value="zero">Zero Tolerance</option>
        <option value="low" selected>Low</option>
        <option value="moderate">Moderate</option>
        <option value="high">High</option>
      </select>
      <span class="appetite-badge" style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#fffbeb;color:#d97706;border:1px solid #fcd34d">Low</span>
    </td>
    <td>
      <input type="number" name="new_max_score[]" class="form-control form-control-sm" min="0" max="100" placeholder="—">
    </td>
    <td>
      <input type="number" name="new_amber_threshold[]" class="form-control form-control-sm" min="0" max="25" placeholder="—">
    </td>
    <td>
      <input type="number" name="new_red_threshold[]" class="form-control form-control-sm" min="0" max="25" placeholder="—">
    </td>
    <td>
      <textarea name="new_statement[]" class="form-control form-control-sm" rows="2" style="resize:vertical" placeholder="Describe the risk tolerance for this category..." required></textarea>
    </td>
    <td>
      <button type="button" class="btn btn-ghost btn-sm" title="Remove row"
              data-click="removeRow" style="color:#dc2626">
        <i class="bi bi-trash3"></i>
      </button>
    </td>
  </tr>
</template>

<script nonce="<?= Security::nonce() ?>">
var _appetiteColors = {
  'zero':     {bg:'#fef2f2',border:'#fca5a5',text:'#dc2626',label:'Zero Tolerance'},
  'low':      {bg:'#fffbeb',border:'#fcd34d',text:'#d97706',label:'Low'},
  'moderate': {bg:'#eff6ff',border:'#93c5fd',text:'#2563eb',label:'Moderate'},
  'high':     {bg:'#f0fdf4',border:'#86efac',text:'#16a34a',label:'High'},
};

function updateRowColor(sel) {
  var val = sel.value;
  var c   = _appetiteColors[val] || _appetiteColors['low'];
  var badge = sel.parentElement.querySelector('.appetite-badge');
  if (badge) {
    badge.style.background   = c.bg;
    badge.style.color        = c.text;
    badge.style.borderColor  = c.border;
    badge.textContent        = c.label;
  }
}

function addNewRow() {
  var tmpl  = document.getElementById('newRowTemplate');
  var clone = tmpl.content.cloneNode(true);
  document.getElementById('newRowsBody').appendChild(clone);
}

function removeRow(btn) {
  var row = btn.closest('tr');
  row.parentElement.removeChild(row);
}

// Init badge colors on load
document.querySelectorAll('.appetite-select').forEach(function(sel) {
  updateRowColor(sel);
});
</script>
