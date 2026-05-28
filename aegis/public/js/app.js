/* AEGIS GRC — app.js */

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
      const item = el ? el.closest('.alert-panel-item') : null;
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
  document.querySelectorAll('.alert-panel-item:not(.read) .alert-read-btn').forEach(function (btn) {
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
window.showModal = function (id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'flex';
};
window.closeModal = function (id) {
  const el = document.getElementById(id);
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
    document.querySelectorAll('.modal-overlay').forEach(function (m) {
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
