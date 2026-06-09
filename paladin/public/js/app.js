/* PALADIN — app.js */

// ── Branding logo fallback ───────────────────────────────────────────────────
// If a configured logo (URL or data URI) fails to load, hide the broken <img>
// and reveal the built-in shield mark so branding never breaks the UI.
(function () {
  document.querySelectorAll('img[data-logo-fallback]').forEach(function (img) {
    img.addEventListener('error', function () {
      img.style.display = 'none';
      var fb = img.parentElement
        ? img.parentElement.querySelector('.brand-logo-fallback')
        : null;
      if (fb) fb.style.display = '';
    });
  });
})();

// ── Settings → Branding live preview ─────────────────────────────────────────
// Reflects the accent colour, display name and logo source into a small preview
// without inline event handlers (CSP-safe). Elements are opt-in via IDs that
// only exist on the branding settings page.
(function () {
  var accent = document.getElementById('brand_accent');
  var accentText = document.getElementById('brand_accent_text');
  var swatch = document.getElementById('brandAccentSwatch');
  var nameInput = document.getElementById('org_name');
  var namePrev = document.getElementById('brandPreviewName');
  var logoUrl = document.getElementById('logo_url');
  var logoPrev = document.getElementById('brandPreviewLogo');
  var logoPrevIcon = document.getElementById('brandPreviewLogoIcon');

  function setLogoPreview(src) {
    if (!logoPrev || !logoPrevIcon) return;
    var ok = /^data:image\//i.test(src) || /^https?:\/\//i.test(src);
    if (src && ok) {
      logoPrev.src = src;
      logoPrev.style.display = '';
      logoPrevIcon.style.display = 'none';
    } else {
      logoPrev.removeAttribute('src');
      logoPrev.style.display = 'none';
      logoPrevIcon.style.display = '';
    }
  }

  if (accent) {
    accent.addEventListener('input', function () {
      if (accentText) accentText.value = accent.value;
      if (swatch) swatch.style.background = accent.value;
    });
  }
  if (accentText) {
    accentText.addEventListener('input', function () {
      var v = accentText.value.trim();
      if (/^#?[0-9a-fA-F]{6}$/.test(v)) {
        if (v[0] !== '#') v = '#' + v;
        if (accent) accent.value = v;
        if (swatch) swatch.style.background = v;
      }
    });
  }
  if (nameInput && namePrev) {
    nameInput.addEventListener('input', function () {
      namePrev.textContent = nameInput.value.trim() || 'PALADIN';
    });
  }
  if (logoUrl) {
    logoUrl.addEventListener('input', function () {
      setLogoPreview(logoUrl.value.trim());
    });
  }
  if (logoPrev) {
    logoPrev.addEventListener('error', function () { setLogoPreview(''); });
  }
})();

// toggleSidebar is defined below in the mobile sidebar IIFE

// Alert panel
const alertPanel = document.getElementById('alertPanel');

function toggleAlertPanel() {
  if (!alertPanel) return;
  alertPanel.classList.toggle('open');
  const overlay = document.getElementById('alertOverlay');
  if (overlay) overlay.classList.toggle('open', alertPanel.classList.contains('open'));
}

// Wire up layout alert bell, panel close, and overlay (previously used inline onclick)
(function () {
  var bell = document.getElementById('alertBell');
  if (bell) bell.addEventListener('click', toggleAlertPanel);

  var closeBtn = document.getElementById('alertPanelClose');
  if (closeBtn) closeBtn.addEventListener('click', toggleAlertPanel);

  var overlay = document.getElementById('alertOverlay');
  if (overlay) overlay.addEventListener('click', toggleAlertPanel);

  // Sidebar toggle button is wired in the mobile sidebar IIFE below.
})();

document.addEventListener('click', function (e) {
  if (!alertPanel) return;
  const bell = document.getElementById('alertBell');
  if (alertPanel.classList.contains('open') && !alertPanel.contains(e.target) && bell && !bell.contains(e.target)) {
    alertPanel.classList.remove('open');
  }
});

function markAlertRead(id, el) {
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrf = csrfMeta ? csrfMeta.content : '';

  fetch('/alerts/' + id + '/read', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'csrf_token=' + encodeURIComponent(csrf),
  }).then(function (r) {
    if (r.ok) {
      const item = el ? el.closest('.alert-item') : null;
      if (item) item.classList.add('read');
      const dot = document.querySelector('.alert-badge');
      if (dot) {
        const current = parseInt(dot.textContent) || 0;
        if (current <= 1) dot.style.display = 'none';
        else dot.textContent = current - 1;
      }
    }
  });
}

function markAllRead() {
  document.querySelectorAll('.alert-item:not(.read) .mark-read-btn').forEach(function (btn) {
    btn.click();
  });
}

// Flash message auto-dismiss
document.querySelectorAll('.alert-box').forEach(function (box) {
  setTimeout(function () {
    box.style.transition = 'opacity 0.4s';
    box.style.opacity = '0';
    setTimeout(function () { box.remove(); }, 400);
  }, 6000);
});

// Mark-read buttons use data-alert-id attribute
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.mark-read-btn');
  if (btn) {
    var id = parseInt(btn.dataset.alertId, 10);
    if (id) markAlertRead(id, btn);
  }
});

