/* ═══════════════════════════════════════════════════════════════════
   NEXUS  ·  Frontend
   ───────────────────────────────────────────────────────────────────
   This is a full rewrite of the original tracker.html SPA — all
   `localStorage` reads/writes have been replaced with `fetch()` calls
   against the PHP+PostgreSQL API at /api/*.
   ═══════════════════════════════════════════════════════════════════ */

'use strict';

// ── API layer ──────────────────────────────────────────────────────
const API = {
  _token: localStorage.getItem('nexus_jwt') || null,

  setToken(t) {
    this._token = t;
    if (t) localStorage.setItem('nexus_jwt', t);
    else   localStorage.removeItem('nexus_jwt');
  },

  async request(method, path, body) {
    const headers = { 'Content-Type': 'application/json' };
    if (this._token) headers['Authorization'] = 'Bearer ' + this._token;

    let res;
    try {
      res = await fetch('/api' + path, {
        method,
        headers,
        credentials: 'include',
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch (e) {
      throw new Error('Network error: ' + e.message);
    }

    let json = null;
    try { json = await res.json(); } catch { /* non-JSON */ }

    if (res.status === 401) {
      API.setToken(null);
      // Defer UI work until the rest of app has bound globals.
      if (window.NEXUS && window.NEXUS.showAuthGate) window.NEXUS.showAuthGate();
      throw new Error(json?.error || 'Unauthorized');
    }
    if (!res.ok) {
      throw new Error(json?.error || ('HTTP ' + res.status));
    }
    return json;
  },

  get:    (p)    => API.request('GET',    p),
  post:   (p, b) => API.request('POST',   p, b),
  patch:  (p, b) => API.request('PATCH',  p, b),
  delete: (p)    => API.request('DELETE', p),
};

// ── App state (in-memory; source of truth lives in the DB) ─────────
const state = {
  user:           null,
  projects:       [],
  currentProject: null,
  members:        [],
  tickets:        [],
  labels:         [],
  sprints:        [],
  notifications:  [],
  currentView:    'board',
  selectedTicket: null,
  filters:        { search: '', priority: '', assignee: '' },
};

// Three known CAC identities (UI only — server still bcrypt-verifies the PIN).
const IDENTITIES = [
  { id: 'rojas', name: 'Jessica Rojas', role: 'admin',  clearance: 'SECRET',       org: 'DIA',  defaultPin: '1231' },
  { id: 'smith', name: 'John Smith',    role: 'member', clearance: 'TS/SCI',       org: 'NSA',  defaultPin: '112233' },
  { id: 'brown', name: 'Sarah Brown',   role: 'viewer', clearance: 'UNCLASSIFIED', org: 'DISA', defaultPin: '999999' },
];

// ── DOM helpers ────────────────────────────────────────────────────
const $  = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => Array.from(root.querySelectorAll(s));
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const el = (tag, attrs = {}, ...children) => {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') n.className = v;
    else if (k === 'html') n.innerHTML = v;
    else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
    else if (v !== false && v != null) n.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c == null || c === false) continue;
    n.append(c.nodeType ? c : document.createTextNode(c));
  }
  return n;
};
const fmt = {
  date: (d) => d ? new Date(d).toLocaleDateString() : '—',
  datetime: (d) => d ? new Date(d).toLocaleString() : '—',
  rel: (d) => {
    if (!d) return '';
    const diff = (Date.now() - new Date(d).getTime()) / 1000;
    if (diff < 60)    return Math.floor(diff)        + 's ago';
    if (diff < 3600)  return Math.floor(diff / 60)   + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  },
};

function toast(msg, kind = 'info') {
  const stack = $('#toast-stack');
  if (!stack) return;
  const t = el('div', { class: 'toast-msg ' + kind }, msg);
  stack.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

// ── Auth flow ──────────────────────────────────────────────────────
function renderIdentityGrid() {
  const grid = $('#identity-grid');
  grid.innerHTML = '';
  for (const id of IDENTITIES) {
    grid.appendChild(el('button', {
      class: 'identity-card',
      onclick: () => selectIdentity(id),
    },
      el('div', { class: 'identity-name' }, id.name),
      el('div', { class: 'identity-meta' }, `${id.clearance} · ${id.org}`),
      el('span', { class: 'identity-role ' + id.role }, id.role),
    ));
  }
}

function setAuthStep(n) {
  $$('#auth-steps .step-node').forEach((node, i) => {
    node.classList.toggle('active', i + 1 === n);
    node.classList.toggle('done',   i + 1 <  n);
  });
  $$('#auth-steps .step-connector').forEach((c, i) => c.classList.toggle('done', i + 1 < n));
  $$('.auth-step').forEach(s => s.classList.toggle('d-none', String(s.dataset.step) !== String(n)));
}

let pendingIdentity = null;
function selectIdentity(id) {
  pendingIdentity = id;
  $('#pin-sub').textContent = `Enter PIN for ${id.name} (default ${id.defaultPin}).`;
  $('#pin-input').value = '';
  $('#pin-err').textContent = '';
  $('#reader-state').textContent = 'Card inserted · awaiting PIN';
  $('#cac-card').classList.add('inserted');
  setAuthStep(2);
  setTimeout(() => $('#pin-input').focus(), 50);
}

async function submitPin() {
  const pin = $('#pin-input').value.trim();
  if (!pendingIdentity || !pin) {
    $('#pin-err').textContent = 'Enter a PIN to continue.';
    return;
  }
  setAuthStep(3);
  try {
    const res = await API.post('/auth/login', { userId: pendingIdentity.id, pin });
    API.setToken(res.data.token);
    state.user = res.data.user;
    $('#auth-final').innerHTML = `<div class="text-success">Welcome, ${state.user.displayName}.</div>`;
    setTimeout(() => showApp(), 400);
  } catch (e) {
    $('#pin-err').textContent = e.message || 'Authentication failed.';
    setAuthStep(2);
  }
}

function showAuthGate() {
  state.user = null;
  $('#auth-gate').classList.remove('d-none');
  $('#app').classList.add('d-none');
  setAuthStep(1);
  $('#cac-card').classList.remove('inserted');
  $('#reader-state').textContent = 'Reader idle';
  renderIdentityGrid();
}

async function bootstrapSession() {
  if (!API._token) { showAuthGate(); return; }
  try {
    const res = await API.get('/auth/me');
    state.user = res.data.user;
    await showApp();
  } catch {
    showAuthGate();
  }
}

// ── Main app shell ─────────────────────────────────────────────────
async function showApp() {
  $('#auth-gate').classList.add('d-none');
  $('#app').classList.remove('d-none');
  renderUserChip();
  if (state.user?.role === 'admin') {
    $('#nav-users').style.display = '';
    $('#new-project-btn').style.display = '';
  }
  await loadProjects();
  await loadNotifications();
  goHome();
}

function renderUserChip() {
  const u = state.user;
  if (!u) return;
  $('#user-chip').innerHTML = '';
  $('#user-chip').append(
    document.createTextNode(u.displayName),
    el('span', { class: 'role-pill ' + u.role }, u.role),
  );
}

async function loadProjects() {
  const r = await API.get('/projects');
  state.projects = r.data;
}

async function loadProjectData() {
  if (!state.currentProject) return;
  const pid = state.currentProject.id;
  const [proj, tickets, labels, sprints] = await Promise.all([
    API.get('/projects/' + pid),
    API.get('/projects/' + pid + '/tickets'),
    API.get('/projects/' + pid + '/labels'),
    API.get('/projects/' + pid + '/sprints'),
  ]);
  state.currentProject = proj.data;
  state.members        = proj.data.members || [];
  state.tickets        = tickets.data;
  state.labels         = labels.data;
  state.sprints        = sprints.data;

  // Populate assignee filter + ticket form
  const sel = $('#filter-assignee');
  sel.innerHTML = '<option value="">All assignees</option>';
  for (const m of state.members) {
    sel.appendChild(el('option', { value: m.id }, m.displayName));
  }
  const fa = $('#ticket-form-assignee');
  fa.innerHTML = '<option value="">— Unassigned —</option>';
  for (const m of state.members) {
    fa.appendChild(el('option', { value: m.id }, m.displayName));
  }
  // Populate sprint selector in ticket form
  const fs = $('#ticket-form-sprint');
  fs.innerHTML = '<option value="">— No sprint —</option>';
  for (const s of state.sprints.filter(s => s.status === 'active')) {
    fs.appendChild(el('option', { value: s.id }, s.name));
  }
}

// ── Home page ─────────────────────────────────────────────────────
function goHome() {
  state.currentProject = null;
  state.currentView = 'home';

  // Header: hide project bar, show global nav
  $('#project-bar').classList.add('d-none');
  $('#global-nav').classList.remove('d-none');

  // Show only the home view
  $$('.view').forEach(v => v.classList.toggle('d-none', v.dataset.view !== 'home'));
  $$('.nav-btn').forEach(b => b.classList.remove('active'));

  renderHome();
}

function renderHome() {
  const grid = $('#project-grid');
  grid.innerHTML = '';

  const sub = $('#home-sub');
  if (sub) sub.textContent = state.projects.length
    ? `${state.projects.length} project${state.projects.length > 1 ? 's' : ''} · select one to get started`
    : 'No projects yet. Create your first one above.';

  if (!state.projects.length) {
    grid.innerHTML = '<p style="color:#8b949e;text-align:center;padding:3rem">No projects found.</p>';
    return;
  }

  for (const p of state.projects) {
    const openCount = (p.ticketCount ?? 0);
    const card = el('div', { class: 'project-card', onclick: () => enterProject(p) });
    card.style.borderTopColor = p.color || '#6366f1';
    card.innerHTML = `
      <div class="project-card-icon">${esc(p.icon || '📁')}</div>
      <div class="project-card-body">
        <div class="project-card-key">${esc(p.key)}</div>
        <div class="project-card-name">${esc(p.name)}</div>
        <div class="project-card-desc">${esc(p.description || '')}</div>
      </div>
      <div class="project-card-meta">
        <span>${openCount} ticket${openCount !== 1 ? 's' : ''}</span>
      </div>
    `;
    grid.append(card);
  }
}

async function enterProject(p) {
  state.currentProject = p;
  await loadProjectData();

  // Header: show project bar, hide global nav
  $('#project-bar').classList.remove('d-none');
  $('#global-nav').classList.add('d-none');
  $('#project-bar-icon').textContent = p.icon || '📁';
  $('#project-bar-name').textContent = p.name;

  switchView('board');
}

async function loadNotifications() {
  try {
    const r = await API.get('/notifications');
    state.notifications = r.data;
    const unread = r.meta?.unread || 0;
    const badge = $('#bell-badge');
    if (unread > 0) {
      badge.textContent = String(unread);
      badge.classList.remove('d-none');
    } else {
      badge.classList.add('d-none');
    }
  } catch { /* ignore */ }
}

// ── View routing ───────────────────────────────────────────────────
function switchView(view) {
  state.currentView = view;
  // Only mark nav-btn active for project views (inside project-bar)
  $$('#project-nav .nav-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));
  $$('.view').forEach(s => s.classList.toggle('d-none', s.dataset.view !== view));
  render();
}

function render() {
  if (state.currentView === 'home')    { renderHome(); return; }
  if (state.currentView === 'users')   { renderUsersAdmin(); return; }
  if (!state.currentProject) return;
  if      (state.currentView === 'board')   renderBoard();
  else if (state.currentView === 'backlog') renderBacklog();
  else if (state.currentView === 'sprints') renderSprints();
  else if (state.currentView === 'history') renderHistory();
}

function filteredTickets() {
  const { search, priority, assignee } = state.filters;
  return state.tickets.filter(t => {
    if (priority && t.priority !== priority) return false;
    if (assignee && t.assigneeId !== assignee) return false;
    if (search) {
      const hay = (t.id + ' ' + t.title + ' ' + (t.description || '')).toLowerCase();
      if (!hay.includes(search.toLowerCase())) return false;
    }
    return true;
  });
}

function renderBoard() {
  const board = $('#board');
  board.innerHTML = '';
  const statuses = state.currentProject.statuses || ['Backlog', 'To Do', 'In Progress', 'In Review', 'Blocked', 'Done'];
  const tickets = filteredTickets();

  for (const status of statuses) {
    const col = el('div', { class: 'board-col' },
      el('div', { class: 'board-col-head' },
        el('span', {}, status),
        el('span', { class: 'board-col-count' }, String(tickets.filter(t => t.status === status).length)),
      ),
    );
    const colTickets = tickets.filter(t => t.status === status);
    for (const t of colTickets) {
      col.appendChild(renderTicketCard(t));
    }
    board.appendChild(col);
  }
}

function renderTicketCard(t) {
  const assignee = state.members.find(m => m.id === t.assigneeId);
  return el('div', { class: 'board-card', onclick: () => openTicket(t.id) },
    el('div', { class: 'board-card-id' }, t.id),
    el('div', { class: 'board-card-title' }, t.title),
    el('div', { class: 'board-card-meta' },
      el('span', { class: 'pill priority-' + t.priority }, t.priority),
      el('span', { class: 'pill type-' + t.type }, t.type),
      assignee ? el('span', { class: 'pill assignee' }, assignee.displayName.split(' ').map(s => s[0]).join('')) : null,
    ),
  );
}

function renderBacklog() {
  const list = $('#backlog-list');
  list.innerHTML = '';
  const tickets = [...state.tickets].sort((a, b) => (a.backlogOrder || 0) - (b.backlogOrder || 0));
  for (const t of tickets) {
    list.appendChild(
      el('div', { class: 'backlog-row', onclick: () => openTicket(t.id) },
        el('span', { class: 'id' }, t.id),
        el('span', { class: 'title' }, t.title),
        el('span', { class: 'pill priority-' + t.priority }, t.priority),
        el('span', { class: 'pill type-' + t.type }, t.type),
        el('span', {}, t.status),
      ),
    );
  }
}

function renderSprints() {
  const wrap = $('#sprints-list');
  wrap.innerHTML = '';
  if (!state.sprints.length) {
    wrap.appendChild(el('div', { class: 'text-muted' }, 'No sprints yet.'));
    return;
  }
  for (const s of state.sprints) {
    wrap.appendChild(
      el('div', { class: 'sprint-card' },
        el('h3', {}, s.name),
        el('div', { class: 'sprint-meta' }, `${s.status} · ${fmt.date(s.startDate)} → ${fmt.date(s.endDate)}`),
        s.goal ? el('div', {}, s.goal) : null,
      ),
    );
  }
}

async function renderHistory() {
  const wrap = $('#history-feed');
  wrap.innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const r = await API.get('/projects/' + state.currentProject.id + '/history');
    wrap.innerHTML = '';
    if (!r.data.length) {
      wrap.appendChild(el('div', { class: 'text-muted' }, 'No history yet.'));
      return;
    }
    for (const h of r.data) {
      const detail = h.event === 'status_change'
        ? ` moved ${h.ticketId} ${h.fromVal} → ${h.toVal}`
        : h.event === 'comment_added' ? ` commented on ${h.ticketId}`
        : h.event === 'created' ? ` created ${h.ticketId}`
        : ` ${h.event} on ${h.ticketId}`;
      wrap.appendChild(
        el('div', { class: 'history-row' },
          el('div', {},
            el('span', { class: 'who' }, h.userDisplayName || h.userId || 'system'),
            detail,
          ),
          el('div', { class: 'when' }, fmt.datetime(h.timestamp)),
        ),
      );
    }
  } catch (e) {
    wrap.innerHTML = '<div class="text-danger">' + e.message + '</div>';
  }
}

// ── Ticket drawer ─────────────────────────────────────────────────
async function openTicket(id) {
  state.selectedTicket = id;
  const drawer = $('#ticket-drawer');
  drawer.classList.remove('d-none');
  $('#drawer-title').textContent = id;
  $('#drawer-body').innerHTML = '<div class="text-muted">Loading…</div>';
  try {
    const [tkt, comments, history] = await Promise.all([
      API.get('/tickets/' + id),
      API.get('/tickets/' + id + '/comments'),
      API.get('/tickets/' + id + '/history'),
    ]);
    renderDrawer(tkt.data, comments.data, history.data);
  } catch (e) {
    $('#drawer-body').innerHTML = '<div class="text-danger">' + e.message + '</div>';
  }
}

function canEdit() {
  return state.user && (state.user.role === 'admin' || state.user.role === 'member');
}

function renderDrawer(t, comments, history) {
  const body = $('#drawer-body');
  const editable = canEdit();
  const statuses = state.currentProject.statuses;
  body.innerHTML = '';

  $('#drawer-title').textContent = `${t.id} · ${t.title}`;

  // Title (read-only display)
  body.appendChild(el('h3', { class: 'mb-2', style: 'color:#f0f6fc' }, t.title));

  // Status picker
  body.appendChild(field('Status',
    el('select', {
      class: 'form-select',
      disabled: !editable,
      onchange: async (ev) => {
        try {
          await API.patch('/tickets/' + t.id + '/status', { status: ev.target.value });
          toast('Status updated', 'success');
          await refreshTicket(t.id);
          await loadNotifications();
        } catch (e) { toast(e.message, 'error'); }
      },
    }, ...statuses.map(s => el('option', { value: s, selected: s === t.status ? 'selected' : false }, s))),
  ));

  // Assignee
  const assigneeSel = el('select', {
    class: 'form-select',
    disabled: !editable,
    onchange: async (ev) => {
      try {
        await API.patch('/tickets/' + t.id, { assigneeId: ev.target.value || null });
        toast('Assignee updated', 'success');
        await refreshTicket(t.id);
      } catch (e) { toast(e.message, 'error'); }
    },
  }, el('option', { value: '' }, '— Unassigned —'),
     ...state.members.map(m => el('option', { value: m.id, selected: m.id === t.assigneeId ? 'selected' : false }, m.displayName)));
  body.appendChild(field('Assignee', assigneeSel));

  // Priority / Effort
  const row = el('div', { class: 'row g-2' });
  row.appendChild(el('div', { class: 'col' }, field('Priority',
    el('select', { class: 'form-select', disabled: !editable, onchange: async (e) => {
      try { await API.patch('/tickets/' + t.id, { priority: e.target.value }); await refreshTicket(t.id); }
      catch (er) { toast(er.message, 'error'); }
    } }, ...['low','medium','high','critical'].map(p => el('option', { value: p, selected: p === t.priority ? 'selected' : false }, p))),
  )));
  row.appendChild(el('div', { class: 'col' }, field('Effort',
    el('select', { class: 'form-select', disabled: !editable, onchange: async (e) => {
      try { await API.patch('/tickets/' + t.id, { effort: e.target.value }); await refreshTicket(t.id); }
      catch (er) { toast(er.message, 'error'); }
    } }, ...['minimal','moderate','substantial','intensive'].map(p => el('option', { value: p, selected: p === t.effort ? 'selected' : false }, p))),
  )));
  body.appendChild(row);

  // Description
  body.appendChild(field('Description',
    el('textarea', {
      class: 'form-control', rows: 4, disabled: !editable,
      onblur: async (e) => {
        if (e.target.value === t.description) return;
        try { await API.patch('/tickets/' + t.id, { description: e.target.value }); toast('Saved', 'success'); }
        catch (er) { toast(er.message, 'error'); }
      },
    }, t.description || ''),
  ));

  // Meta
  body.appendChild(el('div', { class: 'text-muted', style: 'font-size:.78rem' },
    `Reporter ${t.reporterId || '—'} · created ${fmt.rel(t.createdAt)} · updated ${fmt.rel(t.updatedAt)}`,
  ));

  // Watch toggle
  const watching = (t.watchers || []).includes(state.user.id);
  body.appendChild(el('button', {
    class: 'btn btn-sm btn-outline-light mt-3',
    onclick: async () => {
      try {
        await API.patch('/tickets/' + t.id + '/watch');
        toast(watching ? 'Unwatched' : 'Watching', 'success');
        await refreshTicket(t.id);
      } catch (e) { toast(e.message, 'error'); }
    },
  }, watching ? '★ Watching' : '☆ Watch'));

  // Comments
  body.appendChild(el('div', { class: 'drawer-section-title' }, 'Comments'));
  for (const c of comments) {
    body.appendChild(el('div', { class: 'comment-row' },
      el('div', {},
        el('span', { class: 'who' }, c.userDisplayName || c.userId),
        el('span', { class: 'when' }, fmt.rel(c.createdAt)),
      ),
      el('div', {}, c.body),
    ));
  }
  if (editable) {
    const ta = el('textarea', { class: 'form-control', rows: 2, placeholder: 'Add a comment…' });
    body.appendChild(ta);
    body.appendChild(el('button', {
      class: 'btn btn-sm btn-primary mt-2',
      onclick: async () => {
        const v = ta.value.trim();
        if (!v) return;
        try {
          await API.post('/tickets/' + t.id + '/comments', { body: v });
          ta.value = '';
          toast('Comment posted', 'success');
          await openTicket(t.id);
        } catch (e) { toast(e.message, 'error'); }
      },
    }, 'Post comment'));
  }

  // History
  body.appendChild(el('div', { class: 'drawer-section-title' }, 'History'));
  for (const h of history) {
    const detail = h.event === 'status_change' ? `status: ${h.fromVal} → ${h.toVal}`
                 : h.event === 'comment_added' ? 'added a comment'
                 : h.event === 'created'       ? 'created the ticket'
                 : `${h.event}${h.field ? ' (' + h.field + ')' : ''}`;
    body.appendChild(el('div', { class: 'history-mini' },
      `${h.userDisplayName || h.userId || 'system'} · ${detail} · ${fmt.rel(h.timestamp)}`,
    ));
  }

  // Admin delete
  if (state.user.role === 'admin') {
    body.appendChild(el('hr'));
    body.appendChild(el('button', {
      class: 'btn btn-sm btn-outline-danger',
      onclick: async () => {
        if (!confirm('Delete ' + t.id + '?')) return;
        try {
          await API.delete('/tickets/' + t.id);
          toast('Ticket deleted', 'success');
          closeDrawer();
          await loadProjectData();
          render();
        } catch (e) { toast(e.message, 'error'); }
      },
    }, 'Delete ticket'));
  }
}

function field(label, control) {
  return el('div', { class: 'drawer-field' },
    el('label', {}, label),
    control,
  );
}

async function refreshTicket(id) {
  const r = await API.get('/tickets/' + id);
  const idx = state.tickets.findIndex(t => t.id === id);
  if (idx >= 0) state.tickets[idx] = r.data;
  await openTicket(id);
  render();
}

function closeDrawer() {
  $('#ticket-drawer').classList.add('d-none');
  state.selectedTicket = null;
}

// ── New ticket modal ──────────────────────────────────────────────
function openNewTicket() {
  if (!canEdit()) { toast('Viewers cannot create tickets', 'error'); return; }
  $('#ticket-modal').classList.remove('d-none');
  $('#ticket-form').reset();
}
function closeNewTicket() { $('#ticket-modal').classList.add('d-none'); }

async function submitNewTicket() {
  const f = $('#ticket-form');
  const data = Object.fromEntries(new FormData(f));
  if (!data.title) { toast('Title required', 'error'); return; }
  try {
    const body = {
      projectId:   state.currentProject.id,
      title:       data.title,
      description: data.description || '',
      type:        data.type        || 'task',
      priority:    data.priority    || 'medium',
      effort:      data.effort      || 'moderate',
      assigneeId:  data.assigneeId  || null,
      dueDate:     data.dueDate     || null,
    };
    const r = await API.post('/tickets', body);
    toast('Created ' + r.data.id, 'success');
    closeNewTicket();
    await loadProjectData();
    render();
  } catch (e) { toast(e.message, 'error'); }
}

// ── Sprints ───────────────────────────────────────────────────────
async function newSprint() {
  if (state.user.role !== 'admin') { toast('Admins only', 'error'); return; }
  const name = prompt('Sprint name?');
  if (!name) return;
  try {
    await API.post('/projects/' + state.currentProject.id + '/sprints', { name });
    toast('Sprint created', 'success');
    const r = await API.get('/projects/' + state.currentProject.id + '/sprints');
    state.sprints = r.data;
    renderSprints();
  } catch (e) { toast(e.message, 'error'); }
}

// ── Notifications popover ─────────────────────────────────────────
function toggleNotifs() {
  const pop = $('#notif-popover');
  const visible = !pop.classList.contains('d-none');
  if (visible) { pop.classList.add('d-none'); return; }
  pop.classList.remove('d-none');
  const list = $('#notif-list');
  list.innerHTML = '';
  if (!state.notifications.length) {
    list.appendChild(el('div', { class: 'notif-row' }, 'No notifications.'));
    return;
  }
  for (const n of state.notifications) {
    list.appendChild(el('div', {
      class: 'notif-row ' + (n.read ? '' : 'unread'),
      onclick: async () => {
        if (!n.read) {
          try { await API.patch('/notifications/' + n.id, { read: true }); } catch {}
        }
        if (n.ticketId) openTicket(n.ticketId);
        $('#notif-popover').classList.add('d-none');
        await loadNotifications();
      },
    },
      el('div', {}, n.message),
      el('div', { class: 'when' }, fmt.rel(n.createdAt)),
    ));
  }
}

// ── Wire-up ───────────────────────────────────────────────────────
function bindUI() {
  // Auth
  $('#pin-submit').addEventListener('click', submitPin);
  $('#pin-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') submitPin(); });
  $('#pin-back').addEventListener('click', () => setAuthStep(1));

  // Project-scoped nav buttons
  $$('#project-nav .nav-btn').forEach(b => b.addEventListener('click', () => switchView(b.dataset.view)));

  // Global nav (users)
  $('#nav-users').addEventListener('click', () => {
    $('#project-bar').classList.add('d-none');
    $('#global-nav').classList.remove('d-none');
    $$('.view').forEach(v => v.classList.toggle('d-none', v.dataset.view !== 'users'));
    state.currentView = 'users';
    renderUsersAdmin();
  });

  // Back to home
  $('#brand-home').addEventListener('click', goHome);
  $('#back-home-btn').addEventListener('click', goHome);

  // Filters
  $('#search-input').addEventListener('input',     (e) => { state.filters.search   = e.target.value; renderBoard(); });
  $('#filter-priority').addEventListener('change', (e) => { state.filters.priority = e.target.value; renderBoard(); });
  $('#filter-assignee').addEventListener('change', (e) => { state.filters.assignee = e.target.value; renderBoard(); });

  // Ticket buttons
  $('#new-ticket-btn').addEventListener('click', openNewTicket);
  $('#new-ticket-btn-bl').addEventListener('click', openNewTicket);
  $('#new-sprint-btn').addEventListener('click', newSprint);
  $('#new-user-btn')?.addEventListener('click', openNewUserModal);
  $('#ticket-form-submit').addEventListener('click', submitNewTicket);
  $$('[data-modal-close]').forEach(b => b.addEventListener('click', closeNewTicket));
  $('#drawer-close').addEventListener('click', closeDrawer);

  // Project creation
  $('#new-project-btn').addEventListener('click', openProjectModal);
  $('#project-form-submit').addEventListener('click', submitProjectForm);
  $$('[data-project-modal-close]').forEach(b => b.addEventListener('click', closeProjectModal));
  // Auto-generate key from name
  $('#project-form [name="name"]')?.addEventListener('input', (e) => {
    const key = e.target.value.replace(/[^a-zA-Z]/g, '').substring(0, 6).toUpperCase();
    $('#project-key-input').value = key;
  });
  // Color swatches
  const colors = ['#6366f1','#22c55e','#3b82f6','#f59e0b','#ef4444','#a855f7','#14b8a6','#f97316'];
  const swatchContainer = $('#color-swatches');
  colors.forEach(c => {
    const s = el('span', { class: 'color-swatch', title: c });
    s.style.background = c;
    s.onclick = () => {
      $$('.color-swatch').forEach(x => x.classList.remove('selected'));
      s.classList.add('selected');
      $('#project-color-input').value = c;
    };
    swatchContainer.append(s);
  });
  swatchContainer.firstChild?.click();
  $('#bell-btn').addEventListener('click', toggleNotifs);
  $('#notif-readall').addEventListener('click', async () => {
    try { await API.post('/notifications/read-all', {}); await loadNotifications(); toggleNotifs(); toggleNotifs(); }
    catch (e) { toast(e.message, 'error'); }
  });
  $('#logout-btn').addEventListener('click', async () => {
    try { await API.post('/auth/logout', {}); } catch {}
    API.setToken(null);
    showAuthGate();
  });

  // Close popover on outside click
  document.addEventListener('click', (e) => {
    const pop = $('#notif-popover');
    if (pop.classList.contains('d-none')) return;
    if (e.target.closest('#notif-popover') || e.target.closest('#bell-btn')) return;
    pop.classList.add('d-none');
  });
}

