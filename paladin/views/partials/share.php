<?php
/**
 * Share dialog (trigger button + modal). Expects:
 *   $shareType ('page'|'document'|'blog'|'process'), $shareId, $sharePath (canonical path),
 *   and optionally $shareUsers (active users for the people picker).
 * The share link is the canonical ID-based URL — stable across moves/renames.
 */
$shareUsers = $shareUsers ?? [];
if (!$shareUsers) { try { $shareUsers = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name"); } catch (Throwable) { $shareUsers = []; } }
$__base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
if ($__base === '') {
    $__scheme = (($_SERVER['HTTPS'] ?? '') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $__base = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$shareLink = $__base . $sharePath;
?>
<button type="button" class="btn btn-ghost" data-share-open><i class="bi bi-share-fill"></i> Share</button>

<div class="modal-overlay pal-share-overlay" id="palShareModal">
  <div class="modal">
    <div class="modal-header">Share this <?= Security::h($shareType) ?> <button type="button" class="btn-unstyled" data-share-close style="border:none;background:none;cursor:pointer;font-size:1.1rem"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Share link <span class="form-hint">(permanent — works even if this <?= Security::h($shareType) ?> is moved)</span></label>
        <div class="input-group" style="display:flex;gap:8px">
          <input type="text" id="palShareLink" class="form-control" readonly value="<?= Security::h($shareLink) ?>">
          <button type="button" class="btn btn-primary" data-copy="#palShareLink"><i class="bi bi-clipboard"></i> Copy</button>
        </div>
      </div>
      <form method="POST" action="/share">
        <?= Security::csrfField() ?>
        <input type="hidden" name="entity_type" value="<?= Security::h($shareType) ?>">
        <input type="hidden" name="entity_id" value="<?= (int)$shareId ?>">
        <div class="form-group">
          <label class="form-label">Add people <span class="form-hint">(they'll get a notification)</span></label>
          <select name="recipients[]" class="form-select" multiple size="5">
            <?php foreach ($shareUsers as $su): if ((int)$su['id'] === Auth::id()) continue; ?>
              <option value="<?= (int)$su['id'] ?>"><?= Security::h($su['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Message</label><textarea name="message" class="form-control" rows="2" placeholder="Take a look at this <?= Security::h($shareType) ?>"></textarea></div>
        <div class="form-actions" style="margin-top:8px">
          <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> Share</button>
          <button type="button" class="btn btn-ghost" data-share-close>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<style nonce="<?= Security::nonce() ?>">.pal-share-overlay{display:none}.pal-share-overlay.open{display:flex}</style>
<script nonce="<?= Security::nonce() ?>">
(function(){
  var m = document.getElementById('palShareModal');
  if(!m) return;
  document.querySelectorAll('[data-share-open]').forEach(function(b){ b.addEventListener('click', function(){ m.classList.add('open'); }); });
  m.querySelectorAll('[data-share-close]').forEach(function(b){ b.addEventListener('click', function(){ m.classList.remove('open'); }); });
  m.addEventListener('click', function(e){ if(e.target===m) m.classList.remove('open'); });
})();
</script>