// Modal helpers
var _modalOpenedAt = {};
window.showModal = function (id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = 'flex';
  _modalOpenedAt[id] = Date.now();
  // iOS ghost-click guard: block pointer-events on the overlay for 400ms after open.
  // The inner .modal stays interactive so the form is immediately usable.
  el.style.pointerEvents = 'none';
  var inner = el.querySelector('.um-dialog, .modal-box, .modal-card');
  if (inner) inner.style.pointerEvents = 'auto';
  setTimeout(function () {
    el.style.pointerEvents = '';
    if (inner) inner.style.pointerEvents = '';
  }, 400);
};
window.closeModal = function (id) {
  var el = document.getElementById(id);
  if (el) el.style.display = 'none';
};
// openModal fallback (pages may define their own openModal; only set if not already defined)
if (!window.openModal) window.openModal = window.showModal;

// Data-attribute modal triggers: <button data-modal-open="id"> and <button data-modal-close="id">
document.addEventListener('click', function (e) {
  var opener = e.target.closest('[data-modal-open]');
  if (opener) { var m = document.getElementById(opener.dataset.modalOpen); if (m) m.style.display = 'flex'; }

  var closer = e.target.closest('[data-modal-close]');
  if (closer) { var mc = document.getElementById(closer.dataset.modalClose); if (mc) mc.style.display = 'none'; }
});

// Close modals on Escape
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay, .um-overlay').forEach(function (m) {
      m.style.display = 'none';
    });
  }
});

// Time-ago helper
function timeAgo(dateStr) {
  const date = new Date(dateStr);
  const now = new Date();
  const diff = Math.floor((now - date) / 1000);
  if (diff < 60) return diff + 's ago';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}

// Populate time-ago spans
document.querySelectorAll('[data-timeago]').forEach(function (el) {
  el.textContent = timeAgo(el.dataset.timeago);
});

// Permission matrix: toggle row highlight on checkbox change
document.querySelectorAll('.perm-checkbox').forEach(function (cb) {
  cb.addEventListener('change', function () {
    const row = cb.closest('tr');
    if (row) row.classList.toggle('perm-row-modified', true);
  });
});

// Permission matrix: select-all per module column
document.querySelectorAll('.perm-col-all').forEach(function (btn) {
  btn.addEventListener('click', function () {
    const module = btn.dataset.module;
    const perm = btn.dataset.perm;
    const boxes = document.querySelectorAll('.perm-checkbox[data-module="' + module + '"][data-perm="' + perm + '"]');
    const anyUnchecked = Array.from(boxes).some(function (b) { return !b.checked; });
    boxes.forEach(function (b) { b.checked = anyUnchecked; b.dispatchEvent(new Event('change')); });
  });
});

// ── Accordion nav ───────────────────────────────────────────────────────────
(function() {
  var STORE = 'aegisNavAcc';

  function loadState() {
    try { return JSON.parse(sessionStorage.getItem(STORE) || '{}'); } catch(e) { return {}; }
  }
  function saveState(s) {
    try { sessionStorage.setItem(STORE, JSON.stringify(s)); } catch(e) {}
  }

  function applySection(key, open) {
    var h = document.querySelector('[data-acc="' + key + '"]');
    var b = document.getElementById('nav-acc-' + key);
    if (!h || !b) return;
    if (open) { h.classList.add('open'); b.classList.add('open'); }
    else       { h.classList.remove('open'); b.classList.remove('open'); }
  }

  window.toggleAccordion = function(key) {
    var b = document.getElementById('nav-acc-' + key);
    if (!b) return;
    var nowOpen = !b.classList.contains('open');
    applySection(key, nowOpen);
    var s = loadState();
    s[key] = nowOpen;
    saveState(s);
  };

  // Init: disable transitions, set open/closed, re-enable after two frames
  var bodies = document.querySelectorAll('.nav-acc-body');
  bodies.forEach(function(b) { b.style.transition = 'none'; });

  var state = loadState();
  document.querySelectorAll('[data-acc]').forEach(function(h) {
    var key = h.dataset.acc;
    var b = document.getElementById('nav-acc-' + key);
    if (!b) return;
    var hasActive = !!b.querySelector('.nav-item.active');
    var isOpen = state.hasOwnProperty(key) ? state[key] : hasActive;
    applySection(key, isOpen);
  });

  // Re-enable transitions after paint
  requestAnimationFrame(function() {
    requestAnimationFrame(function() {
      bodies.forEach(function(b) { b.style.transition = ''; });
    });
  });
})();

