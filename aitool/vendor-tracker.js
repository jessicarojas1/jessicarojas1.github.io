  (function(){
    const STORE = 'aitool-tracker-v1';
    const PHASES = [
      {id:'1', label:'Phase 1 — Request Submitted', colorClass:''},
      {id:'2', label:'Phase 2 — Regulatory Screening', colorClass:''},
      {id:'3', label:'Phase 3 — Vendor Assessment', colorClass:''},
      {id:'4', label:'Phase 4 — Risk Assessment & Checklist', colorClass:''},
      {id:'5', label:'Phase 5 — Legal & Approval', colorClass:''},
      {id:'6', label:'Phase 6 — Approved / Active', colorClass:'col-approved'},
      {id:'rejected', label:'Rejected', colorClass:'col-rejected'},
    ];
    const CLASS_BADGE = {
      'Non-CUI':'badge-noncui',
      'CUI':'badge-cui',
      'CDI':'badge-cdi',
      'ITAR-Controlled':'badge-itar',
    };
    const SEED = [
      {id:'seed1', tool:'Copilot Enterprise', vendor:'Microsoft', sponsor:'Engineering Dept', classification:'CUI', programs:'FA8621-24-C-0001', isso:'J. Smith', phase:'3', date:'2025-03-10', notes:'Awaiting vendor questionnaire response.', docs:{checklist:true,risk:true,vendorq:false,legal:false,approval:false}, files:{}},
      {id:'seed2', tool:'Gemini for Workspace', vendor:'Google', sponsor:'Program Office', classification:'Non-CUI', programs:'Internal Only', isso:'M. Torres', phase:'5', date:'2025-02-01', notes:'Legal review in progress. DPA draft received.', docs:{checklist:true,risk:true,vendorq:true,legal:false,approval:false}, files:{}},
      {id:'seed3', tool:'ChatGPT Enterprise', vendor:'OpenAI', sponsor:'Contracts Team', classification:'Non-CUI', programs:'Proposals', isso:'J. Smith', phase:'6', date:'2025-01-15', notes:'Approved for non-CUI proposal writing only.', docs:{checklist:true,risk:true,vendorq:true,legal:true,approval:true}, files:{}},
    ];

    function getData(){ try{ const d=JSON.parse(localStorage.getItem(STORE)||'null'); return d || JSON.parse(JSON.stringify(SEED)); } catch(e){ return JSON.parse(JSON.stringify(SEED)); } }
    function saveData(d){ localStorage.setItem(STORE, JSON.stringify(d)); }

    function render(){
      const data = getData();
      const search = document.getElementById('searchInput').value.trim().toLowerCase();
      const filterCls = document.getElementById('filterClass').value;
      const filtered = data.filter(e => {
        const matchSearch = !search || e.tool.toLowerCase().includes(search) || e.vendor.toLowerCase().includes(search);
        const matchCls = !filterCls || e.classification === filterCls;
        return matchSearch && matchCls;
      });

      // Stats
      document.getElementById('statTotal').textContent = data.length;
      document.getElementById('statInProgress').textContent = data.filter(e=>e.phase!=='6'&&e.phase!=='rejected').length;
      document.getElementById('statApproved').textContent = data.filter(e=>e.phase==='6').length;
      document.getElementById('statRejected').textContent = data.filter(e=>e.phase==='rejected').length;

      // Board
      const board = document.getElementById('board');
      board.innerHTML = '';
      PHASES.forEach(ph => {
        const cards = filtered.filter(e => e.phase === ph.id);
        const col = document.createElement('div');
        col.className = 'board-col';
        col.innerHTML = `<div class="col-header ${ph.colorClass}">${ph.label} <span class="badge bg-secondary ms-1">${cards.length}</span></div><div class="col-cards" id="col_${ph.id}"></div>`;
        board.appendChild(col);
        const colCards = col.querySelector('.col-cards');
        cards.forEach(e => {
          const badgeCls = CLASS_BADGE[e.classification] || 'badge-noncui';
          const docKeys = ['checklist','risk','vendorq','legal','approval'];
          const docLabels = ['Checklist','Risk Assess.','Vendor Q','Legal','Approval'];
          const dots = docKeys.map((k,i)=>`<span class="doc-dot ${e.docs&&e.docs[k]?'done':''}" title="${docLabels[i]}"></span>`).join('');
          const card = document.createElement('div');
          card.className = 'eval-card';
          card.innerHTML = `
            <div class="tool-name">${e.tool}</div>
            <div class="vendor-name">${e.vendor}</div>
            <span class="badge ${badgeCls} mb-1" style="font-size:0.68rem">${e.classification}</span>
            ${e.isso ? `<div class="text-secondary" style="font-size:0.72rem">ISSO: ${e.isso}</div>` : ''}
            ${e.date ? `<div class="text-secondary" style="font-size:0.72rem">${e.date}</div>` : ''}
            <div class="doc-dots">${dots}</div>
          `;
          card.addEventListener('click', ()=>openEdit(e.id));
          colCards.appendChild(card);
        });
      });
    }

    function openEdit(id){
      const data = getData();
      const e = data.find(x=>x.id===id);
      if(!e) return;
      document.getElementById('evalModalLabel').textContent = 'Edit Evaluation';
      document.getElementById('editId').value = id;
      document.getElementById('f_tool').value = e.tool||'';
      document.getElementById('f_vendor').value = e.vendor||'';
      document.getElementById('f_sponsor').value = e.sponsor||'';
      document.getElementById('f_class').value = e.classification||'';
      document.getElementById('f_programs').value = e.programs||'';
      document.getElementById('f_isso').value = e.isso||'';
      document.getElementById('f_phase').value = e.phase||'1';
      document.getElementById('f_date').value = e.date||'';
      document.getElementById('f_notes').value = e.notes||'';
      const docs = e.docs||{};
      document.getElementById('doc_checklist').checked = !!docs.checklist;
      document.getElementById('doc_risk').checked = !!docs.risk;
      document.getElementById('doc_vendorq').checked = !!docs.vendorq;
      document.getElementById('doc_legal').checked = !!docs.legal;
      document.getElementById('doc_approval').checked = !!docs.approval;
      const files = e.files||{};
      ['checklist','risk','vendorq','approval'].forEach(k=>{
        document.getElementById('fname_'+k).textContent = files[k]||'';
      });
      document.getElementById('deleteBtn').classList.remove('d-none');
      const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('evalModal'));
      modal.show();
    }

    document.getElementById('newEvalBtn').addEventListener('click', function(){
      document.getElementById('evalModalLabel').textContent = 'New Evaluation';
      document.getElementById('editId').value = '';
      ['f_tool','f_vendor','f_sponsor','f_class','f_programs','f_isso','f_notes'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.value='';
      });
      document.getElementById('f_phase').value = '1';
      document.getElementById('f_date').value = new Date().toISOString().slice(0,10);
      ['checklist','risk','vendorq','legal','approval'].forEach(k=>{ document.getElementById('doc_'+k).checked=false; document.getElementById('fname_'+k).textContent=''; });
      document.getElementById('deleteBtn').classList.add('d-none');
    });

    document.getElementById('saveEvalBtn').addEventListener('click', function(){
      const tool = document.getElementById('f_tool').value.trim();
      const vendor = document.getElementById('f_vendor').value.trim();
      const cls = document.getElementById('f_class').value;
      if(!tool||!vendor||!cls){ alert('Tool Name, Vendor Name, and Classification are required.'); return; }
      const data = getData();
      const id = document.getElementById('editId').value || String(Date.now());
      const files = {};
      ['checklist','risk','vendorq','approval'].forEach(k=>{
        const fn = document.getElementById('fname_'+k).textContent;
        if(fn) files[k]=fn;
      });
      const entry = {
        id, tool, vendor,
        sponsor: document.getElementById('f_sponsor').value.trim(),
        classification: cls,
        programs: document.getElementById('f_programs').value.trim(),
        isso: document.getElementById('f_isso').value.trim(),
        phase: document.getElementById('f_phase').value,
        date: document.getElementById('f_date').value,
        notes: document.getElementById('f_notes').value.trim(),
        docs:{
          checklist: document.getElementById('doc_checklist').checked,
          risk: document.getElementById('doc_risk').checked,
          vendorq: document.getElementById('doc_vendorq').checked,
          legal: document.getElementById('doc_legal').checked,
          approval: document.getElementById('doc_approval').checked,
        },
        files
      };
      const idx = data.findIndex(x=>x.id===id);
      if(idx>=0) data[idx]=entry; else data.push(entry);
      saveData(data);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('evalModal')).hide();
      render();
    });

    document.getElementById('deleteBtn').addEventListener('click', function(){
      const id = document.getElementById('editId').value;
      if(!id||!confirm('Delete this evaluation?')) return;
      let data = getData();
      data = data.filter(x=>x.id!==id);
      saveData(data);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('evalModal')).hide();
      render();
    });

    // File inputs
    ['checklist','risk','vendorq','approval'].forEach(k=>{
      document.getElementById('file_'+k).addEventListener('change', function(){
        const fn = this.files[0]?this.files[0].name:'';
        document.getElementById('fname_'+k).textContent = fn;
      });
    });

    document.getElementById('searchInput').addEventListener('input', render);
    document.getElementById('filterClass').addEventListener('change', render);

    document.getElementById('exportBtn').addEventListener('click', function(){
      const data = getData();
      const blob = new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'ai-tool-evaluations-' + new Date().toISOString().slice(0,10) + '.json';
      a.click();
    });

    render();
  })();
