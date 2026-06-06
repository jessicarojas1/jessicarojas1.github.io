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

  /* ---------- Hero framework chips ---------- */
  $('framework-chips').innerHTML = CITADEL.frameworks.CATALOG
    .map(f => `<span class="hero-chip" title="${f.desc.replace(/"/g, '')}">${f.name}</span>`).join('');

  /* ---------- Frameworks section grid ---------- */
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

  /* ---------- Deep-scan mode (only if backend is present) ---------- */
  let deepMode = false, deepAvailable = false, aiAvailable = false;
  (async function initDeep() {
    const st = await CITADEL.api.available();
    if (!st) return;
    deepAvailable = true;
    aiAvailable = !!st.ai;
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
    const url = $('repo-url').value.trim();
    if (!url) return;
    try {
      let p = 20; showProgress(p, 'Cloning & scanning repository…', url);
      const report = await CITADEL.api.scanUrl(url, (s) => { p = Math.min(90, p + 20); showProgress(p, s, ''); });
      finishScan(report, 'deep');
    } catch (err) { showProgress(100, 'Repo scan failed: ' + (err.message || err), ''); }
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
      showProgress(100, 'Deep scan failed: ' + (err.message || err), '');
      console.error(err);
    }
  }

  // Shared finish: render, record history, reveal, then enrich quick scans with live CVEs.
  function finishScan(report, mode) {
    showProgress(100, mode === 'deep' ? 'Done (deep scan).' : 'Done.', report.findings.length + ' finding(s)');
    CITADEL.report.render(report);
    try { CITADEL.history.record(report); } catch (e) {}
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

  /* ---------- Tabs ---------- */
  document.addEventListener('click', (e) => {
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

    // Suppress / un-suppress a finding
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

    // Finding severity filter
    const filter = e.target.closest('.finding-filter');
    if (filter) {
      document.querySelectorAll('.finding-filter').forEach(b => { b.classList.remove('btn-primary'); b.classList.add('btn-outline-secondary'); });
      filter.classList.add('btn-primary'); filter.classList.remove('btn-outline-secondary');
      const sev = filter.dataset.sev;
      document.querySelectorAll('#findings-list .finding').forEach(f => {
        f.style.display = (sev === 'all' || f.dataset.sev === sev) ? '' : 'none';
      });
      return;
    }

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

  // Minimal, safe Markdown → HTML for AI answers (escape first, then format).
  function mdLite(s) {
    let h = String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    h = h.replace(/```([\s\S]*?)```/g, (m, code) => '<pre class="finding-snippet">' + code.trim() + '</pre>');
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>');
    return '<p>' + h + '</p>';
  }
})(window);
