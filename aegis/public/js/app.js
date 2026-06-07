/* AEGIS GRC — app.js */

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
      namePrev.textContent = nameInput.value.trim() || 'AEGIS GRC';
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

  function getTheme() { return localStorage.getItem('aegis-theme') || 'light'; }

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
    localStorage.setItem('aegis-theme', next);
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
