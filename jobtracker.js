/* ============================================================
   jobtracker.js
   All data stored in localStorage.
   Collections: jobs, hourLogs, tasks, intakeActions, repeatables
   ============================================================ */

// ── Storage helpers ───────────────────────────────────────────
const KEYS = {
  jobs:    'jt_jobs',
  hours:   'jt_hours',
  tasks:   'jt_tasks',
  intake:  'jt_intake',
  repeats: 'jt_repeats',
};

function load(key) {
  try { return JSON.parse(localStorage.getItem(KEYS[key])) || []; }
  catch { return []; }
}

function save(key, data) {
  localStorage.setItem(KEYS[key], JSON.stringify(data));
}

function uid() {
  var arr = new Uint32Array(1);
  crypto.getRandomValues(arr);
  return `${Date.now().toString(36)}_${arr[0].toString(36).slice(0, 5)}`;
}

function escHtml(s) {
  return String(s || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function fmtDate(iso) {
  if (!iso) return '';
  const [y, m, d] = iso.split('-');
  return `${m}/${d}/${y}`;
}

function fmtMoney(n) {
  return '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Bootstrap modals ─────────────────────────────────────────
const modals = {};
['jobModal', 'hourModal', 'taskModal', 'intakeModal', 'repeatModal'].forEach(id => {
  modals[id] = new bootstrap.Modal(document.getElementById(id));
});

// ── Populate job selects ──────────────────────────────────────
function populateJobSelects() {
  const jobs = load('jobs');
  const opts = jobs.length
    ? jobs.map(j => `<option value="${j.id}">${escHtml(j.title)}</option>`).join('')
    : '<option value="" disabled>No jobs — create one first</option>';

  ['hourJobSelect', 'taskJobSelect', 'intakeJobSelect', 'repeatJobSelect'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = opts;
  });

  // Filter dropdowns
  const filterOpts = '<option value="">All Jobs</option>' +
    jobs.map(j => `<option value="${j.id}">${escHtml(j.title)}</option>`).join('');

  ['hourJobFilter', 'taskJobFilter', 'intakeJobFilter', 'repeatJobFilter'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = filterOpts;
  });
}

// ── Summary ───────────────────────────────────────────────────
function updateSummary() {
  const jobs   = load('jobs');
  const hours  = load('hours');
  const tasks  = load('tasks');

  const totalHours = hours.reduce((s, h) => s + Number(h.hours || 0), 0);

  let totalEarnings = 0;
  hours.forEach(h => {
    const job = jobs.find(j => j.id === h.jobId);
    totalEarnings += Number(h.hours || 0) * Number(job?.rate || 0);
  });

  const activeJobs = jobs.filter(j => j.status === 'active').length;
  const openTasks  = tasks.filter(t => t.status !== 'done').length;

  document.getElementById('sumTotalHours').textContent   = totalHours % 1 === 0 ? totalHours : totalHours.toFixed(2);
  document.getElementById('sumTotalEarnings').textContent = fmtMoney(totalEarnings);
  document.getElementById('sumActiveJobs').textContent   = activeJobs;
  document.getElementById('sumOpenTasks').textContent    = openTasks;
}

// ── Jobs ──────────────────────────────────────────────────────
function renderJobs() {
  const jobs     = load('jobs');
  const hours    = load('hours');
  const query    = document.getElementById('jobSearchInput').value.toLowerCase().trim();
  const statusF  = document.getElementById('jobStatusFilter').value;
  const container = document.getElementById('jobList');
  const emptyMsg  = document.getElementById('jobEmptyMsg');

  const filtered = jobs.filter(j => {
    const qOk = !query || j.title.toLowerCase().includes(query);
    const sOk = !statusF || j.status === statusF;
    return qOk && sOk;
  });

  container.querySelectorAll('.jt-job-card').forEach(el => el.remove());

  if (filtered.length === 0) {
    emptyMsg.style.display = '';
    emptyMsg.textContent   = jobs.length === 0
      ? 'No jobs yet. Click + New Job to get started.'
      : 'No jobs match your filter.';
    return;
  }
  emptyMsg.style.display = 'none';

  filtered.forEach(job => {
    const jobHours    = hours.filter(h => h.jobId === job.id).reduce((s, h) => s + Number(h.hours || 0), 0);
    const jobEarnings = jobHours * Number(job.rate || 0);
    const dateRange   = [job.startDate ? fmtDate(job.startDate) : '', job.endDate ? fmtDate(job.endDate) : ''].filter(Boolean).join(' \u2192 ');

    const card = document.createElement('div');
    card.className = 'jt-job-card';
    card.dataset.id = job.id;

    card.innerHTML = `
      <div class="jt-job-header">
        <div style="flex:1">
          <div class="jt-job-title">${escHtml(job.title)}</div>
          <div class="jt-job-meta">
            <span class="jt-badge ${job.status}">${statusLabel(job.status)}</span>
            ${job.rate ? `<span class="jt-rate">${fmtMoney(job.rate)}/hr</span>` : ''}
            ${dateRange ? `<span class="jt-dates">${escHtml(dateRange)}</span>` : ''}
            ${jobHours > 0 ? `<span class="text-secondary small">${jobHours % 1 === 0 ? jobHours : jobHours.toFixed(2)} hrs logged${job.rate ? ' · ' + fmtMoney(jobEarnings) + ' earned' : ''}</span>` : ''}
          </div>
        </div>
      </div>
      ${job.description ? `<div class="jt-job-desc">${escHtml(job.description)}</div>` : ''}
      ${job.link ? `<div class="mt-1"><a class="jt-job-link link-primary" href="${escHtml(job.link)}" target="_blank" rel="noopener noreferrer">${escHtml(job.link)}</a></div>` : ''}
      ${job.notes ? `<div class="jt-job-notes">${escHtml(job.notes)}</div>` : ''}
      <div class="jt-job-actions">
        <button class="btn btn-sm btn-outline-secondary btn-edit-job" data-id="${job.id}">Edit</button>
        <button class="btn btn-sm btn-outline-danger btn-delete-job" data-id="${job.id}">Delete</button>
      </div>
    `;
    container.appendChild(card);
  });
}

document.getElementById('addJobBtn').addEventListener('click', () => openJobModal(null));

function openJobModal(job) {
  document.getElementById('jobModalLabel').textContent = job ? 'Edit Job' : 'New Job / Contract';
  document.getElementById('jobId').value          = job?.id          ?? '';
  document.getElementById('jobTitle').value        = job?.title       ?? '';
  document.getElementById('jobStatus').value       = job?.status      ?? 'active';
  document.getElementById('jobRate').value         = job?.rate        ?? '';
  document.getElementById('jobStartDate').value    = job?.startDate   ?? '';
  document.getElementById('jobEndDate').value      = job?.endDate     ?? '';
  document.getElementById('jobLink').value         = job?.link        ?? '';
  document.getElementById('jobDescription').value  = job?.description ?? '';
  document.getElementById('jobNotes').value        = job?.notes       ?? '';
  modals.jobModal.show();
}

document.getElementById('saveJobBtn').addEventListener('click', () => {
  const title = document.getElementById('jobTitle').value.trim();
  if (!title) { alert('Job title is required.'); return; }

  const id   = document.getElementById('jobId').value;
  const list = load('jobs');

  const record = {
    id:          id || uid(),
    title,
    status:      document.getElementById('jobStatus').value,
    rate:        parseFloat(document.getElementById('jobRate').value) || 0,
    startDate:   document.getElementById('jobStartDate').value,
    endDate:     document.getElementById('jobEndDate').value,
    link:        document.getElementById('jobLink').value.trim(),
    description: document.getElementById('jobDescription').value.trim(),
    notes:       document.getElementById('jobNotes').value.trim(),
    updatedAt:   new Date().toISOString(),
  };

  if (id) {
    const idx = list.findIndex(j => j.id === id);
    if (idx !== -1) list[idx] = record; else list.push(record);
  } else {
    record.createdAt = new Date().toISOString();
    list.push(record);
  }

  save('jobs', list);
  modals.jobModal.hide();
  populateJobSelects();
  renderJobs();
  updateSummary();
});

document.getElementById('jobList').addEventListener('click', e => {
  const editBtn = e.target.closest('.btn-edit-job');
  const delBtn  = e.target.closest('.btn-delete-job');

  if (editBtn) {
    const job = load('jobs').find(j => j.id === editBtn.dataset.id);
    if (job) openJobModal(job);
  }
  if (delBtn) {
    if (!confirm('Delete this job and all associated hours/tasks? This cannot be undone.')) return;
    const id = delBtn.dataset.id;
    save('jobs',   load('jobs').filter(j => j.id !== id));
    save('hours',  load('hours').filter(h => h.jobId !== id));
    save('tasks',  load('tasks').filter(t => t.jobId !== id));
    save('intake', load('intake').filter(i => i.jobId !== id));
    save('repeats',load('repeats').filter(r => r.jobId !== id));
    populateJobSelects();
    renderAll();
    updateSummary();
  }
});

document.getElementById('jobSearchInput').addEventListener('input', renderJobs);
document.getElementById('jobStatusFilter').addEventListener('change', renderJobs);

// ── Hour Log ──────────────────────────────────────────────────
function renderHours() {
  const hours   = load('hours');
  const jobs    = load('jobs');
  const jobF    = document.getElementById('hourJobFilter').value;
  const dateFrom = document.getElementById('hourDateFrom').value;
  const dateTo   = document.getElementById('hourDateTo').value;

  const filtered = hours.filter(h => {
    const jOk = !jobF || h.jobId === jobF;
    const dFromOk = !dateFrom || h.date >= dateFrom;
    const dToOk   = !dateTo   || h.date <= dateTo;
    return jOk && dFromOk && dToOk;
  }).sort((a, b) => b.date.localeCompare(a.date));

  const tbody = document.getElementById('hourTableBody');

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-secondary text-center py-3">No hours logged matching filter.</td></tr>';
    document.getElementById('hourTotalHours').textContent   = '0';
    document.getElementById('hourTotalEarnings').textContent = '$0.00';
    return;
  }

  let totalH = 0, totalE = 0;

  tbody.innerHTML = filtered.map(h => {
    const job      = jobs.find(j => j.id === h.jobId);
    const earnings = Number(h.hours || 0) * Number(job?.rate || 0);
    totalH += Number(h.hours || 0);
    totalE += earnings;

    return `<tr data-id="${h.id}">
      <td>${fmtDate(h.date)}</td>
      <td>${escHtml(job?.title || 'Unknown')}</td>
      <td>${h.hours}</td>
      <td>${escHtml(h.description)}</td>
      <td>${job?.rate ? fmtMoney(earnings) : '—'}</td>
      <td>
        <button class="btn btn-sm btn-outline-secondary btn-edit-hour py-0 px-1" data-id="${h.id}">Edit</button>
        <button class="btn btn-sm btn-outline-danger btn-delete-hour py-0 px-1" data-id="${h.id}">Del</button>
      </td>
    </tr>`;
  }).join('');

  document.getElementById('hourTotalHours').textContent    = totalH % 1 === 0 ? totalH : totalH.toFixed(2);
  document.getElementById('hourTotalEarnings').textContent  = fmtMoney(totalE);
}

