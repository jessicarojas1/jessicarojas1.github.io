<?php
/** @var array $person @var array $accounts @var array $reports @var array $risk */
use Verity\Support\Security;

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
  <h2>System Accounts (<?= count($accounts) ?>)</h2>
  <p class="hint">Every account discovered across connected applications for this person. This is the "one person → many accounts" view (section 7 of the build directive).</p>
  <table class="grid">
    <thead><tr><th>Application</th><th>Account</th><th>Type</th><th>Status</th><th class="num">Entitlements</th><th></th></tr></thead>
    <tbody>
      <?php if ($accounts === []): ?>
      <tr class="empty-row"><td colspan="6" class="empty-state-sm">No accounts linked to this person yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($accounts as $a): ?>
      <tr>
        <td><?= Security::h($a['application_name']) ?></td>
        <td><?= Security::h($a['username'] ?? $a['external_account_id']) ?></td>
        <td><?= Security::h(ucwords($a['account_type'])) ?></td>
        <td><?= $a['status'] === 'enabled' ? '<span class="badge b-ok">Enabled</span>' : '<span class="badge b-neutral">Disabled</span>' ?></td>
        <td class="num"><?= (int) $a['entitlement_count'] ?></td>
        <td><a href="/app/accounts/view?id=<?= (int) $a['id'] ?>">Details</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
