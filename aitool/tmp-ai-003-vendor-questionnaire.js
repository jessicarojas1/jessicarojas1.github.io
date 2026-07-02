  (function(){
    const STORE = 'aitool-vendor-q-v1';
    const certs = [
      {key:'cert_soc2',  label:'SOC 2 Type II'},
      {key:'cert_iso',   label:'ISO 27001'},
      {key:'cert_fedramp_mod', label:'FedRAMP Moderate'},
      {key:'cert_fedramp_hi',  label:'FedRAMP High'},
      {key:'cert_stateramp',   label:'StateRAMP'},
      {key:'cert_pci',   label:'PCI-DSS'},
      {key:'cert_cmmc',  label:'CMMC Level 2+'},
    ];

    // Build cert rows
    const certContainer = document.getElementById('certRows');
    certs.forEach(c => {
      certContainer.innerHTML += `
        <div class="row g-2 align-items-center mb-2 border-bottom pb-2">
          <div class="col-md-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="chk_${c.key}" data-key="chk_${c.key}">
              <label class="form-check-label fw-semibold small" for="chk_${c.key}">${c.label}</label>
            </div>
          </div>
          <div class="col-md-3"><input class="form-control form-control-sm" data-key="${c.key}_date" placeholder="Cert Date (YYYY-MM-DD)" /></div>
          <div class="col-md-3"><input class="form-control form-control-sm" data-key="${c.key}_expiry" placeholder="Expiry Date (YYYY-MM-DD)" /></div>
          <div class="col-md-3">
            <label class="upload-label"><span>📎</span> Upload Cert <input type="file" accept=".pdf,.png,.jpg" class="d-none" data-filekey="${c.key}_file" /></label>
            <span class="file-name-badge" data-filelabel="${c.key}_file"></span>
          </div>
        </div>`;
    });

    // Load / save helpers
    function getStore(){ try{ return JSON.parse(localStorage.getItem(STORE)||'{}'); } catch(e){ return {}; } }
    function saveStore(obj){ localStorage.setItem(STORE, JSON.stringify(obj)); }

    function loadForm(){
      const d = getStore();
      document.querySelectorAll('[data-key]').forEach(el => {
        const v = d[el.dataset.key];
        if(v === undefined) return;
        if(el.type==='checkbox') el.checked = !!v;
        else el.value = v || '';
      });
      // file labels
      document.querySelectorAll('[data-filelabel]').forEach(el => {
        const fn = d['_file_' + el.dataset.filelabel];
        if(fn) el.textContent = fn;
      });
    }

    function saveForm(){
      const d = getStore();
      document.querySelectorAll('[data-key]').forEach(el => {
        if(el.type==='checkbox') d[el.dataset.key] = el.checked;
        else d[el.dataset.key] = el.value;
      });
      saveStore(d);
      updateProgress();
    }

    function updateProgress(){
      const inputs = document.querySelectorAll('[data-key]:not([type=checkbox])');
      let filled = 0;
      inputs.forEach(el => { if(el.value && el.value.trim()) filled++; });
      const pct = Math.round((filled / inputs.length) * 100);
      document.getElementById('progressFill').style.width = pct + '%';
      document.getElementById('progressLabel').textContent = pct + '%';
    }

    // File input handling
    document.addEventListener('change', function(e){
      if(e.target.type === 'file' && e.target.dataset.filekey){
        const fn = e.target.files[0] ? e.target.files[0].name : '';
        const label = document.querySelector('[data-filelabel="' + e.target.dataset.filekey + '"]');
        if(label) label.textContent = fn;
        const d = getStore();
        d['_file_' + e.target.dataset.filekey] = fn;
        saveStore(d);
      }
      if(e.target.dataset.key) saveForm();
    });

    document.getElementById('clearBtn').addEventListener('click', function(){
      if(!confirm('Clear all form data?')) return;
      localStorage.removeItem(STORE);
      document.querySelectorAll('[data-key]').forEach(el => {
        if(el.type==='checkbox') el.checked = false;
        else el.value = '';
      });
      document.querySelectorAll('[data-filelabel]').forEach(el => el.textContent = '');
      updateProgress();
    });

    loadForm();
    updateProgress();
  })();
