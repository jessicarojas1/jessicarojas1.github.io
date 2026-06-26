<?php
$pageTitle   = 'Webhooks';
$breadcrumbs = [['Admin', '/admin'], ['Webhooks', null]];
ob_start();

$providerIcons = [
    'slack'       => 'bi-slack',
    'teams'       => 'bi-microsoft-teams',
    'jira'        => 'bi-bug',
    'pagerduty'   => 'bi-bell-fill',
    'servicenow'  => 'bi-layers',
    'discord'     => 'bi-discord',
    'google_chat' => 'bi-chat-dots-fill',
    'opsgenie'    => 'bi-exclamation-triangle-fill',
    'datadog'     => 'bi-activity',
    'splunk'      => 'bi-search',
    'generic'     => 'bi-globe2',
];
$providerLabels = [
    'slack'       => 'Slack',
    'teams'       => 'Microsoft Teams',
    'jira'        => 'Jira',
    'pagerduty'   => 'PagerDuty',
    'servicenow'  => 'ServiceNow',
    'discord'     => 'Discord',
    'google_chat' => 'Google Chat',
    'opsgenie'    => 'OpsGenie',
    'datadog'     => 'Datadog',
    'splunk'      => 'Splunk HEC',
    'generic'     => 'Generic HTTP',
];
?>

