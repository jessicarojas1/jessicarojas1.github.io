/* ============================================================
   compliancetracker.js
   Full compliance tracker: CMMC L2/L1, ISO 27001
   - Objective-level tracking (Met / Not Met / In Progress / N/A)
   - Notes per objective/control (localStorage)
   - Evidence upload/remove (IndexedDB)
   - Donut chart (Chart.js)
   - SSP generation + PDF export (html2pdf.js) + Word (.doc blob)
   - Data export/import (JSON)
   ============================================================ */

// ── State ────────────────────────────────────────────────────
const App = {
  standard: 'cmmc_l2',
  data: [],
  db: null,
  chart: null,
  refreshTimer: null,
};

const STANDARDS = {
  cmmc_l2:  { file: 'cmmc_l2_data.json',  label: 'CMMC Level 2', objectiveLevel: true,  domainKey: 'domain_id', nameKey: 'domain_name', itemsKey: 'practices',  subKey: 'objectives' },
  cmmc_l1:  { file: 'cmmc_l1_data.json',  label: 'CMMC Level 1', objectiveLevel: false, domainKey: 'domain_id', nameKey: 'domain_name', itemsKey: 'practices',  subKey: null },
  iso27001: { file: 'iso27001_data.json', label: 'ISO 27001:2022', objectiveLevel: false, domainKey: 'domain_id', nameKey: 'domain_name', itemsKey: 'controls',   subKey: null },
};

// ── IndexedDB ────────────────────────────────────────────────
function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('ComplianceTrackerDB', 1);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains('evidence')) {
        const store = db.createObjectStore('evidence', { keyPath: 'id' });
        store.createIndex('objectiveId', 'objectiveId', { unique: false });
      }
    };
    req.onsuccess = e => resolve(e.target.result);
    req.onerror  = e => reject(e.target.error);
  });
}

function dbAdd(record) {
  return new Promise((resolve, reject) => {
    const tx = App.db.transaction('evidence', 'readwrite');
    tx.objectStore('evidence').add(record);
    tx.oncomplete = resolve;
    tx.onerror    = e => reject(e.target.error);
  });
}

function dbGetByObjective(objectiveId) {
  return new Promise((resolve, reject) => {
    const tx    = App.db.transaction('evidence', 'readonly');
    const index = tx.objectStore('evidence').index('objectiveId');
    const req   = index.getAll(objectiveId);
    req.onsuccess = e => resolve(e.target.result);
    req.onerror   = e => reject(e.target.error);
  });
}

function dbDelete(id) {
  return new Promise((resolve, reject) => {
    const tx = App.db.transaction('evidence', 'readwrite');
    tx.objectStore('evidence').delete(id);
    tx.oncomplete = resolve;
    tx.onerror    = e => reject(e.target.error);
  });
}

function dbGetAll() {
  return new Promise((resolve, reject) => {
    const tx  = App.db.transaction('evidence', 'readonly');
    const req = tx.objectStore('evidence').getAll();
    req.onsuccess = e => resolve(e.target.result);
    req.onerror   = e => reject(e.target.error);
  });
}

function dbClear() {
  return new Promise((resolve, reject) => {
    const tx = App.db.transaction('evidence', 'readwrite');
    tx.objectStore('evidence').clear();
    tx.oncomplete = resolve;
    tx.onerror    = e => reject(e.target.error);
  });
}

// ── localStorage helpers ─────────────────────────────────────
function storeKey(id) { return `ct__${App.standard}__${id}`; }

function getStored(id)         { return JSON.parse(localStorage.getItem(storeKey(id)) || '{}'); }
function saveStatus(id, val)   { const d = getStored(id); d.status = val; localStorage.setItem(storeKey(id), JSON.stringify(d)); }
function saveNotes(id, val)    { const d = getStored(id); d.notes  = val; localStorage.setItem(storeKey(id), JSON.stringify(d)); }