document.getElementById('addHourBtn').addEventListener('click', () => openHourModal(null));

function openHourModal(h) {
  document.getElementById('hourModalLabel').textContent = h ? 'Edit Hours' : 'Log Hours';
  document.getElementById('hourId').value          = h?.id          ?? '';
  document.getElementById('hourJobSelect').value   = h?.jobId       ?? '';
  document.getElementById('hourDate').value        = h?.date        ?? new Date().toISOString().split('T')[0];
  document.getElementById('hourHours').value       = h?.hours       ?? '';
  document.getElementById('hourDescription').value = h?.description ?? '';
  modals.hourModal.show();
}

document.getElementById('saveHourBtn').addEventListener('click', () => {
  const jobId  = document.getElementById('hourJobSelect').value;
  const date   = document.getElementById('hourDate').value;
  const hours  = parseFloat(document.getElementById('hourHours').value);
  const desc   = document.getElementById('hourDescription').value.trim();

  if (!jobId || !date || !hours || !desc) { alert('All fields are required.'); return; }

  const id   = document.getElementById('hourId').value;
  const list = load('hours');

  const record = { id: id || uid(), jobId, date, hours, description: desc, updatedAt: new Date().toISOString() };

  if (id) {
    const idx = list.findIndex(h => h.id === id);
    if (idx !== -1) list[idx] = record; else list.push(record);
  } else {
    record.createdAt = new Date().toISOString();
    list.push(record);
  }

  save('hours', list);
  modals.hourModal.hide();
  renderHours();
  updateSummary();
});

