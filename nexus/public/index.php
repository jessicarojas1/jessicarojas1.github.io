<?php
/**
 * APEX - SPA shell.
 */
?><!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>APEX · Project Tracker</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%234f46e5'/%3E%3Cpolygon points='32,10 54,52 10,52' fill='none' stroke='white' stroke-width='4.5' stroke-linejoin='round'/%3E%3Cline x1='21' y1='40' x2='43' y2='40' stroke='white' stroke-width='4.5' stroke-linecap='round'/%3E%3C/svg%3E" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="/app/nexus.css" />
</head>
<body>

  <!-- ── AUTH GATE ─────────────────────────────────────────────── -->
  <div id="auth-gate" class="auth-gate">
    <div class="auth-shell">
      <div class="auth-shell-header">
        <div class="auth-shell-title">APEX · Secure Access</div>
        <div class="step-track" id="auth-steps">
          <div class="step-node active" data-step="1"><div class="step-circle">1</div><div class="step-lbl">Identity</div></div>
          <div class="step-connector"></div>
          <div class="step-node" data-step="2"><div class="step-circle">2</div><div class="step-lbl">PIN</div></div>
          <div class="step-connector"></div>
          <div class="step-node" data-step="3"><div class="step-circle">3</div><div class="step-lbl">Verify</div></div>
        </div>
      </div>
      <div class="auth-body">
        <div class="auth-reader-col">
          <div class="cac-card-wrap">
            <div class="cac-card" id="cac-card">
              <div class="cac-chip"></div>
              <div class="cac-label">CAC / PIV</div>
            </div>
          </div>
          <div class="reader-state" id="reader-state">Reader idle</div>
        </div>
        <div class="auth-content-col">
          <section class="auth-step" data-step="1">
            <h3 class="auth-h">Select identity</h3>
            <p class="auth-sub">Choose a simulated CAC/PIV identity to authenticate.</p>
            <div class="identity-grid" id="identity-grid"></div>
          </section>
          <section class="auth-step d-none" data-step="2">
            <h3 class="auth-h">Enter PIN</h3>
            <p class="auth-sub" id="pin-sub">PIN required to unlock private key.</p>
            <div class="pin-pad">
              <input type="password" inputmode="numeric" autocomplete="off" maxlength="8" id="pin-input" class="pin-field" placeholder="••••••" />
              <button class="btn btn-success" id="pin-submit">Authenticate</button>
              <button class="btn btn-outline-secondary" id="pin-back">Back</button>
            </div>
            <div class="auth-err" id="pin-err"></div>
          </section>
          <section class="auth-step d-none" data-step="3">
            <h3 class="auth-h">Authenticating…</h3>
            <div class="auth-spinner" aria-hidden="true"></div>
            <div id="auth-final"></div>
          </section>
        </div>
      </div>
    </div>
  </div>

  <!-- ── APP SHELL ──────────────────────────────────────────────── -->
  <div id="app" class="app-shell d-none">

    <!-- Top header — always visible -->
    <header class="app-header">
      <div class="brand" id="brand-home" style="cursor:pointer">
        <span class="brand-mark">▲</span>
        <span class="brand-text">APEX</span>
      </div>

      <!-- Project context bar — shown when inside a project -->
      <div class="project-bar d-none" id="project-bar">
        <button class="back-btn" id="back-home-btn">← Projects</button>
        <span class="project-bar-sep"></span>
        <span class="project-bar-icon" id="project-bar-icon"></span>
        <span class="project-bar-name" id="project-bar-name"></span>
        <nav class="project-nav" id="project-nav">
          <button class="nav-btn active" data-view="dashboard">Dashboard</button>
          <button class="nav-btn" data-view="board">Board</button>
          <button class="nav-btn" data-view="backlog">Backlog</button>
          <button class="nav-btn" data-view="sprints">Sprints</button>
          <button class="nav-btn" data-view="history">History</button>
        </nav>
      </div>

      <!-- Global nav — shown on home -->
      <nav class="app-nav" id="global-nav">
        <button class="nav-btn" data-view="admin" id="nav-admin" style="display:none">⚙ Admin</button>
      </nav>

      <div class="app-user">
        <button class="btn btn-sm btn-primary d-none" id="global-new-ticket-btn">+ New Ticket</button>
        <button class="icon-btn" id="bell-btn" title="Notifications">
          🔔 <span id="bell-badge" class="bell-badge d-none">0</span>
        </button>
        <div class="user-chip" id="user-chip" style="cursor:pointer" title="My profile"></div>
        <button class="btn btn-sm btn-outline-light" id="logout-btn">Sign out</button>
      </div>
    </header>

    <main class="app-main">

      <!-- HOME — project grid -->
      <section class="view view-home" data-view="home">
        <div class="home-header">
          <div>
            <h1 class="home-title">Projects</h1>
            <p class="home-sub" id="home-sub"></p>
          </div>
          <button class="btn btn-primary" id="new-project-btn" style="display:none">+ New Project</button>
        </div>
        <div class="project-grid" id="project-grid"></div>
      </section>

      <!-- DASHBOARD -->
      <section class="view view-dashboard d-none" data-view="dashboard">
        <div class="toolbar"><h2 class="view-title">Dashboard</h2></div>
        <div id="dashboard-body"></div>
      </section>

      <!-- BOARD -->
      <section class="view view-board d-none" data-view="board">
        <div class="toolbar">
          <input type="search" class="form-control form-control-sm" id="search-input" placeholder="Search tickets…" />
          <select id="filter-priority" class="form-select form-select-sm">
            <option value="">All priorities</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
          <select id="filter-assignee" class="form-select form-select-sm">
            <option value="">All assignees</option>
          </select>
          <button class="btn btn-sm btn-primary ms-auto" id="new-ticket-btn">+ New Ticket</button>
        </div>
        <div class="board" id="board"></div>
      </section>

      <!-- BACKLOG -->
      <section class="view view-backlog d-none" data-view="backlog">
        <div class="toolbar">
          <h2 class="view-title">Backlog</h2>
          <button class="btn btn-sm btn-primary ms-auto" id="new-ticket-btn-bl">+ New Ticket</button>
        </div>
        <div class="backlog-list" id="backlog-list"></div>
      </section>

      <!-- SPRINTS -->
      <section class="view view-sprints d-none" data-view="sprints">
        <div class="toolbar">
          <h2 class="view-title">Sprints</h2>
          <button class="btn btn-sm btn-primary ms-auto" id="new-sprint-btn">+ New Sprint</button>
        </div>
        <div class="sprints-list" id="sprints-list"></div>
      </section>

      <!-- HISTORY -->
      <section class="view view-history d-none" data-view="history">
        <div class="toolbar"><h2 class="view-title">Project History</h2></div>
        <div class="history-feed" id="history-feed"></div>
      </section>

      <!-- ADMIN CENTER -->
      <section class="view view-admin d-none" data-view="admin">
        <div class="admin-layout">
          <nav class="admin-sidebar">
            <div class="admin-sidebar-title">Admin Center</div>
            <button class="admin-nav-btn" data-admin-tab="users">👥 Users</button>
            <button class="admin-nav-btn" data-admin-tab="workflows">🔄 Workflows</button>
            <button class="admin-nav-btn" data-admin-tab="labels">🏷 Labels</button>
            <button class="admin-nav-btn" data-admin-tab="permissions">🔒 Permissions</button>
            <button class="admin-nav-btn" data-admin-tab="risk">⚠ Risk Matrix</button>
          </nav>
          <div class="admin-content" id="admin-content">
            <div class="admin-placeholder">Select a section from the left panel.</div>
          </div>
        </div>
      </section>

    </main>

    <!-- Ticket drawer -->
    <aside class="drawer d-none" id="ticket-drawer">
      <div class="drawer-header">
        <h2 id="drawer-title">Ticket</h2>
        <button class="btn-close" id="drawer-close"></button>
      </div>
      <div class="drawer-body" id="drawer-body"></div>
    </aside>

    <!-- Notifications popover -->
    <div class="notif-popover d-none" id="notif-popover">
      <div class="notif-head">
        <strong>Notifications</strong>
        <button class="btn btn-sm btn-link" id="notif-readall">Mark all read</button>
      </div>
      <div class="notif-list" id="notif-list"></div>
    </div>

    <!-- New Ticket modal -->
    <div class="modal-shell d-none" id="ticket-modal">
      <div class="modal-card">
        <div class="modal-card-head">
          <h3>New Ticket</h3>
          <button class="btn-close" data-modal-close></button>
        </div>
        <form id="ticket-form" class="modal-card-body">
          <label class="form-label">Project</label>
          <select class="form-select" name="projectId" id="ticket-form-project">
            <option value="">— Select project —</option>
          </select>
          <label class="form-label mt-2">Title</label>
          <input class="form-control" name="title" required placeholder="Short, descriptive title" />
          <label class="form-label mt-2">Description</label>
          <textarea class="form-control" name="description" rows="3" placeholder="Context, acceptance criteria, links…"></textarea>
          <div class="row g-2 mt-2">
            <div class="col">
              <label class="form-label">Type</label>
              <select class="form-select" name="type">
                <option value="task">Task</option>
                <option value="bug">Bug</option>
                <option value="story">Story</option>
                <option value="epic">Epic</option>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Priority</label>
              <select class="form-select" name="priority">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Effort</label>
              <select class="form-select" name="effort">
                <option value="minimal">Minimal</option>
                <option value="moderate" selected>Moderate</option>
                <option value="substantial">Substantial</option>
                <option value="intensive">Intensive</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col">
              <label class="form-label">Assignee</label>
              <select class="form-select" name="assigneeId" id="ticket-form-assignee">
                <option value="">— Unassigned —</option>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Sprint</label>
              <select class="form-select" name="sprintId" id="ticket-form-sprint">
                <option value="">— No sprint —</option>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Due date</label>
              <input type="date" class="form-control" name="dueDate" />
            </div>
          </div>
        </form>
        <div class="modal-card-foot">
          <button class="btn btn-outline-secondary" data-modal-close>Cancel</button>
          <button class="btn btn-primary" id="ticket-form-submit">Create Ticket</button>
        </div>
      </div>
    </div>

    <!-- New Project modal -->
    <div class="modal-shell d-none" id="project-modal">
      <div class="modal-card">
        <div class="modal-card-head">
          <h3>New Project</h3>
          <button class="btn-close" data-project-modal-close></button>
        </div>
        <form id="project-form" class="modal-card-body">
          <div class="row g-2">
            <div class="col-8">
              <label class="form-label">Project Name</label>
              <input class="form-control" name="name" required placeholder="e.g. Security Platform" />
            </div>
            <div class="col-4">
              <label class="form-label">Key</label>
              <input class="form-control" name="key" required placeholder="SEC" maxlength="10" id="project-key-input" />
              <small class="text-muted">2–10 uppercase letters</small>
            </div>
          </div>
          <label class="form-label mt-2">Description</label>
          <textarea class="form-control" name="description" rows="2" placeholder="What is this project about?"></textarea>
          <div class="row g-2 mt-2">
            <div class="col-3">
              <label class="form-label">Icon</label>
              <input class="form-control text-center" name="icon" maxlength="4" value="🚀" id="project-icon-input" />
            </div>
            <div class="col-9">
              <label class="form-label">Color</label>
              <div class="color-swatches" id="color-swatches"></div>
              <input type="hidden" name="color" id="project-color-input" value="#6366f1" />
            </div>
          </div>
        </form>
        <div class="modal-card-foot">
          <button class="btn btn-outline-secondary" data-project-modal-close>Cancel</button>
          <button class="btn btn-primary" id="project-form-submit">Create Project</button>
        </div>
      </div>
    </div>

    <!-- Profile modal -->
    <div class="modal-shell d-none" id="profile-modal">
      <div class="modal-card" style="max-width:420px">
        <div class="modal-card-head">
          <h3>My Profile</h3>
          <button class="btn-close" data-profile-close></button>
        </div>
        <div class="modal-card-body" id="profile-body"></div>
        <div class="modal-card-foot">
          <button class="btn btn-outline-secondary" data-profile-close>Close</button>
          <button class="btn btn-primary" id="profile-pin-btn">Change PIN</button>
        </div>
      </div>
    </div>

    <div id="toast-stack" class="toast-stack"></div>
  </div>

  <script src="/app/nexus.js" defer></script>
</body>
</html>
