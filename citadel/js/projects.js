/* CITADEL — Projects (organize scans under a named project).
 *
 * A project must be selected before scanning (the scan gate enforces it), and
 * every scan is tagged with its project so history is findable by project.
 *
 * Storage mirrors scan history's dual model:
 *   - Per-browser localStorage by default (works with no backend / no database).
 *   - When a durable backend is present (CITADEL.api.projectsList -> enabled),
 *     the server (Postgres) is the source of truth and CRUD round-trips to it.
 * The "current project" id is always kept in localStorage so the choice sticks
 * per browser regardless of backend.
 *
 * window.CITADEL.projects
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const PKEY = 'citadel.projects.v1';
  const CKEY = 'citadel.project.current';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function $(id) { return document.getElementById(id); }
  function read() { try { return JSON.parse(localStorage.getItem(PKEY) || '[]'); } catch (e) { return []; } }
  function write(v) { try { localStorage.setItem(PKEY, JSON.stringify(v)); } catch (e) {} }
  function uid() { return 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function clean(s, n) { return String(s == null ? '' : s).trim().slice(0, n || 200); }

  // Server state (when a durable backend is present). _server === null means
  // "local mode" (no backend / no DB); an array means server-backed.
  let _server = null;

  function serverEnabled() { return Array.isArray(_server); }
  function all() { return serverEnabled() ? _server.slice() : read(); }
  function get(id) { return all().find(p => p.id === id) || null; }
  function currentId() { try { return localStorage.getItem(CKEY) || null; } catch (e) { return null; } }
  function current() { const id = currentId(); return id ? get(id) : null; }
  function setCurrent(id) {
    try { id ? localStorage.setItem(CKEY, id) : localStorage.removeItem(CKEY); } catch (e) {}
    emitChange();
  }
  function count() { return all().length; }

  // Detect + load a durable backend's projects (called once at startup, and
  // after backend auth changes). Safe to call repeatedly; falls back to local.
  async function load() {
    if (CITADEL.api && CITADEL.api.projectsList) {
      try {
        const res = await CITADEL.api.projectsList();
        if (res && res.enabled) { _server = (res.projects || []).map(normalize); emitChange(); return; }
      } catch (e) { /* fall through to local */ }
    }
    _server = null;
    emitChange();
  }
  function normalize(p) {
    return { id: String(p.id), name: p.name || 'Untitled', description: p.description || '',
      createdAt: p.createdAt || new Date().toISOString(), scanCount: p.scanCount | 0 };
  }

  async function create(name, description) {
    name = clean(name, 200); description = clean(description, 2000);
    if (!name) throw new Error('Project name is required.');
    if (serverEnabled()) {
      const p = normalize(await CITADEL.api.projectCreate(name, description));
      _server.unshift(p); setCurrent(p.id); return p;
    }
    const list = read();
    const p = { id: uid(), name, description, createdAt: new Date().toISOString() };
    list.unshift(p); write(list); setCurrent(p.id); emitChange(); return p;
  }
  async function rename(id, name) {
    name = clean(name, 200); if (!name) return null;
    if (serverEnabled()) {
      await CITADEL.api.projectRename(id, name);
      const p = _server.find(x => x.id === id); if (p) p.name = name; emitChange(); return p;
    }
    const list = read(); const p = list.find(x => x.id === id);
    if (p) { p.name = name; write(list); emitChange(); } return p;
  }
  async function remove(id) {
    if (serverEnabled()) {
      await CITADEL.api.projectDelete(id);
      _server = _server.filter(x => x.id !== id);
    } else {
      write(read().filter(x => x.id !== id));
    }
    if (currentId() === id) setCurrent(null); else emitChange();
  }

  // Notify the app (gating + bars + views) that projects/current changed.
  function emitChange() { try { document.dispatchEvent(new CustomEvent('citadel:project-change')); } catch (e) {} }

  /* ---------- Scan-count helper (local mode uses history) ---------- */
  function scanCountOf(p) {
    if (p && typeof p.scanCount === 'number') return p.scanCount;
    try {
      const h = (CITADEL.history && CITADEL.history.list && CITADEL.history.list()) || [];
      return h.filter(x => x.projectId === p.id).length;
    } catch (e) { return 0; }
  }

  /* ---------- Intake project bar ---------- */
  // Renders into #project-bar: shows the current project (with a switcher) or a
  // prominent "set up a project" prompt that gates scanning.
  function renderBar() {
    const el = $('project-bar'); if (!el) return;
    const list = all(); const cur = current();
    if (!cur) {
      el.className = 'project-bar project-bar-empty';
      el.innerHTML = `<div class="d-flex align-items-center gap-2 flex-wrap">
          <i class="bi bi-exclamation-triangle-fill text-warning"></i>
          <strong>Set up a project to start scanning.</strong>
          <span class="text-body-secondary small">Every scan is saved under a project so you can find it by name later.</span>
        </div>
        <button class="btn btn-sm btn-primary" data-projects-open><i class="bi bi-folder-plus"></i> Set up a project</button>`;
      return;
    }
    el.className = 'project-bar';
    const opts = list.map(p => `<option value="${esc(p.id)}"${p.id === cur.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('');
    el.innerHTML = `<div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-body-secondary small text-uppercase fw-bold"><i class="bi bi-folder-fill"></i> Project</span>
        <select class="form-select form-select-sm" id="project-switch" style="max-width:240px" aria-label="Current project">${opts}</select>
        <span class="badge text-bg-secondary" title="Scans in this project">${scanCountOf(cur)} scan(s)</span>
      </div>
      <button class="btn btn-sm btn-outline-secondary" data-projects-open><i class="bi bi-folder2-open"></i> Manage projects</button>`;
  }

  /* ---------- Projects view (full management area) ---------- */
  function renderProjects() {
    const el = $('projects-view'); if (!el) return;
    const list = all(); const curId = currentId();
    const cards = list.length ? list.map(p => {
      const isCur = p.id === curId;
      return `<div class="col-md-6 col-xl-4">
        <div class="card citadel-card project-card${isCur ? ' project-current' : ''}">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <h6 class="mb-1 text-truncate" title="${esc(p.name)}"><i class="bi bi-folder-fill text-warning"></i> ${esc(p.name)}</h6>
              ${isCur ? '<span class="badge text-bg-success">current</span>' : ''}
            </div>
            ${p.description ? `<p class="small text-body-secondary mb-2">${esc(p.description)}</p>` : '<p class="small text-body-secondary fst-italic mb-2">No description</p>'}
            <div class="small text-body-secondary mb-3"><i class="bi bi-clock-history"></i> ${esc(new Date(p.createdAt).toLocaleDateString())} · <strong>${scanCountOf(p)}</strong> scan(s)</div>
            <div class="d-flex flex-wrap gap-1">
              ${isCur
                ? '<button class="btn btn-sm btn-success" disabled><i class="bi bi-check-lg"></i> Selected</button>'
                : `<button class="btn btn-sm btn-primary" data-project-select="${esc(p.id)}"><i class="bi bi-box-arrow-in-right"></i> Select</button>`}
              <button class="btn btn-sm btn-outline-secondary" data-project-history="${esc(p.id)}" title="View this project's scan history"><i class="bi bi-clock-history"></i></button>
              <button class="btn btn-sm btn-outline-secondary" data-project-rename="${esc(p.id)}" title="Rename"><i class="bi bi-pencil"></i></button>
              <button class="btn btn-sm btn-outline-danger" data-project-delete="${esc(p.id)}" title="Delete project"><i class="bi bi-trash"></i></button>
            </div>
          </div>
        </div></div>`;
    }).join('') : '<div class="col-12"><div class="empty-state"><i class="bi bi-folder-plus"></i><p>No projects yet. Create one below to start scanning.</p></div></div>';

    el.innerHTML = `
      <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="page-title mb-0"><i class="bi bi-folder-fill"></i> Projects</h1>
        <button class="btn btn-sm btn-outline-secondary" data-projects-close><i class="bi bi-x-lg"></i> Close</button>
      </div>
      <p class="text-body-secondary">Organize scans under a project. Select a project to scan into it; every scan is tagged with the project so you can find it by name in History.</p>
      <div class="card citadel-card mb-3"><div class="card-body">
        <h6 class="text-uppercase text-body-secondary small fw-bold mb-2"><i class="bi bi-folder-plus"></i> New project</h6>
        <div class="row g-2 align-items-end">
          <div class="col-sm-5"><label class="form-label small mb-1" for="new-project-name">Name</label>
            <input type="text" class="form-control form-control-sm" id="new-project-name" maxlength="200" placeholder="e.g. Payments service"></div>
          <div class="col-sm-5"><label class="form-label small mb-1" for="new-project-desc">Description (optional)</label>
            <input type="text" class="form-control form-control-sm" id="new-project-desc" maxlength="2000" placeholder="What's in this project?"></div>
          <div class="col-sm-2"><button class="btn btn-sm btn-primary w-100" data-project-create><i class="bi bi-plus-lg"></i> Create</button></div>
        </div>
        <div class="small text-danger mt-2 d-none" id="new-project-err"></div>
      </div></div>
      <div class="row g-3">${cards}</div>`;
  }

  CITADEL.projects = {
    all, get, current, currentId, setCurrent, count, create, rename, remove, load,
    serverEnabled, scanCountOf, renderBar, renderProjects, emitChange
  };
})(window);