document.getElementById('hourTableBody').addEventListener('click', e => {
  const editBtn = e.target.closest('.btn-edit-hour');
  const delBtn  = e.target.closest('.btn-delete-hour');

  if (editBtn) {
    const h = load('hours').find(h => h.id === editBtn.dataset.id);
    if (h) openHourModal(h);
  }
  if (delBtn) {
    if (!confirm('Delete this hour log entry?')) return;
    save('hours', load('hours').filter(h => h.id !== delBtn.dataset.id));
    renderHours();
    updateSummary();
  }
});

['hourJobFilter', 'hourDateFrom', 'hourDateTo'].forEach(id => {
  document.getElementById(id).addEventListener('change', renderHours);
});

// ── Tasks ─────────────────────────────────────────────────────
function renderTasks() {
  const tasks   = load('tasks');
  const jobs    = load('jobs');
  const jobF    = document.getElementById('taskJobFilter').value;
  const statusF = document.getElementById('taskStatusFilter').value;
  const container = document.getElementById('taskList');
  const emptyMsg  = document.getElementById('taskEmptyMsg');

  const filtered = tasks.filter(t => {
    return (!jobF || t.jobId === jobF) && (!statusF || t.status === statusF);
  }).sort((a, b) => {
    const p = { high: 0, normal: 1, low: 2 };
    return (p[a.priority] ?? 1) - (p[b.priority] ?? 1);
  });

  container.querySelectorAll('.jt-task-card').forEach(el => el.remove());

  if (filtered.length === 0) {
    emptyMsg.style.display = '';
    emptyMsg.textContent   = tasks.length === 0 ? 'No tasks yet.' : 'No tasks match your filter.';
    return;
  }
  emptyMsg.style.display = 'none';

  const today = new Date().toISOString().split('T')[0];

  filtered.forEach(t => {
    const job   = jobs.find(j => j.id === t.jobId);
    const overdue = t.dueDate && t.dueDate < today && t.status !== 'done';

    const card = document.createElement('div');
    card.className = `jt-task-card ${t.priority} ${t.status === 'done' ? 'done' : ''}`;
    card.dataset.id = t.id;

    card.innerHTML = `
      <div class="jt-task-title">${escHtml(t.title)}</div>
      <div class="jt-task-meta">
        <span class="jt-task-status ${t.status}">${taskStatusLabel(t.status)}</span>
        <span class="text-secondary small">${escHtml(job?.title || '')}</span>
        ${t.dueDate ? `<span class="jt-task-due ${overdue ? 'overdue' : ''}">Due ${fmtDate(t.dueDate)}${overdue ? ' &#9888;' : ''}</span>` : ''}
        ${t.priority !== 'normal' ? `<span class="text-secondary small text-uppercase" style="font-size:0.7rem;">${t.priority}</span>` : ''}
      </div>
      ${t.description ? `<div class="jt-task-desc">${escHtml(t.description)}</div>` : ''}
      ${t.link ? `<div class="mt-1 small"><a class="link-primary" href="${escHtml(t.link)}" target="_blank" rel="noopener noreferrer">${escHtml(t.link)}</a></div>` : ''}
      ${t.notes ? `<div class="jt-item-notes">${escHtml(t.notes)}</div>` : ''}
      <div class="jt-task-actions">
        ${t.status !== 'done' ? `<button class="btn btn-sm btn-success btn-mark-done py-0 px-2" data-id="${t.id}">Mark Done</button>` : ''}
        <button class="btn btn-sm btn-outline-secondary btn-edit-task py-0 px-2" data-id="${t.id}">Edit</button>
        <button class="btn btn-sm btn-outline-danger btn-delete-task py-0 px-2" data-id="${t.id}">Delete</button>
      </div>
    `;
    container.appendChild(card);
  });
}

