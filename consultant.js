/* ============================================================
   consultant.js — CMMC Consulting engagement tracker
   All data stored in localStorage. Integrates with compliancetracker
   via URL parameter ?system=<project name>&standard=<standard>
   ============================================================ */

const STORAGE_KEY = 'consultingEngagements';

const STANDARD_LABELS = {
  cmmc_l2:  'CMMC Level 2',
  cmmc_l1:  'CMMC Level 1',
  iso27001: 'ISO 27001:2022',
};

// ── Data helpers ──────────────────────────────────────────────
function loadEngagements() {
  try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || []; }
  catch { return []; }
}

function saveEngagements(list) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
}

function generateId() {
  return `eng_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
}

// ── Render ────────────────────────────────────────────────────
function renderEngagements() {
  const list    = loadEngagements();
  const query   = document.getElementById('engSearchInput').value.toLowerCase().trim();
  const status  = document.getElementById('engStatusFilter').value;
  const container = document.getElementById('engagementList');
  const emptyMsg  = document.getElementById('engEmptyMsg');

  const filtered = list.filter(e => {
    const matchQuery = !query ||
      (e.customer || '').toLowerCase().includes(query) ||
      (e.project  || '').toLowerCase().includes(query) ||
      (e.contract || '').toLowerCase().includes(query);
    const matchStatus = !status || e.status === status;
    return matchQuery && matchStatus;
  });

  // Remove old cards (keep the empty msg element)
  container.querySelectorAll('.eng-card').forEach(el => el.remove());

  if (filtered.length === 0) {
    emptyMsg.style.display = '';
    emptyMsg.textContent   = list.length === 0
      ? 'No engagements yet. Click + New Engagement to start tracking.'
      : 'No engagements match your filter.';
    return;
  }

  emptyMsg.style.display = 'none';

  filtered.forEach(eng => {
    const card = document.createElement('div');
    card.className = 'eng-card';
    card.dataset.id = eng.id;

    const startStr  = eng.startDate  ? formatDate(eng.startDate)  : '';
    const targetStr = eng.targetDate ? formatDate(eng.targetDate) : '';
    const dateRange = [startStr, targetStr].filter(Boolean).join(' \u2192 ');

    const trackerUrl = `compliancetracker.html?system=${encodeURIComponent(eng.project || eng.customer)}&standard=${encodeURIComponent(eng.standard || 'cmmc_l2')}`;

    card.innerHTML = `
      <div class="eng-card-header">
        <div>
          <div class="eng-customer">${escHtml(eng.customer)}</div>
          <div class="eng-project">${escHtml(eng.project)}</div>
          ${eng.contract ? `<div class="eng-contract">${escHtml(eng.contract)}</div>` : ''}
        </div>
      </div>
      <div class="eng-meta">
        <span class="eng-status ${eng.status}">${statusLabel(eng.status)}</span>
        <span class="eng-standard">${escHtml(STANDARD_LABELS[eng.standard] || eng.standard)}</span>
        ${dateRange ? `<span class="eng-dates">${escHtml(dateRange)}</span>` : ''}
      </div>
      ${eng.notes ? `<div class="eng-notes">${escHtml(eng.notes)}</div>` : ''}
      <div class="eng-actions">
        <a href="${trackerUrl}" class="btn btn-sm btn-primary">Open Tracker</a>
        <button class="btn btn-sm btn-outline-secondary btn-edit" data-id="${eng.id}">Edit</button>
        <button class="btn btn-sm btn-outline-danger btn-delete" data-id="${eng.id}">Delete</button>
      </div>
    `;

    container.appendChild(card);
  });
}

function statusLabel(s) {
  return { active: 'Active', in_progress: 'In Progress', completed: 'Completed', on_hold: 'On Hold' }[s] || s;
}

function formatDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-');
  return `${m}/${d}/${y}`;
}

function escHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── Modal ─────────────────────────────────────────────────────
const engagementModal = new bootstrap.Modal(document.getElementById('engagementModal'));

function openModal(eng) {
  document.getElementById('engModalLabel').textContent = eng ? 'Edit Engagement' : 'New Engagement';
  document.getElementById('engId').value          = eng ? eng.id          : '';
  document.getElementById('engCustomer').value    = eng ? eng.customer    : '';
  document.getElementById('engContract').value    = eng ? eng.contract    : '';
  document.getElementById('engProject').value     = eng ? eng.project     : '';
  document.getElementById('engStandard').value    = eng ? eng.standard    : 'cmmc_l2';
  document.getElementById('engStatus').value      = eng ? eng.status      : 'active';
  document.getElementById('engStartDate').value   = eng ? eng.startDate   : '';
  document.getElementById('engTargetDate').value  = eng ? eng.targetDate  : '';
  document.getElementById('engNotes').value       = eng ? eng.notes       : '';
  document.getElementById('engFormError').classList.add('d-none');
  engagementModal.show();
}

document.getElementById('addEngagementBtn').addEventListener('click', () => openModal(null));

document.getElementById('saveEngagementBtn').addEventListener('click', () => {
  const errEl    = document.getElementById('engFormError');
  const customer = document.getElementById('engCustomer').value.trim();
  const project  = document.getElementById('engProject').value.trim();

  if (!customer || !project) {
    errEl.textContent = 'Customer and Project Name are required.';
    errEl.classList.remove('d-none');
    return;
  }
  errEl.classList.add('d-none');

  const id = document.getElementById('engId').value;
  const list = loadEngagements();

  const record = {
    id:         id || generateId(),
    customer,
    contract:   document.getElementById('engContract').value.trim(),
    project,
    standard:   document.getElementById('engStandard').value,
    status:     document.getElementById('engStatus').value,
    startDate:  document.getElementById('engStartDate').value,
    targetDate: document.getElementById('engTargetDate').value,
    notes:      document.getElementById('engNotes').value.trim(),
    updatedAt:  new Date().toISOString(),
  };

  if (id) {
    const idx = list.findIndex(e => e.id === id);
    if (idx !== -1) { list[idx] = record; } else { list.push(record); }
  } else {
    record.createdAt = new Date().toISOString();
    list.push(record);
  }

  saveEngagements(list);
  engagementModal.hide();
  renderEngagements();
});

// ── Engagement list actions (edit / delete) ───────────────────
document.getElementById('engagementList').addEventListener('click', e => {
  const editBtn   = e.target.closest('.btn-edit');
  const deleteBtn = e.target.closest('.btn-delete');

  if (editBtn) {
    const list = loadEngagements();
    const eng  = list.find(e => e.id === editBtn.dataset.id);
    if (eng) openModal(eng);
  }

  if (deleteBtn) {
    if (!confirm('Delete this engagement? This cannot be undone.')) return;
    const list = loadEngagements().filter(e => e.id !== deleteBtn.dataset.id);
    saveEngagements(list);
    renderEngagements();
  }
});

// ── Filters ───────────────────────────────────────────────────
document.getElementById('engSearchInput').addEventListener('input', renderEngagements);
document.getElementById('engStatusFilter').addEventListener('change', renderEngagements);

// ── Init ──────────────────────────────────────────────────────
renderEngagements();
