<?php
/**
 * NEXUS - SPA shell.
 *
 * This is the single HTML entry point. All data is fetched from /api/*.
 * The frontend logic lives in /app/nexus.js and is served as a static asset.
 */
?><!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>NEXUS · Project Tracker</title>
  <meta name="description" content="NEXUS — DoD-grade project + ticket tracker. PHP + PostgreSQL backend with CAC/PIV simulated auth and full RBAC." />
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%236366f1'/%3E%3Cpath d='M16 18l16 14 16-14M16 46l16-14 16 14' stroke='white' stroke-width='4' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="/app/nexus.css" />
</head>
<body>

  <!-- ─────────── AUTH GATE ─────────── -->
  <div id="auth-gate" class="auth-gate">
    <div class="auth-shell">
      <div class="auth-shell-header">
        <div class="auth-shell-title">NEXUS · Secure Access</div>
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
          <!-- Step 1 -->
          <section class="auth-step" data-step="1">
            <h3 class="auth-h">Select identity</h3>
            <p class="auth-sub">Choose a simulated CAC/PIV identity to authenticate.</p>
            <div class="identity-grid" id="identity-grid"></div>
          </section>
          <!-- Step 2 -->
          <section class="auth-step d-none" data-step="2">
            <h3 class="auth-h">Enter PIN</h3>
            <p class="auth-sub" id="pin-sub">PIN required to unlock private key.</p>
            <div class="pin-pad">
              <input type="password" inputmode="numeric" autocomplete="off" maxlength="6" id="pin-input" class="pin-field" placeholder="••••••" />
              <button class="btn btn-success" id="pin-submit">Authenticate</button>
              <button class="btn btn-outline-secondary" id="pin-back">Back</button>
            </div>
            <div class="auth-err" id="pin-err"></div>
          </section>
          <!-- Step 3 -->
          <section class="auth-step d-none" data-step="3">
            <h3 class="auth-h">Authenticating…</h3>
            <div class="auth-spinner" aria-hidden="true"></div>
            <div id="auth-final"></div>
          </section>
        </div>
      </div>
    </div>
  </div>

  <!-- ─────────── APP SHELL ─────────── -->
  <div id="app" class="app-shell d-none">
    <header class="app-header">
      <div class="brand">
        <span class="brand-mark">⬡</span>
        <span class="brand-text">NEXUS</span>
        <span class="brand-tag">Project Tracker</span>
      </div>
      <nav class="app-nav">
        <button class="nav-btn active" data-view="board">Board</button>
        <button class="nav-btn" data-view="backlog">Backlog</button>
        <button class="nav-btn" data-view="sprints">Sprints</button>
        <button class="nav-btn" data-view="history">History</button>
      </nav>
      <div class="app-user">
        <button class="icon-btn position-relative" id="bell-btn" title="Notifications">
          🔔 <span id="bell-badge" class="bell-badge d-none">0</span>
        </button>
        <select id="project-select" class="form-select form-select-sm"></select>
        <div class="user-chip" id="user-chip"></div>
        <button class="btn btn-sm btn-outline-light" id="logout-btn">Sign out</button>
      </div>
    </header>

    <main class="app-main">
      <!-- BOARD -->
      <section class="view view-board" data-view="board">
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
          <button class="btn btn-sm btn-primary ms-auto" id="new-ticket-btn">+ New ticket</button>
        </div>
        <div class="board" id="board"></div>
      </section>

      <!-- BACKLOG -->
      <section class="view view-backlog d-none" data-view="backlog">
        <div class="toolbar">
          <h2 class="view-title">Backlog</h2>
        </div>
        <div class="backlog-list" id="backlog-list"></div>
      </section>

      <!-- SPRINTS -->
      <section class="view view-sprints d-none" data-view="sprints">
        <div class="toolbar">
          <h2 class="view-title">Sprints</h2>
          <button class="btn btn-sm btn-primary ms-auto" id="new-sprint-btn">+ New sprint</button>
        </div>
        <div class="sprints-list" id="sprints-list"></div>
      </section>

      <!-- HISTORY -->
      <section class="view view-history d-none" data-view="history">
        <div class="toolbar">
          <h2 class="view-title">Project history</h2>
        </div>
        <div class="history-feed" id="history-feed"></div>
      </section>
    </main>

    <!-- Ticket detail drawer -->
    <aside class="drawer d-none" id="ticket-drawer">
      <div class="drawer-header">
        <h2 id="drawer-title">Ticket</h2>
        <button class="btn-close" id="drawer-close" aria-label="Close"></button>
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

    <!-- New ticket modal -->
    <div class="modal-shell d-none" id="ticket-modal">
      <div class="modal-card">
        <div class="modal-card-head">
          <h3>New ticket</h3>
          <button class="btn-close" data-modal-close></button>
        </div>
        <form id="ticket-form" class="modal-card-body">
          <label class="form-label">Title</label>
          <input class="form-control" name="title" required />
          <label class="form-label mt-2">Description</label>
          <textarea class="form-control" name="description" rows="3"></textarea>
          <div class="row g-2 mt-2">
            <div class="col"><label class="form-label">Type</label>
              <select class="form-select" name="type">
                <option value="task">Task</option>
                <option value="bug">Bug</option>
                <option value="story">Story</option>
                <option value="epic">Epic</option>
              </select>
            </div>
            <div class="col"><label class="form-label">Priority</label>
              <select class="form-select" name="priority">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div class="col"><label class="form-label">Effort</label>
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
              <label class="form-label">Due date</label>
              <input type="date" class="form-control" name="dueDate" />
            </div>
          </div>
        </form>
        <div class="modal-card-foot">
          <button class="btn btn-outline-secondary" data-modal-close>Cancel</button>
          <button class="btn btn-primary" id="ticket-form-submit">Create</button>
        </div>
      </div>
    </div>

    <div id="toast-stack" class="toast-stack"></div>
  </div>

  <script src="/app/nexus.js" defer></script>
</body>
</html>