// ── Stats & Chart ────────────────────────────────────────────
function computeStats() {
  const cfg = STANDARDS[App.standard];
  let total = 0, met = 0, notMet = 0, inProg = 0, na = 0;

  App.data.forEach(domain => {
    (domain[cfg.itemsKey] || []).forEach(item => {
      if (cfg.objectiveLevel && item.objectives) {
        item.objectives.forEach(obj => {
          total++;
          const s = (getStored(obj.id).status || '');
          if (s === 'met')          met++;
          else if (s === 'not_met') notMet++;
          else if (s === 'in_progress') inProg++;
          else if (s === 'na')      na++;
        });
      } else {
        total++;
        const s = (getStored(item.id).status || '');
        if (s === 'met')          met++;
        else if (s === 'not_met') notMet++;
        else if (s === 'in_progress') inProg++;
        else if (s === 'na')      na++;
      }
    });
  });

  const eligible = total - na;
  const score = eligible > 0 ? Math.round((met / eligible) * 100) : 0;
  return { total, met, notMet, inProg, na, score };
}

function updateDashboard() {
  const s = computeStats();
  document.getElementById('statTotal').textContent  = s.total;
  document.getElementById('statMet').textContent    = s.met;
  document.getElementById('statNotMet').textContent = s.notMet;
  document.getElementById('statInProg').textContent = s.inProg;
  document.getElementById('statNA').textContent     = s.na;
  document.getElementById('statScore').textContent  = s.score + '%';
  updateChart(s);
  updateDomainProgress();
}

