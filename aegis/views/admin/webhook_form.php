<?php
$isEdit     = isset($endpoint);
$pageTitle  = $isEdit ? 'Edit Webhook' : 'New Webhook';
$breadcrumbs = [
    ['Admin', '/admin'],
    ['Webhooks', '/admin/webhooks'],
    [$isEdit ? 'Edit' : 'New Webhook', null],
];
$formAction = $isEdit ? '/admin/webhooks/' . (int)$endpoint['id'] . '/update' : '/admin/webhooks/create';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Webhook Endpoint' : 'New Webhook Endpoint' ?></h1>
        <p class="page-subtitle">Configure outbound webhook delivery for AEGIS events</p>
    </div>
    <div>
        <a href="/admin/webhooks" class="btn btn-secondary">Cancel</a>
    </div>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert-box error" style="margin-bottom:1rem"><?= Security::h($_SESSION['flash_error']) ?><?php unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="<?= Security::h($formAction) ?>">
            <?= Security::csrfField() ?>

            <div class="form-section mb-4">
                <h3 class="form-section-title">Basic Settings</h3>

                <div class="form-group mb-3">
                    <label for="name" class="form-label">Endpoint Name <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name" class="form-control"
                           value="<?= Security::h($isEdit ? $endpoint['name'] : '') ?>"
                           placeholder="e.g. Slack Alerts Channel" required>
                </div>

                <div class="form-group mb-3">
                    <label for="url" class="form-label">Webhook URL <span class="text-danger">*</span></label>
                    <input type="url" id="url" name="url" class="form-control"
                           value="<?= Security::h($isEdit ? $endpoint['url'] : '') ?>"
                           placeholder="https://hooks.example.com/..." required>
                    <div class="form-hint">Must be a valid HTTP or HTTPS URL.</div>
                </div>

                <div class="form-group mb-3">
                    <label for="provider" class="form-label">Provider</label>
                    <select id="provider" name="provider" class="form-control">
                        <?php foreach ($providers as $key => $label): ?>
                            <option value="<?= Security::h($key) ?>"
                                <?= ($isEdit && $endpoint['provider'] === $key) ? 'selected' : (!$isEdit && $key === 'generic' ? 'selected' : '') ?>>
                                <?= Security::h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Controls the payload format sent to the endpoint.</div>
                </div>

                <div class="form-group mb-3">
                    <label for="secret" class="form-label">Signing Secret</label>
                    <input type="text" id="secret" name="secret" class="form-control"
                           value="<?= Security::h($isEdit ? Security::decryptSetting($endpoint['secret'] ?? '') : '') ?>"
                           placeholder="Optional HMAC-SHA256 secret">
                    <div class="form-hint">If set, each delivery will include an <code>X-AEGIS-Signature</code> header.</div>
                </div>
            </div>

            <div class="form-section mb-4">
                <h3 class="form-section-title">Event Subscriptions</h3>
                <p class="text-muted mb-3">Select which events will trigger this webhook.</p>

                <?php
                // Group event types by their dot-prefix category
                $eventGroups = [
                    'Risks'       => [],
                    'Incidents'   => [],
                    'Audits'      => [],
                    'Compliance'  => [],
                    'Changes'     => [],
                    'Policies'    => [],
                    'Vendors'     => [],
                    'Issues'      => [],
                    'Assets / BCP'=> [],
                ];
                $categoryMap = [
                    'risk'             => 'Risks',
                    'incident'         => 'Incidents',
                    'audit'            => 'Audits',
                    'control'          => 'Compliance',
                    'compliance'       => 'Compliance',
                    'gap_analysis'     => 'Compliance',
                    'change'           => 'Changes',
                    'policy'           => 'Policies',
                    'vendor'           => 'Vendors',
                    'issue'            => 'Issues',
                    'asset'            => 'Assets / BCP',
                    'bcp'              => 'Assets / BCP',
                    'dr'               => 'Assets / BCP',
                ];
                foreach ($eventTypes as $key => $label) {
                    $prefix = strtok($key, '.');
                    $group  = $categoryMap[$prefix] ?? 'Other';
                    if (!isset($eventGroups[$group])) {
                        $eventGroups[$group] = [];
                    }
                    $eventGroups[$group][$key] = $label;
                }
                ?>

                <?php foreach ($eventGroups as $groupName => $groupEvents):
                    if (empty($groupEvents)) continue; ?>
                <div class="event-group mb-3">
                    <div class="event-group-label"><?= Security::h($groupName) ?></div>
                    <div class="event-type-grid">
                        <?php foreach ($groupEvents as $key => $label): ?>
                            <?php $checked = $isEdit && in_array($key, $endpoint['event_types_arr'], true); ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="event_types[]" id="evt_<?= Security::h(str_replace(['.', '_'], '_', $key)) ?>"
                                       value="<?= Security::h($key) ?>"
                                       <?= $checked ? 'checked' : '' ?>>
                                <label class="form-check-label" for="evt_<?= Security::h(str_replace(['.', '_'], '_', $key)) ?>">
                                    <code><?= Security::h($key) ?></code>
                                    <small class="d-block text-muted"><?= Security::h($label) ?></small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-link p-0" data-click="toggleAllEvents" data-args='[true]'>Select all</button>
                    &nbsp;·&nbsp;
                    <button type="button" class="btn btn-sm btn-link p-0" data-click="toggleAllEvents" data-args='[false]'>Deselect all</button>
                </div>
            </div>

            <div class="form-section mb-4">
                <h3 class="form-section-title">Custom Headers</h3>
                <div class="form-group">
                    <label for="custom_headers" class="form-label">Additional Headers (JSON object)</label>
                    <textarea id="custom_headers" name="custom_headers" class="form-control font-monospace" rows="5"
                              placeholder='{"Authorization": "Bearer token123"}'><?= Security::h($isEdit ? $endpoint['custom_headers_str'] : '') ?></textarea>
                    <div class="form-hint">Optional. Provide a JSON object of header name → value pairs to include in every delivery.</div>
                </div>
            </div>

            <?php if ($isEdit): ?>
            <div class="form-section mb-4">
                <h3 class="form-section-title">Status</h3>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           <?= $endpoint['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Endpoint Active</label>
                </div>
                <div class="form-hint">Disabled endpoints will not receive any deliveries.</div>
            </div>
            <?php endif; ?>

            <div class="form-actions d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <?= $isEdit ? 'Save Changes' : 'Create Webhook' ?>
                </button>
                <a href="/admin/webhooks" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style nonce="<?= Security::nonce() ?>">
.form-section-title {
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}
.event-type-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.75rem;
}
.event-type-grid .form-check {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.75rem 0.75rem 0.75rem 2.25rem;
}
.event-type-grid .form-check:has(.form-check-input:checked) {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, transparent);
}
.font-monospace { font-family: monospace; font-size: 0.85rem; }
.event-group-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
}
</style>

<script nonce="<?= Security::nonce() ?>">
function toggleAllEvents(check) {
    document.querySelectorAll('.event-type-grid .form-check-input').forEach(cb => cb.checked = check);
}
</script>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