// ── Users Administration ────────────────────────────────────────────
async function renderUsersAdmin() {
  const container = $('#users-list');
  if (!container) return;
  container.innerHTML = '<p style="color:#8b949e">Loading…</p>';
  try {
    const r = await API.get('/users');
    const users = r.data;
    container.innerHTML = '';

    const table = el('table', { class: 'users-table' });
    table.innerHTML = `
      <thead><tr>
        <th>Name</th><th>ID</th><th>Role</th><th>Clearance</th><th>Org</th><th>Actions</th>
      </tr></thead>
    `;
    const tbody = el('tbody');
    for (const u of users) {
      const tr = el('tr');
      tr.innerHTML = `
        <td><strong>${esc(u.displayName)}</strong></td>
        <td style="font-family:monospace;font-size:.8rem">${esc(u.id)}</td>
        <td><span class="role-pill ${u.role}">${esc(u.role)}</span></td>
        <td style="font-size:.82rem">${esc(u.clearance ?? '—')}</td>
        <td style="font-size:.82rem">${esc(u.org ?? '—')}</td>
        <td></td>
      `;
      const actionsCell = tr.querySelector('td:last-child');
      // Change PIN button
      const pinBtn = el('button', { class: 'btn btn-sm btn-outline-secondary' }, 'Change PIN');
      pinBtn.onclick = () => openChangePinModal(u);
      actionsCell.append(pinBtn);

      // Edit role (can't edit self)
      if (u.id !== state.user?.id) {
        const editBtn = el('button', { class: 'btn btn-sm btn-outline-primary' }, 'Edit');
        editBtn.style.marginLeft = '.35rem';
        editBtn.onclick = () => openEditUserModal(u);
        actionsCell.append(editBtn);

        const delBtn = el('button', { class: 'btn btn-sm btn-outline-danger' }, 'Delete');
        delBtn.style.marginLeft = '.35rem';
        delBtn.onclick = () => deleteUser(u);
        actionsCell.append(delBtn);
      }
      tbody.append(tr);
    }
    table.append(tbody);
    container.append(table);
  } catch (e) {
    container.innerHTML = `<p style="color:#ef4444">${esc(e.message)}</p>`;
  }
}

