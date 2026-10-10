<?php
/**
 * @var string $NONCE @var array $user @var array $kpis
 * @var array $byDepartment @var array $byApplication @var array $connectorHealth
 */
use Verity\Support\Security;
use Verity\Support\Db;

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Enterprise Dashboard</h1>
    <p>Identity, account, and entitlement visibility computed from stored records.</p>
  </div>
</div>

<?php if (!Db::isConfigured()): ?>
  <div class="card"><p class="hint">DATABASE_URL is not configured. Connect PostgreSQL and run <code>database/schema.sql</code> to populate this dashboard.</p></div>
<?php else: ?>

<div class="kpi-grid">
  <div class="kpi"><div class="n"><?= number_format($kpis['total_identities']) ?></div><div class="label">Total Identities</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['active_identities']) ?></div><div class="label">Active Identities</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['connected_applications']) ?></div><div class="label">Connected Applications</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['discovered_accounts']) ?></div><div class="label">Discovered Accounts</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['discovered_entitlement_assignments']) ?></div><div class="label">Entitlement Assignments</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['privileged_accounts']) ?></div><div class="label">Privileged Accounts</div></div>
  <div class="kpi <?= $kpis['unmatched_accounts'] > 0 ? 'warn' : '' ?>"><div class="n"><?= number_format($kpis['unmatched_accounts']) ?></div><div class="label">Unmatched Accounts</div></div>
  <div class="kpi <?= $kpis['terminated_with_enabled_accounts'] > 0 ? 'risk' : '' ?>"><div class="n"><?= number_format($kpis['terminated_with_enabled_accounts']) ?></div><div class="label">Terminated, Still Enabled</div></div>
  <div class="kpi <?= $kpis['expired_temporary_access'] > 0 ? 'risk' : '' ?>"><div class="n"><?= number_format($kpis['expired_temporary_access']) ?></div><div class="label">Expired Temporary Access</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['disabled_accounts']) ?></div><div class="label">Disabled Accounts</div></div>
  <div class="kpi"><div class="n"><?= number_format($kpis['active_campaigns']) ?></div><div class="label">Active Campaigns</div></div>
  <div class="kpi <?= $kpis['pending_campaign_reviews'] > 0 ? 'warn' : '' ?>"><div class="n"><?= number_format($kpis['pending_campaign_reviews']) ?></div><div class="label">Pending Campaign Reviews</div></div>
  <div class="kpi <?= $kpis['open_remediation_tasks'] > 0 ? 'warn' : '' ?>"><div class="n"><?= number_format($kpis['open_remediation_tasks']) ?></div><div class="label">Open Remediation Tasks</div></div>
</div>

<div class="field-row">
  <div class="card flex-1-min280">
    <h2>Accounts by Application</h2>
    <table class="grid">
      <thead><tr><th>Application</th><th class="num">Accounts</th></tr></thead>
      <tbody>
        <?php if ($byApplication === []): ?><tr class="empty-row"><td colspan="2" class="empty-state-sm">No applications yet.</td></tr><?php endif; ?>
        <?php foreach ($byApplication as $row): ?>
        <tr><td><?= Security::h($row['name']) ?></td><td class="num"><?= number_format((int) $row['account_count']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card flex-1-min280">
    <h2>Identities by Department</h2>
    <table class="grid">
      <thead><tr><th>Department</th><th class="num">People</th></tr></thead>
      <tbody>
        <?php if ($byDepartment === []): ?><tr class="empty-row"><td colspan="2" class="empty-state-sm">No department data yet.</td></tr><?php endif; ?>
        <?php foreach ($byDepartment as $row): ?>
        <tr><td><?= Security::h($row['department']) ?></td><td class="num"><?= number_format((int) $row['n']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card flex-1-min280">
    <h2>Connector Health</h2>
    <table class="grid">
      <thead><tr><th>Health</th><th class="num">Connectors</th></tr></thead>
      <tbody>
        <?php if ($connectorHealth === []): ?><tr class="empty-row"><td colspan="2" class="empty-state-sm">No connectors configured yet.</td></tr><?php endif; ?>
        <?php foreach ($connectorHealth as $row): ?>
        <tr><td><?= Security::h($row['connection_health']) ?></td><td class="num"><?= number_format((int) $row['n']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-14">
  <p class="hint">
    Risk intelligence and separation-of-duties KPIs are not shown here because those modules are not implemented
    yet — see <code>OPEN_ITEMS.md</code>. Showing a zero for them would misleadingly read as "nothing outstanding"
    rather than "not built yet".
  </p>
</div>

<?php endif; ?>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
