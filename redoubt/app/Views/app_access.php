<?php
/**
 * Onboarding / offboarding console. Provided by AccessController::index().
 * In scope: $user, $NONCE, $programs, $programId, $can, $pending, $roster,
 *           $companies, $roles, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Onboarding';
$navActive = 'onboarding';
$breadcrumbs = ['Home' => '/app', 'Onboarding' => null];
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <h1 class="page-title">Onboarding &amp; Access</h1>
  <form method="get" action="/app/admin/access" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($can['request']): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:700">➕ Request access for a person</summary>
    <form method="post" action="/app/admin/access" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="request">
      <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:200px"><label>Work email</label><input type="email" name="email" required placeholder="person@company.us"></div>
        <div style="flex:1;min-width:160px"><label>Name</label><input type="text" name="display_name"></div>
      </div>
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
        <div><label>Role</label>
          <select name="requested_role" required>
            <?php foreach ($roles as $k => $name): ?><option value="<?= Security::h($k) ?>"><?= Security::h($name) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label>Kind</label>
          <select name="kind"><option value="external">External (subcontractor)</option><option value="customer">Customer / COR</option><option value="internal">Internal</option></select>
        </div>
        <div><label>Company</label>
          <select name="company_id"><option value="">(none)</option>
            <?php foreach ($companies as $cid => $cname): ?><option value="<?= (int) $cid ?>"><?= Security::h($cname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label>US person? (export gate)</label>
          <select name="is_us_person"><option value="">Unverified</option><option value="1">Yes — US person</option><option value="0">No</option></select>
        </div>
      </div>
      <label>Justification</label>
      <input type="text" name="justification" placeholder="Why this access is needed">
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Submit request</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<?php if ($can['grant'] || $can['view']): ?>
<div class="card">
  <h3 style="margin:0 0 10px;font-size:16px">Pending requests</h3>
  <?php if ($pending === []): ?>
    <div class="empty-state-sm">No pending access requests.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Person</th><th>Role / Company</th><th>US person</th><th>Sponsor</th><?php if ($can['grant']): ?><th style="width:1%">Decision</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($pending as $r): ?>
        <tr>
          <td><strong><?= Security::h($r['display_name'] ?: $r['email']) ?></strong><div class="perm-key"><?= Security::h($r['email']) ?></div></td>
          <td><?= Security::h($r['requested_role']) ?><?php if ($r['company_name']): ?> · <?= Security::h($r['company_name']) ?><?php endif; ?></td>
          <td><?php if ($r['is_us_person'] === true || $r['is_us_person'] === 't'): ?><span class="badge b-ok">US</span><?php elseif ($r['is_us_person'] === false || $r['is_us_person'] === 'f'): ?><span class="badge b-warn">non-US</span><?php else: ?><span class="perm-key">—</span><?php endif; ?></td>
          <td class="perm-key"><?= Security::h($r['sponsor_name'] ?: '—') ?></td>
          <?php if ($can['grant']): ?>
          <td>
            <div style="display:flex;gap:6px">
              <form method="post" action="/app/admin/access"><?= Security::csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Approve</button></form>
              <form method="post" action="/app/admin/access"><?= Security::csrfField() ?><input type="hidden" name="action" value="deny"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm" type="submit">Deny</button></form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($can['revoke'] || $can['view']): ?>
<div class="card">
  <h3 style="margin:0 0 10px;font-size:16px">Program roster</h3>
  <?php if ($roster === []): ?>
    <div class="empty-state-sm">No members.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Member</th><th>Roles</th><th>Company</th><th>Status</th><?php if ($can['revoke'] || $can['grant']): ?><th style="width:1%">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($roster as $m): ?>
        <tr>
          <td><strong><?= Security::h($m['display_name']) ?></strong><div class="perm-key"><?= Security::h($m['email']) ?></div></td>
          <td class="perm-key"><?= Security::h($m['roles'] ?? '') ?></td>
          <td><?= Security::h($m['company_name'] ?: '—') ?></td>
          <td><span class="badge <?= ($m['status'] === 'active') ? 'b-ok' : 'b-muted' ?>"><?= Security::h(ucfirst((string) $m['status'])) ?></span></td>
          <?php if ($can['revoke'] || $can['grant']): ?>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
              <?php if ($can['grant']): ?>
              <form method="post" action="/app/admin/access" style="display:flex;gap:4px;align-items:center">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="set_password">
                <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
                <input type="password" name="password" placeholder="Set password" minlength="8" required style="width:130px">
                <button class="btn btn-sm" type="submit">Set</button>
              </form>
              <?php endif; ?>
              <?php if ($can['revoke']): ?>
              <form method="post" action="/app/admin/access"><?= Security::csrfField() ?><input type="hidden" name="action" value="offboard"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm" type="submit">Offboard</button></form>
              <?php endif; ?>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="perm-key" style="margin-top:8px">Offboarding removes program membership immediately; Entra access revocation &amp; session termination is a follow-on identity step (see docs/SECURITY.md).</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