document.getElementById('addTaskBtn').addEventListener('click', () => openTaskModal(null));

function openTaskModal(t) {
  document.getElementById('taskModalLabel').textContent  = t ? 'Edit Task' : 'New Task';
  document.getElementById('taskId').value          = t?.id          ?? '';
  document.getElementById('taskJobSelect').value   = t?.jobId       ?? '';
  document.getElementById('taskTitle').value        = t?.title       ?? '';
  document.getElementById('taskStatus').value      = t?.status      ?? 'open';
  document.getElementById('taskPriority').value    = t?.priority    ?? 'normal';
  document.getElementById('taskDueDate').value     = t?.dueDate     ?? '';
  document.getElementById('taskLink').value        = t?.link        ?? '';
  document.getElementById('taskDescription').value = t?.description ?? '';
  document.getElementById('taskNotes').value       = t?.notes       ?? '';
  modals.taskModal.show();
}

document.getElementById('saveTaskBtn').addEventListener('click', () => {
  const jobId = document.getElementById('taskJobSelect').value;
  const title = document.getElementById('taskTitle').value.trim();
  if (!jobId || !title) { alert('Job and Task Title are required.'); return; }

  const id   = document.getElementById('taskId').value;
  const list = load('tasks');

  const record = {
    id: id || uid(),
    jobId, title,
    status:      document.getElementById('taskStatus').value,
    priority:    document.getElementById('taskPriority').value,
    dueDate:     document.getElementById('taskDueDate').value,
    link:        document.getElementById('taskLink').value.trim(),
    description: document.getElementById('taskDescription').value.trim(),
    notes:       document.getElementById('taskNotes').value.trim(),
    updatedAt:   new Date().toISOString(),
  };

  if (id) {
    const idx = list.findIndex(t => t.id === id);
    if (idx !== -1) list[idx] = record; else list.push(record);
  } else {
    record.createdAt = new Date().toISOString();
    list.push(record);
  }

  save('tasks', list);
  modals.taskModal.hide();
  renderTasks();
  updateSummary();
});

