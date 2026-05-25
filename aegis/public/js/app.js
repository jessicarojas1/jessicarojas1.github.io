/* AEGIS GRC — app.js */

/* ─── Sidebar ──────────────────────────────────────────────── */
function openSidebar() {
  var sidebar  = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  sidebar.classList.add('open');
  if (backdrop) backdrop.classList.add('open');
}

function closeSidebar() {
  var sidebar  = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  sidebar.classList.remove('open');
  if (backdrop) backdrop.classList.remove('open');
}

function toggleSidebar() {
  var sidebar = document.getElementById('sidebar');
  if (sidebar && sidebar.classList.contains('open')) {
    closeSidebar();
  } else {
    openSidebar();
  }
}

// Wire sidebar toggle and backdrop in DOMContentLoaded.
// Use touchend on mobile to bypass iOS Safari's click-event quirks —
// preventDefault() on touchend stops the synthesised click that would
// otherwise race with the document-level close handler.
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.querySelector('.sidebar-toggle');
  if (toggle) {
    var toggleTouched = false;
    toggle.addEventListener('touchend', function (e) {
      toggleTouched = true;
      e.preventDefault();
      toggleSidebar();
    }, { passive: false });
    toggle.addEventListener('click', function () {
      if (toggleTouched) { toggleTouched = false; return; }
      toggleSidebar();
    });
  }

  var backdrop = document.getElementById('sidebarBackdrop');
  if (backdrop) {
    var backdropTouched = false;
    backdrop.addEventListener('touchend', function (e) {
      backdropTouched = true;
      e.preventDefault();
      closeSidebar();
    }, { passive: false });
    backdrop.addEventListener('click', function () {
      if (backdropTouched) { backdropTouched = false; return; }
      closeSidebar();
    });
  }
});

// Close sidebar on Escape
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    closeSidebar();
    document.querySelectorAll('.modal-overlay').forEach(function (m) {
      m.style.display = 'none';
    });
  }
});

/* ─── Touch swipe to open / close sidebar ──────────────────── */
(function () {
  var touchStartX = 0;
  var touchStartY = 0;
  var swiping = false;
  var THRESHOLD = 60;
  var EDGE_ZONE = 24; // px from left edge to trigger open swipe

  document.addEventListener('touchstart', function (e) {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
    swiping = true;
  }, { passive: true });

  document.addEventListener('touchend', function (e) {
    if (!swiping) return;
    var dx = e.changedTouches[0].clientX - touchStartX;
    var dy = e.changedTouches[0].clientY - touchStartY;
    swiping = false;

    // Only act if mostly horizontal swipe
    if (Math.abs(dy) > Math.abs(dx) * 1.2) return;

    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    if (dx > THRESHOLD && touchStartX < EDGE_ZONE && !sidebar.classList.contains('open')) {
      openSidebar();
    } else if (dx < -THRESHOLD && sidebar.classList.contains('open')) {
      closeSidebar();
    }
  }, { passive: true });
})();

/* ─── Alert panel ───────────────────────────────────────────── */
var alertPanel = document.getElementById('alertPanel');
var alertOverlay = document.getElementById('alertOverlay');

function toggleAlertPanel() {
  if (!alertPanel) return;
  var isOpen = alertPanel.classList.toggle('open');
  if (alertOverlay) alertOverlay.classList.toggle('open', isOpen);
}

function markAlertRead(id, el) {
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.content : '';

  fetch('/alerts/' + id + '/read', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'csrf_token=' + encodeURIComponent(csrf),
  }).then(function (r) {
    if (r.ok) {
      var item = el ? el.closest('.alert-item') : null;
      if (item) item.classList.replace('unread', 'read');
      var dot = document.querySelector('.alert-badge');
      if (dot) {
        var current = parseInt(dot.textContent) || 0;
        if (current <= 1) dot.style.display = 'none';
        else dot.textContent = current - 1;
      }
    }
  });
}

/* ─── Flash message auto-dismiss ───────────────────────────── */
document.querySelectorAll('.alert-box').forEach(function (box) {
  setTimeout(function () {
    box.style.transition = 'opacity 0.4s';
    box.style.opacity = '0';
    setTimeout(function () { box.remove(); }, 400);
  }, 6000);
});

/* ─── Modal helpers ─────────────────────────────────────────── */
window.showModal = function (id) {
  var el = document.getElementById(id);
  if (el) el.style.display = 'flex';
};
window.closeModal = function (id) {
  var el = document.getElementById(id);
  if (el) el.style.display = 'none';
};

/* ─── Time-ago ───────────────────────────────────────────────── */
function timeAgo(dateStr) {
  var date = new Date(dateStr);
  var now = new Date();
  var diff = Math.floor((now - date) / 1000);
  if (diff < 60)    return diff + 's ago';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}

document.querySelectorAll('[data-timeago]').forEach(function (el) {
  el.textContent = timeAgo(el.dataset.timeago);
});

/* ─── Permission matrix ─────────────────────────────────────── */
document.querySelectorAll('.perm-checkbox').forEach(function (cb) {
  cb.addEventListener('change', function () {
    var row = cb.closest('tr');
    if (row) row.classList.add('perm-row-modified');
  });
});

document.querySelectorAll('.perm-col-all').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var module = btn.dataset.module;
    var perm   = btn.dataset.perm;
    var boxes  = document.querySelectorAll(
      '.perm-checkbox[data-module="' + module + '"][data-perm="' + perm + '"]'
    );
    var anyUnchecked = Array.from(boxes).some(function (b) { return !b.checked; });
    boxes.forEach(function (b) {
      b.checked = anyUnchecked;
      b.dispatchEvent(new Event('change'));
    });
  });
});

/* ─── Wrap wide tables for horizontal scroll on mobile ─────── */
(function () {
  if (window.innerWidth > 768) return;
  document.querySelectorAll('.card-body.p0').forEach(function (container) {
    var table = container.querySelector('table.table');
    if (table && !container.classList.contains('table-scroll-applied')) {
      container.style.overflowX = 'auto';
      container.style.webkitOverflowScrolling = 'touch';
      container.classList.add('table-scroll-applied');
    }
  });
})();
