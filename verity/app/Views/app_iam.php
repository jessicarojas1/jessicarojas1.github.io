<?php
/** @var array $bootstrap @var array $people */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div><h1 class="page-title">Access &amp; Security</h1><p>Platform users, roles, and granular permission grants. Role defaults, explicit grants, and denials are always shown separately.</p></div>
</div>

<?php if ($bootstrap['canManage']): ?>
<details class="card mb-14">
  <summary class="btn sm inline-block">Invite User</summary>
  <form method="post" action="/app/admin/iam/create-user" class="mt-12">
    <?= Security::csrfField() ?>
    <div class="field-row">
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required></div>
      <div class="field"><label for="display_name">Display Name</label><input type="text" id="display_name" name="display_name" required></div>
      <div class="field">
        <label for="person_id">Linked Identity (optional)</label>
        <select id="person_id" name="person_id">
          <option value="">—</option>
          <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= Security::h($p['display_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="new_user_password">Initial Password</label>
        <input type="text" id="new_user_password" name="password" minlength="<?= (int) $bootstrap['minPasswordLength'] ?>" required>
        <p class="hint">At least <?= (int) $bootstrap['minPasswordLength'] ?> characters. There is no email invitation flow yet — share this with the new user directly.</p>
      </div>
      <div class="field field-end"><button type="button" class="btn sm" id="generatePassword">Generate</button></div>
    </div>
    <div class="field">
      <label>Roles</label>
      <div class="field-row">
        <?php foreach ($bootstrap['roles'] as $r): ?>
        <label class="check-inline">
          <input type="checkbox" name="roles[]" value="<?= Security::h($r['key']) ?>" class="w-auto"><?= Security::h($r['label']) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <button type="submit" class="btn primary">Create User</button>
  </form>
</details>
<?php endif; ?>

<div class="iam">
  <aside class="iam-users">
    <input type="text" id="userSearch" placeholder="Search users…" class="mb-8">
    <div id="userList"></div>
  </aside>
  <section class="iam-editor">
    <div class="iam-toolbar">
      <div>
        <button type="button" class="btn sm" id="expandAll">Expand All</button>
        <button type="button" class="btn sm" id="collapseAll">Collapse All</button>
      </div>
      <div class="iam-legend">
        <span><span class="dot dot-role"></span>Role default</span>
        <span><span class="dot dot-grant"></span>Explicit grant/deny</span>
        <span><span class="dot dot-none"></span>None</span>
      </div>
      <button type="button" class="btn primary sm" id="saveBtn" disabled>Save changes</button>
    </div>
    <div id="selectedUserInfo" class="hint mb-8">Select a user to edit permissions.</div>
    <div id="userDetails"></div>
    <div id="editor"></div>
  </section>
</div>

<?php
$NONCE = $NONCE ?? '';
?>
<script nonce="<?= Security::h($NONCE) ?>">
  window.VERITY_IAM = <?= Security::jsonForScript($bootstrap) ?>;
</script>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
