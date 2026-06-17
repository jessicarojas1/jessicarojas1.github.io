/* CITADEL — Application Controller
 * Wires the UI: theme, intake (drag/drop, file/folder pickers, demo), the scan
 * pipeline with progress, tab navigation, finding filters, and exports.
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL;
  const $ = (id) => document.getElementById(id);

  /* ---------- Theme ---------- */
  function applyThemeIcon() {
    const t = document.documentElement.getAttribute('data-bs-theme');
    const ic = document.querySelector('#themeToggleBtn .theme-icon');
    if (ic) ic.textContent = t === 'dark' ? '☀️' : '🌙';
  }
  $('themeToggleBtn').addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-bs-theme');
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('bsTheme', next); } catch (e) {}
    applyThemeIcon();
  });
  applyThemeIcon();

  /* ---------- Branding (logo URL, org name, accent) ---------- */
  if (CITADEL.branding) CITADEL.branding.apply();

  /* ---------- Access control (users & page-level permissions) ---------- */
  const escH = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  // When the deep-scan backend is present, authentication & permission checks
  // run server-side (JWT). Otherwise we fall back to the client-only store.
  let backendMode = false, backendUser = null, backendEnforce = false, ssoAvailable = false;
  function curUser() { return backendMode ? backendUser : CITADEL.auth.current(); }
  function aclEnforce() { return backendMode ? backendEnforce : CITADEL.auth.settings().enforce; }
  function aclCan(page) {
    const u = curUser();
    if (!u) return false;
    if (u.role === 'admin') return true;
    return !!(u.permissions && u.permissions[page]);
  }

  let _loginModal = null, pendingMfa = null, mustChangeCtx = null;
  function loginModal() { if (!_loginModal && root.bootstrap) _loginModal = new root.bootstrap.Modal($('loginModal')); return _loginModal; }
  function resetLoginUi() {
    pendingMfa = null; mustChangeCtx = null;
    const cr = $('login-creds-row'), pr = $('login-pass-row'), mr = $('login-mfa-row'), nr = $('login-newpass-row');
    if (cr) cr.classList.remove('d-none'); if (pr) pr.classList.remove('d-none');
    if (mr) mr.classList.add('d-none'); if (nr) nr.classList.add('d-none');
    if ($('login-password')) $('login-password').value = '';
    if ($('login-mfa-code')) $('login-mfa-code').value = '';
    if ($('login-newpass')) $('login-newpass').value = '';
    const b = $('login-submit'); if (b) b.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Sign in';
  }
  function showMfaStep() {
    const cr = $('login-creds-row'), pr = $('login-pass-row'), mr = $('login-mfa-row');
    if (cr) cr.classList.add('d-none'); if (pr) pr.classList.add('d-none');
    if (mr) mr.classList.remove('d-none');
    const h = $('login-hint'); if (h) h.textContent = 'Enter the 6-digit code from your authenticator app (or a backup code).';
    const b = $('login-submit'); if (b) b.innerHTML = '<i class="bi bi-shield-lock"></i> Verify';
    const c = $('login-mfa-code'); if (c) c.focus();
  }
  // A user flagged must-change (default-cred admin, or anyone an admin reset)
  // can't use the app until they set their own password. Surface that step in the
  // same modal instead of letting every action fail with a 403 dead-end.
  function showMustChangeStep() {
    const cr = $('login-creds-row'), pr = $('login-pass-row'), mr = $('login-mfa-row'), nr = $('login-newpass-row');
    if (cr) cr.classList.add('d-none'); if (pr) pr.classList.add('d-none'); if (mr) mr.classList.add('d-none');
    if (nr) nr.classList.remove('d-none');
    const er = $('login-error'); if (er) er.classList.add('d-none');
    const h = $('login-hint'); if (h) h.textContent = 'Your account requires a new password before you can continue.';
    const b = $('login-submit'); if (b) b.innerHTML = '<i class="bi bi-key"></i> Set password &amp; continue';
    const c = $('login-newpass'); if (c) c.focus();
  }
  function openLogin() {
    resetLoginUi();
    const sso = $('login-sso'); if (sso) sso.classList.toggle('d-none', !(backendMode && ssoAvailable));
    const h = $('login-hint');
    if (h) {
      if (backendMode) h.innerHTML = 'Sign in with your CITADEL account. Default admin — <code>admin@citadel.local</code> / <code>citadel-admin</code> (change after first login).';
      else { const a = CITADEL.auth; h.innerHTML = 'Demo admin — <code>' + escH(a.DEFAULT_ADMIN.email) + '</code> / <code>' + escH(a.DEFAULT_ADMIN.password) + '</code>'; }
    }
    const er = $('login-error'); if (er) er.classList.add('d-none');
    const m = loginModal(); if (m) m.show();
  }
  function renderUserArea() {
    const ua = $('user-area'); if (!ua) return;
    const u = curUser();
    const adm = $('nav-admin'); if (adm) adm.classList.toggle('d-none', !(u && u.role === 'admin'));
    ua.innerHTML = u
      ? `<span class="badge bg-secondary">${escH(u.role)}</span><span class="small d-none d-sm-inline">${escH(u.name || u.email)}</span><button class="btn btn-sm btn-outline-secondary" id="logout-btn" title="Sign out"><i class="bi bi-box-arrow-right"></i></button>`
      : `<button class="btn btn-sm btn-outline-primary" id="login-btn"><i class="bi bi-box-arrow-in-right"></i> Login</button>`;
  }
  function applyAccess() {
    const enforce = aclEnforce();
    renderUserArea();
    document.querySelectorAll('.tab-btn').forEach(b => {
      const restrict = enforce && !aclCan(b.dataset.tab);
      b.classList.toggle('d-none', restrict);
    });
    // deep-scan gate
    const dt = $('deep-mode-toggle');
    if (dt && enforce && !aclCan('deepscan')) { dt.checked = false; dt.disabled = true; } else if (dt) { dt.disabled = false; }
    const navHist = $('nav-history'); if (navHist) navHist.classList.toggle('d-none', enforce && !aclCan('tab-history'));
    // if the active tab is now restricted, switch to the first accessible one
    const active = document.querySelector('.tab-btn.active');
    if (enforce && active && active.classList.contains('d-none')) {
      const first = document.querySelector('.tab-btn:not(.d-none)');
      if (first) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('d-none'));
        first.classList.add('active');
        const panel = $(first.dataset.tab); if (panel) panel.classList.remove('d-none');
      }
    }
  }
  // Block a scan if access control is on and the user lacks the 'analyze' page.
  // The backend enforces this authoritatively; this is a UX pre-check only.
  function gateScan() {
    if (!aclEnforce()) return true;
    if (aclCan('analyze')) return true;
    if (!curUser()) { openLogin(); showProgress(100, 'Sign in required to run a scan.', ''); }
    else { showProgress(100, 'Your account does not have permission to run scans.', ''); }
    return false;
  }
  // Open the History view from the top menu bar (without needing a fresh scan).
  function openHistory() {
    const results = $('results'); if (results) results.classList.remove('d-none');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('d-none'));
    const hbtn = document.querySelector('.tab-btn[data-tab="tab-history"]');
    if (hbtn) hbtn.classList.add('active');
    const panel = $('tab-history'); if (panel) panel.classList.remove('d-none');
    if (CITADEL.report && CITADEL.report.renderHistory) CITADEL.report.renderHistory('tab-history');
    if (results) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  // Load a saved scan's full report and show it (shared by Recent + History).
  async function openScanById(id) {
    try {
      const report = await CITADEL.api.scanGet(id);
      CITADEL.report.render(report);
      const results = $('results'); if (results) results.classList.remove('d-none');
      const rep = document.querySelector('.tab-btn[data-tab="tab-report"]'); if (rep) rep.click();
      if (results) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {}
  }
  // Dashboard "Recent scans" widget (only when durable server history is present).
  async function loadRecentActivity() {
    const wrap = $('recent-activity'), list = $('recent-list');
    if (!wrap || !list || !backendMode || !CITADEL.api.scansList) return;
    let data = null;
    try { data = await CITADEL.api.scansList(6); } catch (e) {}
    if (!data || !data.enabled || !(data.scans && data.scans.length)) { wrap.classList.add('d-none'); return; }
    // Security-score trend across the recent scans (oldest → newest).
    const spark = $('recent-spark');
    if (spark && CITADEL.report && CITADEL.report.sparkline) {
      spark.innerHTML = CITADEL.report.sparkline(data.scans.slice().reverse().map(s => s.security | 0), 110, 24);
    }
    const gc = g => 'grade-' + String(g || '?').toLowerCase();
    list.innerHTML = data.scans.map(s =>
      '<button type="button" class="recent-item" data-open-recent="' + escH(s.id) + '">' +
        '<span class="badge grade-pill ' + gc(s.grade) + '">' + escH(s.grade) + '</span>' +
        '<span class="recent-src">' + escH(s.source || 'scan') + '</span>' +
        '<span class="recent-meta">' + escH(new Date(s.ts).toLocaleString()) + ' · ' + (s.findings | 0) + ' findings' +
          (((s.critical | 0) + (s.high | 0)) ? ' · ' + ((s.critical | 0) + (s.high | 0)) + ' crit/high' : '') + '</span>' +
      '</button>').join('');
    wrap.classList.remove('d-none');
  }
  // If a scan endpoint rejects us (server-side enforcement), reflect it in the UI.
  function handleAuthError(err) {
    if (err && (err.status === 401 || err.status === 403)) {
      if (err.status === 401) { backendUser = null; renderUserArea(); openLogin(); }
      showProgress(100, err.status === 401 ? 'Sign in required to run a scan.' : 'Your account does not have permission for this action.', '');
      return true;
    }
    return false;
  }
  CITADEL.auth.ready.then(() => { if (!backendMode) applyAccess(); });

  /* ---------- Hero live stats ---------- */
  (function () {
    const hs = $('hero-stats'); if (!hs) return;
    const cwe = new Set(CITADEL.rules.filter(r => r.cwe).map(r => r.cwe)).size;
    const stats = [
      [CITADEL.lang.count, 'languages'],
      [CITADEL.rules.length, 'SAST rules'],
      [CITADEL.frameworks.CATALOG.length, 'frameworks'],
      [CITADEL.frameworks.catalogTotal(), 'controls'],
      [cwe, 'CWEs']
    ];
    hs.innerHTML = stats.map(s => `<div class="hero-stat"><span class="hs-num">${s[0]}</span><span class="hs-lbl">${s[1]}</span></div>`).join('');
  })();

  /* ---------- Hero framework chips (only if present — moved to coverage.html) ---------- */
  const fchips = $('framework-chips');
  if (fchips) fchips.innerHTML = CITADEL.frameworks.CATALOG
    .map(f => `<span class="hero-chip" title="${f.desc.replace(/"/g, '')}">${f.name}</span>`).join('');

  /* ---------- Frameworks section grid (only if present — moved to coverage.html) ---------- */
  const fgrid = $('frameworks-grid');
  if (fgrid) {
    fgrid.innerHTML = CITADEL.frameworks.CATALOG.map(f => `
      <div class="col-sm-6 col-lg-4 col-xl-3">
        <a class="fw-tile" href="${f.url}" target="_blank" rel="noopener">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <span class="fw-tile-name">${f.name}</span>
            <span class="badge framework-tag">${f.tag}</span>
          </div>
          <div class="fw-tile-ver">${f.version}</div>
          <div class="fw-tile-desc">${f.desc.replace(/"/g, '')}</div>
        </a>
      </div>`).join('');
  }

  /* ---------- Engine / scanner status panel ---------- */
  function renderEngineStatus(health) {
    const el = $('engine-status'); if (!el) return;
    const workerOn = (typeof Worker !== 'undefined');
    const pill = (label, on, title) => `<span class="es-pill ${on ? 'on' : 'off'}" title="${title || ''}"><span class="es-dot"></span>${label}</span>`;
    let html = `<div class="es-group"><span class="es-title"><i class="bi bi-cpu"></i> In-browser engine</span>
      ${pill('Active', true, 'heuristic SAST + secrets + SBOM + OSV CVEs')}
      ${pill(workerOn ? 'Web Worker' : 'Main thread', true, workerOn ? 'non-blocking background scanning' : 'inline scanning')}</div>`;
    if (health && health.scanners) {
      const scs = health.scanners.map(s => pill(s.tool, s.available, s.available ? 'online' : 'not installed')).join('');
      html += `<div class="es-group"><span class="es-title"><i class="bi bi-hdd-network"></i> Backend scanners</span>${scs}
        ${pill('AI fix', !!health.ai, health.ai ? 'Claude remediation on' : 'no API key')}</div>`;
    } else {
      html += `<div class="es-group"><span class="es-title"><i class="bi bi-hdd-network"></i> Backend</span>${pill('Not connected — client-side only', false, 'deploy the backend for deep scan')}</div>`;
    }
    el.innerHTML = html;
  }
  renderEngineStatus(null);

  /* ---------- Deep-scan mode (only if backend is present) ---------- */
  let deepMode = false, deepAvailable = false, aiAvailable = false;
  (async function initDeep() {
    const st = await CITADEL.api.available();
    if (!st) return;
    if (CITADEL.branding) CITADEL.branding.syncFromBackend();   // shared branding from the server
    renderEngineStatus(st);
    deepAvailable = true;
    aiAvailable = !!st.ai;
    // Backend present → auth & permission checks are authoritative server-side.
    backendMode = true;
    backendEnforce = !!(st.auth && st.auth.enforce);
    ssoAvailable = !!(st.auth && st.auth.sso);
    try { backendUser = await CITADEL.api.authMe(); } catch (e) { backendUser = null; }
    applyAccess();
    loadRecentActivity();
    CITADEL.report.setAi(aiAvailable);
    $('deep-mode-row').classList.remove('d-none');
    $('url-scan-row').classList.remove('d-none');
    const on = (st.scanners || []).filter(s => s.available).map(s => s.tool);
    $('deep-mode-tools').innerHTML = (on.length
      ? 'Real scanners online: ' + on.map(t => '<span class="badge bg-secondary">' + t + '</span>').join(' ')
      : 'Backend detected, but no scanners are installed — deep scan will fall back to heuristics.')
      + (aiAvailable ? ' <span class="badge" style="background:#10b981">AI remediation on</span>' : '');
    const tg = $('deep-mode-toggle');
    deepMode = tg.checked;
    tg.addEventListener('change', () => { deepMode = tg.checked; });
  })();

  $('scan-url-btn').addEventListener('click', async () => {
    if (!gateScan()) return;
    const url = $('repo-url').value.trim();
    if (!url) return;
    const subEl = $('repo-subpath');
    const subpath = subEl ? subEl.value.trim() : '';
    try {
      let p = 20; showProgress(p, 'Cloning & scanning repository…', url + (subpath ? ' /' + subpath : ''));
      const report = await CITADEL.api.scanUrl(url, subpath, (s) => { p = Math.min(90, p + 20); showProgress(p, s, ''); });
      finishScan(report, 'deep');
    } catch (err) { if (!handleAuthError(err)) showProgress(100, 'Repo scan failed: ' + (err.message || err), ''); }
  });

  /* ---------- Intake ---------- */
  const dz = $('dropzone');
  const fileInput = $('file-input');
  const folderInput = $('folder-input');

  $('pick-files').addEventListener('click', (e) => { e.stopPropagation(); fileInput.click(); });
  $('pick-folder').addEventListener('click', (e) => { e.stopPropagation(); folderInput.click(); });
  $('load-demo').addEventListener('click', (e) => { e.stopPropagation(); runDemo(); });
  dz.addEventListener('click', () => fileInput.click());
  dz.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });

  fileInput.addEventListener('change', () => { if (fileInput.files.length) handleFiles([...fileInput.files]); });
  folderInput.addEventListener('change', () => { if (folderInput.files.length) handleFiles([...folderInput.files]); });

  ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('dragover'); }));
  ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); if (ev !== 'drop') dz.classList.remove('dragover'); }));
  dz.addEventListener('drop', async (e) => {
    dz.classList.remove('dragover');
    const items = e.dataTransfer.items;
    let files = [];
    if (items && items.length && items[0].webkitGetAsEntry) {
      files = await readDataTransfer(items);
    } else {
      files = [...e.dataTransfer.files];
    }
    if (files.length) handleFiles(files);
  });

  // Recursively walk dropped directory entries
  async function readDataTransfer(items) {
    const entries = [];
    for (const it of items) { const en = it.webkitGetAsEntry && it.webkitGetAsEntry(); if (en) entries.push(en); }
    const files = [];
    async function walk(entry, path) {
      if (entry.isFile) {
        await new Promise(res => entry.file(f => {
          try { Object.defineProperty(f, 'webkitRelativePath', { value: path + f.name }); } catch (e) {}
          files.push(f); res();
        }, res));
      } else if (entry.isDirectory) {
        const reader = entry.createReader();
        const batch = await new Promise(res => reader.readEntries(res, () => res([])));
        for (const c of batch) await walk(c, path + entry.name + '/');
      }
    }
    for (const en of entries) await walk(en, '');
    return files;
  }

  /* ---------- Progress ---------- */
  function showProgress(pct, stage, detail) {
    $('progress-wrap').classList.remove('d-none');
    $('progress-bar').style.width = pct + '%';
    if (stage) $('progress-stage').textContent = stage;
    if (detail != null) $('progress-detail').textContent = detail;
  }
  function hideProgress() { setTimeout(() => $('progress-wrap').classList.add('d-none'), 600); }

  /* ---------- Run pipeline ---------- */
  async function handleDeep(files) {
    try {
      let p = 15;
      showProgress(p, 'Deep scan — uploading…', files.length + ' item(s)');
      const report = await CITADEL.api.scan(files, (stage) => { p = Math.min(90, p + 18); showProgress(p, stage, ''); });
      finishScan(report, 'deep');
    } catch (err) {
      if (!handleAuthError(err)) showProgress(100, 'Deep scan failed: ' + (err.message || err), '');
      console.error(err);
    }
  }

  // Shared finish: render, record history, reveal, then enrich quick scans with live CVEs.
  function finishScan(report, mode) {
    showProgress(100, mode === 'deep' ? 'Done (deep scan).' : 'Done.', report.findings.length + ' finding(s)');
    CITADEL.report.render(report);
    try { CITADEL.history.record(report); } catch (e) {}
    applyAccess();
    if (mode === 'deep') setTimeout(loadRecentActivity, 600);   // server has just persisted it
    $('results').classList.remove('d-none');
    hideProgress();
    $('results').scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (mode !== 'deep' && report.sbom && report.sbom.components.length) enrichOsv(report);
  }

  // Quick mode: query OSV.dev for real CVEs, merge, re-score, re-render.
  async function enrichOsv(report) {
    try {
      const res = await CITADEL.osv.enrich(report.sbom.components);
      if (!res.findings.length) {
        const s = $('osv-status'); if (s) s.innerHTML = '<i class="bi bi-check-circle text-success"></i> No known vulnerabilities for the ' + res.queried + ' pinned dependenc(ies) checked via OSV.dev.';
        return;
      }
      report.findings = report.findings.concat(res.findings);
      report.scoring = CITADEL.scanner.score(report.findings, report.quality);
      report.posture = CITADEL.frameworks.posture(report.findings);
      CITADEL.report.render(report);
      try { CITADEL.history.record(report, 'Scan + OSV ' + new Date().toLocaleString()); } catch (e) {}
    } catch (e) {
      const s = $('osv-status'); if (s) s.innerHTML = '<i class="bi bi-exclamation-circle"></i> OSV.dev lookup unavailable (offline?).';
    }
  }

  async function handleFiles(files) {
    if (!gateScan()) return;
    if (deepMode && deepAvailable) return handleDeep(files);
    try {
      showProgress(5, 'Ingesting files…', files.length + ' item(s)');
      const entries = await CITADEL.ingest.ingestFiles(files, (i, n, name) => {
        showProgress(5 + Math.round((i / n) * 35), 'Ingesting files…', shorten(name));
      });
      await runScan(entries);
    } catch (err) {
      showProgress(100, 'Error: ' + (err.message || err), '');
      console.error(err);
    }
  }

  async function runDemo() {
    if (!gateScan()) return;
    showProgress(20, 'Building demo project…', 'synthetic vulnerable app');
    const entries = CITADEL.demo.buildEntries();
    await runScan(entries);
  }

  // Web Worker: keeps the UI responsive on large repos. Falls back to inline.
  let _worker;
  function getWorker() {
    if (_worker !== undefined) return _worker;
    try { _worker = new Worker('js/worker.js'); _worker.onerror = function () {}; }
    catch (e) { _worker = null; }
    return _worker;
  }
  function scanInline(entries) {
    let p = 45;
    return CITADEL.scanner.scan(entries, (stage) => { p = Math.min(96, p + 7); showProgress(p, stage, ''); });
  }
  function scanViaWorker(entries) {
    return new Promise((resolve, reject) => {
      const w = getWorker();
      if (!w) return reject(new Error('no worker'));
      let p = 45, settled = false;
      const onMsg = (e) => {
        const m = e.data || {};
        if (m.type === 'progress') { p = Math.min(96, p + 7); showProgress(p, m.stage, ''); }
        else if (m.type === 'done') { cleanup(); resolve(m.report); }
        else if (m.type === 'error' || m.type === 'fatal') { cleanup(); reject(new Error(m.message)); }
      };
      const onErr = () => { cleanup(); reject(new Error('worker error')); };
      function cleanup() { if (settled) return; settled = true; w.removeEventListener('message', onMsg); w.removeEventListener('error', onErr); }
      w.addEventListener('message', onMsg);
      w.addEventListener('error', onErr);
      try { w.postMessage({ type: 'scan', entries: entries }); } catch (e) { cleanup(); reject(e); }
    });
  }
  async function runScan(entries) {
    if (!entries.length) { showProgress(100, 'No analyzable files found.', ''); return; }
    let report;
    try {
      showProgress(45, 'Analyzing…', getWorker() ? 'background worker' : '');
      report = getWorker() ? await scanViaWorker(entries) : await scanInline(entries);
    } catch (err) {
      // Worker failed for any reason — degrade to inline scanning.
      try { report = await scanInline(entries); } catch (e2) { showProgress(100, 'Scan error: ' + (e2.message || e2), ''); return; }
    }
    finishScan(report, 'quick');
  }

  function shorten(s) { return s.length > 48 ? '…' + s.slice(-46) : s; }

  /* ---------- Findings facet filters (selects + search) ---------- */
  document.addEventListener('change', (e) => { if (e.target && e.target.closest && e.target.closest('.finding-flt') && CITADEL.report.applyFilters) CITADEL.report.applyFilters(); });
  document.addEventListener('input', (e) => { if (e.target && e.target.id === 'fnd-search' && CITADEL.report.applyFilters) CITADEL.report.applyFilters(); });

  /* ---------- Tabs ---------- */
  document.addEventListener('click', (e) => {
    // Auth controls
    // On mobile, collapse the expanded menu after picking a real nav item (not
    // the Docs dropdown toggle) so the page is visible again.
    if (e.target.closest('.navbar-collapse .nav-link:not(.dropdown-toggle), .navbar-collapse .dropdown-item')) {
      const col = $('citadelNav');
      if (col && col.classList.contains('show') && root.bootstrap) root.bootstrap.Collapse.getOrCreateInstance(col).hide();
    }
    if (e.target.closest('#nav-history')) { e.preventDefault(); openHistory(); return; }
    if (e.target.closest('#recent-viewall')) { e.preventDefault(); openHistory(); return; }
    const recOpen = e.target.closest('[data-open-recent]');
    if (recOpen) { e.preventDefault(); openScanById(recOpen.getAttribute('data-open-recent')); return; }
    if (e.target.closest('#login-btn')) return openLogin();
    if (e.target.closest('#logout-btn')) {
      if (backendMode) { CITADEL.api.authLogout(); backendUser = null; } else { CITADEL.auth.logout(); }
      applyAccess(); return;
    }
    if (e.target.closest('#login-submit')) {
      const er = $('login-error');
      const showErr = (msg) => { if (er) { er.textContent = msg; er.classList.remove('d-none'); } };
      if (er) er.classList.add('d-none');
      const complete = (u) => { backendUser = u; const m = loginModal(); if (m) m.hide(); resetLoginUi(); applyAccess(); };
      // Backend login succeeded but the account must set a new password first →
      // switch to the must-change step (we hold the just-entered current password).
      const finishOk = (u) => {
        if (backendMode && u && u.mustChange) { mustChangeCtx = { user: u, current: $('login-password').value || '' }; showMustChangeStep(); return; }
        complete(u);
      };
      // In-flight must-change step: set the new password, then finish sign-in.
      if (mustChangeCtx) {
        (async () => {
          const next = ($('login-newpass').value || '');
          if (next.length < 8) { showErr('New password must be at least 8 characters.'); return; }
          try {
            await CITADEL.api.authChangePassword(mustChangeCtx.current, next);
            const u = Object.assign({}, mustChangeCtx.user, { mustChange: false });
            mustChangeCtx = null;
            complete(u);
          } catch (ex) { showErr((ex && ex.message) || 'Could not change password.'); }
        })();
        return;
      }
      if (!backendMode) {
        CITADEL.auth.loginByCreds($('login-email').value, $('login-password').value)
          .then(u => u ? finishOk(u) : showErr('Invalid credentials or inactive account.'))
          .catch(() => showErr('Invalid credentials or inactive account.'));
        return;
      }
      (async () => {
        try {
          if (pendingMfa) {
            const u = await CITADEL.api.authMfaVerify(pendingMfa, ($('login-mfa-code').value || '').trim());
            if (u) finishOk(u); else showErr('Invalid authenticator code.');
            return;
          }
          const r = await CITADEL.api.authLogin($('login-email').value, $('login-password').value);
          if (!r) { showErr('Invalid credentials or inactive account.'); return; }
          if (r.mfaRequired) { pendingMfa = r.mfaToken; showMfaStep(); return; }
          finishOk(r.user);
        } catch (e2) { showErr('Sign-in failed. Please try again.'); }
      })();
      return;
    }

    const tab = e.target.closest('.tab-btn');
    if (tab) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      tab.classList.add('active');
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('d-none'));
      const panel = $(tab.dataset.tab);
      if (panel) panel.classList.remove('d-none');
      if (tab.dataset.tab === 'tab-history') CITADEL.report.renderHistory('tab-history');
      return;
    }

    // Set a finding disposition (open / accepted / false-positive / remediated / na)
    const dispBtn = e.target.closest('[data-dispose-set]');
    if (dispBtn) {
      const [idx, state] = dispBtn.dataset.disposeSet.split(':');
      const f = CITADEL.report.shownFinding(+idx);
      if (f && CITADEL.disposition) { CITADEL.disposition.set(f, state); CITADEL.report.renderFindings(CITADEL.report.current); }
      return;
    }
    // Suppress / un-suppress a finding (legacy button path; still supported)
    const supBtn = e.target.closest('[data-suppress]');
    if (supBtn) {
      const f = CITADEL.report.shownFinding(+supBtn.dataset.suppress);
      if (f) { CITADEL.suppress.isSuppressed(f) ? CITADEL.suppress.unsuppress(f) : CITADEL.suppress.suppress(f); CITADEL.report.renderFindings(CITADEL.report.current); }
      return;
    }
    if (e.target.closest('#toggle-suppressed')) { CITADEL.report.toggleSuppressedView(); return; }

    // AI explain
    const aiBtn = e.target.closest('[data-explain]');
    if (aiBtn) {
      const i = +aiBtn.dataset.explain;
      const f = CITADEL.report.shownFinding(i);
      const box = $('finding-ai-' + i);
      if (!f || !box) return;
      box.classList.remove('d-none');
      box.innerHTML = '<i class="bi bi-hourglass-split"></i> Asking Claude…';
      aiBtn.disabled = true;
      CITADEL.api.explain(f).then(out => {
        box.innerHTML = '<div class="ai-answer"><div class="small text-body-secondary mb-1"><i class="bi bi-stars"></i> ' + out.model + '</div>' + mdLite(out.text) + '</div>';
      }).catch(err => { box.innerHTML = '<span class="text-danger">' + (err.message || err) + '</span>'; })
        .finally(() => { aiBtn.disabled = false; });
      return;
    }

    // History compare / clear
    if (e.target.closest('#hist-compare')) { CITADEL.report.renderCompare($('hist-a').value, $('hist-b').value); return; }
    if (e.target.closest('#hist-clear')) { CITADEL.history.clear(); CITADEL.report.renderHistory('tab-history'); return; }

    // Finding expand/collapse
    const head = e.target.closest('[data-finding-toggle]');
    if (head) {
      const body = $('finding-body-' + head.dataset.findingToggle);
      if (body) { body.classList.toggle('d-none'); head.querySelector('.finding-chev')?.classList.toggle('open'); }
      return;
    }

    // Finding severity filter (composes with the facet dropdowns + search)
    const filter = e.target.closest('.finding-filter');
    if (filter) {
      document.querySelectorAll('.finding-filter').forEach(b => { b.classList.remove('btn-primary'); b.classList.add('btn-outline-secondary'); });
      filter.classList.add('btn-primary'); filter.classList.remove('btn-outline-secondary');
      if (CITADEL.report.applyFilters) CITADEL.report.applyFilters();
      return;
    }
    if (e.target.closest('#fnd-reset')) { if (CITADEL.report.resetFilters) CITADEL.report.resetFilters(); return; }

    // Exports
    if (e.target.closest('#exp-json')) return CITADEL.report.exportJson();
    // Full control-list expander (Compliance tab)
    const ce = e.target.closest('.ctrl-expand');
    if (ce) {
      const el = $('fwctrls-' + ce.dataset.fwctrls);
      if (el) { if (!ce.dataset.orig) ce.dataset.orig = ce.textContent; const hidden = el.classList.toggle('d-none'); ce.textContent = hidden ? ce.dataset.orig : 'Hide controls'; }
      return;
    }
    // Report tab + AI fix prompt
    if (e.target.closest('#dl-report')) return CITADEL.report.downloadHtmlReport(CITADEL.report.current);
    if (e.target.closest('#copy-aifix')) return CITADEL.report.copyAiFix();
    if (e.target.closest('#dl-aifix')) return CITADEL.report.downloadAiFix();

    if (e.target.closest('#exp-sbom') || e.target.closest('#dl-sbom')) return CITADEL.report.exportSbom();
    if (e.target.closest('#exp-sarif')) return CITADEL.report.exportSarif();
    if (e.target.closest('#exp-poam')) return CITADEL.report.exportPoam();
    if (e.target.closest('#exp-ssp')) return CITADEL.report.exportSsp();
    if (e.target.closest('#exp-junit')) return CITADEL.report.exportJUnit();
    if (e.target.closest('#exp-prcomment')) return CITADEL.report.exportPrComment();
    if (e.target.closest('#exp-md')) return CITADEL.report.exportMarkdown();
    if (e.target.closest('#exp-pdf')) return CITADEL.report.exportPdf();
  });

  /* ---------- Keyboard shortcuts + help overlay ---------- */
  (function keyboardShortcuts() {
    const SHORTCUTS = [
      { keys: ['?'], desc: 'Toggle this shortcuts help' },
      { keys: ['t'], desc: 'Toggle light / dark theme' },
      { keys: ['d'], desc: 'Run the demo scan' },
      { keys: ['1', '–', '9'], desc: 'Jump to the Nth tab' },
      { keys: ['g', 'then', 'o'], desc: 'Go to Overview tab' },
      { keys: ['g', 'then', 'f'], desc: 'Go to Findings tab' },
      { keys: ['g', 'then', 'c'], desc: 'Go to Compliance tab' },
      { keys: ['g', 'then', 'r'], desc: 'Go to Report tab' },
      { keys: ['Esc'], desc: 'Close this overlay' }
    ];
    const CHORD_TABS = { o: 'tab-overview', f: 'tab-findings', c: 'tab-compliance', r: 'tab-report' };

    let overlay = null;          // the .kbd-overlay element (built lazily)
    let chordActive = false;     // are we waiting for the 2nd key of a 'g' chord?
    let chordTimer = null;
    let lastFocused = null;      // restore focus when the overlay closes

    function isTyping() {
      const el = document.activeElement;
      if (!el) return false;
      const tag = (el.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
      if (el.isContentEditable) return true;
      return false;
    }

    function buildOverlay() {
      const ov = document.createElement('div');
      ov.className = 'kbd-overlay d-none';
      ov.setAttribute('role', 'dialog');
      ov.setAttribute('aria-modal', 'true');
      ov.setAttribute('aria-label', 'Keyboard shortcuts');

      const card = document.createElement('div');
      card.className = 'kbd-card';
      card.setAttribute('tabindex', '-1');

      const h = document.createElement('div');
      h.className = 'kbd-card-head';
      h.innerHTML = '<span class="kbd-title"><i class="bi bi-keyboard"></i> Keyboard shortcuts</span>'
        + '<span class="kbd-esc-hint"><kbd>Esc</kbd> to close</span>';
      card.appendChild(h);

      const list = document.createElement('div');
      list.className = 'kbd-list';
      SHORTCUTS.forEach(s => {
        const row = document.createElement('div');
        row.className = 'kbd-row';
        const keysWrap = document.createElement('div');
        keysWrap.className = 'kbd-keys';
        s.keys.forEach(k => {
          if (k === 'then' || k === '–') {
            const sep = document.createElement('span');
            sep.className = 'kbd-sep';
            sep.textContent = k === 'then' ? 'then' : '–';
            keysWrap.appendChild(sep);
          } else {
            const kb = document.createElement('kbd');
            kb.textContent = k;
            keysWrap.appendChild(kb);
          }
        });
        const desc = document.createElement('div');
        desc.className = 'kbd-desc';
        desc.textContent = s.desc;
        row.appendChild(keysWrap);
        row.appendChild(desc);
        list.appendChild(row);
      });
      card.appendChild(list);
      ov.appendChild(card);

      // Close when clicking the dimmed backdrop (but not the card itself).
      ov.addEventListener('click', (e) => { if (e.target === ov) hideOverlay(); });

      document.body.appendChild(ov);
      return ov;
    }

    function isOpen() { return overlay && !overlay.classList.contains('d-none'); }

    function showOverlay() {
      if (!overlay) overlay = buildOverlay();
      lastFocused = document.activeElement;
      overlay.classList.remove('d-none');
      const card = overlay.querySelector('.kbd-card');
      if (card) try { card.focus(); } catch (e) {}
    }
    function hideOverlay() {
      if (!overlay) return;
      overlay.classList.add('d-none');
      if (lastFocused && typeof lastFocused.focus === 'function') { try { lastFocused.focus(); } catch (e) {} }
      lastFocused = null;
    }
    function toggleOverlay() { isOpen() ? hideOverlay() : showOverlay(); }

    function clearChord() { chordActive = false; if (chordTimer) { clearTimeout(chordTimer); chordTimer = null; } }

    function visibleTabs() {
      return Array.prototype.filter.call(
        document.querySelectorAll('.tab-btn'),
        (b) => !b.classList.contains('d-none')
      );
    }
    function clickVisibleTabByData(tabId) {
      const tabs = visibleTabs();
      for (let i = 0; i < tabs.length; i++) {
        if (tabs[i].dataset && tabs[i].dataset.tab === tabId) { tabs[i].click(); return true; }
      }
      return false;
    }

    document.addEventListener('keydown', (e) => {
      // Never hijack modifier combos (browser/OS shortcuts).
      if (e.ctrlKey || e.metaKey || e.altKey) return;

      // Escape always closes the overlay if it is open.
      if (e.key === 'Escape') { if (isOpen()) { hideOverlay(); e.preventDefault(); } clearChord(); return; }

      // Ignore everything else while typing in a field / contenteditable.
      if (isTyping()) { clearChord(); return; }

      // Resolve the 2nd key of a 'g' chord first.
      if (chordActive) {
        const k = (e.key || '').toLowerCase();
        const target = CHORD_TABS[k];
        if (target) { if (clickVisibleTabByData(target)) e.preventDefault(); }
        clearChord();
        return;
      }

      const key = e.key;

      if (key === '?') { toggleOverlay(); e.preventDefault(); return; }

      // Other single-key shortcuts should not fire while the overlay is open
      // (Esc/'?' above already handle the overlay itself).
      if (isOpen()) return;

      if (key === 't') {
        const btn = $('themeToggleBtn');
        if (btn) { btn.click(); e.preventDefault(); }
        return;
      }
      if (key === 'd') {
        const btn = $('load-demo');
        if (btn) { btn.click(); e.preventDefault(); }
        return;
      }
      if (key === 'g') {
        chordActive = true;
        if (chordTimer) clearTimeout(chordTimer);
        chordTimer = setTimeout(() => { chordActive = false; chordTimer = null; }, 1000);
        e.preventDefault();
        return;
      }
      if (key >= '1' && key <= '9') {
        const tabs = visibleTabs();
        const idx = parseInt(key, 10) - 1;
        if (tabs[idx]) { tabs[idx].click(); e.preventDefault(); }
        return;
      }
    });
  })();

  // Minimal, safe Markdown → HTML for AI answers (escape first, then format).
  function mdLite(s) {
    let h = String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    h = h.replace(/```([\s\S]*?)```/g, (m, code) => '<pre class="finding-snippet">' + code.trim() + '</pre>');
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>');
    return '<p>' + h + '</p>';
  }

  // Deep-link: open History directly (e.g. from the Coverage page's menu).
  if (location.hash === '#history') { try { openHistory(); } catch (e) {} }
})(window);
