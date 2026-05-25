/* AEGIS GRC — app.js */

// Sidebar toggle
function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('open');
}

// Close sidebar on overlay click (mobile)
document.addEventListener('click', function (e) {
  const sidebar = document.querySelector('.sidebar');
  if (sidebar && sidebar.classList.contains('open') && !sidebar.contains(e.target)) {
    const btn = document.querySelector('.sidebar-toggle');
    if (btn && !btn.contains(e.target)) sidebar.classList.remove('open');
  }
});

// Alert panel
const alertPanel = document.getElementById('alertPanel');

function toggleAlertPanel() {
  if (!alertPanel) return;
  alertPanel.classList.toggle('open');
}

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

// Modal helpers (also declared inline in views — safe to redefine here)
window.showModal = function (id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'flex';
};
window.closeModal = function (id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
};

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

// ── Mobile sidebar overlay ──────────────────────────────────────────────────
(function() {
  var overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.onclick = function() { closeMobileSidebar(); };
  document.body.appendChild(overlay);

  window.toggleSidebar = function() {
    var s = document.getElementById('sidebar');
    if (!s) return;
    if (s.classList.contains('open')) { closeMobileSidebar(); }
    else { s.classList.add('open'); overlay.classList.add('open'); }
  };

  function closeMobileSidebar() {
    var s = document.getElementById('sidebar');
    if (s) s.classList.remove('open');
    overlay.classList.remove('open');
  }
})();
