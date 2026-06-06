/* AeroMarkup — application bootstrap & shell.
   Builds the CUI-marked enterprise shell, wires the router to lifecycle
   views, and tracks identity + connectivity. Offline-first throughout. */
import { openDB, getMeta, setMeta } from "./store.js";
import { loadSession, currentUser, getClassification, setClassification, CLASSIFICATIONS } from "./session.js";
import { onNetChange, checkReachable, netState } from "./api.js";
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
];

function shell() {
  document.body.innerHTML = `
    <div class="cui-banner cui-top" data-cui>CUI</div>
    <div class="app">
      <header class="topbar">
        <button class="btn-icon" data-menu aria-label="Menu">${icon("menu", 20)}</button>
        <div class="brand"><span class="brand-mark">${icon("plane", 20)}</span><span class="brand-name">AeroMarkup</span></div>
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

async function boot() {
  await openDB();
  await loadSession();
  await V.ensureSeed();
  shell();
  paintUser();
  paintNet();

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
  setNotFound(() => navigate("dashboard"));
  startRouter();

  // connectivity
  onNetChange(paintNet);
  checkReachable();

  // service worker (offline shell)
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("sw.js").catch((e) => console.warn("SW:", e));
  }
}

boot();
