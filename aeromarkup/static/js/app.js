/* AeroMarkup — application bootstrap & shell.
   Builds the CUI-marked enterprise shell, wires the router to lifecycle
   views, and tracks identity + connectivity. Offline-first throughout. */
import { openDB, getMeta, setMeta, all } from "./store.js";
import { loadSession, currentUser, hydrateSession, login as sessionLogin } from "./session.js";
import { onNetChange, checkReachable, netState, authStatus, bootstrap as apiBootstrap, onAuthChange } from "./api.js";
import { applyBranding } from "./branding.js";
import { icon } from "./icons.js";
import { $, $$, el, esc } from "./ui.js";
import { route, setNotFound, startRouter, navigate, parseHash } from "./router.js";
import * as V from "./views.js";

const NAV = [
  ["dashboard", "Dashboard", "dashboard"],
  ["projects", "Projects", "project"],
  ["ncr", "Nonconformance", "ncr"],
  ["inspections", "Inspections", "inspection"],
  ["approvals", "Approvals", "approval"],
  ["audit", "Audit Trail", "audit"],
  ["admin", "Administration", "admin"],
  ["settings", "Settings", "settings"],
];

function shell() {
  document.body.innerHTML = `
    <div class="app">
      <header class="topbar">
        <button class="btn-icon" data-menu aria-label="Menu">${icon("menu", 20)}</button>
        <a class="brand" href="#/dashboard" style="text-decoration:none;color:inherit" title="Home / Dashboard"><span class="brand-mark">${icon("plane", 20)}</span><span class="brand-name">AeroMarkup</span></a>
        <div style="flex:1"></div>
        <span class="badge badge-offline" data-net>Offline</span>
        <div class="user-chip" data-user>
          <span class="user-avatar" data-avatar>U</span>
          <span class="user-name" data-username>User</span>
          <span class="role-pill" data-role>engineer</span>
        </div>
      </header>
      <aside class="sidebar" data-sidebar>
        <div class="nav-section">Lifecycle</div>
        ${NAV.map(([id, label, ic]) => `
          <a class="nav-item" href="#/${id}" data-nav="${id}">
            <span class="nav-icon">${icon(ic, 18)}</span><span>${esc(label)}</span>
            <span class="nav-badge hidden" data-badge="${id}"></span>
          </a>`).join("")}
        <div class="nav-section" style="margin-top:auto">Status</div>
        <div class="nav-item" style="cursor:default">
          <span class="nav-icon">${icon("lock", 18)}</span>
          <span class="muted" style="font-size:.75rem">Data stays on-device until you Sync. Air-gap ready.</span>
        </div>
      </aside>
      <main class="main"><div class="view" id="view"></div></main>
    </div>`;
}

function setActiveNav() {
  const { name } = parseHash();
  $$("[data-nav]").forEach((a) => a.classList.toggle("active", a.dataset.nav === name
    || (name === "project" && a.dataset.nav === "projects")
    || (name === "editor" && a.dataset.nav === "projects")));
}

function paintUser() {
  const u = currentUser();
  const initials = (u.display_name || u.username || "U").split(/\s+/).map((s) => s[0]).join("").slice(0, 2).toUpperCase();
  $("[data-avatar]").textContent = initials;
  $("[data-username]").textContent = u.display_name || u.username;
  $("[data-role]").textContent = u.role;
}

function paintNet() {
  const { online, reachable } = netState();
  const b = $("[data-net]");
  if (reachable) { b.textContent = "Online · Server"; b.className = "badge badge-online"; }
  else if (online) { b.textContent = "Online · Local"; b.className = "badge badge-muted"; }
  else { b.textContent = "Offline"; b.className = "badge badge-offline"; }
}

async function paintBadges() {
  const { all } = await import("./store.js");
  const ncrs = await all("ncrs");
  const open = ncrs.filter((n) => n.status !== "closed").length;
  const b = $('[data-badge="ncr"]');
  if (b) { b.textContent = open; b.classList.toggle("hidden", !open); }
}

function withView(fn) {
  return async (param) => {
    setActiveNav();
    const host = $("#view");
    host.scrollTop = 0;
    try { await fn(host, param); } catch (e) { console.error(e); host.innerHTML = `<div class="empty-state">Error: ${esc(e.message)}</div>`; }
    paintBadges();
    // collapse mobile drawer after navigation
    $("[data-sidebar]").classList.remove("open");
  };
}

async function openCommandPalette() {
  if (document.querySelector(".cmdk-overlay")) return;
  const [projects, drawings] = await Promise.all([all("projects"), all("drawings")]);
  const cmds = [
    ...NAV.map(([id, label, ic]) => ({ ic, label: "Go to " + label, group: "Navigate", run: () => navigate(id) })),
    ...projects.map((p) => ({ ic: "project", label: "Open project: " + p.name, group: "Projects", run: () => navigate("project/" + p.id) })),
    ...drawings.map((d) => ({ ic: "drawing", label: "Open drawing: " + (d.drawing_number || d.title), group: "Drawings", run: () => navigate("editor/" + d.id) })),
  ];
  const ov = el(`<div class="cmdk-overlay"><div class="cmdk">
    <input class="cmdk-input" placeholder="Search projects, drawings, sections…" />
    <div class="cmdk-list"></div></div></div>`);
  document.body.appendChild(ov);
  const input = $(".cmdk-input", ov), list = $(".cmdk-list", ov);
  let active = 0, filtered = cmds;
  const draw = () => {
    list.innerHTML = filtered.slice(0, 40).map((c, i) => `
      <div class="cmdk-item ${i === active ? "active" : ""}" data-i="${i}">
        <span class="cmdk-ic">${icon(c.ic, 16)}</span><span>${esc(c.label)}</span>
        <span class="cmdk-hint">${esc(c.group)}</span></div>`).join("") || `<div class="cmdk-group">No matches</div>`;
    $$(".cmdk-item", list).forEach((d) => d.addEventListener("click", () => run(+d.dataset.i)));
  };
  const run = (i) => { const c = filtered[i]; if (c) { ov.remove(); c.run(); } };
  const close = () => ov.remove();
  input.addEventListener("input", () => {
    const q = input.value.toLowerCase();
    filtered = cmds.filter((c) => c.label.toLowerCase().includes(q)); active = 0; draw();
  });
  input.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown") { active = Math.min(active + 1, filtered.length - 1); draw(); e.preventDefault(); }
    else if (e.key === "ArrowUp") { active = Math.max(active - 1, 0); draw(); e.preventDefault(); }
    else if (e.key === "Enter") { run(active); }
    else if (e.key === "Escape") { close(); }
  });
  ov.addEventListener("mousedown", (e) => { if (e.target === ov) close(); });
  draw(); input.focus();
}

