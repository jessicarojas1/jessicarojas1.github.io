<?php
$pageTitle    = 'Webhooks';
$activeModule = 'admin_webhooks';
$breadcrumbs  = [['Administration', '/admin'], ['Webhooks', null]];
ob_start();
$truthy = fn($v) => in_array(strtolower((string)$v), ['1','t','true','yes','on'], true);
?>
<div class="page-header">
  <div><h1 class="page-title">Webhooks</h1><p class="page-subtitle">Send a signed HTTP POST to an external endpoint when platform events occur.</p></div>
</div>

<div class="iam" style="grid-template-columns:1fr 340px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-broadcast"></i> Endpoints</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0">
        <thead><tr><th>Name</th><th>URL</th><th>Events</th><th>Status</th><th>Last</th><th style="width:150px"></th></tr></thead>
        <tbody>
        <?php foreach ($hooks as $h): ?>
          <tr>
            <td><?= Security::h($h['name']) ?><?php if ((int)$h['failure_count'] > 0): ?><br><span class="badge badge-amber"><?= (int)$h['failure_count'] ?> failures</span><?php endif; ?></td>
            <td class="form-hint" style="word-break:break-all;max-width:220px"><?= Security::h($h['url']) ?></td>
            <td><span class="chip"><?= $h['events'] === '*' ? 'all events' : Security::h(str_replace(',', ', ', $h['events'])) ?></span></td>
            <td><?= $truthy($h['is_active']) ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Paused</span>' ?></td>
            <td class="form-hint"><?= $h['last_fired_at'] ? Security::h(View::timeAgo($h['last_fired_at'])) . ($h['last_status'] ? ' · ' . (int)$h['last_status'] : '') : '—' ?></td>
            <td style="text-align:right;white-space:nowrap">
              <a href="/admin/webhooks/<?= (int)$h['id'] ?>/deliveries" class="btn btn-sm" title="Delivery log"><i class="bi bi-clock-history"></i></a>
              <form method="POST" action="/admin/webhooks/<?= (int)$h['id'] ?>/test" style="display:inline;margin:0"><?= Security::csrfField() ?><button class="btn btn-sm" type="submit" title="Send test delivery"><i class="bi bi-send"></i></button></form>
              <form method="POST" action="/admin/webhooks/<?= (int)$h['id'] ?>/toggle" style="display:inline;margin:0"><?= Security::csrfField() ?><button class="btn btn-sm" type="submit" title="<?= $truthy($h['is_active']) ? 'Pause' : 'Resume' ?>"><i class="bi <?= $truthy($h['is_active']) ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
              <form method="POST" action="/admin/webhooks/<?= (int)$h['id'] ?>/delete" style="display:inline;margin:0" data-confirm="Delete this webhook?"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$hooks): ?>
          <tr><td colspan="6" class="empty-row"><div class="empty-state-sm"><i class="bi bi-broadcast"></i><p>No webhooks configured.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-plus-lg"></i> New Webhook</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/webhooks">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label" for="wh_name">Name</label><input type="text" id="wh_name" name="name" class="form-control" required maxlength="160"></div>
        <div class="form-group"><label class="form-label" for="wh_url">Payload URL</label><input type="url" id="wh_url" name="url" class="form-control" required placeholder="https://example.com/hooks/paladin"></div>
        <div class="form-group"><label class="form-label" for="wh_secret">Secret (optional)</label><input type="text" id="wh_secret" name="secret" class="form-control" maxlength="128" placeholder="HMAC-SHA256 signing key">
          <div class="form-hint">If set, deliveries include an <code>X-Paladin-Signature: sha256=…</code> header.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Events</label>
          <label class="form-label" style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer"><input type="checkbox" name="events[]" value="*" checked> All events</label>
          <div style="max-height:180px;overflow:auto;border:1px solid var(--border-light);border-radius:8px;padding:8px;margin-top:6px">
            <?php foreach ($events as $key => $label): ?>
              <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400"><input type="checkbox" name="events[]" value="<?= Security::h($key) ?>"> <?= Security::h($label) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="form-hint">Leave "All events" checked to receive everything.</div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Add Webhook</button></div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
