let allControls = [];
let familyMap = {};
let chartInstance;

async function loadControls() {
  const response = await fetch("cmmc_controls.json");
  const controls = await response.json();
  allControls = controls;
  updateFamilyChart(controls);
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
      const stored = JSON.parse(localStorage.getItem(subId) || "{}");
      if (stored.status === "compliant") {
        familyMap[familyId].compliant++;
      }
    });
  });

  const labels = Object.entries(familyMap).map(([id, info]) => `${info.family_name} (${id})`);
  const data = Object.values(familyMap).map(info => {
    return info.total > 0 ? Math.round((info.compliant / info.total) * 100) : 0;
  });
  const backgroundColors = data.map(percent =>
    percent >= 75 ? "#2563eb" : percent >= 50 ? "#facc15" : "#dc2626"
  );

  const ctx = document.getElementById("familyPieChart").getContext("2d");

  if (chartInstance) chartInstance.destroy();

  chartInstance = new Chart(ctx, {
    type: "pie",
    data: {
      labels: labels,
      datasets: [
        {
          label: "Compliance %",
          data: data,
          backgroundColor: backgroundColors,
        },
      ],
    },
    options: {
      responsive: true,
      onClick: function (e, elements) {
        if (elements.length > 0) {
          const index = elements[0].index;
          const selectedFamily = Object.keys(familyMap)[index];
          const filtered = allControls.filter(c => c.family_id === selectedFamily);
          renderControls(filtered);
          document.getElementById("controlView").classList.remove("hidden");
        }
      },
    },
  });

  updateFamilyTable();
}

function updateFamilyTable() {
  const tbody = document.getElementById("familyTableBody");
  tbody.innerHTML = "";

  Object.entries(familyMap).forEach(([id, info]) => {
    const percent = info.total === 0 ? 0 : Math.round((info.compliant / info.total) * 100);
    tbody.innerHTML += `
      <tr>
        <td>${info.family_name} (${id})</td>
        <td>${info.compliant}</td>
        <td>${info.total}</td>
        <td>${percent}%</td>
      </tr>
    `;
  });
}

function renderControls(controls) {
  const container = document.getElementById("controlsList");
  container.innerHTML = "";

  controls.forEach(control => {
    const module = document.createElement("div");
    module.className = "control-module";

    const header = document.createElement("h3");
    header.textContent = `${control.id} – ${control.title}`;
    module.appendChild(header);

    control.subcontrols.forEach(sub => {
      const subId = `${control.id}-${sub.id}`;
      const stored = JSON.parse(localStorage.getItem(subId) || "{}");

      const row = document.createElement("div");
      row.className = "subcontrol";

      const label = document.createElement("label");
      label.textContent = `${sub.id} – ${sub.title}`;
      row.appendChild(label);

      const select = document.createElement("select");
      select.innerHTML = `
        <option value="">Set Status</option>
        <option value="compliant">Compliant</option>
        <option value="non-compliant">Non-Compliant</option>
        <option value="in-progress">In Progress</option>
        <option value="poam">POAM</option>
      `;
      select.value = stored.status || "";
      select.addEventListener("change", () => {
        localStorage.setItem(subId, JSON.stringify({
          status: select.value,
          notes: notes.value
        }));
        updateFamilyChart(allControls);
      });
      row.appendChild(select);

      const notes = document.createElement("textarea");
      notes.placeholder = "SSP Notes...";
      notes.value = stored.notes || "";
      notes.rows = 3;
      notes.addEventListener("blur", () => {
        localStorage.setItem(subId, JSON.stringify({
          status: select.value,
          notes: notes.value
        }));
      });
      row.appendChild(notes);

      module.appendChild(row);
    });

    container.appendChild(module);
  });
}

document.getElementById("backToChart").addEventListener("click", () => {
  document.getElementById("controlView").classList.add("hidden");
});

document.getElementById("showAllControls").addEventListener("click", () => {
  renderControls(allControls);
  document.getElementById("controlView").classList.remove("hidden");
});

loadControls();
