<?php
/** Integrations console. In scope: $user,$NONCE,$programs,$programId,$clients,$subs,
 *  $deliveries,$scopes,$events,$flash,$csrf. */
use Redoubt\Support\Security;

$title = 'Integrations';
$navActive = 'integrations';
$breadcrumbs = ['Home' => '/app', 'Integrations' => null];
require __DIR__ . '/partials/app_header.php';
$host = ($_SERVER['HTTP_HOST'] ?? 'your-host');
?>
<div class="page-header">
  <h1 class="page-title">Integrations — API &amp; Webhooks</h1>
  <form method="get" action="/app/admin/integrations" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($flash): ?>
  <?php if ($flash['type'] === 'token' || $flash['type'] === 'secret'): ?>
    <div class="card" style="border-color:var(--warn)">
      <strong><?= $flash['type'] === 'token' ? 'API token' : 'Webhook signing secret' ?> — copy it now, it won't be shown again:</strong>
      <div class="mono" style="margin-top:8px;padding:10px 12px;background:var(--bg);border:1px solid var(--line);border-radius:8px;word-break:break-all;user-select:all"><?= Security::h($flash['value']) ?></div>
    </div>
  <?php else: ?>
    <div class="card" style="border-color:var(--ok)"><span class="badge b-ok">OK</span> <?= Security::h($flash['value']) ?></div>
  <?php endif; ?>
<?php endif; ?>

<!-- API CLIENTS -->
<div class="card">
  <h3 class="dash-h">API Clients</h3>
  <p class="perm-key" style="margin:-4px 0 12px">Machine access to <code>/api/v1</code>. Present the token as <code>Authorization: Bearer &lt;token&gt;</code>. Responses are permission-trimmed to the client's scopes; export-controlled documents are never returned to API clients.</p>
  <details style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:700">➕ New API client</summary>
    <form method="post" action="/app/admin/integrations" style="margin-top:12px">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="create_key"><input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <label>Name</label><input type="text" name="name" placeholder="e.g. Recruiting sync" required>
      <label>Scopes</label>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($scopes as $k => $lbl): ?>
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="scopes[]" value="<?= Security::h($k) ?>" style="width:auto"> <?= Security::h($k) ?> <span class="perm-key">(<?= Security::h($lbl) ?>)</span></label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Create client</button></div>
    </form>
  </details>
  <?php if ($clients === []): ?><div class="empty-state-sm">No API clients yet.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Name</th><th>Ref</th><th>Scopes</th><th>Status</th><th>Last used</th><th style="width:1%"></th></tr></thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr<?= $c['active'] ? '' : ' style="opacity:.5"' ?>>
          <td><strong><?= Security::h($c['name']) ?></strong></td>
          <td class="perm-key"><?= Security::h($c['client_ref']) ?></td>
          <td class="perm-key"><?= Security::h(implode(', ', $c['scopes'])) ?: '—' ?></td>
          <td><span class="badge <?= $c['active'] ? 'b-ok' : 'b-muted' ?>"><?= $c['active'] ? 'Active' : 'Revoked' ?></span></td>
          <td class="perm-key"><?= Security::h($c['last_used'] ?: 'never') ?></td>
          <td><?php if ($c['active']): ?><form method="post" action="/app/admin/integrations"><?= Security::csrfField() ?><input type="hidden" name="action" value="revoke_key"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm" type="submit">Revoke</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <details style="margin-top:12px"><summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Usage example</summary>
    <pre class="mono" style="margin-top:8px;padding:10px 12px;background:var(--bg);border:1px solid var(--line);border-radius:8px;overflow:auto;font-size:12px">curl -H "Authorization: Bearer &lt;token&gt;" \
  "https://<?= Security::h($host) ?>/api/v1/announcements?program_id=<?= (int) $programId ?>"</pre>
  </details>
</div>