// ── Mobile sidebar overlay ──────────────────────────────────────────────────
(function() {
  var overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.addEventListener('click', function() { closeMobileSidebar(); });
  document.body.appendChild(overlay);

  function openSidebar() {
    var s = document.getElementById('sidebar');
    if (!s) return;
    s.classList.add('open');
    overlay.classList.add('open');
  }

  function closeMobileSidebar() {
    var s = document.getElementById('sidebar');
    if (s) s.classList.remove('open');
    overlay.classList.remove('open');
  }

  window.toggleSidebar = function() {
    var s = document.getElementById('sidebar');
    if (!s) return;
    if (s.classList.contains('open')) { closeMobileSidebar(); }
    else { openSidebar(); }
  };

  // Wire the hamburger button directly.
  // Script is at bottom of <body> so .sidebar-toggle is already in the DOM.
  var btn = document.querySelector('.sidebar-toggle');
  if (btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      window.toggleSidebar();
    });
  }

  // Persist sidebar scroll position across page navigations
  var sidebar = document.getElementById('sidebar');
  if (sidebar) {
    var saved = sessionStorage.getItem('sidebarScroll');
    if (saved) { sidebar.scrollTop = parseInt(saved, 10); }
    sidebar.addEventListener('scroll', function() {
      sessionStorage.setItem('sidebarScroll', sidebar.scrollTop);
    });
  }
})();

// ── CSP-safe event delegation ────────────────────────────────────────────────
// Replaces inline onclick/onchange/oninput/onsubmit attributes.
// Views use data-* attributes; handlers are resolved from window scope.
//
//   data-click="fnName"                  — calls window.fnName(el) with this=el
//   data-click="fnName" data-arg="v"     — calls window.fnName(v)
//   data-click="fnName" data-args='[1]'  — calls window.fnName(1) via JSON.parse
//   data-print                           — window.print()
//   data-change="fnName"                 — called on change (data-input-val: passes value)
//   data-autosubmit                      — submits the element's form on change
//   data-input="fnName"                  — called on input event
//   data-input-val                       — passes this.value as argument to input/change fn
//   data-value-display="id"              — on input: sets textContent of #id to this.value
//   data-confirm="message"               — confirm() guard on the parent form
//   data-confirm-click="message"         — confirm() guard on click
//   data-submit="fnName"                 — custom form submit handler: fn(event, ...data-args)
//   data-toggle-class="cls"              — toggles CSS class on target (data-target="#id")
//   data-add-class="cls"                 — adds CSS class on target
//   data-remove-class="cls"              — removes CSS class on target
//   data-close-modal="id"                — calls closeModal(id) or pkgCloseModal()
//   data-show-modal="id"                 — calls openModal(id) or showModal(id)
//   data-toggle-visible="id"             — toggles display:none/block on element by id
//   data-toggle-sibling="cls"            — toggles CSS class on the next sibling element
//   data-expand="id"                     — toggles display of #id, updates button label
(function () {
  function resolve(name) {
    return name.split('.').reduce(function (o, k) { return o && o[k]; }, window);
  }
  function callFn(el, name) {
    var fn = resolve(name);
    if (typeof fn !== 'function') return;
    var args;
    if (el.dataset.args) {
      try { args = JSON.parse(el.dataset.args); } catch (x) { args = []; }
    } else if (el.dataset.arg !== undefined) {
      args = [el.dataset.arg];
    } else {
      args = [el]; // pass element as first arg (mirrors onclick="fn(this)")
    }
    fn.apply(el, args);
  }

  // ── click ──────────────────────────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    var el = e.target;

    // modal-overlay / um-overlay backdrop: clicking the overlay element itself closes it.
    // Guard against iOS ghost-click: ignore if modal opened < 350ms ago.
    if (el.classList && (el.classList.contains('modal-overlay') || el.classList.contains('um-overlay'))) {
      var openedAt = _modalOpenedAt[el.id] || 0;
      if (Date.now() - openedAt > 350) {
        el.style.display = 'none';
      }
      return;
    }

    // data-print
    if (el.closest('[data-print]')) { window.print(); return; }

    // data-confirm-click
    var cc = el.closest('[data-confirm-click]');
    if (cc) {
      if (!confirm(cc.dataset.confirmClick)) { e.preventDefault(); return; }
    }

    // data-close-modal
    var cm = el.closest('[data-close-modal]');
    if (cm) {
      var cmId = cm.dataset.closeModal;
      if (cmId && typeof window.closeModal === 'function') window.closeModal(cmId);
      else if (typeof window.pkgCloseModal === 'function') window.pkgCloseModal();
      return;
    }

    // data-show-modal
    var sm = el.closest('[data-show-modal]');
    if (sm) {
      var smId = sm.dataset.showModal;
      if (typeof window.showModal === 'function') window.showModal(smId);
      else if (typeof window.openModal === 'function') window.openModal(smId);
      else { var smEl = document.getElementById(smId); if (smEl) smEl.classList.add('open'); }
      return;
    }

    // data-toggle-visible
    var tv = el.closest('[data-toggle-visible]');
    if (tv) {
      var tvEl = document.getElementById(tv.dataset.toggleVisible);
      if (tvEl) tvEl.style.display = tvEl.style.display === 'none' ? 'block' : 'none';
      return;
    }

    // data-toggle-sibling: toggle a CSS class on the next sibling element
    var tsi = el.closest('[data-toggle-sibling]');
    if (tsi) {
      var tsib = tsi.nextElementSibling;
      if (tsib) tsib.classList.toggle(tsi.dataset.toggleSibling || 'hidden');
      return;
    }

    // data-expand: toggle display of target element, update button label
    var exp = el.closest('[data-expand]');
    if (exp) {
      var expEl = document.getElementById(exp.dataset.expand);
      if (expEl) {
        var shown = expEl.style.display !== 'none';
        expEl.style.display = shown ? 'none' : 'block';
        exp.innerHTML = shown
          ? '<i class="bi bi-chevron-down"></i> Show assumptions'
          : '<i class="bi bi-chevron-up"></i> Hide assumptions';
      }
      return;
    }

    // data-toggle-class / data-add-class / data-remove-class
    var tc = el.closest('[data-toggle-class]');
    if (tc) {
      var tcTarget = tc.dataset.target ? document.querySelector(tc.dataset.target) : tc;
      if (tcTarget) tcTarget.classList.toggle(tc.dataset.toggleClass);
      return;
    }
    var ac = el.closest('[data-add-class]');
    if (ac) {
      var acTarget = ac.dataset.target ? document.querySelector(ac.dataset.target) : ac;
      if (acTarget) acTarget.classList.add(ac.dataset.addClass);
      return;
    }
    var rc = el.closest('[data-remove-class]');
    if (rc) {
      var rcTarget = rc.dataset.target ? document.querySelector(rc.dataset.target) : rc;
      if (rcTarget) rcTarget.classList.remove(rc.dataset.removeClass);
      return;
    }

    // data-click (generic function call)
    var dc = el.closest('[data-click]');
    if (dc) { callFn(dc, dc.dataset.click); }
  });

  // ── change ─────────────────────────────────────────────────────────────────
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.dataset.autosubmit !== undefined) { if (el.form) el.form.submit(); return; }
    if (el.dataset.change) {
      if (el.dataset.inputVal !== undefined) {
        var fn = resolve(el.dataset.change);
        if (typeof fn === 'function') fn.call(el, el.value);
      } else {
        callFn(el, el.dataset.change);
      }
    }
  });

  // ── input ──────────────────────────────────────────────────────────────────
  document.addEventListener('input', function (e) {
    var el = e.target;
    // data-value-display: set textContent of another element to this.value
    if (el.dataset.valueDisplay !== undefined) {
      var vd = document.getElementById(el.dataset.valueDisplay);
      if (vd) vd.textContent = el.value;
      return;
    }
    if (!el.dataset.input) return;
    var fn = resolve(el.dataset.input);
    if (typeof fn !== 'function') return;
    if (el.dataset.inputVal !== undefined) fn.call(el, el.value);
    else fn.call(el, e);
  });

  // ── submit ─────────────────────────────────────────────────────────────────
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) {
      e.preventDefault(); return;
    }
    // data-submit: custom submit handler receives (event, ...data-args)
    if (form.dataset.submit) {
      e.preventDefault();
      var fn = resolve(form.dataset.submit);
      if (typeof fn === 'function') {
        var args = [];
        if (form.dataset.args) try { args = JSON.parse(form.dataset.args); } catch (x) {}
        fn.apply(form, [e].concat(args));
      }
    }
  });

  // ── accordion: wire data-acc buttons (replaces onclick="toggleAccordion()") ──
  document.querySelectorAll('.nav-acc-header[data-acc]').forEach(function (btn) {
    btn.removeAttribute('onclick');
    btn.addEventListener('click', function () {
      if (typeof window.toggleAccordion === 'function') window.toggleAccordion(btn.dataset.acc);
    });
  });
}());