document.getElementById('taskList').addEventListener('click', e => {
  const doneBtn = e.target.closest('.btn-mark-done');
  const editBtn = e.target.closest('.btn-edit-task');
  const delBtn  = e.target.closest('.btn-delete-task');

  if (doneBtn) {
    const list = load('tasks');
    const idx  = list.findIndex(t => t.id === doneBtn.dataset.id);
    if (idx !== -1) { list[idx].status = 'done'; list[idx].updatedAt = new Date().toISOString(); }
    save('tasks', list);
    renderTasks();
    updateSummary();
  }
  if (editBtn) {
    const t = load('tasks').find(t => t.id === editBtn.dataset.id);
    if (t) openTaskModal(t);
  }
  if (delBtn) {
    if (!confirm('Delete this task?')) return;
    save('tasks', load('tasks').filter(t => t.id !== delBtn.dataset.id));
    renderTasks();
    updateSummary();
  }
});

['taskJobFilter', 'taskStatusFilter'].forEach(id => {
  document.getElementById(id).addEventListener('change', renderTasks);
});

// ── Intake Actions ────────────────────────────────────────────
function renderIntake() {
  const items   = load('intake');
  const jobs    = load('jobs');
  const jobF    = document.getElementById('intakeJobFilter').value;
  const container = document.getElementById('intakeList');
  const emptyMsg  = document.getElementById('intakeEmptyMsg');

  const filtered = items.filter(i => !jobF || i.jobId === jobF);
  container.querySelectorAll('.jt-intake-card').forEach(el => el.remove());

  if (filtered.length === 0) {
    emptyMsg.style.display = '';
    emptyMsg.textContent   = items.length === 0 ? 'No intake actions yet.' : 'No items match filter.';
    return;
  }
  emptyMsg.style.display = 'none';

  filtered.forEach(item => {
    const job  = jobs.find(j => j.id === item.jobId);
    const card = document.createElement('div');
    card.className = 'jt-intake-card';
    card.dataset.id = item.id;

    card.innerHTML = `
      <div class="jt-intake-title">${escHtml(item.title)}</div>
      <div class="jt-intake-meta">
        <span class="jt-task-status ${item.status}">${taskStatusLabel(item.status)}</span>
        <span class="text-secondary small">${escHtml(job?.title || '')}</span>
        ${item.dueDate ? `<span class="jt-task-due">Due ${fmtDate(item.dueDate)}</span>` : ''}
      </div>
      ${item.notes ? `<div class="jt-item-desc">${escHtml(item.notes)}</div>` : ''}
      ${item.link ? `<div class="mt-1 small"><a class="link-primary" href="${escHtml(item.link)}" target="_blank" rel="noopener noreferrer">${escHtml(item.link)}</a></div>` : ''}
      <div class="jt-item-actions">
        ${item.status !== 'done' ? `<button class="btn btn-sm btn-success btn-done-intake py-0 px-2" data-id="${item.id}">Mark Done</button>` : ''}
        <button class="btn btn-sm btn-outline-secondary btn-edit-intake py-0 px-2" data-id="${item.id}">Edit</button>
        <button class="btn btn-sm btn-outline-danger btn-delete-intake py-0 px-2" data-id="${item.id}">Delete</button>
      </div>
    `;
    container.appendChild(card);
  });
}

