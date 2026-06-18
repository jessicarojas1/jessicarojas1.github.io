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
    <button type="button" class="wtb" data-insert="props" title="Page properties (for reports)"><i class="bi bi-table"></i><i class="bi bi-key" style="font-size:.7em"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-insert="children" title="Child pages list (dynamic)"><i class="bi bi-diagram-2-fill"></i></button>
    <button type="button" class="wtb" data-insert="pagetree" title="Page tree (dynamic)"><i class="bi bi-diagram-3-fill"></i></button>
    <button type="button" class="wtb" data-insert="recent" title="Recently updated (dynamic)"><i class="bi bi-clock-history"></i></button>
    <button type="button" class="wtb" data-insert-include title="Include another page (dynamic)"><i class="bi bi-box-arrow-in-down-right"></i></button>
    <span class="wtb-sep"></span>
    <button type="button" class="wtb" data-macro-browser title="Browse macros"><i class="bi bi-grid-3x3-gap-fill"></i> Macros</button>
    <button type="button" class="wtb wtb-toggle" data-toggle-html="1" title="Toggle HTML source"><i class="bi bi-braces"></i> HTML</button>
  </div>
  <div class="wysiwyg-surface prose" id="<?= Security::h($wId) ?>-surface" contenteditable="true"><?= $wValue /* already sanitized HTML */ ?></div>
  <textarea class="wysiwyg-source form-control" id="<?= Security::h($wId) ?>-source" name="<?= Security::h($wName) ?>" style="display:none;min-height:300px;font-family:monospace"><?= Security::h($wValue) ?></textarea>

  <!-- Macro browser: a searchable insert palette. Each card is a .wtb carrying a
       data-insert (or data-insert-include) attribute, so the existing toolbar
       handler performs the insertion; this dialog only adds discoverability. -->
  <div class="wmacro-overlay" id="<?= Security::h($wId) ?>-macros" hidden>
    <div class="wmacro-dialog" role="dialog" aria-label="Insert macro">
      <div class="wmacro-head">
        <strong><i class="bi bi-grid-3x3-gap-fill"></i> Insert a macro</strong>
        <button type="button" class="wtb" data-macro-close title="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <input type="text" class="form-control wmacro-search" data-macro-search placeholder="Search macros…" autocomplete="off">
      <div class="wmacro-grid">
        <?php
        $__macros = [
          ['ins'=>'data-insert="panel-info"',    'icon'=>'bi-info-circle-fill',          'name'=>'Info panel',         'desc'=>'Highlighted note box'],
          ['ins'=>'data-insert="panel-success"', 'icon'=>'bi-check-circle-fill',         'name'=>'Success panel',      'desc'=>'Positive / done callout'],
          ['ins'=>'data-insert="panel-warning"', 'icon'=>'bi-exclamation-triangle-fill', 'name'=>'Warning panel',      'desc'=>'Caution callout'],
          ['ins'=>'data-insert="panel-note"',    'icon'=>'bi-sticky-fill',               'name'=>'Note panel',         'desc'=>'Side note box'],
          ['ins'=>'data-insert="expand"',        'icon'=>'bi-chevron-bar-expand',        'name'=>'Expand',             'desc'=>'Collapsible section'],
          ['ins'=>'data-insert="status"',        'icon'=>'bi-tag-fill',                  'name'=>'Status lozenge',     'desc'=>'Coloured status label'],
          ['ins'=>'data-insert="toc"',           'icon'=>'bi-list-nested',               'name'=>'Table of contents',  'desc'=>'On-this-page outline'],
          ['ins'=>'data-insert="props"',         'icon'=>'bi-table',                     'name'=>'Page properties',    'desc'=>'Key/value table for reports'],
          ['ins'=>'data-insert="task"',          'icon'=>'bi-check2-square',             'name'=>'Action item',        'desc'=>'Tracked task / to-do'],
          ['ins'=>'data-insert="children"',      'icon'=>'bi-diagram-2-fill',            'name'=>'Children',           'desc'=>'Live list of child pages'],
          ['ins'=>'data-insert="pagetree"',      'icon'=>'bi-diagram-3-fill',            'name'=>'Page tree',          'desc'=>'Live nested descendant tree'],
          ['ins'=>'data-insert="recent"',        'icon'=>'bi-clock-history',             'name'=>'Recently updated',   'desc'=>'Live list of recent pages'],
          ['ins'=>'data-insert-include',         'icon'=>'bi-box-arrow-in-down-right',   'name'=>'Include page',       'desc'=>'Transclude another page'],
        ];
        foreach ($__macros as $m): ?>
          <button type="button" class="wtb wmacro-card" <?= $m['ins'] ?> data-macro-name="<?= Security::h(strtolower($m['name'].' '.$m['desc'])) ?>">
            <i class="bi <?= Security::h($m['icon']) ?>"></i>
            <span class="wmacro-name"><?= Security::h($m['name']) ?></span>
            <span class="wmacro-desc"><?= Security::h($m['desc']) ?></span>
          </button>
        <?php endforeach; ?>
        <div class="wmacro-empty" data-macro-empty hidden>No macros match your search.</div>
      </div>
    </div>
  </div>