// ── Dark mode toggle ─────────────────────────────────────────────────────────
(function() {
  var btn = document.getElementById('themeToggle');
  if (!btn) return;

  function getTheme() { return localStorage.getItem('paladin-theme') || 'light'; }

  function applyTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    var icon = document.getElementById('themeIcon');
    if (icon) {
      icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    }
    btn.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
  }

  applyTheme(getTheme());

  btn.addEventListener('click', function() {
    var next = getTheme() === 'dark' ? 'light' : 'dark';
    localStorage.setItem('paladin-theme', next);
    applyTheme(next);
  });
}());

// ── File drop zones ───────────────────────────────────────────────────────────
window.showFileChange = function(arg) {
  var input = (arg && arg.target) ? arg.target : (arg instanceof Element ? arg : this);
  if (!input || !input.files || !input.files.length) return;
  var dropEl = input.dataset.dropId ? document.getElementById(input.dataset.dropId) : null;
  var nameEl = input.dataset.nameId ? document.getElementById(input.dataset.nameId) : null;
  var color  = input.dataset.color || 'var(--primary)';
  if (nameEl) {
    nameEl.style.display = 'block';
    nameEl.style.color   = color;
    var span = nameEl.querySelector('span');
    if (span) span.textContent = input.files[0].name;
  }
  if (dropEl) dropEl.style.borderColor = color;
};
document.querySelectorAll('.file-drop').forEach(function(drop) {
  drop.addEventListener('dragover',  function(e) { e.preventDefault(); drop.classList.add('drag-over'); });
  drop.addEventListener('dragleave', function()  { drop.classList.remove('drag-over'); });
  drop.addEventListener('drop', function(e) {
    e.preventDefault(); drop.classList.remove('drag-over');
    var forId = drop.getAttribute('for');
    var input = forId ? document.getElementById(forId) : null;
    if (!input || !e.dataTransfer.files.length) return;
    var dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    input.files = dt.files;
    window.showFileChange.call(input, input);
  });
});

