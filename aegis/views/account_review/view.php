<?php
$breadcrumbs = $breadcrumbs ?? [['Account Reviews', '/account_review'], ['Review', null]];
ob_start();
$total    = count($items);
$reviewed = count(array_filter($items, fn($i) => $i['decision'] !== 'pending'));
$approved = count(array_filter($items, fn($i) => $i['decision'] === 'approved'));
$revoked  = count(array_filter($items, fn($i) => $i['decision'] === 'revoked'));
$pct      = $total > 0 ? round(($reviewed / $total) * 100) : 0;
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($review['title']) ?></h1>
    <p class="page-subtitle">Access certification campaign</p>
  </div>
  <div class="page-actions">
    <a href="/account-reviews" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
    <?php if (Auth::can('admin')): ?>
    <form method="POST" action="/account-reviews/<?= (int)$review['id'] ?>/delete"
          data-confirm="Delete this review campaign and all items?">
      <?= Security::csrfField() ?>
      <button class="btn btn-danger btn-sm"><i class="bi bi-trash3-fill"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">

  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Items table -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left"><i class="bi bi-table" style="color:var(--primary)"></i><span class="card-title">Accounts to Review (<?= $total ?>)</span></div>
      </div>
      <div class="card-body" style="padding:0">
        <?php if ($items): ?>
        <table class="table">
          <thead>
            <tr>
              <th>Account</th>
              <th>User</th>
              <th>System</th>
              <th>Access Level</th>
              <th>Decision</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item):
              $decColors = ['pending'=>'gray','approved'=>'green','revoked'=>'red','modified'=>'yellow'];
              $dc = $decColors[$item['decision']] ?? 'gray';
            ?>
            <tr>
              <td style="font-weight:600"><?= Security::h($item['account_name']) ?></td>
              <td><?= Security::h($item['user_full_name'] ?? '—') ?></td>
              <td><?= Security::h($item['system_name'] ?? '—') ?></td>
              <td><?= Security::h($item['access_level'] ?? '—') ?></td>
              <td><span class="badge badge-<?= $dc ?>"><?= ucfirst(Security::h($item['decision'])) ?></span></td>
              <td>
                <?php if ($item['decision'] === 'pending' || Auth::can('admin')): ?>
                <button class="btn btn-ghost btn-sm" data-click="openDecision"
                        data-args='[<?= (int)$item['id'] ?>,"<?= Security::h($item['account_name']) ?>"]'>
                  <i class="bi bi-pencil-fill"></i> Decide
                </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div style="padding:30px;text-align:center;color:var(--text-muted)">No accounts added yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Add item -->
    <?php if (Auth::can('admin')): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-left"><i class="bi bi-plus-circle-fill" style="color:var(--primary)"></i><span class="card-title">Add Account</span></div>
      </div>
      <div class="card-body">
        <form method="POST" action="/account-reviews/<?= (int)$review['id'] ?>/add-item">
          <?= Security::csrfField() ?>
          <div class="form-row">
            <div class="form-group" style="flex:2">
              <label class="form-label required">Account / Username</label>
              <input type="text" name="account_name" class="form-control" required placeholder="user@example.com or svc-account">
            </div>
            <div class="form-group" style="flex:2">
              <label class="form-label">Full Name</label>
              <input type="text" name="user_full_name" class="form-control" placeholder="Jane Smith">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2">
              <label class="form-label">System / Application</label>
              <input type="text" name="system_name" class="form-control" placeholder="AWS IAM, Salesforce, VPN…">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label">Access Level</label>
              <input type="text" name="access_level" class="form-control" placeholder="Admin, Read, Write…">
            </div>
          </div>
          <button type="submit" class="btn btn-secondary btn-sm"><i class="bi bi-plus-lg"></i> Add</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i><span class="card-title">Progress</span></div></div>
      <div class="card-body">
        <div style="text-align:center;margin-bottom:12px">
          <div style="font-size:36px;font-weight:800;color:var(--primary)"><?= $pct ?>%</div>
          <div style="font-size:12px;color:var(--text-muted)"><?= $reviewed ?> of <?= $total ?> reviewed</div>
        </div>
        <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin-bottom:16px">
          <div style="width:<?= $pct ?>%;height:100%;background:var(--primary);border-radius:4px"></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
          <div style="display:flex;justify-content:space-between">
            <span style="color:var(--success)"><i class="bi bi-check-circle-fill"></i> Approved</span>
            <strong><?= $approved ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span style="color:var(--danger)"><i class="bi bi-x-circle-fill"></i> Revoked</span>
            <strong><?= $revoked ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span style="color:var(--text-muted)"><i class="bi bi-clock"></i> Pending</span>
            <strong><?= $total - $reviewed ?></strong>
          </div>
        </div>
        <?php if ($review['due_date']): ?>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)">
          <i class="bi bi-calendar3"></i> Due <?= date('M j, Y', strtotime($review['due_date'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">Details</span></div></div>
      <div class="card-body" style="font-size:13px;display:flex;flex-direction:column;gap:10px">
        <div><span style="color:var(--text-muted)">Status</span><br><span class="badge badge-<?= ['pending'=>'gray','in_progress'=>'blue','complete'=>'green','cancelled'=>'red'][$review['status']] ?? 'gray' ?>"><?= ucfirst(str_replace('_',' ',$review['status'])) ?></span></div>
        <div><span style="color:var(--text-muted)">Reviewer</span><br><strong><?= Security::h($review['reviewer_name'] ?? 'Unassigned') ?></strong></div>
        <div><span style="color:var(--text-muted)">Created by</span><br><strong><?= Security::h($review['created_by_name'] ?? '—') ?></strong></div>
        <?php if ($review['scope']): ?>
        <div><span style="color:var(--text-muted)">Scope</span><br><?= nl2br(Security::h($review['scope'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Decision modal -->
<div class="um-overlay" id="decisionModal">
  <div class="um-dialog">
    <div class="modal-header">
      <h3 style="font-size:15px;font-weight:700;margin:0"><i class="bi bi-person-check-fill" style="color:var(--primary)"></i> Review Decision</h3>
      <button class="modal-close" data-close-modal="decisionModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <form id="decisionForm" method="POST">
      <?= Security::csrfField() ?>
      <div style="padding:20px;display:flex;flex-direction:column;gap:14px">
        <div id="decisionAccountName" style="font-weight:600;font-size:14px"></div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Decision <span style="color:var(--danger)">*</span></label>
          <select name="decision" class="form-control" required>
            <option value="approved">✓ Approved — access is appropriate</option>
            <option value="revoked">✗ Revoked — access should be removed</option>
            <option value="modified">~ Modified — access level should change</option>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="3" placeholder="Justification or follow-up actions…"></textarea>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg)">
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal="decisionModal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save Decision</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
var _reviewId = <?= (int)$review['id'] ?>;
function openDecision(el, itemId, accountName) {
  document.getElementById('decisionAccountName').textContent = accountName;
  document.getElementById('decisionForm').action = '/account-reviews/' + _reviewId + '/item/' + itemId + '/decide';
  document.getElementById('decisionModal').classList.add('open');
}
function closeDecision() {
  document.getElementById('decisionModal').classList.remove('open');
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
