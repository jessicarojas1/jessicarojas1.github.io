<?php
$pageTitle    = 'Personal Access Tokens';
$activeModule = 'profile_tokens';
$breadcrumbs  = [['Account', null], ['Personal Access Tokens', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Personal Access Tokens</h1><p class="page-subtitle">Tokens authenticate to the PALADIN API as you, scoped to your permissions.</p></div>
</div>

<div class="iam" style="grid-template-columns:1fr 320px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-key-fill"></i> Your Tokens</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0">
        <thead><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Last Used</th><th>Expires</th><th style="width:60px"></th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $t): ?>
          <tr>
            <td><?= Security::h($t['name']) ?></td>
            <td><span class="chip"><?= Security::h($t['token_prefix']) ?>…</span></td>
            <td><span class="badge badge-blue"><?= Security::h($t['scopes']) ?></span></td>
            <td class="form-hint"><?= $t['last_used'] ? Security::h(View::timeAgo($t['last_used'])) : 'never' ?></td>
            <td class="form-hint"><?= $t['expires_at'] ? Security::h(View::fmtDate($t['expires_at'])) : '—' ?></td>
            <td style="text-align:right"><form method="POST" action="/profile/tokens/<?= (int)$t['id'] ?>/revoke" style="margin:0" data-confirm="Revoke this token? Applications using it will stop working."><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i></button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tokens): ?>
          <tr><td colspan="6" class="empty-row"><div class="empty-state-sm"><i class="bi bi-key"></i><p>No personal access tokens yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-plus-lg"></i> New Token</span></div></div>
    <div class="card-body">
      <form method="POST" action="/profile/tokens">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label" for="tok_name">Name</label><input type="text" id="tok_name" name="name" class="form-control" required maxlength="120" placeholder="e.g. CI pipeline"></div>
        <div class="form-group"><label class="form-label" for="tok_scopes">Scope</label>
          <select id="tok_scopes" name="scopes" class="form-select">
            <option value="read">Read only</option>
            <option value="write">Read &amp; write</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label" for="tok_expires">Expires (optional)</label><input type="date" id="tok_expires" name="expires_at" class="form-control"></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Generate Token</button></div>
        <div class="form-hint" style="margin-top:10px"><i class="bi bi-info-circle"></i> The full token is shown once after creation — copy it immediately. Send it as <code>Authorization: Bearer &lt;token&gt;</code>.</div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