<!-- WEBHOOKS -->
<div class="card">
  <h3 class="dash-h">Webhooks</h3>
  <p class="perm-key" style="margin:-4px 0 12px">We POST signed JSON to your endpoint on program events. Verify the <code>X-Redoubt-Signature: sha256=…</code> HMAC over the raw body using your signing secret.</p>
  <details style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:700">➕ New webhook</summary>
    <form method="post" action="/app/admin/integrations" style="margin-top:12px">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="create_hook"><input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <label>Endpoint URL (https recommended)</label><input type="url" name="url" placeholder="https://hooks.example.us/redoubt" required>
      <label>Events</label>
      <div style="display:flex;flex-wrap:wrap;gap:12px">
        <?php foreach ($events as $k => $lbl): ?>
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="events[]" value="<?= Security::h($k) ?>" style="width:auto"> <?= Security::h($lbl) ?> <span class="perm-key">(<?= Security::h($k) ?>)</span></label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Create webhook</button></div>
    </form>
  </details>
  <?php if ($subs === []): ?><div class="empty-state-sm">No webhooks configured.</div>
  <?php else: foreach ($subs as $s): ?>
    <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
        <div>
          <strong class="mono" style="word-break:break-all"><?= Security::h($s['url']) ?></strong>
          <div class="perm-key" style="margin-top:2px">events: <?= Security::h(implode(', ', $s['events'])) ?: 'none' ?> · <span class="badge <?= $s['active'] ? 'b-ok' : 'b-muted' ?>"><?= $s['active'] ? 'Active' : 'Inactive' ?></span></div>
        </div>
        <div style="display:flex;gap:6px">
          <form method="post" action="/app/admin/integrations"><?= Security::csrfField() ?><input type="hidden" name="action" value="test_hook"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Send test</button></form>
          <form method="post" action="/app/admin/integrations"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete_hook"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
        </div>
      </div>
      <?php $dl = $deliveries[$s['id']] ?? []; if ($dl !== []): ?>
      <div style="margin-top:10px"><span class="perm-key">Recent deliveries:</span>
        <?php foreach ($dl as $d): ?>
          <div class="feed-row" style="padding:4px 0">
            <span class="perm-key" style="width:150px"><?= Security::h((string) $d['at']) ?></span>
            <span class="feed-title"><?= Security::h((string) $d['event']) ?></span>
            <span class="badge <?= $d['status'] === 'delivered' ? 'b-ok' : 'b-warn' ?>"><?= Security::h((string) $d['status']) ?> <?= (int) $d['response_code'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<!-- CONNECTORS (inbound systems of record) -->
<div class="card">
  <h3 class="dash-h">Connectors</h3>
  <p class="perm-key" style="margin:-4px 0 12px">Register external systems of record (ATS, Finance, Contracts, Teams, SharePoint/Graph). Config is stored here; secrets live in your secret manager and are referenced by name — never stored in the portal.</p>
  <details style="margin-bottom:12px"><summary style="cursor:pointer;font-weight:700">➕ Add connector</summary>
    <form method="post" action="/app/admin/integrations" style="margin-top:12px">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="create_connector"><input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
        <div><label>System</label><select name="kind"><?php foreach ($kinds as $k => $lbl): ?><option value="<?= Security::h($k) ?>"><?= Security::h($lbl) ?></option><?php endforeach; ?></select></div>
        <div style="flex:1;min-width:220px"><label>Base URL</label><input type="url" name="base_url" placeholder="https://ats.example.us"></div>
        <div style="flex:1;min-width:180px"><label>Secret reference (name in secret manager)</label><input type="text" name="secret_ref" placeholder="ats-api-key"></div>
      </div>
      <label>Teams incoming-webhook URL (for a Teams connector — receives review digests)</label><input type="url" name="webhook_url" placeholder="https://outlook.office.com/webhook/...">
      <label>Note</label><input type="text" name="note" placeholder="What this connector is for">
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Add connector</button></div>
    </form>
  </details>
  <?php if ($connectors === []): ?><div class="empty-state-sm">No connectors registered.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>System</th><th>Base URL</th><th>Secret ref</th><th>Status</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($connectors as $c): ?>
        <tr<?= $c['active'] ? '' : ' style="opacity:.55"' ?>>
          <td><strong><?= Security::h($kinds[$c['kind']] ?? $c['kind']) ?></strong><?php if (!empty($c['config']['note'])): ?><div class="perm-key"><?= Security::h($c['config']['note']) ?></div><?php endif; ?></td>
          <td class="perm-key mono"><?= Security::h((string) ($c['config']['base_url'] ?? '—')) ?></td>
          <td class="perm-key"><?= Security::h((string) ($c['secret_ref'] ?: '—')) ?></td>
          <td><span class="badge <?= $c['active'] ? 'b-ok' : 'b-muted' ?>"><?= $c['active'] ? 'Active' : 'Disabled' ?></span></td>
          <td>
            <div style="display:flex;gap:6px">
              <form method="post" action="/app/admin/integrations"><?= Security::csrfField() ?><input type="hidden" name="action" value="toggle_connector"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="active" value="<?= $c['active'] ? '0' : '1' ?>"><button class="btn btn-sm" type="submit"><?= $c['active'] ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" action="/app/admin/integrations"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete_connector"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3 class="dash-h">Review digests</h3>
  <p class="perm-key" style="margin:-4px 0 12px">Push pending-review reminders beyond the in-portal bell: a program summary card to each active <strong>Teams</strong> connector webhook, and a personalized email to each approver with work waiting (Microsoft Graph sendMail in GCC High, from <span class="mono">DIGEST_MAIL_SENDER</span>). Unconfigured channels are skipped safely. Schedule it with the <span class="mono">bin/digest.php</span> runner (cron / Kubernetes CronJob); or send one now:</p>
  <form method="post" action="/app/admin/integrations">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="send_digest">
    <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
    <button class="btn btn-primary" type="submit">Send review digests now</button>
  </form>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