function openChangePinModal(u) {
  const pin = prompt(`New PIN for ${u.displayName} (4–8 digits):`);
  if (!pin) return;
  if (!/^\d{4,8}$/.test(pin)) { toast('PIN must be 4–8 digits', 'error'); return; }
  API.patch(`/users/${u.id}/pin`, { pin })
    .then(() => toast('PIN updated', 'success'))
    .catch(e => toast(e.message, 'error'));
}

function openEditUserModal(u) {
  const role = prompt(`Role for ${u.displayName} (admin / member / viewer):`, u.role);
  if (!role) return;
  if (!['admin','member','viewer'].includes(role)) { toast('Invalid role', 'error'); return; }
  API.patch(`/users/${u.id}`, { role })
    .then(() => { toast('User updated', 'success'); renderUsersAdmin(); })
    .catch(e => toast(e.message, 'error'));
}

async function deleteUser(u) {
  if (!confirm(`Delete user ${u.displayName}? This cannot be undone.`)) return;
  try {
    await API.delete(`/users/${u.id}`);
    toast(`${u.displayName} deleted`, 'info');
    renderUsersAdmin();
  } catch (e) { toast(e.message, 'error'); }
}

function openNewUserModal() {
  const id        = prompt('User ID (e.g. jones):');
  if (!id?.trim()) return;
  const firstName = prompt('First name:');
  if (!firstName?.trim()) return;
  const lastName  = prompt('Last name:');
  if (!lastName?.trim()) return;
  const role      = prompt('Role (admin / member / viewer):', 'member');
  if (!['admin','member','viewer'].includes(role)) { toast('Invalid role', 'error'); return; }
  const clearance = prompt('Clearance level:', 'UNCLASSIFIED');
  const org       = prompt('Organization:');
  const pin       = prompt('Initial PIN (4–8 digits):');
  if (!pin || !/^\d{4,8}$/.test(pin)) { toast('PIN must be 4–8 digits', 'error'); return; }

  API.post('/users', { id: id.trim(), firstName: firstName.trim(), lastName: lastName.trim(), role, clearance: clearance || 'UNCLASSIFIED', org: org || '', pin })
    .then(() => { toast('User created', 'success'); renderUsersAdmin(); })
    .catch(e => toast(e.message, 'error'));
}

