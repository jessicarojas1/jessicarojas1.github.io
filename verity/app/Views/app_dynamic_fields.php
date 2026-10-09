<?php
/** @var array $fields @var array $applications @var string $csrf */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div><h1 class="page-title">Dynamic Fields</h1><p>Application-specific and global custom attributes. Retiring a field never deletes its historical values.</p></div>
</div>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Entity</th><th>Scope</th><th>Status</th><th></th></tr></thead>
  <tbody>
    <?php if ($fields === []): ?>
    <tr class="empty-row"><td colspan="7" class="empty-state-sm">No custom fields defined yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($fields as $f): ?>
    <tr>
      <td><?= Security::h($f['label']) ?><?= $f['replaces_field_definition_id'] ? ' <span class="badge b-neutral">replacement</span>' : '' ?></td>
      <td><code><?= Security::h($f['field_key']) ?></code></td>
      <td><?= Security::h(str_replace('_', ' ', $f['field_type'])) ?></td>
      <td><?= Security::h(str_replace('_', ' ', $f['entity_type'])) ?></td>
      <td><?= Security::h($f['application_name'] ?? 'Global') ?></td>
      <td><?= $f['active'] ? '<span class="badge b-ok">Active</span>' : '<span class="badge b-neutral">Retired</span>' ?></td>
      <td>
        <?php if ($f['active']): ?>
        <form method="post" action="/app/admin/dynamic-fields" class="inline">
          <?= $csrf ?>
          <input type="hidden" name="action" value="retire">
          <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
          <button type="submit" class="btn sm danger" data-confirm="Retire this field? Historical values are preserved.">Retire</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="card mt-16">
  <h2>Add Field</h2>
  <form method="post" action="/app/admin/dynamic-fields">
    <?= $csrf ?>
    <input type="hidden" name="action" value="create">
    <div class="field-row">
      <div class="field"><label for="field_key">Key</label><input type="text" id="field_key" name="field_key" placeholder="e.g. coms_gerp_role" required></div>
      <div class="field"><label for="label">Label</label><input type="text" id="label" name="label" placeholder="e.g. COMS GERP Role" required></div>
      <div class="field">
        <label for="field_type">Type</label>
        <select id="field_type" name="field_type">
          <?php foreach (['text','long_text','number','decimal','boolean','date','datetime','single_select','multi_select','url'] as $t): ?>
          <option value="<?= $t ?>"><?= ucwords(str_replace('_',' ',$t)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="entity_type">Applies To</label>
        <select id="entity_type" name="entity_type">
          <option value="system_account">Account</option>
          <option value="entitlement_assignment">Entitlement Assignment</option>
          <option value="person">Person</option>
        </select>
      </div>
      <div class="field">
        <label for="application_id">Application Scope</label>
        <select id="application_id" name="application_id">
          <option value="">Global (all applications)</option>
          <?php foreach ($applications as $a): ?><option value="<?= (int) $a['id'] ?>"><?= Security::h($a['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field field-end">
        <label><input type="checkbox" name="required" value="1" class="check-gap">Required</label>
      </div>
    </div>
    <div class="field"><label for="options">Options (comma-separated, for select types only)</label><input type="text" id="options" name="options" placeholder="Value A, Value B, Value C"></div>
    <button type="submit" class="btn primary">Add Field</button>
  </form>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
