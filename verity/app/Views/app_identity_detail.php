<?php
/** @var array $person @var array $accounts @var array $entitlementsByAccount @var array $reports @var array $risk @var bool $canEditAccess @var string $csrf */
use Verity\Support\Security;

$appScript = '/assets/identity_detail.js';
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($person['display_name']) ?></h1>
    <p>
      <?= Security::h($person['position_title'] ?? '—') ?> · <?= Security::h($person['department'] ?? '—') ?>
      · <?= Security::h(ucwords(str_replace('_', ' ', $person['identity_type']))) ?>
    </p>
  </div>
  <a class="btn primary" href="/app/matrix?view=person&person_id=<?= (int) $person['id'] ?>">View in Access Matrix</a>
</div>

<div class="field-row">
  <div class="card flex-1-min260">
    <h2>Profile</h2>
    <table class="grid">
      <tbody>
        <tr><th>Employee ID</th><td><?= Security::h($person['employee_id'] ?? '—') ?></td></tr>
        <tr><th>Email</th><td><?= Security::h($person['email'] ?? '—') ?></td></tr>
        <tr><th>Business Unit</th><td><?= Security::h($person['business_unit'] ?? '—') ?></td></tr>
        <tr><th>Manager</th><td><?= $person['manager_name'] ? '<a href="/app/identities/view?id=' . (int) $person['manager_person_id'] . '">' . Security::h($person['manager_name']) . '</a>' : '—' ?></td></tr>
        <tr><th>Location</th><td><?= Security::h($person['location'] ?? '—') ?></td></tr>
        <tr><th>Employment Status</th><td><?= Security::h(ucwords(str_replace('_',' ',$person['employment_status']))) ?></td></tr>
        <tr><th>Start Date</th><td><?= Security::h($person['start_date'] ?? '—') ?></td></tr>
        <tr><th>Identity Authority</th><td><?= Security::h($person['identity_authority']) ?></td></tr>
      </tbody>
    </table>
  </div>
  <div class="card flex-1-min260">
    <h2>Direct Reports (<?= count($reports) ?>)</h2>
    <?php if ($reports === []): ?>
      <p class="empty-state-sm">No direct reports.</p>
    <?php else: ?>
      <table class="grid">
        <tbody>
          <?php foreach ($reports as $r): ?>
          <tr>
            <td><a href="/app/identities/view?id=<?= (int) $r['id'] ?>"><?= Security::h($r['display_name']) ?></a></td>
            <td><?= Security::h($r['position_title'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-14">
  <?php $riskCls = ['low' => 'b-neutral', 'medium' => 'b-warn', 'high' => 'b-risk', 'critical' => 'b-critical'][$risk['tier']]; ?>
  <h2>Risk Score — <span class="badge <?= $riskCls ?>"><?= ucfirst($risk['tier']) ?></span> (<?= (int) $risk['score'] ?>)</h2>
  <p class="hint">A v1 heuristic computed from existing entitlement risk levels, application classifications, and account/employment conditions already on record — not a business-approved risk model yet. See <code>OPEN_ITEMS.md</code> and <code>app/Support/RiskScoring.php</code> for exactly how this is calculated and why the weights are what they are.</p>
  <table class="grid">
    <thead><tr><th>Factor</th><th>Contributing items</th><th class="num">Weight</th></tr></thead>
    <tbody>
      <tr><th>Entitlements held (by risk level + privileged)</th><td><?= (int) $risk['factors']['entitlement']['count'] ?></td><td class="num"><?= (int) $risk['factors']['entitlement']['weight'] ?></td></tr>
      <tr><th>Applications touched (by data classification)</th><td><?= (int) $risk['factors']['application']['count'] ?></td><td class="num"><?= (int) $risk['factors']['application']['weight'] ?></td></tr>
      <tr><th>Privileged accounts</th><td><?= (int) $risk['factors']['privileged_account']['count'] ?></td><td class="num"><?= (int) $risk['factors']['privileged_account']['weight'] ?></td></tr>
      <tr><th>Expired temporary access still assigned</th><td><?= (int) $risk['factors']['expired_temporary']['count'] ?></td><td class="num"><?= (int) $risk['factors']['expired_temporary']['weight'] ?></td></tr>
      <tr><th>Enabled accounts on a terminated identity</th><td><?= (int) $risk['factors']['terminated_enabled']['count'] ?></td><td class="num"><?= (int) $risk['factors']['terminated_enabled']['weight'] ?></td></tr>
    </tbody>
  </table>
</div>

<div class="card mt-14">
  <h2>System Accounts &amp; Access (<?= count($accounts) ?>)</h2>
  <p class="hint">
    Every account discovered across connected applications for this person, and exactly what each one holds.
    <?php if ($canEditAccess): ?>
    You can grant a new entitlement directly, or flag an existing one for removal — see
    <code>app/Support/EntitlementAssignments.php</code> for why "remove" opens a tracked remediation task rather
    than deleting the record immediately.
    <?php endif; ?>
  </p>
  <?php if (!empty($_GET['granted'])): ?><div class="card"><span class="badge b-ok">Entitlement granted</span></div><?php endif; ?>
  <?php if (!empty($_GET['flagged'])): ?><div class="card"><span class="badge b-ok">Flagged for removal — see Remediation Tasks</span></div><?php endif; ?>

  <?php if ($accounts === []): ?>
  <p class="empty-state-sm">No accounts linked to this person yet.</p>
  <?php endif; ?>

  <?php foreach ($accounts as $a): ?>
  <?php $accountEntitlements = $entitlementsByAccount[(int) $a['id']] ?? []; ?>
  <div class="card mt-12">
    <div class="row-between">
      <div>
        <strong><?= Security::h($a['application_name']) ?></strong> —
        <?= Security::h($a['username'] ?? $a['external_account_id']) ?>
        <?= $a['status'] === 'enabled' ? '<span class="badge b-ok">Enabled</span>' : '<span class="badge b-neutral">Disabled</span>' ?>
        <span class="hint"><?= Security::h(ucwords($a['account_type'])) ?> account</span>
      </div>
      <a href="/app/accounts/view?id=<?= (int) $a['id'] ?>">Account Details</a>
    </div>
    <table class="grid mt-8">
      <thead><tr><th>Entitlement</th><th>Type</th><th>Risk</th><th>Expires</th><?php if ($canEditAccess): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php if ($accountEntitlements === []): ?>
        <tr class="empty-row"><td colspan="<?= $canEditAccess ? 5 : 4 ?>" class="empty-state-sm">No entitlements on this account.</td></tr>
        <?php endif; ?>
        <?php foreach ($accountEntitlements as $e): ?>
        <tr>
          <td><?= Security::h($e['entitlement_name']) ?><?= $e['is_privileged'] ? ' <span class="badge b-risk">Privileged</span>' : '' ?></td>
          <td><?= Security::h(ucwords($e['assignment_type'])) ?></td>
          <td><?php $rcls = ['low' => 'b-ok', 'medium' => 'b-warn', 'high' => 'b-risk', 'critical' => 'b-critical'][$e['risk_level']] ?? 'b-neutral'; ?><span class="badge <?= $rcls ?>"><?= Security::h(ucwords($e['risk_level'])) ?></span></td>
          <td><?= $e['expires_at'] ? Security::h(substr((string) $e['expires_at'], 0, 10)) : '—' ?></td>
          <?php if ($canEditAccess): ?>
          <td>
            <form method="post" action="/app/identities/flag-for-removal" class="field-row">
              <?= $csrf ?>
              <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
              <input type="hidden" name="system_account_id" value="<?= (int) $a['id'] ?>">
              <input type="hidden" name="entitlement_id" value="<?= (int) $e['entitlement_id'] ?>">
              <input type="text" name="note" placeholder="Reason (optional)">
              <button type="submit" class="btn sm danger" data-confirm="Flag this entitlement for removal? This opens a tracked remediation task — it does not delete the access record immediately.">Flag for Removal</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($canEditAccess): ?>
    <details class="mt-8">
      <summary class="btn sm">+ Add entitlement</summary>
      <form method="post" action="/app/identities/grant" class="field-row mt-8"
            data-add-entitlement data-application-id="<?= (int) $a['application_id'] ?>" data-account-id="<?= (int) $a['id'] ?>" data-person-id="<?= (int) $person['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
        <input type="hidden" name="system_account_id" value="<?= (int) $a['id'] ?>">
        <select name="entitlement_id" class="entitlement-select" disabled required>
          <option value="">Loading…</option>
        </select>
        <input type="text" name="note" placeholder="Note (optional)">
        <button type="submit" class="btn sm primary" data-confirm="Grant this entitlement now? This creates real access in Verity's own record immediately — it is not a request someone else approves.">Grant</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
