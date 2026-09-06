/* MERIDIAN — application controller.
 * Hash routing, event delegation (no inline handlers), settings I/O, the
 * generate flow, archive, sources, and delivery actions. Depends on the other
 * MERIDIAN modules loaded before it.
 * window.MERIDIAN.app
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};
  const doc = root.document;
  const { store, branding, feeds, llm, render, exporter } = M;

  const state = { brief: null };
  const $ = sel => doc.querySelector(sel);
  const $$ = sel => Array.prototype.slice.call(doc.querySelectorAll(sel));

  // ---------- toasts ----------
  function toast(msg, kind) {
    const host = $('#toast-host'); if (!host) return;
    const t = doc.createElement('div');
    t.className = 'mer-toast ' + (kind || 'info');
    const icon = doc.createElement('i');
    icon.className = 'bi ' + (kind === 'ok' ? 'bi-check-circle' : kind === 'err' ? 'bi-exclamation-octagon' : 'bi-info-circle');
    const span = doc.createElement('span'); span.textContent = msg;
    t.appendChild(icon); t.appendChild(span); host.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, kind === 'err' ? 7000 : 4000);
  }

  // ---------- routing ----------
  const VIEWS = ['brief', 'archive', 'sources', 'settings'];
  function showView(name) {
    if (VIEWS.indexOf(name) === -1) name = 'brief';
    $$('section[data-view]').forEach(s => { s.hidden = (s.getAttribute('data-view') !== name); });
    $$('[data-nav]').forEach(a => a.classList.toggle('active', a.getAttribute('data-nav') === name));
    if (name === 'archive') renderArchive();
    if (name === 'settings') loadSettingsForm();
    if (name === 'sources') { renderSources(); $('#source-material').value = store.getMaterial(); }
    doc.querySelector('main').scrollIntoView({ block: 'start' });
  }
  function routeFromHash() { showView((location.hash || '#brief').replace('#', '')); }

  // ---------- brief view ----------
  function renderBrief(brief) {
    state.brief = brief;
    const empty = $('#brief-empty');
    if (!brief) { $('#brief-body').textContent = ''; $('#brief-meta').textContent = ''; empty.classList.remove('d-none'); return; }
    empty.classList.add('d-none');
    render.render(brief, $('#brief-meta'), $('#brief-body'));
    store.setCurrentId(brief.date);
    populatePicker();
  }
  function populatePicker() {
    const picker = $('#brief-picker'); if (!picker) return;
    const list = store.listBriefs();
    picker.textContent = '';
    if (!list.length) { const o = doc.createElement('option'); o.textContent = 'No briefs'; picker.appendChild(o); return; }
    list.forEach(b => {
      const o = doc.createElement('option');
      o.value = b.date; o.textContent = b.date + (b.meta && b.meta.generatedBy === 'manual' ? '  (curated)' : '');
      if (state.brief && b.date === state.brief.date) o.selected = true;
      picker.appendChild(o);
    });
  }

  // ---------- archive ----------
  function renderArchive() {
    const tb = $('#archive-rows'); tb.textContent = '';
    const list = store.listBriefs();
    if (!list.length) {
      const tr = doc.createElement('tr');
      const td = doc.createElement('td'); td.colSpan = 4; td.className = 'empty-row';
      const div = doc.createElement('div'); div.className = 'empty-state-sm'; div.textContent = 'No saved briefs yet. Generate one or import JSON.';
      td.appendChild(div); tr.appendChild(td); tb.appendChild(tr); return;
    }
    list.forEach(b => {
      const tr = doc.createElement('tr');
      const tdDate = doc.createElement('td'); tdDate.textContent = b.date; tr.appendChild(tdDate);
      const tdH = doc.createElement('td'); tdH.textContent = render.headlineOf(b); tr.appendChild(tdH);
      const tdS = doc.createElement('td'); tdS.textContent = (b.meta && b.meta.generatedBy) || 'manual'; tr.appendChild(tdS);
      const tdA = doc.createElement('td'); tdA.className = 'text-end';
      const bOpen = mkBtn('bi-eye', 'Open', () => { renderBrief(store.getBrief(b.date)); location.hash = '#brief'; });
      const bExp = mkBtn('bi-download', 'Export', () => exporter.downloadJSON(b, 'meridian-brief-' + b.date + '.json'));
      const bDel = mkBtn('bi-trash', 'Delete', () => { if (confirm('Delete brief ' + b.date + '?')) { store.deleteBrief(b.date); if (state.brief && state.brief.date === b.date) renderBrief(store.listBriefs()[0] || null); renderArchive(); populatePicker(); } }, 'btn-outline-danger');
      tdA.appendChild(bOpen); tdA.appendChild(bExp); tdA.appendChild(bDel); tr.appendChild(tdA);
      tb.appendChild(tr);
    });
  }
  function mkBtn(icon, label, fn, cls) {
    const b = doc.createElement('button');
    b.className = 'btn btn-sm ' + (cls || 'btn-outline-secondary') + ' ms-1';
    b.title = label; b.setAttribute('aria-label', label);
    const i = doc.createElement('i'); i.className = 'bi ' + icon; b.appendChild(i);
    b.addEventListener('click', fn);
    return b;
  }

  // ---------- sources ----------
  function renderSources() {
    const grid = $('#sources-grid'); if (grid.dataset.built) return; grid.dataset.built = '1';
    feeds.PORTALS.forEach(g => {
      const col = doc.createElement('div'); col.className = 'col-md-6 col-lg-4';
      const card = doc.createElement('div'); card.className = 'card h-100 portal-group';
      const body = doc.createElement('div'); body.className = 'card-body';
      const h3 = doc.createElement('h3'); h3.textContent = g.group; body.appendChild(h3);
      g.links.forEach(l => {
        const a = doc.createElement('a');
        a.className = 'portal-link'; a.href = l.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
        const i = doc.createElement('i'); i.className = 'bi bi-box-arrow-up-right me-1 text-secondary';
        a.appendChild(i); a.appendChild(doc.createTextNode(l.name));
        body.appendChild(a);
      });
      card.appendChild(body); col.appendChild(card); grid.appendChild(col);
    });
  }

  // ---------- settings form ----------
  function loadSettingsForm() {
    const e = store.getEngine();
    $('#set-provider').value = e.provider; $('#set-model').value = e.model; $('#set-apikey').value = e.apiKey;
    $('#set-websearch').checked = !!e.webSearch; updateModelHint();
    const pf = store.getProfile();
    $('#set-role').value = pf.role; $('#set-focus').value = pf.focus; $('#set-orgcontext').value = pf.orgContext;
    const em = store.getEmail();
    $('#set-ej-public').value = em.publicKey; $('#set-ej-service').value = em.serviceId; $('#set-ej-template').value = em.templateId; $('#set-ej-to').value = em.to;
    const b = branding.get();
    $('#set-org').value = b.orgName; $('#set-logo').value = b.logoUrl; $('#set-accent').value = /^#[0-9a-fA-F]{6}$/.test(b.accent) ? b.accent : '#3d6fe0';
  }
  function updateModelHint() {
    const prov = $('#set-provider').value;
    $('#model-hint').textContent = prov === 'openai'
      ? 'e.g. gpt-4o, gpt-4.1, o4-mini (a Responses-API model with web search).'
      : 'e.g. claude-opus-4-8, claude-sonnet-5 (a model that supports the web-search tool).';
    if (!$('#set-model').value) $('#set-model').value = store.DEFAULT_MODELS[prov] || '';
  }

  // ---------- generate flow ----------
  function openGen() {
    const e = store.getEngine();
    const warn = $('#gen-warn'); warn.textContent = '';
    if (!e.apiKey) { warn.className = 'small text-warning'; warn.textContent = 'No API key configured. Add one in Settings → Intelligence Engine before generating.'; }
    else if (!e.webSearch) { warn.className = 'small text-secondary'; warn.textContent = 'Web search is OFF — the brief will rely on model training data and be marked lower confidence.'; }
    $('#gen-date').value = todayStr();
    $('#gen-progress').classList.add('d-none'); $('#gen-body').classList.remove('d-none'); $('#gen-actions').classList.remove('d-none');
    $('#gen-modal').classList.remove('d-none');
  }
  function closeGen() { $('#gen-modal').classList.add('d-none'); }
  function setProgress(txt, pct) { $('#gen-progress-text').textContent = txt; if (pct) $('#gen-bar').style.width = pct + '%'; }

  async function runGenerate() {
    const dateStr = $('#gen-date').value || todayStr();
    $('#gen-body').classList.add('d-none'); $('#gen-actions').classList.add('d-none'); $('#gen-progress').classList.remove('d-none');
    setProgress('Contacting the intelligence engine…', 10);
    try {
      const brief = await llm.generate(dateStr, setProgress);
      setProgress('Saving…', 95);
      store.saveBrief(brief);
      closeGen();
      renderBrief(brief);
      location.hash = '#brief';
      toast('Brief generated for ' + brief.date + (brief.meta && brief.meta.webSearchDegraded ? ' (web search was unavailable)' : ''), 'ok');
    } catch (err) {
      closeGen();
      toast(err.userFacing ? err.message : ('Generation failed: ' + (err.message || err)), 'err');
      if (err.rawText) console.warn('MERIDIAN raw model output:', err.rawText);
    }
  }

  function todayStr() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  // ---------- actions ----------
  const ACTIONS = {
    'toggle-theme': () => { const cur = doc.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'; doc.documentElement.setAttribute('data-bs-theme', cur); try { localStorage.setItem('bsTheme', cur); } catch (e) {} },
    'generate': openGen,
    'close-gen': closeGen,
    'run-generate': runGenerate,
    'copy-email': async () => { if (!state.brief) return toast('No brief loaded.', 'err'); const mode = await exporter.copyEmail(state.brief, branding.get()); toast(mode === 'rich' ? 'Email copied (HTML + text) — paste into your mail client.' : 'Email copied as plain text.', 'ok'); },
    'print': () => { if (!state.brief) return toast('No brief loaded.', 'err'); exporter.printBrief(); },
    'send-email': async () => {
      if (!state.brief) return toast('No brief loaded.', 'err');
      const cfg = store.getEmail();
      if (!cfg.publicKey || !cfg.serviceId || !cfg.templateId || !cfg.to) { toast('Configure EmailJS in Settings first.', 'err'); location.hash = '#settings'; return; }
      if (!confirm('Send this brief to ' + cfg.to + ' via EmailJS?')) return;
      try { await exporter.sendEmail(state.brief, branding.get()); toast('Brief sent to ' + cfg.to + '.', 'ok'); }
      catch (e) { toast('Send failed: ' + (e.message || e), 'err'); }
    },
    'export-json': () => { if (!state.brief) return toast('No brief loaded.', 'err'); exporter.downloadJSON(state.brief, 'meridian-brief-' + state.brief.date + '.json'); },
    'save-engine': () => {
      const model = $('#set-model').value.trim();
      store.setEngine({ provider: $('#set-provider').value, model, apiKey: $('#set-apikey').value.trim(), webSearch: $('#set-websearch').checked });
      toast('Engine settings saved.', 'ok');
    },
    'test-engine': async () => {
      const status = $('#engine-status'); status.className = 'small mt-2 text-secondary'; status.textContent = 'Testing…';
      store.setEngine({ provider: $('#set-provider').value, model: $('#set-model').value.trim(), apiKey: $('#set-apikey').value.trim(), webSearch: $('#set-websearch').checked });
      try { const r = await llm.test(); status.className = 'small mt-2 text-success'; status.textContent = 'Connected. Model replied: "' + r + '"'; }
      catch (e) { status.className = 'small mt-2 text-danger'; status.textContent = 'Failed: ' + llm.friendlyError(e, $('#set-provider').value); }
    },
    'toggle-key': () => { const i = $('#set-apikey'); i.type = i.type === 'password' ? 'text' : 'password'; },
    'save-profile': () => { store.setProfile({ role: $('#set-role').value.trim(), focus: $('#set-focus').value.trim(), orgContext: $('#set-orgcontext').value.trim() }); toast('Profile saved.', 'ok'); },
    'save-email': () => { store.setEmail({ publicKey: $('#set-ej-public').value.trim(), serviceId: $('#set-ej-service').value.trim(), templateId: $('#set-ej-template').value.trim(), to: $('#set-ej-to').value.trim() }); toast('Email settings saved.', 'ok'); },
    'save-branding': () => { const merged = branding.set({ orgName: $('#set-org').value.trim(), logoUrl: $('#set-logo').value.trim(), accent: $('#set-accent').value }); branding.apply(merged); if ($('#set-logo').value.trim() && !merged.logoUrl) toast('Logo URL ignored — must be http(s):// or data:image/…', 'err'); else toast('Branding saved.', 'ok'); },
    'reset-branding': () => { branding.set(Object.assign({}, branding.DEFAULTS)); branding.apply(branding.DEFAULTS); loadSettingsForm(); toast('Branding reset to default.', 'ok'); },
    'save-material': () => { store.setMaterial($('#source-material').value); toast('Source material saved.', 'ok'); },
    'clear-material': () => { store.setMaterial(''); $('#source-material').value = ''; toast('Source material cleared.', 'ok'); },
    'export-all': () => { exporter.downloadJSON(store.listBriefs(), 'meridian-archive-' + todayStr() + '.json'); },
    'clear-archive': () => { store.clearArchive(); renderBrief(null); renderArchive(); populatePicker(); toast('Archive cleared.', 'ok'); }
  };

  function onClick(ev) {
    const btn = ev.target.closest('[data-action]');
    if (btn) {
      const act = btn.getAttribute('data-action');
      const confirmMsg = btn.getAttribute('data-confirm');
      if (confirmMsg && !confirm(confirmMsg)) return;
      const fn = ACTIONS[act]; if (fn) { ev.preventDefault(); fn(btn); }
      return;
    }
    const nav = ev.target.closest('[data-nav]');
    if (nav) { /* hash change drives view; let it navigate */ }
    // close modal when clicking backdrop
    if (ev.target.id === 'gen-modal') closeGen();
  }

  // ---------- file inputs ----------
  function readFile(file, cb) { const r = new FileReader(); r.onload = () => cb(r.result); r.readAsText(file); }
  function wireFileInputs() {
    $('#import-file').addEventListener('change', function () { const f = this.files[0]; if (!f) return; readFile(f, txt => { try { const n = store.importBriefs(JSON.parse(txt)); renderArchive(); populatePicker(); toast('Imported ' + n + ' brief(s).', 'ok'); } catch (e) { toast('Invalid JSON file.', 'err'); } this.value = ''; }); });
    $('#import-all-file').addEventListener('change', function () { const f = this.files[0]; if (!f) return; readFile(f, txt => { try { const n = store.importBriefs(JSON.parse(txt)); renderArchive(); populatePicker(); toast('Imported ' + n + ' brief(s).', 'ok'); } catch (e) { toast('Invalid JSON file.', 'err'); } this.value = ''; }); });
    $('#set-logo-file').addEventListener('change', function () { const f = this.files[0]; if (!f) return; if (!/^image\//.test(f.type)) { toast('Please choose an image file.', 'err'); return; } if (f.size > 512 * 1024) { toast('Logo too large (max 512 KB for inline storage).', 'err'); return; } readFile(f, dataUrl => { $('#set-logo').value = dataUrl; toast('Logo loaded — click Save branding to apply.', 'info'); }); });
  }

  // ---------- seed load ----------
  async function seedFromFiles() {
    try {
      const res = await fetch('data/briefs.json', { cache: 'no-cache' });
      if (!res.ok) return;
      const files = await res.json();
      for (const f of files) {
        try {
          const r = await fetch('data/' + f, { cache: 'no-cache' });
          if (!r.ok) continue;
          const b = render.normalize(await r.json());
          if (!b.date) continue;
          if (!b.meta) b.meta = { generatedBy: 'manual' };
          if (!store.getBrief(b.date)) { const arc = store.getArchive(); arc[b.date] = b; try { localStorage.setItem(store.KEYS.archive, JSON.stringify(arc)); } catch (e) {} }
        } catch (e) { /* skip one bad seed */ }
      }
    } catch (e) { /* offline / file missing: fine */ }
  }

  // ---------- init ----------
  async function init() {
    branding.apply();
    doc.addEventListener('click', onClick);
    root.addEventListener('hashchange', routeFromHash);
    $('#set-provider').addEventListener('change', () => { $('#set-model').value = store.DEFAULT_MODELS[$('#set-provider').value] || ''; updateModelHint(); });
    $('#brief-picker').addEventListener('change', function () { const b = store.getBrief(this.value); if (b) renderBrief(b); });
    wireFileInputs();

    await seedFromFiles();

    // pick initial brief: current id, else latest
    const cur = store.getCurrentId();
    const brief = (cur && store.getBrief(cur)) || store.listBriefs()[0] || null;
    renderBrief(brief);
    routeFromHash();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();

  M.app = { toast, renderBrief, showView };
})(window);
