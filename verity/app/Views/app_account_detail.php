<?php
/** @var array $account @var array $history @var array $assignments @var array|null $suggestion @var bool $canFlagRemediation @var array $remediationTasks @var string $csrf @var string $NONCE */
use Verity\Support\Security;

$appScript = '/assets/accounts.js';
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($account['username'] ?? $account['external_account_id']) ?></h1>
    <p><?= Security::h($account['application_name']) ?> · <?= Security::h(ucwords($account['account_type'])) ?> account</p>
  </div>
</div>

<div class="field-row">
  <div class="card flex-1-min260">
    <h2>Account</h2>
    <table class="grid">
      <tbody>
        <tr><th>External ID</th><td><?= Security::h($account['external_account_id']) ?></td></tr>
        <tr><th>Status</th><td><?= $account['status'] === 'enabled' ? '<span class="badge b-ok">Enabled</span>' : '<span class="badge b-neutral">Disabled</span>' ?></td></tr>
        <tr><th>Source</th><td><?= Security::h($account['source']) ?></td></tr>
        <tr><th>Last login</th><td><?= Security::h($account['last_login_at'] ?? '—') ?></td></tr>
        <tr><th>Linked identity</th>
          <td>
            <?php if ($account['person_id']): ?>
              <a href="/app/identities/view?id=<?= (int) $account['person_id'] ?>"><?= Security::h($account['person_name']) ?></a>
            <?php else: ?>
              <span class="badge b-warn">Unmatched</span>
            <?php endif; ?>
          </td>
        </tr>
      </tbody>
    </table>

    <?php if ($account['person_id']): ?>
      <form method="post" action="/app/accounts/unlink" id="unlinkForm" class="mt-12">
        <?= $csrf ?>
        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
        <div class="field"><label for="unlink_note">Reason for unlinking</label><input type="text" id="unlink_note" name="note" placeholder="Optional note"></div>
        <button type="submit" class="btn danger" data-confirm="Unlink this account from its identity?">Unlink Identity</button>
      </form>
    <?php else: ?>
      <?php if ($suggestion !== null): ?>
      <form method="post" action="/app/accounts/link" id="suggestedLinkForm" class="mt-12">
        <?= $csrf ?>
        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
        <input type="hidden" name="person_id" value="<?= (int) $suggestion['person_id'] ?>">
        <input type="hidden" name="accept_suggestion" value="1">
        <div class="card">
          <span class="badge <?= $suggestion['confidence'] === 'high' ? 'b-ok' : 'b-warn' ?>">Suggested match — <?= Security::h(ucfirst($suggestion['confidence'])) ?> confidence</span>
          <p class="mt-6"><strong><?= Security::h($suggestion['person_name']) ?></strong> — <?= Security::h($suggestion['reason']) ?>.</p>
          <div class="field"><label for="suggested_note">Note</label><input type="text" id="suggested_note" name="note" placeholder="Optional correlation note"></div>
          <button type="submit" class="btn primary">Accept Suggested Match</button>
        </div>
      </form>
      <?php endif; ?>
      <form method="post" action="/app/accounts/link" id="linkForm" class="mt-12">
        <?= $csrf ?>
        <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
        <input type="hidden" name="person_id" id="link_person_id" value="">
        <div class="field">
          <label for="link_search">Or search manually</label>
          <input type="text" id="link_search" autocomplete="off" placeholder="Search by name, email, or employee ID">
          <div id="link_results" class="card dropdown-panel hidden"></div>
        </div>
        <div class="field"><label for="link_note">Note</label><input type="text" id="link_note" name="note" placeholder="Optional correlation note"></div>
        <button type="submit" class="btn primary" id="linkSubmit" disabled>Link Account</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card flex-1-min260">
    <h2>Correlation History</h2>
    <?php if ($history === []): ?>
      <p class="empty-state-sm">No linking activity recorded yet.</p>
    <?php else: ?>
      <table class="grid">
        <thead><tr><th>Person</th><th>Method</th><th>By</th><th>Linked</th><th>Unlinked</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr>
            <td><?= Security::h($h['person_name'] ?? '—') ?></td>
            <td><?= Security::h($h['link_method']) ?></td>
            <td><?= Security::h($h['linked_by_name'] ?? 'system') ?></td>
            <td><?= Security::h(substr((string) $h['linked_at'], 0, 16)) ?></td>
            <td><?= $h['unlinked_at'] ? Security::h(substr((string) $h['unlinked_at'], 0, 16)) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-14">
  <h2>Entitlement Assignments (<?= count($assignments) ?>)</h2>
  <table class="grid">
    <thead><tr><th>Entitlement</th><th>Type</th><th>Assignment</th><th>Risk</th><th>Granted</th><th>Expires</th></tr></thead>
    <tbody>
      <?php if ($assignments === []): ?>
      <tr class="empty-row"><td colspan="6" class="empty-state-sm">No entitlements assigned to this account.</td></tr>
      <?php endif; ?>
      <?php foreach ($assignments as $a): ?>
      <tr>
        <td><?= Security::h($a['entitlement_name']) ?><?= $a['is_privileged'] ? ' <span class="badge b-risk">Privileged</span>' : '' ?></td>
        <td><?= Security::h(ucwords($a['entitlement_type'])) ?></td>
        <td><?= Security::h(ucwords($a['assignment_type'])) ?></td>
        <td>
          <?php $risk = $a['risk_level']; $cls = $risk === 'low' ? 'b-ok' : ($risk === 'medium' ? 'b-warn' : 'b-risk'); ?>
          <span class="badge <?= $cls ?>"><?= Security::h(ucwords($risk)) ?></span>
        </td>
        <td><?= Security::h(substr((string) $a['granted_at'], 0, 10)) ?></td>
        <td><?= $a['expires_at'] ? Security::h(substr((string) $a['expires_at'], 0, 10)) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card mt-14">
  <h2>Remediation Tasks (<?= count($remediationTasks) ?>)</h2>
  <?php if ($remediationTasks === []): ?>
  <p class="empty-state-sm">No remediation tasks for this account.</p>
  <?php else: ?>
  <table class="grid">
    <thead><tr><th>Type</th><th>Entitlement</th><th>Status</th><th>Opened</th><th>Resolution</th></tr></thead>
    <tbody>
      <?php foreach ($remediationTasks as $t): ?>
      <tr>
        <td><?= Security::h(ucwords(str_replace('_', ' ', $t['task_type']))) ?></td>
        <td><?= Security::h($t['entitlement_name'] ?? 'Whole account') ?></td>
        <td>
          <?php $tcls = $t['status'] === 'open' ? 'b-warn' : ($t['status'] === 'resolved' ? 'b-ok' : 'b-neutral'); ?>
          <span class="badge <?= $tcls ?>"><?= Security::h(ucwords($t['status'])) ?></span>
        </td>
        <td><?= Security::h(substr((string) $t['created_at'], 0, 16)) ?><?= $t['source'] === 'campaign' ? ' (from a campaign revoke)' : '' ?></td>
        <td><?= Security::h($t['resolution_note'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($canFlagRemediation): ?>
  <details class="mt-12">
    <summary class="btn sm inline-block">Flag for Remediation</summary>
    <form method="post" action="/app/remediation/create" class="mt-12">
      <?= $csrf ?>
      <input type="hidden" name="system_account_id" value="<?= (int) $account['id'] ?>">
      <div class="field-row">
        <div class="field">
          <label for="task_type">Task type</label>
          <select id="task_type" name="task_type" required>
            <option value="remove_access">Remove access</option>
            <option value="disable_account">Disable account</option>
            <option value="investigate">Investigate</option>
          </select>
        </div>
        <div class="field">
          <label for="entitlement_id">Specific entitlement (optional — leave blank for the whole account)</label>
          <select id="entitlement_id" name="entitlement_id">
            <option value="">Whole account</option>
            <?php foreach ($assignments as $a): ?>
            <option value="<?= (int) $a['entitlement_id'] ?>"><?= Security::h($a['entitlement_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field"><label for="remediation_description">Description</label><textarea id="remediation_description" name="description" rows="2"></textarea></div>
      <button type="submit" class="btn primary">Create Task</button>
    </form>
  </details>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
