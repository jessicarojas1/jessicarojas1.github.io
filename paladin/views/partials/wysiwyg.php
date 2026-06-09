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
    <button type="button" class="wtb" data-cmd="strikeThrough" title="Strikethrough"><i class="bi bi-type-strikethrough"></i></button>
    <span class="wtb-sep"></span>
    <select class="wtb wtb-block" data-block title="Text style" style="padding:5px 6px">
      <option value="p">Paragraph</option>
      <option value="h1">Title (H1)</option>
      <option value="h2">Heading (H2)</option>
      <option value="h3">Subheading (H3)</option>
      <option value="h4">Heading 4</option>
      <option value="h5">Heading 5</option>
      <option value="h6">Heading 6</option>
      <option value="blockquote">Quote</option>
      <option value="pre">Code block</option>
    </select>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="h1" title="Title (H1)"><i class="bi bi-type-h1"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="h2" title="Heading (H2)"><i class="bi bi-type-h2"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="h3" title="Subheading (H3)"><i class="bi bi-type-h3"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="p" title="Paragraph"><i class="bi bi-text-paragraph"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-img-url title="Insert image by URL"><i class="bi bi-image"></i></button>
    <button type="button" class="wtb" data-img-upload title="Upload an image"><i class="bi bi-upload"></i></button>
    <input type="file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" data-img-file hidden>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-cmd="insertUnorderedList" title="Bulleted list"><i class="bi bi-list-ul"></i></button>
    <button type="button" class="wtb" data-cmd="insertOrderedList" title="Numbered list"><i class="bi bi-list-ol"></i></button>
    <button type="button" class="wtb" data-insert="task" title="Task / action item"><i class="bi bi-check2-square"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="blockquote" title="Quote"><i class="bi bi-blockquote-left"></i></button>
    <button type="button" class="wtb" data-cmd="formatBlock" data-val="pre" title="Code block"><i class="bi bi-code-square"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-cmd="createLink" title="Insert link"><i class="bi bi-link-45deg"></i></button>
    <button type="button" class="wtb" data-cmd="insertTable" title="Insert table"><i class="bi bi-table"></i></button>
    <button type="button" class="wtb" data-cmd="insertHorizontalRule" title="Divider"><i class="bi bi-dash-lg"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-insert="panel-info" title="Info panel"><i class="bi bi-info-circle-fill" style="color:var(--info)"></i></button>
    <button type="button" class="wtb" data-insert="panel-success" title="Success panel"><i class="bi bi-check-circle-fill" style="color:var(--success)"></i></button>
    <button type="button" class="wtb" data-insert="panel-warning" title="Warning panel"><i class="bi bi-exclamation-triangle-fill" style="color:var(--warning)"></i></button>
    <button type="button" class="wtb" data-insert="panel-note" title="Note panel"><i class="bi bi-sticky-fill" style="color:var(--indigo)"></i></button>
    <button type="button" class="wtb" data-insert="expand" title="Expand section"><i class="bi bi-chevron-bar-expand"></i></button>
    <button type="button" class="wtb" data-insert="status" title="Status lozenge"><i class="bi bi-tag-fill"></i></button>
    <button type="button" class="wtb" data-insert="toc" title="Table of contents"><i class="bi bi-list-nested"></i></button>
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

  var fileInput = wrap.querySelector('[data-img-file]');
  var blockSel  = wrap.querySelector('[data-block]');

  // Block-style dropdown (Title / Headings / Quote / Code).
  if (blockSel) blockSel.addEventListener('change', function(){
    surface.focus();
    try { document.execCommand('formatBlock', false, blockSel.value); } catch(e){}
    blockSel.selectedIndex = 0;
    sync();
  });

  // Image upload → POST to /media/upload, insert the returned /media/{id} URL.
  function uploadImage(file){
    if (!file) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    var fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', meta ? meta.getAttribute('content') : '');
    surface.focus();
    fetch('/media/upload', { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.csrf && meta) meta.setAttribute('content', j.csrf);
        if (j && j.ok && j.url) { try { document.execCommand('insertImage', false, j.url); } catch(e){} sync(); }
        else { alert((j && j.error) || 'Image upload failed.'); }
      })
      .catch(function(){ alert('Image upload failed.'); });
  }
  if (fileInput) fileInput.addEventListener('change', function(){
    if (fileInput.files && fileInput.files[0]) uploadImage(fileInput.files[0]);
    fileInput.value = '';
  });

  wrap.querySelectorAll('.wtb').forEach(function(btn){
    if (btn.tagName === 'SELECT' || btn.hasAttribute('data-img-file')) return;
    btn.addEventListener('click', function(){
      if (btn.hasAttribute('data-img-url')) {
        surface.focus();
        var iurl = window.prompt('Image URL (https://…):', 'https://');
        if (iurl) { try { document.execCommand('insertImage', false, iurl); } catch(e){} sync(); }
        return;
      }
      if (btn.hasAttribute('data-img-upload')) { if (fileInput) fileInput.click(); return; }
      if (btn.hasAttribute('data-toggle-html')) {
        if (showingHtml) { surface.innerHTML = source.value; surface.style.display=''; source.style.display='none'; }
        else { source.value = surface.innerHTML; source.style.display=''; surface.style.display='none'; }
        showingHtml = !showingHtml;
        return;
      }
      var ins = btn.getAttribute('data-insert');
      surface.focus();
      if (ins) {
        var snippets = {
          'panel-info':    '<div class="panel panel-info"><div class="panel-icon"><i class="bi bi-info-circle-fill"></i></div><div class="panel-body"><p>Info — type your note here.</p></div></div><p></p>',
          'panel-success': '<div class="panel panel-success"><div class="panel-icon"><i class="bi bi-check-circle-fill"></i></div><div class="panel-body"><p>Success — type your note here.</p></div></div><p></p>',
          'panel-warning': '<div class="panel panel-warning"><div class="panel-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="panel-body"><p>Warning — type your note here.</p></div></div><p></p>',
          'panel-note':    '<div class="panel panel-note"><div class="panel-icon"><i class="bi bi-sticky-fill"></i></div><div class="panel-body"><p>Note — type your note here.</p></div></div><p></p>',
          'task':          '<ul class="task-list"><li>[ ] Action item — describe the task</li></ul><p></p>',
          'expand':        '<details><summary>Click to expand</summary><p>Hidden content…</p></details><p></p>',
          'status':        '<span class="lozenge lozenge-green">Done</span>&nbsp;',
          'toc':           '<div class="macro-toc"><div class="macro-toc-title">On this page</div></div><p></p>'
        };
        if (snippets[ins]) { try { document.execCommand('insertHTML', false, snippets[ins]); } catch(e){} }
        sync();
        return;
      }
      var cmd = btn.getAttribute('data-cmd');
      var val = btn.getAttribute('data-val') || null;
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