document.getElementById('addIntakeBtn').addEventListener('click', () => openIntakeModal(null));

function openIntakeModal(item) {
  document.getElementById('intakeModalLabel').textContent = item ? 'Edit Intake Action' : 'New Intake Action';
  document.getElementById('intakeId').value         = item?.id       ?? '';
  document.getElementById('intakeJobSelect').value  = item?.jobId    ?? '';
  document.getElementById('intakeTitle').value      = item?.title    ?? '';
  document.getElementById('intakeStatus').value     = item?.status   ?? 'pending';
  document.getElementById('intakeDueDate').value    = item?.dueDate  ?? '';
  document.getElementById('intakeLink').value       = item?.link     ?? '';
  document.getElementById('intakeNotes').value      = item?.notes    ?? '';
  modals.intakeModal.show();
}

document.getElementById('saveIntakeBtn').addEventListener('click', () => {
  const jobId = document.getElementById('intakeJobSelect').value;
  const title = document.getElementById('intakeTitle').value.trim();
  if (!jobId || !title) { alert('Job and Action Title are required.'); return; }

  const id   = document.getElementById('intakeId').value;
  const list = load('intake');

  const record = {
    id: id || uid(), jobId, title,
    status:  document.getElementById('intakeStatus').value,
    dueDate: document.getElementById('intakeDueDate').value,
    link:    document.getElementById('intakeLink').value.trim(),
    notes:   document.getElementById('intakeNotes').value.trim(),
    updatedAt: new Date().toISOString(),
  };

  if (id) {
    const idx = list.findIndex(i => i.id === id);
    if (idx !== -1) list[idx] = record; else list.push(record);
  } else {
    record.createdAt = new Date().toISOString();
    list.push(record);
  }

  save('intake', list);
  modals.intakeModal.hide();
  renderIntake();
});

document.getElementById('intakeList').addEventListener('click', e => {
  const doneBtn = e.target.closest('.btn-done-intake');
  const editBtn = e.target.closest('.btn-edit-intake');
  const delBtn  = e.target.closest('.btn-delete-intake');

  if (doneBtn) {
    const list = load('intake');
    const idx  = list.findIndex(i => i.id === doneBtn.dataset.id);
    if (idx !== -1) { list[idx].status = 'done'; list[idx].updatedAt = new Date().toISOString(); }
    save('intake', list); renderIntake();
  }
  if (editBtn) {
    const item = load('intake').find(i => i.id === editBtn.dataset.id);
    if (item) openIntakeModal(item);
  }
  if (delBtn) {
    if (!confirm('Delete this intake action?')) return;
    save('intake', load('intake').filter(i => i.id !== delBtn.dataset.id));
    renderIntake();
  }
});

