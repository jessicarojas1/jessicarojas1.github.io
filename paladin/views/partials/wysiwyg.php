<?php
/**
 * Reusable rich-text editor partial (CSP-safe).
 * Expects: $wId (unique id), $wName (textarea field name), $wValue (raw HTML).
 * Emits a toolbar + contenteditable surface + a hidden textarea kept in sync,
 * plus a raw-HTML toggle. All wiring is a nonce'd <script> (no inline handlers).
 */
$wId    = $wId    ?? 'editor';
$wName  = $wName  ?? 'body';
$wValue = $wValue ?? '';
?>
<div class="wysiwyg" id="<?= Security::h($wId) ?>-wrap">
  <div class="wysiwyg-toolbar" id="<?= Security::h($wId) ?>-tb">
    <button type="button" class="wtb" data-cmd="bold" title="Bold"><i class="bi bi-type-bold"></i></button>
    <button type="button" class="wtb" data-cmd="italic" title="Italic"><i class="bi bi-type-italic"></i></button>
    <button type="button" class="wtb" data-cmd="underline" title="Underline"><i class="bi bi-type-underline"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="h2" title="Heading"><i class="bi bi-type-h2"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="h3" title="Subheading"><i class="bi bi-type-h3"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="p" title="Paragraph"><i class="bi bi-text-paragraph"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-cmd="insertUnorderedList" title="Bulleted list"><i class="bi bi-list-ul"></i></button>
    <button type="button" class="wtb" data-cmd="insertOrderedList" title="Numbered list"><i class="bi bi-list-ol"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="blockquote" title="Quote"><i class="bi bi-blockquote-left"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="pre" title="Code block"><i class="bi bi-code-square"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-cmd="createLink" title="Insert link"><i class="bi bi-link-45deg"></i></button>
    <button type="button" class="wtb" data-cmd="insertTable" title="Insert table"><i class="bi bi-table"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb wtb-toggle" data-toggle-html="1" title="Toggle HTML source"><i class="bi bi-braces"></i> HTML</button>
  </div>
  <div class="wysiwyg-surface prose" id="<?= Security::h($wId) ?>-surface" contenteditable="true"><?= $wValue /* already sanitized HTML */ ?></div>
  <textarea class="wysiwyg-source form-control" id="<?= Security::h($wId) ?>-source" name="<?= Security::h($wName) ?>" style="display:none;min-height:300px;font-family:monospace"><?= Security::h($wValue) ?></textarea>
</div>
<style nonce="<?= Security::nonce() ?>">
.wysiwyg{border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.wysiwyg-toolbar{display:flex;flex-wrap:wrap;gap:2px;padding:6px;background:var(--bg-secondary);border-bottom:1px solid var(--border)}
.wtb{background:none;border:1px solid transparent;border-radius:6px;padding:5px 9px;cursor:pointer;color:var(--text-muted);font-size:.9rem}
.wtb:hover{background:var(--card-bg);border-color:var(--border);color:var(--text)}
.wtb-sep{width:1px;background:var(--border);margin:2px 4px}
.wysiwyg-surface{min-height:320px;padding:16px 18px;background:var(--card-bg);outline:none;color:var(--text)}
.wysiwyg-surface:focus{box-shadow:inset 0 0 0 2px var(--primary-light)}
</style>
<script nonce="<?= Security::nonce() ?>">
(function(){
  var wrap = document.getElementById('<?= Security::h($wId) ?>-wrap');
  if (!wrap) return;
  var surface = document.getElementById('<?= Security::h($wId) ?>-surface');
  var source  = document.getElementById('<?= Security::h($wId) ?>-source');
  var showingHtml = false;

  function sync(){ if(!showingHtml){ source.value = surface.innerHTML; } }

  wrap.querySelectorAll('.wtb').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (btn.hasAttribute('data-toggle-html')) {
        if (showingHtml) { surface.innerHTML = source.value; surface.style.display=''; source.style.display='none'; }
        else { source.value = surface.innerHTML; source.style.display=''; surface.style.display='none'; }
        showingHtml = !showingHtml;
        return;
      }
      var cmd = btn.getAttribute('data-cmd');
      var val = btn.getAttribute('data-val') || null;
      surface.focus();
      if (cmd === 'createLink') {
        var url = window.prompt('Link URL (https://…):', 'https://');
        if (url) { try { document.execCommand('createLink', false, url); } catch(e){} }
      } else if (cmd === 'insertTable') {
        var html = '<table><thead><tr><th>Header</th><th>Header</th></tr></thead><tbody><tr><td>Cell</td><td>Cell</td></tr><tr><td>Cell</td><td>Cell</td></tr></tbody></table><p></p>';
        try { document.execCommand('insertHTML', false, html); } catch(e){}
      } else if (cmd === 'formatBlock') {
        try { document.execCommand('formatBlock', false, val); } catch(e){}
      } else {
        try { document.execCommand(cmd, false, val); } catch(e){}
      }
      sync();
    });
  });
  surface.addEventListener('input', sync);
  // Keep the textarea current right before the form submits.
  var form = wrap.closest('form');
  if (form) form.addEventListener('submit', function(){ if(showingHtml){ surface.innerHTML = source.value; } sync(); });
})();
</script>
