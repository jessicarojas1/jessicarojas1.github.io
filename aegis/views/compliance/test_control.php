<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$breadcrumbs = [['Compliance', '/compliance'], ['Testing', '/compliance/testing'], [Security::h($obj['title'] ?? 'Control'), null]];

function testResultBadge(string $result): string {
    return match($result) {
        'pass'       => '<span class="badge" style="background:var(--primary-tint);color:var(--primary);border:1px solid var(--primary-ring)">Pass</span>',
        'fail'       => '<span class="badge" style="background:var(--danger-tint);color:var(--danger);border:1px solid var(--danger-ring)">Fail</span>',
        'partial'    => '<span class="badge" style="background:var(--warning-tint);color:var(--warning);border:1px solid var(--warning-ring)">Partial</span>',
        'not_tested' => '<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);border:1px solid var(--border)">Not Tested</span>',
        default      => '<span class="badge">' . htmlspecialchars($result, ENT_QUOTES, 'UTF-8') . '</span>',
    };
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Test Control: <?= Security::h($obj['code']) ?></h1>
    <p class="page-subtitle"><?= Security::h($obj['title']) ?> &mdash; <?= Security::h($obj['package_name']) ?></p>
  </div>
  <div class="page-actions">
    <a href="/compliance/<?= (int)$obj['package_id'] ?>" class="btn btn-ghost">
      <i class="bi bi-arrow-left"></i> Back to Package
    </a>
    <a href="/compliance/testing" class="btn btn-ghost">
      <i class="bi bi-bar-chart-line"></i> Testing Dashboard
    </a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert-box success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert-box error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<!-- Control Details -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-shield-check" style="color:var(--primary)"></i>
      <span class="card-title">Control Details</span>
    </div>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <div>
        <div class="form-label text-sm" style="color:var(--text-muted);margin-bottom:4px">Control Code</div>
        <div style="font-family:monospace;font-weight:700;font-size:16px;color:var(--text)"><?= Security::h($obj['code']) ?></div>
      </div>
      <div>
        <div class="form-label text-sm" style="color:var(--text-muted);margin-bottom:4px">Standard</div>
        <div style="font-weight:600;color:var(--text)"><?= Security::h($obj['standard_name']) ?></div>
      </div>
      <div>
        <div class="form-label text-sm" style="color:var(--text-muted);margin-bottom:4px">Package</div>
        <div><a href="/compliance/<?= (int)$obj['package_id'] ?>" style="color:var(--primary);text-decoration:none"><?= Security::h($obj['package_name']) ?></a></div>
      </div>
      <div>
        <div class="form-label text-sm" style="color:var(--text-muted);margin-bottom:4px">Current Status</div>
        <div>
          <?php
          $implStatus = $obj['status'] ?? 'not_started';
          $statusCfg = [
            'compliant'      => ['bg'=>'#dcfce7','text'=>'var(--primary)','label'=>'Compliant'],
            'partial'        => ['bg'=>'var(--warning-subtle)','text'=>'var(--warning)','label'=>'Partial'],
            'non_compliant'  => ['bg'=>'var(--danger-subtle)','text'=>'var(--danger)','label'=>'Non-Compliant'],
            'not_applicable' => ['bg'=>'#f4f4f5','text'=>'#71717a','label'=>'Not Applicable'],
            'not_started'    => ['bg'=>'#f9fafb','text'=>'#a1a1aa','label'=>'Not Started'],
          ];
          $sc = $statusCfg[$implStatus] ?? $statusCfg['not_started'];
          ?>
          <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:600"><?= $sc['label'] ?></span>
        </div>
      </div>
    </div>
    <?php if (!empty($obj['description'])): ?>
    <div>
      <div class="form-label text-sm" style="color:var(--text-muted);margin-bottom:6px">Description</div>
      <div style="color:var(--text);line-height:1.6;font-size:14px"><?= Security::h($obj['description']) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Test History -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-clock-history" style="color:var(--primary)"></i>
      <span class="card-title">Test History</span>
      <span style="background:var(--border);color:var(--text-muted);border-radius:12px;padding:2px 10px;font-size:12px;font-weight:600"><?= count($history) ?></span>
    </div>
  </div>
  <?php if ($history): ?>
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Tester</th>
          <th>Result</th>
          <th>Effectiveness</th>
          <th>Method</th>
          <th>Findings</th>
          <th>Next Test</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td style="white-space:nowrap"><?= Security::h($h['test_date']) ?></td>
          <td><?= Security::h($h['tester_name'] ?? '—') ?></td>
          <td><?= testResultBadge($h['result']) ?></td>
          <td>
            <?php if ($h['effectiveness'] !== null): ?>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:60px;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                <div style="width:<?= (int)$h['effectiveness'] ?>%;height:100%;background:<?= $h['effectiveness'] >= 75 ? 'var(--primary)' : ($h['effectiveness'] >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;border-radius:3px"></div>
              </div>
              <span style="font-size:12px;font-weight:600;color:var(--text)"><?= (int)$h['effectiveness'] ?>%</span>
            </div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= Security::h($h['method'] ?? '—') ?></td>
          <td style="max-width:200px">
            <?php if (!empty($h['findings'])): ?>
              <span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px" title="<?= Security::h($h['findings']) ?>"><?= Security::h($h['findings']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <?php if (!empty($h['next_test_date'])): ?>
              <?php
              $ntd = new DateTime($h['next_test_date']);
              $now = new DateTime();
              $isOverdue = $ntd < $now;
              ?>
              <span style="<?= $isOverdue ? 'color:var(--danger);font-weight:600' : '' ?>"><?= Security::h($h['next_test_date']) ?><?= $isOverdue ? ' <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger)"></i>' : '' ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card-body">
    <div class="empty-state-sm" style="text-align:center;padding:24px;color:var(--text-muted)">
      <i class="bi bi-clipboard2-x" style="font-size:28px;display:block;margin-bottom:8px"></i>
      <p style="margin:0">No test results recorded yet. Use the form below to record the first test.</p>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- New Test Form -->
<div class="card">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-clipboard2-plus" style="color:var(--primary)"></i>
      <span class="card-title">Record New Test</span>
    </div>
  </div>
  <div class="card-body">
    <form method="POST" action="/compliance/control/<?= (int)$obj['id'] ?>/test/save">
      <?= Security::csrfField() ?>

      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label" for="test_date">Test Date <span style="color:var(--danger)">*</span></label>
          <input type="date" id="test_date" name="test_date" class="form-control"
                 value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="result">Result <span style="color:var(--danger)">*</span></label>
          <select id="result" name="result" class="form-control" required>
            <option value="">— Select result —</option>
            <option value="pass">Pass</option>
            <option value="fail">Fail</option>
            <option value="partial">Partial</option>
            <option value="not_tested">Not Tested</option>
          </select>
        </div>
      </div>

      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label" for="effectiveness">How effective is this control? (0–100%)</label>
          <div style="display:flex;align-items:center;gap:12px">
            <input type="number" id="effectiveness" name="effectiveness" class="form-control"
                   min="0" max="100" placeholder="e.g. 85" style="flex:1"
                   data-input="updateEffBar" data-input-val="1">
            <div style="width:80px;text-align:center">
              <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin-bottom:2px">
                <div id="effBar" style="width:0%;height:100%;background:var(--primary);border-radius:4px;transition:width .2s"></div>
              </div>
              <span id="effLabel" style="font-size:11px;color:var(--text-muted)">—</span>
            </div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="method">Testing Method</label>
          <input type="text" id="method" name="method" class="form-control"
                 placeholder="e.g. Interview, Document Review, Observation, Re-performance">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="findings">Findings</label>
        <textarea id="findings" name="findings" class="form-control" rows="3"
                  placeholder="Describe what was found during the test..."></textarea>
      </div>

      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label" for="evidence_refs">Evidence References</label>
          <input type="text" id="evidence_refs" name="evidence_refs" class="form-control"
                 placeholder="e.g. evidence IDs or file names">
        </div>
        <div class="form-group">
          <label class="form-label" for="next_test_date">Next Test Date</label>
          <input type="date" id="next_test_date" name="next_test_date" class="form-control">
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px">
        <a href="/compliance/<?= (int)$obj['package_id'] ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-floppy2-fill"></i> Save Test Result
        </button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function updateEffBar(val) {
  var n   = parseInt(val, 10);
  var bar = document.getElementById('effBar');
  var lbl = document.getElementById('effLabel');
  if (isNaN(n) || val === '') {
    bar.style.width = '0%';
    lbl.textContent = '—';
    return;
  }
  n = Math.max(0, Math.min(100, n));
  bar.style.width = n + '%';
  bar.style.background = n >= 75 ? 'var(--primary)' : (n >= 40 ? 'var(--warning)' : 'var(--danger)');
  lbl.textContent = n + '%';
}
</script>
