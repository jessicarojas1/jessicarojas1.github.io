/* AeroMarkup — application bootstrap & shell.
   Builds the CUI-marked enterprise shell, wires the router to lifecycle
   views, and tracks identity + connectivity. Offline-first throughout. */
import { openDB, getMeta, setMeta, all } from "./store.js";
import { loadSession, currentUser, getClassification, setClassification, CLASSIFICATIONS } from "./session.js";
import { onNetChange, checkReachable, netState } from "./api.js";
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
    <div class="cui-banner cui-top" data-cui>CUI</div>
    <div class="app">
      <header class="topbar">
        <button class="btn-icon" data-menu aria-label="Menu">${icon("menu", 20)}</button>
        <a class="brand" href="#/dashboard" style="text-decoration:none;color:inherit" title="Home / Dashboard"><span class="brand-mark">${icon("plane", 20)}</span><span class="brand-name">AeroMarkup</span></a>
        <select class="classification-select" data-classification title="Document classification">
          ${CLASSIFICATIONS.map((c) => `<option>${c}</option>`).join("")}
        </select>
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
    </div>
    <div class="cui-banner cui-bottom" data-cui>CUI</div>`;
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

async function boot() {
  await openDB();
  await loadSession();
  await V.ensureSeed();
  shell();
  paintUser();
  paintNet();
  await applyBranding();
  window.addEventListener("am:branding-changed", applyBranding);

  // classification banner
  const cls = await getClassification();
  $("[data-classification]").value = cls;
  $$("[data-cui]").forEach((b) => (b.textContent = cls === "UNCLASSIFIED" ? "UNCLASSIFIED" : cls));
  $("[data-classification]").addEventListener("change", async (e) => {
    await setClassification(e.target.value);
    $$("[data-cui]").forEach((b) => (b.textContent = e.target.value === "UNCLASSIFIED" ? "UNCLASSIFIED" : e.target.value));
  });

  // mobile menu
  $("[data-menu]").addEventListener("click", () => $("[data-sidebar]").classList.toggle("open"));
  window.addEventListener("am:session-changed", paintUser);

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