<?php if (!empty($_GET['created'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Webhook endpoint created successfully.</div>
<?php endif; ?>
<?php if (!empty($_GET['updated'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Webhook endpoint updated.</div>
<?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Webhook endpoint deleted.</div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-globe2"></i> Webhook Endpoints</h1>
  <button class="btn btn-primary" data-show-modal="createWebhookModal">
    <i class="bi bi-plus-lg"></i> New Webhook
  </button>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th scope="col">Name</th>
          <th scope="col">Provider</th>
          <th scope="col">URL</th>
          <th scope="col">Events</th>
          <th scope="col">Status</th>
          <th scope="col">Last Delivery</th>
          <th scope="col"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($endpoints): foreach ($endpoints as $ep):
          $icon       = $providerIcons[$ep['provider']] ?? 'bi-globe2';
          $provLabel  = $providerLabels[$ep['provider']] ?? ucfirst($ep['provider']);
          $epEvents   = json_decode($ep['event_types'] ?? '[]', true) ?? [];
          $truncUrl   = strlen($ep['url']) > 55 ? substr($ep['url'], 0, 52) . '…' : $ep['url'];
          $lastStatus = $ep['last_delivery_status'] ?? null;
        ?>
          <tr <?= !$ep['is_active'] ? 'class="row-muted"' : '' ?>>

            <td>
              <strong><?= Security::h($ep['name']) ?></strong>
              <?php if ($ep['creator_name']): ?>
                <div class="text-muted text-sm">by <?= Security::h($ep['creator_name']) ?></div>
              <?php endif; ?>
            </td>

            <td>
              <span class="d-flex align-items-center gap-1">
                <i class="bi <?= $icon ?>" title="<?= Security::h($provLabel) ?>"></i>
                <?= Security::h($provLabel) ?>
              </span>
            </td>

            <td>
              <code class="mono text-sm" title="<?= Security::h($ep['url']) ?>"><?= Security::h($truncUrl) ?></code>
            </td>

            <td>
              <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:220px">
                <?php if ($epEvents): foreach ($epEvents as $evt): ?>
                  <span class="tag" style="font-size:11px"><?= Security::h($evt) ?></span>
                <?php endforeach; else: ?>
                  <span class="text-muted text-sm">None</span>
                <?php endif; ?>
              </div>
            </td>

            <td>
              <form method="POST" action="/admin/webhooks/<?= (int) $ep['id'] ?>/toggle" style="display:inline">
                <?= Security::csrfField() ?>
                <button type="submit"
                        class="toggle-switch <?= $ep['is_active'] ? 'on' : '' ?>"
                        title="<?= $ep['is_active'] ? 'Active — click to disable' : 'Inactive — click to enable' ?>">
                  <span></span>
                </button>
              </form>
            </td>

            <td>
              <?php if ($ep['last_delivery_at']): ?>
                <span class="text-sm"><?= date('M j, g:ia', strtotime($ep['last_delivery_at'])) ?></span><br>
                <?php if ($lastStatus === 'delivered'): ?>
                  <span class="badge badge-green">delivered</span>
                <?php elseif ($lastStatus === 'failed'): ?>
                  <span class="badge badge-red">failed</span>
                <?php else: ?>
                  <span class="badge badge-yellow">pending</span>
                <?php endif; ?>
                <span class="text-muted text-sm">(<?= (int) $ep['total_deliveries'] ?> total)</span>
              <?php else: ?>
                <span class="text-muted text-sm">No deliveries yet</span>
              <?php endif; ?>
            </td>

            <td style="white-space:nowrap">
              <a href="/admin/webhooks/<?= (int) $ep['id'] ?>/edit"
                 class="btn btn-ghost btn-sm" title="Edit">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="/admin/webhooks/<?= (int) $ep['id'] ?>/deliveries"
                 class="btn btn-ghost btn-sm" title="Delivery log">
                <i class="bi bi-list-ul"></i>
              </a>
              <form method="POST" action="/admin/webhooks/<?= (int) $ep['id'] ?>/delete"
                    style="display:inline"
                    data-confirm="Delete this webhook endpoint and all its delivery records?">
                <?= Security::csrfField() ?>
                <button type="submit" class="btn btn-ghost btn-sm text-danger" title="Delete">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </td>

          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="7" class="empty-row">
              <div class="empty-state-sm">
                <i class="bi bi-globe2"></i>
                <p>No webhook endpoints configured. Add one to push events to Slack, Teams, Discord, Jira, PagerDuty, OpsGenie, or any HTTP endpoint.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Create Webhook Modal ──────────────────────────────────────────────── -->
<div class="um-overlay" id="createWebhookModal" style="display:none">
  <div class="um-dialog" style="max-width:600px">
    <div class="um-header">
      <h3><i class="bi bi-globe2"></i> New Webhook Endpoint</h3>
      <button class="um-close" data-close-modal="createWebhookModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <form method="POST" action="/admin/webhooks/create">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label required">Name</label>
          <input type="text" name="name" class="form-control"
                 placeholder="e.g. Slack #security-alerts" required maxlength="255">
        </div>

        <div class="form-group">
          <label class="form-label required">Provider</label>
          <select name="provider" class="form-control">
            <?php foreach ($providers as $val => $label): ?>
              <option value="<?= Security::h($val) ?>"><?= Security::h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label required">Endpoint URL</label>
          <input type="url" name="url" class="form-control"
                 placeholder="https://hooks.example.com/..." required>
          <div class="form-hint">Must be an http:// or https:// URL.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Signing Secret</label>
          <input type="text" name="secret" class="form-control"
                 placeholder="Optional HMAC signing key" autocomplete="off">
          <div class="form-hint">If set, each delivery will include an <code>X-AEGIS-Signature</code> header.</div>
        </div>

        <div class="form-group">
          <label class="form-label required">Events to Subscribe</label>
          <?php
          $modalCategoryMap = [
              'risk'         => 'Risks',
              'incident'     => 'Incidents',
              'audit'        => 'Audits',
              'control'      => 'Compliance',
              'compliance'   => 'Compliance',
              'gap_analysis' => 'Compliance',
              'change'       => 'Changes',
              'policy'       => 'Policies',
              'vendor'       => 'Vendors',
              'issue'        => 'Issues',
              'asset'        => 'Assets / BCP',
              'bcp'          => 'Assets / BCP',
              'dr'           => 'Assets / BCP',
          ];
          $modalGroups = [];
          foreach ($eventTypes as $val => $lbl) {
              $prefix = strtok($val, '.');
              $grp    = $modalCategoryMap[$prefix] ?? 'Other';
              $modalGroups[$grp][$val] = $lbl;
          }
          ?>
          <?php foreach ($modalGroups as $grpName => $grpEvents): ?>
          <div style="margin-bottom:8px">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:4px"><?= Security::h($grpName) ?></div>
            <div class="checkbox-group" style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
              <?php foreach ($grpEvents as $val => $lbl): ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px">
                  <input type="checkbox" name="event_types[]" value="<?= Security::h($val) ?>">
                  <?= Security::h($lbl) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Custom Headers <span class="text-muted">(JSON)</span></label>
          <textarea name="custom_headers" class="form-control" rows="3"
                    placeholder='{"Authorization": "Bearer token123"}'
                    style="font-family:monospace;font-size:13px"></textarea>
          <div class="form-hint">Optional JSON object of extra HTTP headers to send with each delivery.</div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Create Webhook
          </button>
          <button type="button" class="btn btn-ghost" data-close-modal="createWebhookModal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
