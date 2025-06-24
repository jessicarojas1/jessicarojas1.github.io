let allControls = [];
let familyMap = {};

async function loadControls() {
  const response = await fetch('cmmc_controls.json');
  const controls = await response.json();
  allControls = controls;
  updateFamilyChart(controls);
}

function renderControls(controls) {
  const container = document.getElementById('controlsList');
  container.innerHTML = '';

  controls.forEach(control => {
    const module = document.createElement('div');
    module.className = 'control-module';

    const title = document.createElement('h4');
    title.className = 'text-lg font-bold mb-2 text-green-300';
    title.textContent = `${control.id} – ${control.title} (${control.family_name})`;
    module.appendChild(title);

    control.subs.forEach(sub => {
      const subId = `${control.id}${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(subId) || '{}');

      const row = document.createElement('div');
      row.className = 'mb-2 p-2 rounded bg-gray-800';

      const desc = document.createElement('div');
      desc.className = 'text-sm text-white mb-1';
      desc.textContent = `${sub.id} – ${sub.title}`;
      row.appendChild(desc);

      const notes = document.createElement('textarea');
      notes.placeholder = 'Enter SSP objective/notes...';
      notes.value = stored.notes || '';
      notes.onchange = () => {
        const updated = {
          ...stored,
          notes: notes.value
        };
        localStorage.setItem(subId, JSON.stringify(updated));
        generateSSPPreview();
      };
      row.appendChild(notes);

      const status = document.createElement('select');
      status.className = 'mt-1 text-sm p-1 rounded';
      status.innerHTML = `
        <option value="">Set Status</option>
        <option value="compliant">Compliant</option>
        <option value="non-compliant">Non-Compliant</option>
        <option value="in-progress">In Progress</option>
        <option value="poam">POAM</option>
      `;
      status.value = stored.status || '';
      status.onchange = () => {
        const updated = {
          ...stored,
          status: status.value
        };
        localStorage.setItem(subId, JSON.stringify(updated));
        updateFamilyChart(allControls);
        generateSSPPreview();
      };
      row.appendChild(status);

      module.appendChild(row);
    });

    container.appendChild(module);
  });
}

function updateFamilyChart(controls) {
  familyMap = {};

  controls.forEach(control => {
    const family = control.family_id;
    if (!familyMap[family]) {
      familyMap[family] = {
        total: 0,
        compliant: 0,
        family_name: control.family_name
      };
    }

    control.subs.forEach(sub => {
      familyMap[family].total++;
      const subId = `${control.id}${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(subId) || '{}');
      if (stored.status === 'compliant') {
        familyMap[family].compliant++;
      }
    });
  });

  const labels = Object.values(familyMap).map(f => `${f.family_name} (${f.family_id})`);
  const data = Object.values(familyMap).map(v =>
    v.total === 0 ? 0 : Math.round((v.compliant / v.total) * 100)
  );
  const colors = data.map(val => val === 100 ? '#2563eb' : '#dc2626');

  const ctx = document.getElementById('familyPieChart').getContext('2d');
  if (window.familyPieChart && typeof window.familyPieChart.destroy === 'function') {
    window.familyPieChart.destroy();
  }

  window.familyPieChart = new Chart(ctx, {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{
        label: 'Compliance % by Family',
        data: data,
        backgroundColor: colors
      }]
    },
    options: {
      onClick: (e, elements) => {
        if (elements.length > 0) {
          const index = elements[0].index;
          const selectedLabel = labels[index];
          const selectedFamily = selectedLabel.match(/\((.*?)\)/)[1];
          const filtered = allControls.filter(control => control.family_id === selectedFamily);
          document.getElementById('controlView').classList.remove('hidden');
          renderControls(filtered);
          window.scrollTo({ top: document.getElementById('controlView').offsetTop, behavior: 'smooth' });
        }
      },
      responsive: true,
      animation: {
        animateRotate: true,
        animateScale: true
      }
    }
  });

  const tableBody = document.getElementById('familyTableBody');
  tableBody.innerHTML = '';
  Object.entries(familyMap).forEach(([fam, stats]) => {
    const percent = stats.total === 0 ? 0 : Math.round((stats.compliant / stats.total) * 100);
    tableBody.innerHTML += `
      <tr>
        <td class="border-t border-green-700 px-4 py-2">${stats.family_name} (${fam})</td>
        <td class="border-t border-green-700 text-center">${stats.compliant}</td>
        <td class="border-t border-green-700 text-center">${stats.total}</td>
        <td class="border-t border-green-700 text-center">${percent}%</td>
      </tr>
    `;
  });
}

function generateSSPPreview() {
  let output = '';
  allControls.forEach(control => {
    output += `\n${control.id} – ${control.title} (${control.family_name})\n`;
    control.subs.forEach(sub => {
      const subId = `${control.id}${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(subId) || '{}');
      output += `  ${sub.id} – ${sub.title}\n`;
      output += `    Status: ${stored.status || 'Not set'}\n`;
      output += `    Notes: ${stored.notes || 'None'}\n`;
    });
  });
  document.getElementById('sspOutput').textContent = output.trim();
}

document.getElementById('backToChart').addEventListener('click', () => {
  document.getElementById('controlView').classList.add('hidden');
});

document.getElementById('showAllControls').addEventListener('click', () => {
  document.getElementById('controlView').classList.remove('hidden');
  renderControls(allControls);
  window.scrollTo({ top: document.getElementById('controlView').offsetTop, behavior: 'smooth' });
});

document.getElementById('exportSSP').addEventListener('click', () => {
  const text = document.getElementById('sspOutput').textContent;
  const blob = new Blob([text], { type: 'application/msword' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'SSP_Summary.doc';
  link.click();
  URL.revokeObjectURL(url);
});

loadControls();
