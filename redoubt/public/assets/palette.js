/* REDOUBT — ⌘K / Ctrl-K command palette. Served from 'self'; no inline handlers. */
(function () {
  'use strict';
  var NAV = window.REDOUBT_NAV || [];
  var PID = window.REDOUBT_PID || 0;
  var pal = document.getElementById('palette');
  var input = document.getElementById('paletteInput');
  var list = document.getElementById('paletteList');
  if (!pal || !input || !list) { return; }
  var idx = 0, items = [];

  function withPid(url) {
    if (!PID || url.indexOf('?') >= 0) { return url; }
    return url + '?program_id=' + encodeURIComponent(PID);
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function build(q) {
    var ql = (q || '').trim().toLowerCase();
    items = NAV.filter(function (n) { return !ql || n.label.toLowerCase().indexOf(ql) >= 0; })
      .map(function (n) { return { label: n.label, url: withPid(n.url), kind: 'Go' }; });
    if (ql) {
      items.push({ label: 'Search for “' + q.trim() + '”', url: '/app/search?program_id=' + PID + '&q=' + encodeURIComponent(q.trim()), kind: 'Search' });
      items.push({ label: 'Ask the assistant: “' + q.trim() + '”', url: '/app/assistant?program_id=' + PID + '&q=' + encodeURIComponent(q.trim()), kind: 'Ask' });
    }
    idx = 0;
    render();
  }
  function render() {
    list.innerHTML = '';
    items.forEach(function (it, i) {
      var li = document.createElement('li');
      li.className = i === idx ? 'on' : '';
      li.innerHTML = '<span class="k">' + esc(it.kind) + '</span> <span>' + esc(it.label) + '</span>';
      li.addEventListener('click', function () { go(i); });
      list.appendChild(li);
    });
  }
  function go(i) { if (items[i]) { window.location.href = items[i].url; } }

  function open() {
    pal.hidden = false; pal.setAttribute('aria-hidden', 'false');
    input.value = ''; build(''); input.focus();
  }
  function close() { pal.hidden = true; pal.setAttribute('aria-hidden', 'true'); }

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); pal.hidden ? open() : close(); return; }
    if (pal.hidden) { return; }
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(items.length - 1, idx + 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); render(); }
    else if (e.key === 'Enter') { e.preventDefault(); go(idx); }
  });
  input.addEventListener('input', function () { build(input.value); });
  pal.addEventListener('click', function (e) { if (e.target === pal) { close(); } });
})();