/* ── Authentication gate ──────────────────────────────────────────────
   Only engages when a backend is reachable. Offline / no-backend use stays
   fully local (air-gap friendly). The server enforces auth regardless. */
function authGateMarkup(mode) {
  const isBoot = mode === "bootstrap";
  return `<div class="modal-overlay" data-auth-gate>
    <div class="modal" style="max-width:420px">
      <div class="modal-head"><span class="brand-mark">${icon("plane", 20)}</span>
        <h2 class="modal-title">${isBoot ? "Set up administrator" : "Sign in to AeroMarkup"}</h2></div>
      <div class="modal-body">
        ${isBoot ? `<p class="muted" style="margin-top:0">No account exists yet. Create the first administrator to secure this deployment.</p>` : ""}
        <div class="field"><label>Username</label><input class="input" data-af="username" autocomplete="username"></div>
        ${isBoot ? `<div class="field"><label>Display name</label><input class="input" data-af="display_name" autocomplete="name"></div>` : ""}
        <div class="field"><label>Password</label><input class="input" type="password" data-af="password" autocomplete="${isBoot ? "new-password" : "current-password"}"></div>
        <div class="hidden" data-af-error style="color:var(--danger);font-size:.85rem;margin-top:var(--s2)"></div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-primary" data-af-submit>${isBoot ? "Create administrator" : "Sign in"}</button>
      </div>
    </div></div>`;
}

function showAuthGate(mode) {
  return new Promise((resolve) => {
    if (document.querySelector("[data-auth-gate]")) return resolve(false);
    const ov = el(authGateMarkup(mode));
    document.body.appendChild(ov);
    const err = $("[data-af-error]", ov);
    const submit = $("[data-af-submit]", ov);
    const fail = (m) => { err.textContent = m; err.classList.remove("hidden"); submit.disabled = false; };
    const go = async () => {
      const v = Object.fromEntries($$("[data-af]", ov).map((i) => [i.dataset.af, i.value.trim()]));
      err.classList.add("hidden"); submit.disabled = true;
      try {
        if (mode === "bootstrap") {
          if (v.username.length < 3 || v.password.length < 8) return fail("Username needs 3+ chars and password 8+ chars.");
          await apiBootstrap(v);
          await hydrateSession();
        } else {
          if (!v.username || !v.password) return fail("Enter your username and password.");
          await sessionLogin(v.username, v.password);
        }
        ov.remove();
        resolve(true);
      } catch (e) {
        if (e && e.status === 429) fail("Too many failed attempts. Please wait a few minutes and try again.");
        else fail(mode === "bootstrap" ? "Could not create administrator. Try again." : "Invalid username or password.");
      }
    };
    submit.addEventListener("click", go);
    ov.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); go(); } });
    const first = $("[data-af]", ov);
    if (first) first.focus();
  });
}

async function maybeAuthGate() {
  const st = await authStatus();
  if (!st.db) return true;                       // offline-only / no backend → local mode
  if (!st.needs_bootstrap && await hydrateSession()) return true;  // valid cookie already
  return showAuthGate(st.needs_bootstrap ? "bootstrap" : "login");
}

async function boot() {
  await openDB();
  await loadSession();
  await V.ensureSeed();
  shell();
  paintUser();
  paintNet();
  await applyBranding();
  window.addEventListener("am:branding-changed", applyBranding);


  // mobile menu
  $("[data-menu]").addEventListener("click", () => $("[data-sidebar]").classList.toggle("open"));
  window.addEventListener("am:session-changed", paintUser);

  // Authenticate against the backend before wiring routes (no-op offline).
  await checkReachable();
  await maybeAuthGate();
  paintUser();
  paintNet();
  // Re-prompt if the server session expires or is rejected mid-session.
  onAuthChange(({ authed }) => {
    if (!authed && netState().reachable) maybeAuthGate().then(paintUser);
  });

  // routes
  route("dashboard", withView(V.renderDashboard));
  route("projects", withView(V.renderProjects));
  route("project", withView(V.renderProject));
  route("editor", withView(V.renderEditor));
  route("ncr", withView(V.renderNCR));
  route("inspections", withView(V.renderInspections));
  route("approvals", withView(V.renderApprovals));
  route("audit", withView(V.renderAudit));
  route("admin", withView(V.renderAdmin));
  route("settings", withView(V.renderSettings));
  setNotFound(() => navigate("dashboard"));
  startRouter();

  // command palette (Ctrl/Cmd+K)
  window.addEventListener("keydown", (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); openCommandPalette(); }
  });

  // connectivity
  onNetChange(paintNet);
  checkReachable();

  // service worker (offline shell)
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("sw.js").catch((e) => console.warn("SW:", e));
  }
}

boot();
