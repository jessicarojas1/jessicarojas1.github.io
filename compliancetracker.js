let allControls = [];
let familyMap = {};
let chartInstance;

async function loadControls() {
  try {
    const response = await fetch('cmmc_controls.json');
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    allControls = await response.json();
    updateFamilyChart(allControls);

    // Wire up search (was in HTML but never implemented)
    document.getElementById('searchInput').addEventListener('input', (e) => {
      const query = e.target.value.toLowerCase().trim();
      const filtered = allControls.filter(c =>
        c.family_name.toLowerCase().includes(query) ||
        c.family_id.toLowerCase().includes(query) ||
        c.title?.toLowerCase().includes(query)
      );
      updateFamilyChart(filtered);
    });
  } catch (err) {
    console.error('Failed to load controls:', err);
    document.querySelector('.container').insertAdjacentHTML(
      'afterbegin',
      '<p style="color:#ef4444;">⚠️ Failed to load compliance data. Ensure cmmc_controls.json is present.</p>'
    );
  }
}

function updateFamilyChart(controls) {
  familyMap = {};

  controls.forEach(control => {
    const familyId = control.family_id;
    if (!familyMap[familyId]) {
      familyMap[familyId] = { total: 0, compliant: 0, family_name: control.family_name };
    }
    control.subcontrols.forEach(sub => {
      familyMap[familyId].total++;
      const subId = `${control.id}-${sub.id}`;
      try {
        const stored = JSON.parse(localStorage.getItem(subId) || '{}');
        if (stored.status === 'compliant') familyMap[familyId].compliant++;
      } catch { /* ignore corrupt storage */ }
    });
  });

  const labels = Object.entries(familyMap).map(([id, info]) => `${info.family_name} (${id})`);
  const data = Object.values(familyMap).map(info =>
    info.total > 0 ? Math.round((info.compliant / info.total) * 100) : 0
  );
  const backgroundColors = data.map(pct =>
    pct >= 75 ? '#16a34a' : pct >= 50 ? '#facc15' : '#dc2626'
  );

  const ctx = document.getElementById('familyPieChart').getContext('2d');
  if (chartInstance) chartInstance.destroy();

  chartInstance = new Chart(ctx, {
    type: 'pie',
    data: {
      labels,
      datasets: [{ label: 'Compliance %', data, backgroundColor: backgroundColors }],
    },
    options: {
      responsive: true,
      plugins: {
        legend: { labels: { color: '#c9d1d9' } },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.parsed}% compliant`
          }
        }
      },
      onClick(_e, elements) {
        if (elements.length > 0) {
          const selectedFamily = Object.keys(familyMap)[elements[0].index];
          const filtered = allControls.filter(c => c.family_id === selectedFamily);
          renderControls(filtered);
          document.getElementById('controlView').classList.remove('hidden');
          document.getElementById('controlView').scrollIntoView({ behavior: 'smooth' });
        }
      },
    },
  });

  updateFamilyTable();
}

function updateFamilyTable() {
  const tbody = document.getElementById('familyTableBody');
  tbody.innerHTML = '';
  Object.entries(familyMap).forEach(([id, info]) => {
    const pct = info.total === 0 ? 0 : Math.round((info.compliant / info.total) * 100);
    const color = pct >= 75 ? '#16a34a' : pct >= 50 ? '#facc15' : '#dc2626';
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${info.family_name} (${id})</td>
      <td>${info.compliant}</td>
      <td>${info.total}</td>
      <td style="color:${color};font-weight:bold;">${pct}%</td>
    `;
    tbody.appendChild(row);
  });
}

function renderControls(controls) {
  const container = document.getElementById('controlsList');
  container.innerHTML = '';

  controls.forEach(control => {
    const module = document.createElement('div');
    module.className = 'control-module';

    const header = document.createElement('h3');
    header.textContent = `${control.id} – ${control.title}`;
    module.appendChild(header);

    control.subcontrols.forEach(sub => {
      const subId = `${control.id}-${sub.id}`;
      let stored = {};
      try { stored = JSON.parse(localStorage.getItem(subId) || '{}'); } catch { /* ignore */ }

      const row = document.createElement('div');
      row.className = 'subcontrol';

      const label = document.createElement('label');
      label.textContent = `${sub.id} – ${sub.title}`;
      label.htmlFor = `select-${subId}`;
      row.appendChild(label);

      const select = document.createElement('select');
      select.id = `select-${subId}`;
      select.innerHTML = `
        <option value="">Set Status</option>
        <option value="compliant">Compliant</option>
        <option value="non-compliant">Non-Compliant</option>
        <option value="in-progress">In Progress</option>
        <option value="poam">POAM</option>
      `;
      select.value = stored.status || '';

      const notes = document.createElement('textarea');
      notes.placeholder = 'SSP Notes...';
      notes.value = stored.notes || '';
      notes.rows = 3;
      notes.setAttribute('aria-label', `SSP notes for ${sub.id}`);

      const save = () => {
        try {
          localStorage.setItem(subId, JSON.stringify({ status: select.value, notes: notes.value }));
          updateFamilyChart(allControls);
        } catch (e) {
          console.warn('localStorage unavailable:', e);
        }
      };

      select.addEventListener('change', save);
      notes.addEventListener('blur', save);

      row.appendChild(select);
      row.appendChild(notes);
      module.appendChild(row);
    });

    container.appendChild(module);
  });
}

document.getElementById('backToChart').addEventListener('click', () => {
  document.getElementById('controlView').classList.add('hidden');
});

document.getElementById('showAllControls').addEventListener('click', () => {
  renderControls(allControls);
  document.getElementById('controlView').classList.remove('hidden');
  document.getElementById('controlView').scrollIntoView({ behavior: 'smooth' });
});

loadControls();