document.getElementById('intakeJobFilter').addEventListener('change', renderIntake);

// ── Repeatable Items ──────────────────────────────────────────
const FREQ_LABELS = {
  daily: 'Daily', weekly: 'Weekly', biweekly: 'Bi-Weekly',
  monthly: 'Monthly', quarterly: 'Quarterly', as_needed: 'As Needed',
};

function renderRepeatables() {
  const items   = load('repeats');
  const jobs    = load('jobs');
  const jobF    = document.getElementById('repeatJobFilter').value;
  const container = document.getElementById('repeatList');
  const emptyMsg  = document.getElementById('repeatEmptyMsg');

  const filtered = items.filter(r => !jobF || r.jobId === jobF);
  container.querySelectorAll('.jt-repeat-card').forEach(el => el.remove());

  if (filtered.length === 0) {
    emptyMsg.style.display = '';
    emptyMsg.textContent   = items.length === 0 ? 'No repeatable items yet.' : 'No items match filter.';
    return;
  }
  emptyMsg.style.display = 'none';

  const today = new Date().toISOString().split('T')[0];

  filtered.forEach(r => {
    const job      = jobs.find(j => j.id === r.jobId);
    const dueSoon  = r.nextDue && r.nextDue <= addDays(today, 7) && r.nextDue >= today;
    const overdue  = r.nextDue && r.nextDue < today;

    const card = document.createElement('div');
    card.className = 'jt-repeat-card';
    card.dataset.id = r.id;

    card.innerHTML = `
      <div class="d-flex align-items-start justify-content-between gap-2">
        <div style="flex:1">
          <div class="jt-repeat-title">${escHtml(r.title)}</div>
          <div class="jt-repeat-meta">
            <span class="jt-repeat-freq">${escHtml(FREQ_LABELS[r.frequency] || r.frequency)}</span>
            <span class="text-secondary small">${escHtml(job?.title || '')}</span>
            ${r.lastDone ? `<span class="jt-dates">Last done: ${fmtDate(r.lastDone)}</span>` : ''}
            ${r.nextDue  ? `<span class="jt-repeat-next ${overdue ? 'overdue' : dueSoon ? 'due-soon' : ''}">Next: ${fmtDate(r.nextDue)}${overdue ? ' &#9888;' : dueSoon ? ' (soon)' : ''}</span>` : ''}
          </div>
          ${r.description ? `<div class="jt-item-desc">${escHtml(r.description)}</div>` : ''}
          ${r.link ? `<div class="mt-1 small"><a class="link-primary" href="${escHtml(r.link)}" target="_blank" rel="noopener noreferrer">${escHtml(r.link)}</a></div>` : ''}
          ${r.notes ? `<div class="jt-item-notes">${escHtml(r.notes)}</div>` : ''}
        </div>
      </div>
      <div class="jt-item-actions">
        <button class="btn btn-sm btn-success btn-complete-repeat py-0 px-2" data-id="${r.id}">Mark Complete</button>
        <button class="btn btn-sm btn-outline-secondary btn-edit-repeat py-0 px-2" data-id="${r.id}">Edit</button>
        <button class="btn btn-sm btn-outline-danger btn-delete-repeat py-0 px-2" data-id="${r.id}">Delete</button>
      </div>
    `;
    container.appendChild(card);
  });
}

function addDays(dateStr, n) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + n);
  return d.toISOString().split('T')[0];
}

function nextDueDate(frequency, fromDate) {
  const map = { daily: 1, weekly: 7, biweekly: 14, monthly: 30, quarterly: 91 };
  if (!map[frequency]) return '';
  return addDays(fromDate || new Date().toISOString().split('T')[0], map[frequency]);
}

