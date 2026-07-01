  (function() {
    const STORAGE_KEY = 'aitool-checklist-v1';
    const TOTAL_ITEMS = 64;

    const sections = [
      { id: 'sec1', total: 6 },
      { id: 'sec2', total: 8 },
      { id: 'sec3', total: 14 },
      { id: 'sec4', total: 6 },
      { id: 'sec5', total: 8 },
      { id: 'sec6', total: 8 },
      { id: 'sec7', total: 8 },
      { id: 'sec8', total: 6 }
    ];

    function saveState() {
      const state = {};
      document.querySelectorAll('.checklist-items input[type=checkbox]').forEach(cb => {
        state[cb.id] = cb.checked;
      });
      state['__toolName'] = document.getElementById('toolNameInput').value;
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function loadState() {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      try {
        const state = JSON.parse(raw);
        document.querySelectorAll('.checklist-items input[type=checkbox]').forEach(cb => {
          if (state[cb.id] !== undefined) cb.checked = state[cb.id];
        });
        if (state['__toolName']) {
          document.getElementById('toolNameInput').value = state['__toolName'];
        }
      } catch(e) {}
    }

    function getSectionChecked(secId) {
      const container = document.querySelector(`#${secId} .checklist-items`);
      if (!container) return 0;
      return Array.from(container.querySelectorAll('input[type=checkbox]')).filter(cb => cb.checked).length;
    }

    function updateSection(secId, total) {
      const checked = getSectionChecked(secId);
      const pct = total > 0 ? Math.round((checked / total) * 100) : 0;
      const bar = document.getElementById('bar-' + secId);
      const lbl = document.getElementById('lbl-' + secId);
      if (bar) { bar.style.width = pct + '%'; bar.setAttribute('aria-valuenow', pct); }
      if (lbl) lbl.textContent = checked + ' / ' + total;
    }

    function updateOverall() {
      let totalChecked = 0;
      sections.forEach(s => { totalChecked += getSectionChecked(s.id); });
      const pct = Math.round((totalChecked / TOTAL_ITEMS) * 100);
      const bar = document.getElementById('overallBar');
      const lbl = document.getElementById('overallCount');
      bar.style.width = pct + '%';
      bar.textContent = pct + '%';
      bar.setAttribute('aria-valuenow', pct);
      lbl.textContent = totalChecked + ' / ' + TOTAL_ITEMS + ' items complete';
      bar.className = 'progress-bar';
      if (pct >= 80) bar.classList.add('bg-success');
      else if (pct >= 50) bar.classList.add('bg-warning');
      else bar.classList.add('bg-danger');
    }

    function updateAll() {
      sections.forEach(s => updateSection(s.id, s.total));
      updateOverall();
    }

    // Event listeners
    document.querySelectorAll('.checklist-items input[type=checkbox]').forEach(cb => {
      cb.addEventListener('change', function() {
        const container = this.closest('.checklist-items');
        const secId = container.getAttribute('data-section');
        const total = parseInt(container.getAttribute('data-total'), 10);
        updateSection(secId, total);
        updateOverall();
        saveState();
      });
    });

    document.getElementById('toolNameInput').addEventListener('input', saveState);

    document.getElementById('resetBtn').addEventListener('click', function() {
      if (!confirm('Reset all checkboxes? This cannot be undone.')) return;
      document.querySelectorAll('.checklist-items input[type=checkbox]').forEach(cb => { cb.checked = false; });
      updateAll();
      saveState();
    });

    // Init
    loadState();
    updateAll();
  })();