// Filter popover toggle
document.addEventListener('click', function(e) {
  var btn = e.target.closest('.filter-btn[data-filter-toggle]');
  if (btn) {
    e.stopPropagation();
    var wrap = btn.closest('.filter-popover-wrap');
    var popover = wrap ? wrap.querySelector('.filter-popover') : null;
    if (popover) {
      var isOpen = popover.classList.contains('open');
      // Close all other open popovers
      document.querySelectorAll('.filter-popover.open').forEach(function(p) { p.classList.remove('open'); });
      document.querySelectorAll('.filter-btn[data-filter-toggle].active').forEach(function(b) { b.classList.remove('active'); });
      if (!isOpen) {
        popover.classList.add('open');
        btn.classList.add('active');
      }
    }
    return;
  }
  // Close on outside click
  if (!e.target.closest('.filter-popover-wrap')) {
    document.querySelectorAll('.filter-popover.open').forEach(function(p) { p.classList.remove('open'); });
    document.querySelectorAll('.filter-btn[data-filter-toggle].active').forEach(function(b) { b.classList.remove('active'); });
  }
});

// ── Table-of-contents macro: populate .macro-toc from headings in the .prose ──
(function () {
  document.querySelectorAll('.macro-toc').forEach(function (toc) {
    var prose = toc.closest('.prose');
    if (!prose) return;
    var heads = prose.querySelectorAll('h2, h3');
    if (!heads.length) return;
    var ul = document.createElement('ul');
    var n = 0;
    heads.forEach(function (h) {
      if (toc.contains(h)) return;
      if (!h.id) { h.id = 'h-' + (n++) + '-' + (h.textContent || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }
      var li = document.createElement('li');
      if (h.tagName === 'H3') li.className = 'toc-h3';
      var a = document.createElement('a');
      a.href = '#' + h.id;
      a.textContent = h.textContent;
      li.appendChild(a);
      ul.appendChild(li);
    });
    toc.appendChild(ul);
  });
})();

// ── Copy-to-clipboard: [data-copy="#selector"] copies that field's value ──────
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var sel = btn.getAttribute('data-copy');
    var src = document.querySelector(sel);
    if (!src) return;
    var text = src.value !== undefined ? src.value : src.textContent;
    var done = function () {
      var old = btn.getAttribute('data-label') || btn.innerHTML;
      btn.setAttribute('data-label', old);
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied';
      setTimeout(function () { btn.innerHTML = old; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { src.select && src.select(); });
    } else if (src.select) { src.select(); try { document.execCommand('copy'); done(); } catch (e2) {} }
  });
})();

