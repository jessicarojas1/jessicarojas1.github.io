/* script.js — Core site utilities */

/* ── Theme: set before CSS loads to prevent FOUC ─────────────── */
/* (This logic also runs inline in <head> via a tiny inline script) */

document.addEventListener('DOMContentLoaded', () => {

  /* ── Dark / Light mode toggle ────────────────────────────── */
  const themeBtn = document.getElementById('themeToggleBtn');

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    localStorage.setItem('bsTheme', theme);
    if (themeBtn) {
      themeBtn.querySelector('.theme-icon').textContent = theme === 'dark' ? '☀️' : '🌙';
      themeBtn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
  }

  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-bs-theme') || 'dark';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
    // Sync icon on load
    const saved = localStorage.getItem('bsTheme') || 'dark';
    const icon = themeBtn.querySelector('.theme-icon');
    if (icon) icon.textContent = saved === 'dark' ? '☀️' : '🌙';
  }

  /* ── Dynamic copyright year ──────────────────────────────── */
  document.querySelectorAll('[data-year]').forEach(el => {
    el.textContent = new Date().getFullYear();
  });

  /* ── Scroll reveal ───────────────────────────────────────── */
  const reveals = document.querySelectorAll('.reveal');
  if (reveals.length) {
    const obs = new IntersectionObserver(
      entries => entries.forEach(e => {
        if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }
      }),
      { threshold: 0.1 }
    );
    reveals.forEach(el => obs.observe(el));
  }

  /* ── Contact form → localStorage submissions (for admin view) */
  const contactForm = document.getElementById('contactForm');
  if (contactForm) {
    contactForm.addEventListener('submit', e => {
      const name    = document.getElementById('cf-name')?.value   || '';
      const email   = document.getElementById('cf-email')?.value  || '';
      const subject = document.getElementById('cf-subject')?.value || '';
      const message = document.getElementById('cf-message')?.value || '';
      const submissions = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
      submissions.unshift({
        id: Date.now(),
        name, email, subject, message,
        date: new Date().toLocaleString(),
        read: false,
      });
      localStorage.setItem('contact_submissions', JSON.stringify(submissions));
    });
  }

  /* ── Admin: render contact submissions ────────────────────── */
  const inboxEl = document.getElementById('admin-inbox');
  if (inboxEl && typeof RBAC !== 'undefined' && RBAC.isAtLeast('admin')) {
    renderInbox(inboxEl);
  }

  /* ── Admin: dynamic projects ─────────────────────────────── */
  if (typeof RBAC !== 'undefined' && RBAC.isAtLeast('editor')) {
    initDynamicProjects();
  }

});

/* ── Inbox renderer ──────────────────────────────────────────── */
function renderInbox(container) {
  const submissions = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
  if (!submissions.length) {
    container.innerHTML = '<p class="text-secondary">No submissions yet.</p>';
    return;
  }
  container.innerHTML = submissions.map((s, i) => `
    <div class="card mb-2 ${s.read ? '' : 'border-primary'}">
      <div class="card-body py-2">
        <div class="d-flex justify-content-between">
          <strong>${escHtml(s.name)}</strong>
          <small class="text-secondary">${escHtml(s.date)}</small>
        </div>
        <div class="text-secondary small">${escHtml(s.email)}</div>
        ${s.subject ? `<div class="small fw-semibold mt-1">${escHtml(s.subject)}</div>` : ''}
        <p class="mb-1 mt-1 small">${escHtml(s.message)}</p>
        <button class="btn btn-sm btn-outline-secondary" onclick="markRead(${i})">
          ${s.read ? 'Mark Unread' : 'Mark Read'}
        </button>
        <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteSubmission(${i})">Delete</button>
      </div>
    </div>
  `).join('');
}

function markRead(idx) {
  const subs = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
  if (subs[idx]) { subs[idx].read = !subs[idx].read; localStorage.setItem('contact_submissions', JSON.stringify(subs)); }
  const inboxEl = document.getElementById('admin-inbox');
  if (inboxEl) renderInbox(inboxEl);
}

function deleteSubmission(idx) {
  const subs = JSON.parse(localStorage.getItem('contact_submissions') || '[]');
  subs.splice(idx, 1);
  localStorage.setItem('contact_submissions', JSON.stringify(subs));
  const inboxEl = document.getElementById('admin-inbox');
  if (inboxEl) renderInbox(inboxEl);
}

/* ── Dynamic projects (editor/admin add project) ─────────────── */
function initDynamicProjects() {
  const grid = document.getElementById('projects-grid');
  const addForm = document.getElementById('add-project-form');
  if (!grid || !addForm) return;

  // Render saved projects
  renderDynamicProjects(grid);

  addForm.addEventListener('submit', e => {
    e.preventDefault();
    const title = document.getElementById('proj-title').value.trim();
    const desc  = document.getElementById('proj-desc').value.trim();
    const url   = document.getElementById('proj-url').value.trim();
    const link  = document.getElementById('proj-link').value.trim();
    if (!title) return;

    const projects = JSON.parse(localStorage.getItem('dynamic_projects') || '[]');
    projects.push({ id: Date.now(), title, desc, url, link });
    localStorage.setItem('dynamic_projects', JSON.stringify(projects));
    renderDynamicProjects(grid);
    addForm.reset();
    bootstrap.Modal.getInstance(document.getElementById('addProjectModal'))?.hide();
  });
}

function renderDynamicProjects(grid) {
  // Remove existing dynamic cards
  grid.querySelectorAll('[data-dynamic-project]').forEach(el => el.remove());

  const projects = JSON.parse(localStorage.getItem('dynamic_projects') || '[]');
  const isAdmin  = typeof RBAC !== 'undefined' && RBAC.isAtLeast('admin');

  projects.forEach(p => {
    const col = document.createElement('div');
    col.className = 'col';
    col.setAttribute('data-dynamic-project', p.id);
    col.innerHTML = `
      <div class="card h-100">
        <div class="card-body">
          <h3 class="card-title h5">${escHtml(p.title)}</h3>
          <p class="card-text">${escHtml(p.desc)}</p>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
          ${p.link ? `<a href="${escHtml(p.link)}" class="btn btn-sm btn-primary">View Project</a>` : ''}
          ${isAdmin ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteDynamicProject(${p.id})">Delete</button>` : ''}
        </div>
      </div>`;
    grid.appendChild(col);
  });
}

function deleteDynamicProject(id) {
  let projects = JSON.parse(localStorage.getItem('dynamic_projects') || '[]');
  projects = projects.filter(p => p.id !== id);
  localStorage.setItem('dynamic_projects', JSON.stringify(projects));
  const grid = document.getElementById('projects-grid');
  if (grid) renderDynamicProjects(grid);
}

/* ── HTML escape helper ──────────────────────────────────────── */
function escHtml(str) {
  return String(str).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])
  );
}