</div>
<style nonce="<?= Security::nonce() ?>">
.wysiwyg{border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.wysiwyg-toolbar{display:flex;flex-wrap:wrap;gap:2px;padding:6px;background:var(--bg-secondary);border-bottom:1px solid var(--border)}
.wtb{background:none;border:1px solid transparent;border-radius:6px;padding:5px 9px;cursor:pointer;color:var(--text-muted);font-size:.9rem}
.wtb:hover{background:var(--card-bg);border-color:var(--border);color:var(--text)}
.wtb-sep{width:1px;background:var(--border);margin:2px 4px}
.wysiwyg-surface{min-height:320px;padding:16px 18px;background:var(--card-bg);outline:none;color:var(--text)}
.wysiwyg-surface:focus{box-shadow:inset 0 0 0 2px var(--primary-light)}
.wmacro-overlay{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.45);display:flex;align-items:flex-start;justify-content:center;padding:8vh 16px}
.wmacro-dialog{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.3);width:100%;max-width:640px;max-height:78vh;display:flex;flex-direction:column;overflow:hidden}
.wmacro-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--border)}
.wmacro-search{margin:12px 14px 8px}
.wmacro-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:0 14px 14px;overflow:auto}
.wmacro-card{display:flex;flex-direction:column;align-items:flex-start;gap:2px;text-align:left;padding:10px 12px;border:1px solid var(--border);border-radius:8px}
.wmacro-card>i{font-size:1.1rem;color:var(--primary)}
.wmacro-name{font-weight:600;color:var(--text)}
.wmacro-desc{font-size:.78rem;color:var(--text-muted)}
.wmacro-empty{grid-column:1/-1;color:var(--text-muted);padding:16px;text-align:center}
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
      if (btn.hasAttribute('data-insert-include')) {
        var ref = window.prompt('Include another page — enter its slug or numeric id:', '');
        if (ref) {
          var safe = ref.replace(/[^A-Za-z0-9_\-]/g, '');
          if (safe) {
            var inc = '<div class="macro-include" data-page="' + safe + '" data-mode="full">'
                    + '<em class="macro-ph">↳ Include page: ' + safe + ' (rendered on view)</em></div><p></p>';
            try { document.execCommand('insertHTML', false, inc); } catch(e){}
            sync();
          }
        }
        return;
      }
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
          'toc':           '<div class="macro-toc"><div class="macro-toc-title">On this page</div></div><p></p>',
          'props':         '<table class="page-properties"><tbody><tr><th>Status</th><td>Draft</td></tr><tr><th>Owner</th><td>@</td></tr><tr><th>Due</th><td>YYYY-MM-DD</td></tr></tbody></table><p></p>',
          'children':      '<div class="macro-children"><em class="macro-ph">↳ Child pages (rendered on view)</em></div><p></p>',
          'pagetree':      '<div class="macro-pagetree" data-depth="3"><em class="macro-ph">↳ Page tree (rendered on view)</em></div><p></p>',
          'recent':        '<div class="macro-recently-updated" data-limit="10" data-scope="space"><em class="macro-ph">↳ Recently updated (rendered on view)</em></div><p></p>'
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

  // ── Macro browser (searchable insert palette) ──────────────────────────────
  var overlay = document.getElementById('<?= Security::h($wId) ?>-macros');
  if (overlay) {
    var openBtn  = wrap.querySelector('[data-macro-browser]');
    var search   = overlay.querySelector('[data-macro-search]');
    var emptyMsg = overlay.querySelector('[data-macro-empty]');
    var cards    = Array.prototype.slice.call(overlay.querySelectorAll('.wmacro-card'));

    function openMacros(){ overlay.hidden = false; if (search){ search.value=''; filter(''); search.focus(); } }
    function closeMacros(){ overlay.hidden = true; surface.focus(); }
    function filter(q){
      q = (q||'').toLowerCase().trim(); var shown = 0;
      cards.forEach(function(c){
        var hit = !q || (c.getAttribute('data-macro-name')||'').indexOf(q) !== -1;
        c.style.display = hit ? '' : 'none'; if (hit) shown++;
      });
      if (emptyMsg) emptyMsg.hidden = shown !== 0;
    }
    if (openBtn) openBtn.addEventListener('click', openMacros);
    overlay.querySelectorAll('[data-macro-close]').forEach(function(b){ b.addEventListener('click', closeMacros); });
    // Click on the backdrop (not the dialog) closes.
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeMacros(); });
    if (search) search.addEventListener('input', function(){ filter(search.value); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !overlay.hidden) closeMacros(); });
    // After a macro card inserts (handled by the .wtb listener above), close.
    cards.forEach(function(c){ c.addEventListener('click', function(){ setTimeout(closeMacros, 0); }); });
  }

  // Keep the textarea current right before the form submits.
  var form = wrap.closest('form');
  if (form) form.addEventListener('submit', function(){ if(showingHtml){ surface.innerHTML = source.value; } sync(); });
})();
</script>
