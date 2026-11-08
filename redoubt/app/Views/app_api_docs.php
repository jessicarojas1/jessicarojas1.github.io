<?php
/** REST API & Webhook documentation. In scope: $user,$NONCE,$spec,$scopes,$events,$host. */
use Redoubt\Support\Security;

$title = 'API Docs';
$navActive = 'apidocs';
$breadcrumbs = ['Home' => '/app', 'API Docs' => null];
require __DIR__ . '/partials/app_header.php';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = 'https://' . $host . '/api/v1';
?>
<div class="page-header"><h1 class="page-title">REST API &amp; Webhooks</h1>
  <a class="btn btn-sm" href="/api/v1/openapi.json" target="_blank" rel="noopener">openapi.json ↗</a>
</div>

<div class="card">
  <h3 class="dash-h">Authentication</h3>
  <p class="perm-key" style="margin:-4px 0 10px">Create a token in <a href="/app/admin/integrations">Integrations → API Clients</a>, then send it on every request:</p>
  <pre class="mono" style="padding:10px 12px;background:var(--bg);border:1px solid var(--line);border-radius:8px;overflow:auto;font-size:12px">curl -H "Authorization: Bearer rk_&lt;ref&gt;_&lt;secret&gt;" \
  "<?= Security::h($base) ?>/announcements?program_id=1"</pre>
  <p class="perm-key" style="margin-top:8px">Every response is permission-trimmed to the token's scopes and program; export-controlled documents are never returned to API clients.</p>
</div>

<div class="card">
  <h3 class="dash-h">Endpoints</h3>
  <div class="tablewrap"><table>
    <thead><tr><th>Method</th><th>Path</th><th>Summary</th><th>Auth</th></tr></thead>
    <tbody>
    <?php foreach ($spec['paths'] as $path => $methods): ?>
      <?php foreach ($methods as $m => $op): ?>
        <tr>
          <td><span class="badge b-ok"><?= Security::h(strtoupper($m)) ?></span></td>
          <td class="mono"><?= Security::h('/api/v1' . $path) ?></td>
          <td><?= Security::h((string) ($op['summary'] ?? '')) ?></td>
          <td class="perm-key"><?= isset($op['security']) && $op['security'] === [] ? 'public' : 'bearer' ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="dash-cols">
  <div class="card">
    <h3 class="dash-h">Scopes</h3>
    <?php foreach ($scopes as $k => $lbl): ?>
      <div class="feed-row"><span class="mono" style="width:160px;flex:0 0 auto"><?= Security::h($k) ?></span><span class="feed-title perm-key"><?= Security::h($lbl) ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h3 class="dash-h">Webhook events</h3>
    <?php foreach ($events as $k => $lbl): ?>
      <div class="feed-row"><span class="mono" style="width:190px;flex:0 0 auto"><?= Security::h($k) ?></span><span class="feed-title perm-key"><?= Security::h($lbl) ?></span></div>
    <?php endforeach; ?>
    <h4 class="sub" style="margin:14px 0 6px;font-size:14px">Verify a delivery</h4>
    <pre class="mono" style="padding:10px 12px;background:var(--bg);border:1px solid var(--line);border-radius:8px;overflow:auto;font-size:11.5px"># header: X-Redoubt-Signature: sha256=&lt;hex&gt;
expected = "sha256=" + hmac_sha256(raw_body, signing_secret)
assert constant_time_equals(expected, header)</pre>
  </div>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
