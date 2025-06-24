let allControls = [];
let familyMap = {};
const pieCanvas = document.getElementById('familyPieChart');

async function loadControls() {
  const response = await fetch('cmmc_controls.json');
  const controls = await response.json();
  allControls = controls;
  updateFamilyChart(controls);
}
  
function updateFamilyChart(controls) {
  familyMap = {};

  controls.forEach(control => {
    const famId = control.family_id;
    if (!familyMap[famId]) {
      familyMap[famId] = {
        family_name: control.family_name,
        compliant: 0,
        total: 0
      };
    }
    control.subs.forEach(sub => {
      familyMap[famId].total++;
      const key = `${control.id}${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(key) || '{}');
      if (stored.status === 'compliant') {
        familyMap[famId].compliant++;
      }
    });
  });

  const labels = Object.values(familyMap).map(f => f.family_name);
  const data = Object.values(familyMap).map(f =>
    f.total === 0 ? 0 : Math.round((f.compliant / f.total) * 100)
  );
  const bgColors = data.map(percent => percent === 0
    ? '#dc2626'
    : `hsl(${percent * 1.2}, 70%, 50%)`
  );

  const ctx = pieCanvas.getContext('2d');
  if (window.familyPieChart) window.familyPieChart.destroy();

  window.familyPieChart = new Chart(ctx, {
    type: 'pie',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: bgColors
      }]
    },
    options: {
      responsive: true,
      onClick: (e, elements) => {
        if (elements.length > 0) {
          const index = elements[0].index;
          const selectedFamilyName = labels[index];
          const selectedFamilyId = Object.entries(familyMap).find(
            ([, v]) => v.family_name === selectedFamilyName
          )[0];
          const filteredControls = allControls.filter(c => c.family_id === selectedFamilyId);
          document.getElementById('controlView').classList.remove('hidden');
          renderControls(filteredControls);
          window.scrollTo({ top: document.getElementById('controlView').offsetTop, behavior: 'smooth' });
        }
      }
    }
  });

  const tableBody = document.getElementById('familyTableBody');
  tableBody.innerHTML = '';
  Object.entries(familyMap).forEach(([famId, stats]) => {
    const percent = stats.total === 0 ? 0 : Math.round((stats.compliant / stats.total) * 100);
    tableBody.innerHTML += `
      <tr>
        <td class="px-4 py-2">${stats.family_name} (${famId})</td>
        <td class="text-center">${stats.compliant}</td>
        <td class="text-center">${stats.total}</td>
        <td class="text-center">${percent}%</td>
      </tr>
    `;
  });
}

function renderControls(controls) {
  const list = document.getElementById('controlsList');
  list.innerHTML = '';

  controls.forEach(control => {
    const module = document.createElement('div');
    module.className = 'control-module';

    const title = document.createElement('h4');
    title.className = 'text-green-300 text-lg font-bold mb-2';
    title.textContent = `${control.id} – ${control.title}`;
    module.appendChild(title);

    control.subs.forEach(sub => {
      const key = `${control.id}${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(key) || '{}');

      const block = document.createElement('div');
      block.className = 'mb-3 p-2 bg-gray-800 rounded';

      const label = document.createElement('div');
      label.className = 'text-sm font-semibold text-white mb-1';
      label.textContent = `${sub.id} – ${sub.title}`;
      block.appendChild(label);

      const select = document.createElement('select');
      select.className = 'mt-1 mb-2';
      select.innerHTML = `
        <option value="">Set Status</option>
        <option value="compliant">Compliant</option>
        <option value="non-compliant">Non-Compliant</option>
        <option value="in-progress">In Progress</option>
        <option value="poam">POAM</option>
      `;
      select.value = stored.status || '';
      block.appendChild(select);

      const noteInput = document.createElement('textarea');
      noteInput.placeholder = "SSP Notes / Evidence";
      noteInput.rows = 3;
      noteInput.value = stored.notes || '';
      block.appendChild(noteInput);

      select.onchange = saveAndUpdate;
      noteInput.oninput = saveAndUpdate;

      function saveAndUpdate() {
        localStorage.setItem(key, JSON.stringify({
          status: select.value,
          notes: noteInput.value
        }));
        updateFamilyChart(allControls);
        updateSSPPreview();
      }

      module.appendChild(block);
    });

    list.appendChild(module);
  });

  updateSSPPreview();
}

function updateSSPPreview() {
  const sspOutput = document.getElementById('sspOutput');
  let content = '';

  allControls.forEach(control => {
    content += `## ${control.id} – ${control.title}\n\n`;
    control.subs.forEach(sub => {
      const key = `${control.id}${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(key) || '{}');
      content += `- ${sub.id} – ${sub.title}\n`;
      content += `  - Status: ${stored.status || 'Not Set'}\n`;
      content += `  - Notes: ${stored.notes || 'N/A'}\n\n`;
    });
  });

  sspOutput.textContent = content;
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
  const sspText = document.getElementById('sspOutput').textContent;
  const blob = new Blob([sspText], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'ssp_export.txt';
  link.click();
  URL.revokeObjectURL(url);
});

loadControls();