/* ── Inline (anchored) comments on pages ──────────────────────────────────── */
(function () {
  var root = document.querySelector('[data-inline-root]');
  if (!root) return;

  var addBtn  = document.getElementById('ic-add-btn');
  var formWrap = document.getElementById('ic-form-wrap');
  var qInput  = document.getElementById('ic-quote');
  var pInput  = document.getElementById('ic-prefix');
  var sInput  = document.getElementById('ic-suffix');
  var preview = document.getElementById('ic-quote-preview');
  var bodyEl  = document.getElementById('ic-body');
  var cancel  = document.getElementById('ic-cancel');

  // ── Highlight existing anchors ──
  function highlightQuote(quote, prefix, id) {
    if (!quote) return false;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [], full = '', n;
    while ((n = walker.nextNode())) { nodes.push({ node: n, start: full.length }); full += n.nodeValue; }
    var idx = -1;
    if (prefix) { var p = full.indexOf(prefix + quote); if (p >= 0) idx = p + prefix.length; }
    if (idx < 0) idx = full.indexOf(quote);
    if (idx < 0) return false;
    var end = idx + quote.length;
    for (var i = 0; i < nodes.length; i++) {
      var ns = nodes[i].start, ne = ns + nodes[i].node.nodeValue.length;
      if (idx >= ns && end <= ne) {
        try {
          var range = document.createRange();
          range.setStart(nodes[i].node, idx - ns);
          range.setEnd(nodes[i].node, end - ns);
          var mark = document.createElement('mark');
          mark.className = 'ic-highlight';
          mark.setAttribute('data-ic-id', String(id));
          range.surroundContents(mark);
          return true;
        } catch (e) { return false; }
      }
    }
    return false; // spans element boundaries — skip highlight (still listed in sidebar)
  }

  var anchorsEl = document.getElementById('ic-anchors');
  var found = {};
  if (anchorsEl) {
    var anchors = [];
    try { anchors = JSON.parse(anchorsEl.textContent || '[]'); } catch (e) { anchors = []; }
    anchors.forEach(function (a) { found[a.id] = highlightQuote(a.quote, a.prefix, a.id); });
  }

  // Mark sidebar items whose anchor couldn't be located as "outdated".
  Object.keys(found).forEach(function (id) {
    if (!found[id]) {
      var item = document.querySelector('[data-ic-item="' + id + '"]');
      if (item) {
        var tag = document.createElement('span');
        tag.className = 'badge badge-amber';
        tag.style.marginLeft = '6px';
        tag.textContent = 'outdated';
        var q = item.querySelector('blockquote');
        if (q) q.appendChild(tag);
      }
    }
  });

  function setActive(id, on) {
    var mark = root.querySelector('mark.ic-highlight[data-ic-id="' + id + '"]');
    var item = document.querySelector('[data-ic-item="' + id + '"]');
    if (mark) mark.classList.toggle('ic-active', on);
    if (item) item.classList.toggle('ic-active', on);
  }

  // Highlight ↔ sidebar linking
  root.addEventListener('click', function (e) {
    var mark = e.target.closest && e.target.closest('mark.ic-highlight');
    if (!mark) return;
    var id = mark.getAttribute('data-ic-id');
    var item = document.getElementById('ic-' + id);
    if (item) { item.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    setActive(id, true);
    setTimeout(function () { setActive(id, false); }, 1600);
  });
  document.querySelectorAll('[data-ic-item]').forEach(function (item) {
    item.addEventListener('mouseenter', function () { setActive(item.getAttribute('data-ic-item'), true); });
    item.addEventListener('mouseleave', function () { setActive(item.getAttribute('data-ic-item'), false); });
    item.addEventListener('click', function (e) {
      if (e.target.closest('form')) return; // don't hijack resolve/delete buttons
      var id = item.getAttribute('data-ic-item');
      var mark = root.querySelector('mark.ic-highlight[data-ic-id="' + id + '"]');
      if (mark) mark.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  // ── Selection → comment composer ──
  if (!addBtn) return; // user lacks page.comment

  var pending = null;

  function clamp(s, max) { return s.length > max ? s.slice(s.length - max) : s; }

  function captureSelection() {
    var sel = window.getSelection();
    if (!sel || sel.isCollapsed) return null;
    var text = sel.toString().replace(/\s+/g, ' ').trim();
    if (text.length < 2) return null;
    var range = sel.getRangeAt(0);
    if (!root.contains(range.commonAncestorContainer)) return null;
    var prefix = '', suffix = '';
    if (range.startContainer.nodeType === 3) prefix = clamp(range.startContainer.nodeValue.slice(0, range.startOffset), 60).trim();
    if (range.endContainer.nodeType === 3)   suffix = range.endContainer.nodeValue.slice(range.endOffset, range.endOffset + 60).trim();
    return { text: text, prefix: prefix, suffix: suffix, rect: range.getBoundingClientRect() };
  }

  function placeAt(el, rect) {
    el.style.top = (window.scrollY + rect.bottom + 8) + 'px';
    el.style.left = (window.scrollX + rect.left) + 'px';
  }

  root.addEventListener('mouseup', function () {
    setTimeout(function () {
      var cap = captureSelection();
      if (!cap) { addBtn.hidden = true; return; }
      pending = cap;
      placeAt(addBtn, cap.rect);
      addBtn.hidden = false;
    }, 10);
  });

  addBtn.addEventListener('click', function () {
    if (!pending) return;
    qInput.value = pending.text;
    pInput.value = pending.prefix;
    sInput.value = pending.suffix;
    preview.textContent = pending.text;
    placeAt(formWrap, pending.rect);
    addBtn.hidden = true;
    formWrap.hidden = false;
    bodyEl.focus();
  });

  cancel.addEventListener('click', function () { formWrap.hidden = true; pending = null; });

  document.addEventListener('mousedown', function (e) {
    if (formWrap.contains(e.target) || addBtn.contains(e.target) || root.contains(e.target)) return;
    addBtn.hidden = true;
  });
})();

/* ── Workflow state-transition diagram (render + drag + connect) ───────────── */
(function () {
  var SVGNS = 'http://www.w3.org/2000/svg';
  var W = 132, H = 46;

  function el(name, attrs) {
    var n = document.createElementNS(SVGNS, name);
    for (var k in attrs) { if (attrs.hasOwnProperty(k)) n.setAttribute(k, attrs[k]); }
    return n;
  }
  // Clip a ray from a node centre to the node's rectangle border.
  function border(cx, cy, tx, ty) {
    var dx = tx - cx, dy = ty - cy;
    if (dx === 0 && dy === 0) return { x: cx, y: cy };
    var sx = (W / 2) / Math.abs(dx || 1e-6), sy = (H / 2) / Math.abs(dy || 1e-6);
    var s = Math.min(sx, sy);
    return { x: cx + dx * s, y: cy + dy * s };
  }

  document.querySelectorAll('svg[data-wf-diagram]').forEach(function (svg) {
    var wfId = svg.getAttribute('data-wf-id');
    var editable = svg.getAttribute('data-wf-editable') === '1';
    var dataEl = document.querySelector('script[data-wf-data="' + wfId + '"]');
    if (!dataEl) return;
    var data;
    try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
    var states = data.states || [], transitions = data.transitions || [];
    if (!states.length) return;

    var nodesG = svg.querySelector('[data-wf-nodes]');
    var edgesG = svg.querySelector('[data-wf-edges]');
    var byId = {};

    // Auto-layout any state missing a stored position (row-wrap).
    var perRow = Math.max(1, Math.floor((svg.getAttribute('width') - 40) / (W + 70)));
    states.forEach(function (s, i) {
      byId[s.id] = s;
      if (s.x === null || s.y === null || isNaN(s.x) || isNaN(s.y)) {
        s.x = 30 + (i % perRow) * (W + 70);
        s.y = 40 + Math.floor(i / perRow) * (H + 70);
        s.autoplaced = true;
      }
    });

    function drawEdges() {
      edgesG.textContent = '';
      transitions.forEach(function (t) {
        var a = byId[t.from], b = byId[t.to];
        if (!a || !b) return;
        var ac = { x: a.x + W / 2, y: a.y + H / 2 }, bc = { x: b.x + W / 2, y: b.y + H / 2 };
        if (t.from === t.to) {
          // Self-loop
          var lx = a.x + W / 2, ly = a.y;
          var p = 'M ' + (lx - 18) + ' ' + ly + ' C ' + (lx - 40) + ' ' + (ly - 50) + ', ' + (lx + 40) + ' ' + (ly - 50) + ', ' + (lx + 18) + ' ' + ly;
          edgesG.appendChild(el('path', { d: p, fill: 'none', stroke: 'var(--text-muted,#64748b)', 'stroke-width': '1.6', 'marker-end': 'url(#wf-arrow)' }));
          addLabel(lx, ly - 46, t.label);
          return;
        }
        var s1 = border(ac.x, ac.y, bc.x, bc.y);
        var s2 = border(bc.x, bc.y, ac.x, ac.y);
        // Offset opposing edges so A→B and B→A don't overlap.
        var nx = -(s2.y - s1.y), ny = (s2.x - s1.x);
        var nl = Math.hypot(nx, ny) || 1; nx /= nl; ny /= nl;
        var off = (t.from < t.to) ? 18 : -18;
        var mx = (s1.x + s2.x) / 2 + nx * off, my = (s1.y + s2.y) / 2 + ny * off;
        var d = 'M ' + s1.x + ' ' + s1.y + ' Q ' + mx + ' ' + my + ' ' + s2.x + ' ' + s2.y;
        edgesG.appendChild(el('path', { d: d, fill: 'none', stroke: 'var(--text-muted,#64748b)', 'stroke-width': '1.6', 'marker-end': 'url(#wf-arrow)' }));
        addLabel(mx, my, t.label);
      });
    }
    function addLabel(x, y, text) {
      var pad = 4, w = (text || '').length * 6.4 + pad * 2;
      edgesG.appendChild(el('rect', { x: x - w / 2, y: y - 9, width: w, height: 16, rx: 4, fill: 'var(--card-bg,#fff)', stroke: 'var(--border-light,#e2e8f0)' }));
      var t = el('text', { x: x, y: y + 3, 'text-anchor': 'middle', 'font-size': '11', fill: 'var(--text,#0f172a)' });
      t.textContent = text || '';
      edgesG.appendChild(t);
    }

    function drawNodes() {
      nodesG.textContent = '';
      states.forEach(function (s) {
        var g = el('g', { transform: 'translate(' + s.x + ',' + s.y + ')', 'data-state': s.id, style: editable ? 'cursor:grab' : '' });
        g.appendChild(el('rect', { width: W, height: H, rx: 9, fill: s.color, opacity: '0.92', stroke: 'rgba(0,0,0,.18)' }));
        if (s.isInitial) g.appendChild(el('circle', { cx: 12, cy: 12, r: 5, fill: '#fff', stroke: s.color, 'stroke-width': '1.5' }));
        var t = el('text', { x: W / 2, y: H / 2 + 4, 'text-anchor': 'middle', 'font-size': '13', 'font-weight': '600', fill: '#fff' });
        t.textContent = s.name;
        g.appendChild(t);
        nodesG.appendChild(g);
      });
    }

    drawNodes();
    drawEdges();
    if (!editable) return;

    // ── Drag to reposition ──
    var dragging = null, saveTimer = null;
    function svgPoint(evt) {
      var pt = svg.createSVGPoint();
      pt.x = evt.clientX; pt.y = evt.clientY;
      var ctm = svg.getScreenCTM();
      return ctm ? pt.matrixTransform(ctm.inverse()) : { x: evt.clientX, y: evt.clientY };
    }
    svg.addEventListener('pointerdown', function (e) {
      var g = e.target.closest && e.target.closest('g[data-state]');
      if (!g || connectMode) return;
      var s = byId[g.getAttribute('data-state')];
      var p = svgPoint(e);
      dragging = { s: s, dx: p.x - s.x, dy: p.y - s.y, g: g };
      g.setAttribute('style', 'cursor:grabbing');
      svg.setPointerCapture(e.pointerId);
    });
    svg.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var p = svgPoint(e);
      dragging.s.x = Math.max(0, Math.round(p.x - dragging.dx));
      dragging.s.y = Math.max(0, Math.round(p.y - dragging.dy));
      dragging.g.setAttribute('transform', 'translate(' + dragging.s.x + ',' + dragging.s.y + ')');
      drawEdges();
    });
    svg.addEventListener('pointerup', function (e) {
      if (!dragging) return;
      dragging.g.setAttribute('style', 'cursor:grab');
      dragging = null;
      scheduleSave();
    });

    function scheduleSave() {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(savePositions, 600);
    }
    function savePositions() {
      var meta = document.querySelector('meta[name="csrf-token"]');
      var positions = {};
      states.forEach(function (s) { positions[s.id] = { x: s.x, y: s.y }; });
      fetch('/workflows/' + wfId + '/layout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: meta ? meta.getAttribute('content') : '', positions: positions })
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.csrf && meta) meta.setAttribute('content', j.csrf);
      }).catch(function () {});
    }

    // ── Connect mode (click two states → create transition) ──
    var connectMode = false, connectFrom = null;
    var toggleBtn = document.querySelector('[data-wf-connect-toggle]');
    var hint = document.querySelector('[data-wf-hint]');
    var form = document.querySelector('[data-wf-connect-form]');
    var autoBtn = document.querySelector('[data-wf-autolayout]');

    function setHint(msg) { if (hint) hint.textContent = msg || ''; }
    if (toggleBtn) toggleBtn.addEventListener('click', function () {
      connectMode = !connectMode; connectFrom = null;
      toggleBtn.classList.toggle('btn-primary', connectMode);
      toggleBtn.classList.toggle('btn-light', !connectMode);
      setHint(connectMode ? 'Click the source state…' : '');
    });
    svg.addEventListener('click', function (e) {
      if (!connectMode) return;
      var g = e.target.closest && e.target.closest('g[data-state]');
      if (!g) return;
      var id = g.getAttribute('data-state');
      if (!connectFrom) { connectFrom = id; setHint('…now click the target state'); return; }
      if (form) {
        form.querySelector('[data-wf-from]').value = connectFrom;
        form.querySelector('[data-wf-to]').value = id;
        form.submit();
      }
    });
    if (autoBtn) autoBtn.addEventListener('click', function () {
      states.forEach(function (s, i) {
        s.x = 30 + (i % perRow) * (W + 70);
        s.y = 40 + Math.floor(i / perRow) * (H + 70);
      });
      drawNodes(); drawEdges(); savePositions();
    });

    // Persist any auto-placed positions once so the first load sticks.
    if (states.some(function (s) { return s.autoplaced; })) scheduleSave();
  });
})();

/* ── Drag-and-drop page tree (reorder + re-nest) ──────────────────────────── */
(function () {
  var tree = document.querySelector('[data-page-tree][data-can-edit]');
  if (!tree) return;

  var dragId = null, overRow = null, zone = null;

  function clearMarker() {
    tree.querySelectorAll('.pt-row').forEach(function (r) {
      r.style.borderTop = ''; r.style.borderBottom = ''; r.style.background = '';
    });
  }

  tree.addEventListener('dragstart', function (e) {
    var row = e.target.closest && e.target.closest('.pt-row');
    if (!row) return;
    dragId = row.closest('li[data-pt-node]').getAttribute('data-pt-node');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', dragId); } catch (x) {}
    row.style.opacity = '0.5';
  });
  tree.addEventListener('dragend', function (e) {
    var row = e.target.closest && e.target.closest('.pt-row');
    if (row) row.style.opacity = '';
    clearMarker(); dragId = null; overRow = null; zone = null;
  });

  tree.addEventListener('dragover', function (e) {
    var row = e.target.closest && e.target.closest('.pt-row');
    if (!row || !dragId) return;
    var li = row.closest('li[data-pt-node]');
    if (li.getAttribute('data-pt-node') === dragId) return; // not onto itself
    // Don't allow dropping onto a descendant of the dragged node.
    if (li.closest('li[data-pt-node="' + dragId + '"]')) return;
    e.preventDefault();
    clearMarker();
    var r = row.getBoundingClientRect();
    var rel = (e.clientY - r.top) / r.height;
    zone = rel < 0.3 ? 'before' : (rel > 0.7 ? 'after' : 'inside');
    overRow = row;
    if (zone === 'before') row.style.borderTop = '2px solid var(--primary)';
    else if (zone === 'after') row.style.borderBottom = '2px solid var(--primary)';
    else row.style.background = 'color-mix(in srgb, var(--primary) 16%, transparent)';
  });

  tree.addEventListener('drop', function (e) {
    if (!dragId || !overRow || !zone) return;
    e.preventDefault();
    var targetLi = overRow.closest('li[data-pt-node]');
    var targetId = targetLi.getAttribute('data-pt-node');
    var targetParent = targetLi.getAttribute('data-pt-parent') || null;
    var parentId, position;

    if (zone === 'inside') {
      parentId = targetId;
      var childUl = targetLi.querySelector(':scope > ul');
      position = childUl ? childUl.children.length + 1 : 1;
    } else {
      parentId = targetParent;
      // Position among the target's siblings (1-based), before/after target.
      var sibLis = Array.prototype.filter.call(targetLi.parentNode.children, function (n) { return n.matches('li[data-pt-node]'); });
      var idx = sibLis.indexOf(targetLi);
      position = (zone === 'before' ? idx : idx + 1) + 1;
    }
    clearMarker();

    var meta = document.querySelector('meta[name="csrf-token"]');
    fetch('/pages/' + dragId + '/reorder', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: meta ? meta.getAttribute('content') : '', parent_id: parentId, position: position })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.csrf && meta) meta.setAttribute('content', j.csrf);
      if (j && j.ok) { window.location.reload(); }
      else { alert((j && j.error) || 'Could not move the page.'); }
    }).catch(function () { alert('Could not move the page.'); });
  });
})();