document.getElementById('addRepeatBtn').addEventListener('click', () => openRepeatModal(null));

function openRepeatModal(r) {
  document.getElementById('repeatModalLabel').textContent  = r ? 'Edit Repeatable Item' : 'New Repeatable Item';
  document.getElementById('repeatId').value          = r?.id          ?? '';
  document.getElementById('repeatJobSelect').value   = r?.jobId       ?? '';
  document.getElementById('repeatTitle').value        = r?.title       ?? '';
  document.getElementById('repeatFrequency').value   = r?.frequency   ?? 'weekly';
  document.getElementById('repeatLastDone').value    = r?.lastDone    ?? '';
  document.getElementById('repeatNextDue').value     = r?.nextDue     ?? '';
  document.getElementById('repeatLink').value        = r?.link        ?? '';
  document.getElementById('repeatDescription').value = r?.description ?? '';
  document.getElementById('repeatNotes').value       = r?.notes       ?? '';
  modals.repeatModal.show();
}

document.getElementById('saveRepeatBtn').addEventListener('click', () => {
  const jobId = document.getElementById('repeatJobSelect').value;
  const title = document.getElementById('repeatTitle').value.trim();
  if (!jobId || !title) { alert('Job and Item Title are required.'); return; }

  const id   = document.getElementById('repeatId').value;
  const list = load('repeats');

  const frequency = document.getElementById('repeatFrequency').value;
  const lastDone  = document.getElementById('repeatLastDone').value;
  let   nextDue   = document.getElementById('repeatNextDue').value;
  if (!nextDue && lastDone) nextDue = nextDueDate(frequency, lastDone);

  const record = {
    id: id || uid(), jobId, title, frequency, lastDone, nextDue,
    link:        document.getElementById('repeatLink').value.trim(),
    description: document.getElementById('repeatDescription').value.trim(),
    notes:       document.getElementById('repeatNotes').value.trim(),
    updatedAt:   new Date().toISOString(),
  };

  if (id) {
    const idx = list.findIndex(r => r.id === id);
    if (idx !== -1) list[idx] = record; else list.push(record);
  } else {
    record.createdAt = new Date().toISOString();
    list.push(record);
  }

  save('repeats', list);
  modals.repeatModal.hide();
  renderRepeatables();
});

document.getElementById('repeatList').addEventListener('click', e => {
  const doneBtn = e.target.closest('.btn-complete-repeat');
  const editBtn = e.target.closest('.btn-edit-repeat');
  const delBtn  = e.target.closest('.btn-delete-repeat');

  if (doneBtn) {
    const list = load('repeats');
    const idx  = list.findIndex(r => r.id === doneBtn.dataset.id);
    if (idx !== -1) {
      const today = new Date().toISOString().split('T')[0];
      list[idx].lastDone  = today;
      list[idx].nextDue   = nextDueDate(list[idx].frequency, today);
      list[idx].updatedAt = new Date().toISOString();
    }
    save('repeats', list); renderRepeatables();
  }
  if (editBtn) {
    const r = load('repeats').find(r => r.id === editBtn.dataset.id);
    if (r) openRepeatModal(r);
  }
  if (delBtn) {
    if (!confirm('Delete this repeatable item?')) return;
    save('repeats', load('repeats').filter(r => r.id !== delBtn.dataset.id));
    renderRepeatables();
  }
});

document.getElementById('repeatJobFilter').addEventListener('change', renderRepeatables);

// ── Label helpers ─────────────────────────────────────────────
function statusLabel(s) {
  return { active: 'Active', completed: 'Completed', paused: 'Paused' }[s] || s;
}

function taskStatusLabel(s) {
  return { open: 'Open', in_progress: 'In Progress', done: 'Done', pending: 'Pending' }[s] || s;
}

// ── Render all ────────────────────────────────────────────────
function renderAll() {
  renderJobs();
  renderHours();
  renderTasks();
  renderIntake();
  renderRepeatables();
}

// ── Init ──────────────────────────────────────────────────────
populateJobSelects();
renderAll();
updateSummary();
