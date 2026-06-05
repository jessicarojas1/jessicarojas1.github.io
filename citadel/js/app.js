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

  /* ---------- Hero framework chips ---------- */
  $('framework-chips').innerHTML = CITADEL.frameworks.CATALOG
    .map(f => `<span class="hero-chip" title="${f.desc.replace(/"/g, '')}">${f.name}</span>`).join('');

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
  async function handleFiles(files) {
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

  async function runScan(entries) {
    if (!entries.length) { showProgress(100, 'No analyzable files found.', ''); return; }
    let p = 45;
    const report = await CITADEL.scanner.scan(entries, (stage) => {
      p = Math.min(96, p + 7);
      showProgress(p, stage, '');
    });
    showProgress(100, 'Done.', report.findings.length + ' finding(s)');
    CITADEL.report.render(report);
    $('results').classList.remove('d-none');
    hideProgress();
    $('results').scrollIntoView({ behavior: 'smooth', block: 'start' });
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
      return;
    }

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
    if (e.target.closest('#exp-sbom') || e.target.closest('#dl-sbom')) return CITADEL.report.exportSbom();
    if (e.target.closest('#exp-md')) return CITADEL.report.exportMarkdown();
    if (e.target.closest('#exp-pdf')) return CITADEL.report.exportPdf();
  });
})(window);