// ── Project modal ─────────────────────────────────────────────────
function openProjectModal() {
  $('#project-modal').classList.remove('d-none');
  $('#project-form').reset();
  $('#project-icon-input').value = '🚀';
  $('#color-swatches')?.firstElementChild?.click();
}

function closeProjectModal() {
  $('#project-modal').classList.add('d-none');
}

async function submitProjectForm() {
  const f = $('#project-form');
  const data = Object.fromEntries(new FormData(f));
  const name  = (data.name || '').trim();
  const key   = (data.key  || '').trim().toUpperCase();
  if (!name || !key) { toast('Name and Key are required', 'error'); return; }
  if (!/^[A-Z]{2,10}$/.test(key)) { toast('Key must be 2–10 uppercase letters', 'error'); return; }
  try {
    const r = await API.post('/projects', {
      name,
      key,
      description: data.description || '',
      icon:        data.icon  || '🚀',
      color:       data.color || '#6366f1',
    });
    toast('Project created: ' + r.data.name, 'success');
    closeProjectModal();
    await loadProjects();
    renderHome();
  } catch (e) { toast(e.message, 'error'); }
}

// ── Expose a tiny namespace for the API layer's 401 handler ───────
window.NEXUS = { showAuthGate };

document.addEventListener('DOMContentLoaded', () => {
  bindUI();
  bootstrapSession();
});
