<?php
/** @var array $app @var array $connectors @var array $entitlements @var bool $canManage @var array $people @var string $csrf */
use Verity\Support\Security;
use Verity\Support\Connectors;

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($app['name']) ?></h1>
    <p><?= Security::h(ucwords($app['classification'])) ?> · Owner: <?= Security::h($app['system_owner_name'] ?? '—') ?></p>
  </div>
  <a class="btn primary" href="/app/matrix?view=application&application_id=<?= (int) $app['id'] ?>">View in Access Matrix</a>
</div>

<?php if (!empty($_GET['csv_result'])): ?>
<?php $csvResult = (string) $_GET['csv_result']; $csvCls = $csvResult === 'succeeded' ? 'b-ok' : ($csvResult === 'partial' ? 'b-warn' : 'b-risk'); ?>
<div class="card">
  <span class="badge <?= $csvCls ?>">CSV import <?= Security::h($csvResult) ?></span>
  <?= (int) ($_GET['csv_accounts'] ?? 0) ?> account(s) imported, <?= (int) ($_GET['csv_entitlements'] ?? 0) ?> entitlement assignment(s) applied<?= (int) ($_GET['csv_failures'] ?? 0) > 0 ? ', ' . (int) $_GET['csv_failures'] . ' row(s) failed — see Sync History below for details' : '' ?>.
</div>
<?php elseif (!empty($_GET['csv_error'])): ?>
<div class="card"><span class="badge b-risk">CSV import error</span> <?= Security::h((string) $_GET['csv_error']) ?></div>
<?php endif; ?>

<div class="card">
  <h2>Ownership</h2>
  <table class="grid">
    <tbody>
      <tr><th>System Owner</th><td><?= Security::h($app['system_owner_name'] ?? '—') ?></td></tr>
      <tr><th>Technical Owner</th><td><?= Security::h($app['technical_owner_name'] ?? '—') ?></td></tr>
      <tr><th>Business Owner</th><td><?= Security::h($app['business_owner_name'] ?? '—') ?></td></tr>
      <tr><th>Description</th><td><?= Security::h($app['description'] ?? '—') ?></td></tr>
    </tbody>
  </table>
</div>