function updateChart(s) {
  const ctx = document.getElementById('statusChart').getContext('2d');
  const notAssessed = s.total - s.met - s.notMet - s.inProg - s.na;
  const data = [s.met, s.notMet, s.inProg, s.na, notAssessed];

  if (App.chart) {
    App.chart.data.datasets[0].data = data;
    App.chart.update();
    return;
  }

  App.chart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Met', 'Not Met', 'In Progress', 'N/A', 'Not Assessed'],
      datasets: [{
        data,
        backgroundColor: ['#4ade80', '#f87171', '#facc15', '#8b949e', '#30363d'],
        borderColor: '#0d1117',
        borderWidth: 2,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          display: false,
        },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.raw}`
          }
        }
      },
      cutout: '65%',
    }
  });
}

function scheduleRefresh() {
  clearTimeout(App.refreshTimer);
  App.refreshTimer = setTimeout(updateDashboard, 250);
}

// ── Per-domain progress bar ───────────────────────────────────
function getDomainStats(domain) {
  const cfg = STANDARDS[App.standard];
  let total = 0, met = 0;
  (domain[cfg.itemsKey] || []).forEach(item => {
    if (cfg.objectiveLevel && item.objectives) {
      item.objectives.forEach(obj => { total++; if (getStored(obj.id).status === 'met') met++; });
    } else {
      total++;
      if (getStored(item.id).status === 'met') met++;
    }
  });
  return { total, met };
}

function updateDomainProgress() {
  App.data.forEach(domain => {
    const { total, met } = getDomainStats(domain);
    const pct = total > 0 ? Math.round((met / total) * 100) : 0;
    const bar  = document.querySelector(`.progress-fill[data-domain="${domain[STANDARDS[App.standard].domainKey]}"]`);
    const lbl  = document.querySelector(`.progress-label[data-domain="${domain[STANDARDS[App.standard].domainKey]}"]`);
    if (bar) {
      bar.style.width = pct + '%';
      bar.className = 'progress-fill' + (pct >= 75 ? ' good' : pct >= 40 ? ' partial' : '');
    }
    if (lbl) lbl.textContent = `${met}/${total}`;
  });
}

// ── Evidence helpers ──────────────────────────────────────────
async function loadEvidenceList(objectiveId, container) {
  if (!App.db) return;
  container.innerHTML = '';
  const files = await dbGetByObjective(objectiveId);
  if (!files.length) return;
  const ul = document.createElement('ul');
  ul.className = 'evidence-list';
  files.forEach(f => {
    const li = document.createElement('li');
    li.className = 'evidence-item';
    li.innerHTML = `
      <span class="evidence-item-name" title="${f.name}">${f.name}</span>
      <span class="evidence-item-size">${formatBytes(f.size)}</span>
      <button class="evidence-remove" data-id="${f.id}" title="Remove">✕</button>`;
    li.querySelector('.evidence-remove').addEventListener('click', async () => {
      await dbDelete(f.id);
      await loadEvidenceList(objectiveId, container);
    });
    ul.appendChild(li);
  });
  container.appendChild(ul);
}

function formatBytes(b) {
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
  return (b / 1048576).toFixed(1) + ' MB';
}

// ── Render Accordion ──────────────────────────────────────────
function renderAccordion(data) {
  const cfg = STANDARDS[App.standard];
  const container = document.getElementById('domainAccordion');
  container.innerHTML = '';

  if (!data.length) {
    container.innerHTML = '<p class="no-results">No results found.</p>';
    return;
  }

  data.forEach(domain => {
    const domainId = domain[cfg.domainKey];
    const { total, met } = getDomainStats(domain);
    const pct = total > 0 ? Math.round((met / total) * 100) : 0;
    const fillClass = pct >= 75 ? ' good' : pct >= 40 ? ' partial' : '';

    const block = document.createElement('div');
    block.className = 'domain-block';

    block.innerHTML = `
      <div class="domain-header" data-domain="${domainId}">
        <div class="domain-header-left">
          <span class="domain-id-badge">${domainId}</span>
          <span class="domain-name">${domain[cfg.nameKey]}</span>
        </div>
        <div class="domain-header-right">
          <div class="progress-wrap">
            <div class="progress-track">
              <div class="progress-fill${fillClass}" data-domain="${domainId}" style="width:${pct}%"></div>
            </div>
            <span class="progress-label" data-domain="${domainId}">${met}/${total}</span>
          </div>
          <span class="domain-chevron">▼</span>
        </div>
      </div>
      <div class="domain-body" data-body="${domainId}"></div>`;

    const header = block.querySelector('.domain-header');
    const body   = block.querySelector('.domain-body');

    header.addEventListener('click', () => {
      const isOpen = header.classList.contains('open');
      if (!isOpen) {
        // Lazy render
        if (!body.dataset.rendered) {
          renderDomainBody(domain, body);
          body.dataset.rendered = '1';
        }
        header.classList.add('open');
        body.classList.add('open');
      } else {
        header.classList.remove('open');
        body.classList.remove('open');
      }
    });

    container.appendChild(block);
  });
}

function renderDomainBody(domain, container) {
  const cfg = STANDARDS[App.standard];
  const items = domain[cfg.itemsKey] || [];

  if (cfg.objectiveLevel) {
    // CMMC L2: nested practice → objective
    items.forEach(practice => renderPracticeBlock(practice, container));
  } else {
    // Simple: flat control rows
    items.forEach(item => renderControlRow(item, container));
  }
}

function renderPracticeBlock(practice, container) {
  const objectives = practice.objectives || [];
  const metCount = objectives.filter(o => getStored(o.id).status === 'met').length;
  const statusText = metCount > 0 ? `${metCount}/${objectives.length} met` : `0/${objectives.length} met`;

  const block = document.createElement('div');
  block.className = 'practice-block';
  block.innerHTML = `
    <div class="practice-header" data-practice="${practice.id}">
      <span class="practice-id">${practice.id}</span>
      <span class="practice-title-text" title="${practice.title}">${practice.title}</span>
      <span class="practice-status-pill">${statusText}</span>
      <span style="color:#8b949e;font-size:0.8rem;margin-left:0.5rem;">▶</span>
    </div>
    <div class="practice-body" data-pbody="${practice.id}"></div>`;

  const pHeader = block.querySelector('.practice-header');
  const pBody   = block.querySelector('.practice-body');

  pHeader.addEventListener('click', () => {
    const isOpen = pHeader.classList.contains('open');
    if (!isOpen) {
      if (!pBody.dataset.rendered) {
        renderObjectiveList(practice, pBody);
        pBody.dataset.rendered = '1';
      }
      pHeader.classList.add('open');
      pBody.classList.add('open');
      pHeader.querySelector('span:last-child').textContent = '▼';
    } else {
      pHeader.classList.remove('open');
      pBody.classList.remove('open');
      pHeader.querySelector('span:last-child').textContent = '▶';
    }
  });

  container.appendChild(block);
}

function refreshPracticePill(practiceId) {
  const header = document.querySelector(`.practice-header[data-practice="${practiceId}"]`);
  if (!header) return;
  // Find objectives from App.data
  for (const domain of App.data) {
    for (const p of (domain.practices || domain.controls || [])) {
      if (p.id === practiceId) {
        const objs = p.objectives || [];
        const met = objs.filter(o => getStored(o.id).status === 'met').length;
        const pill = header.querySelector('.practice-status-pill');
        if (pill) pill.textContent = `${met}/${objs.length} met`;
        return;
      }
    }
  }
}

function renderObjectiveList(practice, container) {
  (practice.objectives || []).forEach(obj => {
    const stored = getStored(obj.id);
    const status = stored.status || '';

    const row = document.createElement('div');
    row.className = 'objective-row';

    row.innerHTML = `
      <div class="obj-label">
        <span class="status-indicator ${status}"></span>
        ${obj.id.match(/\[.+\]$/)?.[0] || obj.id}
      </div>
      <div class="obj-controls">
        <div class="obj-text">${obj.text}</div>
        <div class="obj-status-row">
          <select class="obj-status-select" data-id="${obj.id}" data-practice="${practice.id}">
            <option value="">— Set Status —</option>
            <option value="met"          ${status === 'met'          ? 'selected' : ''}>✅ Met</option>
            <option value="not_met"      ${status === 'not_met'      ? 'selected' : ''}>❌ Not Met</option>
            <option value="in_progress"  ${status === 'in_progress'  ? 'selected' : ''}>🔄 In Progress</option>
            <option value="na"           ${status === 'na'           ? 'selected' : ''}>➖ N/A</option>
          </select>
        </div>
        <textarea class="obj-notes" data-id="${obj.id}" placeholder="Add notes, policy references, implementation details…" rows="2">${stored.notes || ''}</textarea>
        <div class="evidence-section">
          <label class="evidence-upload-btn">
            📎 Attach Evidence
            <input type="file" class="evidence-file-input" multiple data-id="${obj.id}" />
          </label>
          <div class="evidence-list-container" data-ev="${obj.id}"></div>
        </div>
      </div>`;

    // Load evidence
    const evContainer = row.querySelector('.evidence-list-container');
    loadEvidenceList(obj.id, evContainer);

    // Status change
    row.querySelector('.obj-status-select').addEventListener('change', e => {
      const val = e.target.value;
      saveStatus(obj.id, val);
      const indicator = row.querySelector('.status-indicator');
      indicator.className = `status-indicator ${val}`;
      refreshPracticePill(practice.id);
      scheduleRefresh();
    });

    // Notes blur
    row.querySelector('.obj-notes').addEventListener('blur', e => {
      saveNotes(obj.id, e.target.value);
    });

    // Evidence upload
    row.querySelector('.evidence-file-input').addEventListener('change', async e => {
      const files = Array.from(e.target.files);
      for (const file of files) {
        if (file.size > 10 * 1024 * 1024) {
          alert(`${file.name} exceeds the 10 MB limit and was skipped.`);
          continue;
        }
        const buf = await file.arrayBuffer();
        await dbAdd({
          id:          crypto.randomUUID(),
          objectiveId: obj.id,
          standard:    App.standard,
          name:        file.name,
          type:        file.type,
          size:        file.size,
          data:        buf,
          uploadedAt:  Date.now(),
        });
      }
      e.target.value = '';
      await loadEvidenceList(obj.id, evContainer);
    });

    container.appendChild(row);
  });
}

function renderControlRow(item, container) {
  const stored = getStored(item.id);
  const status = stored.status || '';

  const row = document.createElement('div');
  row.innerHTML = `
    <div class="control-row">
      <span class="control-id">
        <span class="status-indicator ${status}"></span>
        ${item.id}
      </span>
      <span class="control-title-text">${item.title}</span>
      <select class="control-status-select" data-id="${item.id}">
        <option value="">— Status —</option>
        <option value="met"          ${status === 'met'          ? 'selected' : ''}>✅ Met</option>
        <option value="not_met"      ${status === 'not_met'      ? 'selected' : ''}>❌ Not Met</option>
        <option value="in_progress"  ${status === 'in_progress'  ? 'selected' : ''}>🔄 In Progress</option>
        <option value="na"           ${status === 'na'           ? 'selected' : ''}>➖ N/A</option>
      </select>
    </div>
    <div class="control-notes-row">
      <textarea class="control-notes" data-id="${item.id}" placeholder="Notes…" rows="1">${stored.notes || ''}</textarea>
    </div>`;

  row.querySelector('.control-status-select').addEventListener('change', e => {
    const val = e.target.value;
    saveStatus(item.id, val);
    row.querySelector('.status-indicator').className = `status-indicator ${val}`;
    scheduleRefresh();
  });

  row.querySelector('.control-notes').addEventListener('blur', e => {
    saveNotes(item.id, e.target.value);
  });

  container.appendChild(row);
}

// ── Search / filter ───────────────────────────────────────────
function filterData(query) {
  if (!query.trim()) return App.data;
  const q = query.toLowerCase();
  const cfg = STANDARDS[App.standard];
  return App.data
    .map(domain => {
      if (domain[cfg.domainKey].toLowerCase().includes(q) || domain[cfg.nameKey].toLowerCase().includes(q)) {
        return domain;
      }
      const items = (domain[cfg.itemsKey] || []).filter(item => {
        if (item.id.toLowerCase().includes(q) || (item.title || '').toLowerCase().includes(q)) return true;
        if (cfg.objectiveLevel && item.objectives) {
          return item.objectives.some(o => o.id.toLowerCase().includes(q) || o.text.toLowerCase().includes(q));
        }
        return false;
      });
      if (!items.length) return null;
      return { ...domain, [cfg.itemsKey]: items };
    })
    .filter(Boolean);
}

// ── Load standard ─────────────────────────────────────────────
async function loadStandard(standardId) {
  App.standard = standardId;
  const cfg = STANDARDS[standardId];

  // Destroy old chart
  if (App.chart) { App.chart.destroy(); App.chart = null; }

  document.getElementById('domainAccordion').innerHTML = '<p class="no-results">Loading…</p>';

  try {
    const res  = await fetch(cfg.file);
    App.data   = await res.json();
  } catch {
    document.getElementById('domainAccordion').innerHTML = '<p class="no-results">⚠ Failed to load data file.</p>';
    return;
  }

  renderAccordion(App.data);
  updateDashboard();
}

// ── SSP Generation ────────────────────────────────────────────
function generateSSPHTML() {
  const cfg      = STANDARDS[App.standard];
  const org      = document.getElementById('sspOrg').value       || '[Organization Name]';
  const system   = document.getElementById('sspSystem').value    || '[System Name]';
  const owner    = document.getElementById('sspOwner').value     || '[System Owner]';
  const dateVal  = document.getElementById('sspDate').value      || new Date().toISOString().split('T')[0];
  const purpose  = document.getElementById('sspPurpose').value   || '[System Purpose]';
  const boundary = document.getElementById('sspBoundary').value  || '[Authorization Boundary]';
  const prepBy   = document.getElementById('sspPreparedBy').value || '[Prepared By]';

  const stats  = computeStats();
  const stdLbl = cfg.label;

  let html = `
    <h1>System Security Plan</h1>
    <table>
      <tr><th>Organization</th><td>${org}</td><th>System Name</th><td>${system}</td></tr>
      <tr><th>System Owner</th><td>${owner}</td><th>Assessment Date</th><td>${dateVal}</td></tr>
      <tr><th>Standard</th><td>${stdLbl}</td><th>Prepared By</th><td>${prepBy}</td></tr>
    </table>

    <h2>1. System Description</h2>
    <p><strong>Purpose:</strong> ${purpose}</p>
    <p><strong>Authorization Boundary:</strong> ${boundary}</p>

    <h2>2. Compliance Summary</h2>
    <table>
      <tr><th>Total ${cfg.objectiveLevel ? 'Objectives' : 'Controls'}</th><th>Met</th><th>Not Met</th><th>In Progress</th><th>N/A</th><th>Score</th></tr>
      <tr>
        <td>${stats.total}</td>
        <td class="ssp-met">${stats.met}</td>
        <td class="ssp-not-met">${stats.notMet}</td>
        <td class="ssp-in-prog">${stats.inProg}</td>
        <td class="ssp-na">${stats.na}</td>
        <td><strong>${stats.score}%</strong></td>
      </tr>
    </table>

    <h2>3. Control Implementation Details</h2>`;

  App.data.forEach(domain => {
    const domainId = domain[cfg.domainKey];
    const items    = domain[cfg.itemsKey] || [];
    html += `<h3>${domainId} – ${domain[cfg.nameKey]}</h3>`;

    if (cfg.objectiveLevel) {
      html += `<table><tr><th>Practice / Objective</th><th>Status</th><th>Notes</th></tr>`;
      items.forEach(practice => {
        (practice.objectives || []).forEach(obj => {
          const s    = getStored(obj.id);
          const stat = s.status || 'not_assessed';
          const css  = stat === 'met' ? 'ssp-met' : stat === 'not_met' ? 'ssp-not-met' : stat === 'in_progress' ? 'ssp-in-prog' : stat === 'na' ? 'ssp-na' : '';
          const lbl  = stat === 'met' ? 'Met' : stat === 'not_met' ? 'Not Met' : stat === 'in_progress' ? 'In Progress' : stat === 'na' ? 'N/A' : 'Not Assessed';
          html += `<tr>
            <td><strong>${obj.id}</strong><br/><small>${obj.text}</small></td>
            <td class="${css}">${lbl}</td>
            <td>${s.notes || ''}</td>
          </tr>`;
        });
      });
      html += `</table>`;
    } else {
      html += `<table><tr><th>Control ID</th><th>Title</th><th>Status</th><th>Notes</th></tr>`;
      items.forEach(item => {
        const s    = getStored(item.id);
        const stat = s.status || 'not_assessed';
        const css  = stat === 'met' ? 'ssp-met' : stat === 'not_met' ? 'ssp-not-met' : stat === 'in_progress' ? 'ssp-in-prog' : stat === 'na' ? 'ssp-na' : '';
        const lbl  = stat === 'met' ? 'Met' : stat === 'not_met' ? 'Not Met' : stat === 'in_progress' ? 'In Progress' : stat === 'na' ? 'N/A' : 'Not Assessed';
        html += `<tr>
          <td>${item.id}</td>
          <td>${item.title}</td>
          <td class="${css}">${lbl}</td>
          <td>${s.notes || ''}</td>
        </tr>`;
      });
      html += `</table>`;
    }
  });

  // POA&M
  const poam = [];
  App.data.forEach(domain => {
    const cfg2 = STANDARDS[App.standard];
    (domain[cfg2.itemsKey] || []).forEach(item => {
      if (cfg2.objectiveLevel) {
        (item.objectives || []).forEach(obj => {
          const s = getStored(obj.id);
          if (s.status === 'not_met' || s.status === 'in_progress') {
            poam.push({ id: obj.id, title: obj.text, status: s.status, notes: s.notes || '' });
          }
        });
      } else {
        const s = getStored(item.id);
        if (s.status === 'not_met' || s.status === 'in_progress') {
          poam.push({ id: item.id, title: item.title, status: s.status, notes: s.notes || '' });
        }
      }
    });
  });

  html += `<h2>4. Plan of Action & Milestones (POA&M)</h2>`;
  if (poam.length) {
    html += `<table><tr><th>ID</th><th>Description</th><th>Status</th><th>Notes / Planned Remediation</th></tr>`;
    poam.forEach(p => {
      const css = p.status === 'not_met' ? 'ssp-not-met' : 'ssp-in-prog';
      const lbl = p.status === 'not_met' ? 'Not Met' : 'In Progress';
      html += `<tr><td>${p.id}</td><td>${p.title}</td><td class="${css}">${lbl}</td><td>${p.notes}</td></tr>`;
    });
    html += `</table>`;
  } else {
    html += `<p><em>No open POA&M items.</em></p>`;
  }

  html += `<p style="margin-top:2rem;font-size:0.8rem;color:#8b949e;">Generated: ${new Date().toLocaleString()} | ${stdLbl}</p>`;
  return html;
}

function openSSPModal() {
  document.getElementById('sspModal').classList.remove('hidden');
  const sysInput = document.getElementById('systemNameInput').value;
  if (sysInput) document.getElementById('sspSystem').value = sysInput;
  document.getElementById('sspDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('sspDocument').style.display = 'none';
}

document.getElementById('previewSSPBtn').addEventListener('click', () => {
  const doc = document.getElementById('sspDocument');
  doc.innerHTML = generateSSPHTML();
  doc.style.display = 'block';
});

document.getElementById('exportPDFBtn').addEventListener('click', () => {
  const doc = document.getElementById('sspDocument');
  const html = generateSSPHTML();
  doc.innerHTML = html;
  doc.style.display = 'block';

  const sysName = document.getElementById('sspSystem').value || 'SSP';
  const dateStr = new Date().toISOString().split('T')[0];

  doc.classList.add('ssp-print-mode');
  const opt = {
    margin:       [10, 10, 10, 10],
    filename:     `SSP_${sysName}_${dateStr}.pdf`,
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
    jsPDF:        { unit: 'mm', format: 'letter', orientation: 'portrait' }
  };
  html2pdf().set(opt).from(doc).save().then(() => {
    doc.classList.remove('ssp-print-mode');
  });
});

document.getElementById('exportWordBtn').addEventListener('click', () => {
  const html    = generateSSPHTML();
  const sysName = document.getElementById('sspSystem').value || 'SSP';
  const dateStr = new Date().toISOString().split('T')[0];
  const full = `<html xmlns:o='urn:schemas-microsoft-com:office:office'
    xmlns:w='urn:schemas-microsoft-com:office:word'
    xmlns='http://www.w3.org/TR/REC-html40'>
  <head><meta charset='utf-8'><title>SSP</title>
  <style>
    body { font-family: Calibri, sans-serif; color:#000; font-size:11pt; }
    table { border-collapse:collapse; width:100%; margin:8pt 0; }
    th, td { border:1px solid #999; padding:4pt 6pt; font-size:9pt; vertical-align:top; }
    th { background:#eee; font-weight:bold; }
    h1 { color:#b83200; } h2 { color:#b83200; border-bottom:1px solid #ccc; }
    h3 { color:#333; } .ssp-met { color:#166534; font-weight:bold; }
    .ssp-not-met { color:#991b1b; font-weight:bold; }
    .ssp-in-prog { color:#854d0e; font-weight:bold; }
    .ssp-na { color:#6b7280; font-weight:bold; }
  </style></head><body>${html}</body></html>`;
  const blob = new Blob(['\ufeff', full], { type: 'application/msword' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `SSP_${sysName}_${dateStr}.doc`;
  a.click();
  URL.revokeObjectURL(url);
});

document.getElementById('sspClose').addEventListener('click', () => {
  document.getElementById('sspModal').classList.add('hidden');
});

document.getElementById('sspModal').addEventListener('click', e => {
  if (e.target === e.currentTarget) e.currentTarget.classList.add('hidden');
});

// ── SSP button ────────────────────────────────────────────────
document.getElementById('sspBtn').addEventListener('click', openSSPModal);

// ── Standard selector ─────────────────────────────────────────
document.getElementById('standardSelect').addEventListener('change', e => {
  loadStandard(e.target.value);
});

// ── Search ────────────────────────────────────────────────────
document.getElementById('searchInput').addEventListener('input', e => {
  const filtered = filterData(e.target.value);
  renderAccordion(filtered);
});

// ── Export / Import data ──────────────────────────────────────
document.getElementById('exportDataBtn').addEventListener('click', async () => {
  const keys = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    if (k.startsWith('ct__')) keys.push(k);
  }
  const lsData = {};
  keys.forEach(k => { lsData[k] = JSON.parse(localStorage.getItem(k)); });

  const evidenceMeta = (await dbGetAll()).map(({ data: _d, ...rest }) => rest);

  const blob = new Blob([JSON.stringify({ localStorage: lsData, evidenceMeta }, null, 2)], { type: 'application/json' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `compliance_data_${new Date().toISOString().split('T')[0]}.json`;
  a.click();
  URL.revokeObjectURL(url);
});

document.getElementById('importDataBtn').addEventListener('click', () => {
  document.getElementById('importFileInput').click();
});

document.getElementById('importFileInput').addEventListener('change', e => {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = ev => {
    try {
      const imported = JSON.parse(ev.target.result);
      if (imported.localStorage) {
        Object.entries(imported.localStorage).forEach(([k, v]) => {
          localStorage.setItem(k, JSON.stringify(v));
        });
      }
      alert('Data imported successfully. Reloading…');
      loadStandard(App.standard);
    } catch {
      alert('Import failed: invalid JSON file.');
    }
  };
  reader.readAsText(file);
  e.target.value = '';
});

// ── Clear all data ────────────────────────────────────────────
document.getElementById('clearDataBtn').addEventListener('click', async () => {
  if (!confirm('Clear ALL compliance data for ALL standards? This cannot be undone.')) return;
  for (const k of Object.keys(localStorage).filter(k => k.startsWith('ct__'))) {
    localStorage.removeItem(k);
  }
  await dbClear();
  loadStandard(App.standard);
});

// ── System name persistence ───────────────────────────────────
const sysInput = document.getElementById('systemNameInput');
sysInput.value = localStorage.getItem('ct__systemName') || '';
sysInput.addEventListener('blur', () => {
  localStorage.setItem('ct__systemName', sysInput.value);
});

// ── Init ──────────────────────────────────────────────────────
async function init() {
  App.db = await openDB();
  await loadStandard('cmmc_l2');
}

init();