<div class="card mt-14">
  <h2>Connectors (<?= count($connectors) ?>)</h2>
  <table class="grid">
    <thead><tr><th>Type</th><th>Capabilities</th><th>Health</th><th>Last Sync</th><th>Remediation Mode</th></tr></thead>
    <tbody>
      <?php if ($connectors === []): ?>
      <tr class="empty-row"><td colspan="5" class="empty-state-sm">No connector configured for this application yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($connectors as $c): ?>
      <?php $manifest = is_string($c['capability_manifest']) ? json_decode($c['capability_manifest'], true) : $c['capability_manifest']; ?>
      <tr>
        <td><?= Security::h(ucwords(str_replace('_', ' ', $c['connector_type']))) ?></td>
        <td class="dynamic-col">
          <?php $active = array_keys(array_filter($manifest ?? [])); ?>
          <?= $active === [] ? 'None confirmed' : Security::h(implode(', ', array_map(static fn($k) => str_replace('_',' ',$k), $active))) ?>
        </td>
        <td>
          <?php $health = $c['connection_health']; $cls = $health === 'healthy' ? 'b-ok' : ($health === 'unknown' ? 'b-neutral' : ($health === 'degraded' ? 'b-warn' : 'b-risk')); ?>
          <span class="badge <?= $cls ?>"><?= Security::h(ucwords($health)) ?></span>
        </td>
        <td><?= $c['last_sync_started_at'] ? Security::h(substr((string) $c['last_sync_started_at'], 0, 16)) . ' (' . Security::h((string) $c['last_sync_status']) . ')' : 'Never' ?></td>
        <td><?= Security::h(ucwords($c['remediation_mode'])) ?></td>
      </tr>
      <?php if ($c['connector_type'] === 'csv_import'): ?>
      <tr>
        <td colspan="5">
          <?php if ($canManage): ?>
          <form method="post" action="/app/applications/connector/sync-csv" enctype="multipart/form-data" class="field-row mb-8">
            <?= $csrf ?>
            <input type="hidden" name="connector_id" value="<?= (int) $c['id'] ?>">
            <div class="field">
              <label for="csv_file_<?= (int) $c['id'] ?>">Import CSV</label>
              <input type="file" id="csv_file_<?= (int) $c['id'] ?>" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn sm primary">Run Import</button>
          </form>
          <p class="empty-state-sm">Columns: <code>external_account_id</code> (required), <code>username</code>, <code>account_type</code> (standard/privileged/service/shared), <code>status</code> (enabled/disabled), <code>entitlements</code> (pipe-separated names). Add/update only — a later import never disables an account or removes an assignment just because a row is missing.</p>
          <?php endif; ?>
          <?php if ($c['sync_history'] !== []): ?>
          <details>
            <summary class="btn sm inline-block">Sync History (<?= count($c['sync_history']) ?> most recent)</summary>
            <table class="grid mt-8">
              <thead><tr><th>Started</th><th>Status</th><th>Accounts</th><th>Entitlements</th><th>Failures</th><th>Error Summary</th></tr></thead>
              <tbody>
                <?php foreach ($c['sync_history'] as $job): ?>
                <tr>
                  <td><?= Security::h(substr((string) $job['started_at'], 0, 16)) ?></td>
                  <td>
                    <?php $jcls = $job['status'] === 'succeeded' ? 'b-ok' : ($job['status'] === 'partial' ? 'b-warn' : ($job['status'] === 'running' ? 'b-neutral' : 'b-risk')); ?>
                    <span class="badge <?= $jcls ?>"><?= Security::h(ucwords($job['status'])) ?></span>
                  </td>
                  <td><?= (int) $job['imported_accounts'] ?></td>
                  <td><?= (int) $job['imported_entitlements'] ?></td>
                  <td><?= (int) $job['failure_count'] ?></td>
                  <td class="dynamic-col"><?= Security::h($job['error_summary'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($canManage): ?>
  <details class="mt-12">
    <summary class="btn sm inline-block">Add Connector</summary>
    <form method="post" action="/app/applications/connector" class="mt-12">
      <?= $csrf ?>
      <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
      <div class="field-row">
        <div class="field">
          <label for="connector_type">Connector Type</label>
          <select id="connector_type" name="connector_type">
            <option value="manual">Manual (no automated discovery)</option>
            <option value="csv_import">CSV Import</option>
            <option value="rest_api_manual">REST API (manually configured)</option>
            <option value="entra_gcc_high_mock">Microsoft Entra ID GCC High (mock — Phase 4)</option>
          </select>
        </div>
        <div class="field">
          <label for="remediation_mode">Remediation Mode</label>
          <select id="remediation_mode" name="remediation_mode">
            <option value="manual">Manual</option>
            <option value="automated">Automated</option>
          </select>
        </div>
        <div class="field">
          <label for="default_reviewer_person_id">Default Reviewer</label>
          <select id="default_reviewer_person_id" name="default_reviewer_person_id">
            <option value="">—</option>
            <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= Security::h($p['display_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field"><label for="credential_reference">Credential Reference (secrets manager pointer — never an actual secret)</label>
        <input type="text" id="credential_reference" name="credential_reference" placeholder="e.g. secrets-manager:verity/entra-gcc-high"></div>
      <button type="submit" class="btn primary">Add Connector</button>
    </form>
  </details>
  <?php endif; ?>
</div>

<div class="card mt-14">
  <h2>Entitlements (<?= count($entitlements) ?>)</h2>
  <table class="grid">
    <thead><tr><th>Name</th><th>Type</th><th>Privileged</th><th>Risk</th></tr></thead>
    <tbody>
      <?php if ($entitlements === []): ?>
      <tr class="empty-row"><td colspan="4" class="empty-state-sm">No entitlements recorded for this application yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($entitlements as $e): ?>
      <tr>
        <td><?= Security::h($e['name']) ?></td>
        <td><?= Security::h(ucwords($e['entitlement_type'])) ?></td>
        <td><?= $e['is_privileged'] ? '<span class="badge b-risk">Yes</span>' : '—' ?></td>
        <td>
          <?php $risk = $e['risk_level']; $cls = $risk === 'low' ? 'b-ok' : ($risk === 'medium' ? 'b-warn' : 'b-risk'); ?>
          <span class="badge <?= $cls ?>"><?= Security::h(ucwords($risk)) ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
