/* CITADEL docs — capabilities page logic (externalized so the CSP can drop
   script-src 'unsafe-inline'). Dynamic colors are applied via the CSSOM
   (element.style.* / setProperty), which is NOT subject to style-src, instead
   of inline style="" attributes, which are. */
(function () {
  var C = window.CITADEL;
  // theme toggle
  function icon(){var t=document.documentElement.getAttribute('data-bs-theme');var i=document.querySelector('#themeToggleBtn .theme-icon');if(i)i.textContent=t==='dark'?'☀️':'🌙';}
  document.getElementById('themeToggleBtn').addEventListener('click',function(){var c=document.documentElement.getAttribute('data-bs-theme');var n=c==='dark'?'light':'dark';document.documentElement.setAttribute('data-bs-theme',n);try{localStorage.setItem('bsTheme',n);}catch(e){}icon();});icon();

  // Apply data-dot-color attributes to lang-dot backgrounds via CSSOM (CSP-safe).
  function paintDots(root){
    (root||document).querySelectorAll('.lang-dot[data-dot-color]').forEach(function(d){
      d.style.background = d.getAttribute('data-dot-color');
    });
  }

  var LANGS = C.lang.LANGS, CATS = C.lang.CATS;
  var RULES = C.rules || [];
  var SBOM_ECO = {}; // language name -> true if it has a manifest ecosystem
  // Map sbom manifest ecosystems to representative languages.
  var ecoLang = { npm:['JavaScript','TypeScript'], pypi:['Python'], maven:['Java','Kotlin','Scala','Groovy'], golang:['Go'], gem:['Ruby'], composer:['PHP'], cargo:['Rust'], nuget:['C#','F#','Visual Basic'] };
  Object.keys(ecoLang).forEach(function(e){ ecoLang[e].forEach(function(l){ SBOM_ECO[l]=true; }); });

  // language -> count of language-specific SAST rules
  var sastByLang = {}, universal = 0;
  RULES.forEach(function(r){
    if (r.langs === '*') { universal++; return; }
    (r.langs||[]).forEach(function(l){ sastByLang[l]=(sastByLang[l]||0)+1; });
  });
  var execLangs = { JavaScript:0 }; // n/a; exec handled separately

  // Stats
  document.getElementById('h-langs').textContent = C.lang.count;
  document.getElementById('s-langs').textContent = C.lang.count;
  document.getElementById('s-rules').textContent = RULES.length;
  document.getElementById('s-fw').textContent = C.frameworks.CATALOG.length;
  document.getElementById('h-fw').textContent = C.frameworks.CATALOG.length;
  var totalControls = C.frameworks.catalogTotal ? C.frameworks.catalogTotal() : 0;
  document.getElementById('s-controls').textContent = totalControls;
  document.getElementById('h-controls').textContent = totalControls;

  // Capabilities list
  var caps = [
    ['bug-fill','SAST (Static Application Security Testing)', RULES.length + ' heuristic rules + Semgrep/Bandit on the backend — injection, XSS, crypto, deserialization, SSRF, traversal, IaC misconfig and more across all code-bearing languages.'],
    ['key-fill','Secrets & credential detection','Entropy + pattern matching for API keys, tokens, private keys and passwords; Gitleaks/Trivy on the backend.'],
    ['box-seam-fill','SBOM & live CVEs','CycloneDX SBOM from 8 package ecosystems, cross-checked against OSV.dev for real CVEs; Syft/Grype/Trivy on the backend.'],
    ['cpu-fill','Executable & bytecode analysis','PE/ELF/Mach-O/WASM/DEX/Java-class detection, entropy/packing, string & capability indicators; ClamAV malware scan on the backend.'],
    ['clipboard-check-fill','Compliance mapping','Every finding mapped to the exact controls across ' + C.frameworks.CATALOG.length + ' frameworks (' + totalControls + ' controls catalogued).'],
    ['award-fill','Quality, licenses & deployment','Maintainability index, comment density, license detection (copyleft flags), and Docker/K8s/Terraform/CI deployment detection.'],
    ['robot','AI-assisted remediation','Per-finding “Explain & fix” via Claude (backend), plus a generated copy-paste fix prompt enumerating every issue.'],
    ['download','Reports & CI/CD','Report tab + downloadable HTML/JSON/SARIF/CycloneDX/POA&M/SSP/Markdown/PDF, a CLI, and a GitHub Action for code scanning.']
  ];
  document.getElementById('cap-list').innerHTML = caps.map(function(c){
    return '<div class="cap-row"><i class="bi bi-'+c[0]+'"></i><div><strong>'+c[1]+'</strong><div class="small text-body-secondary">'+c[2]+'</div></div></div>';
  }).join('');

  // Language matrix grouped by category
  var byCat = {};
  LANGS.forEach(function(l){ (byCat[l.c]=byCat[l.c]||[]).push(l); });
  var execNote = { WebAssembly:1, Java:1, Kotlin:1, Scala:1, Groovy:1, Clojure:1 }; // produce analyzable binaries/bytecode
  var html = '';
  Object.keys(CATS).forEach(function(cat){
    var list = byCat[cat]; if (!list) return;
    html += '<div class="lang-cat"><h6>'+CATS[cat].label+' · '+list.length+'</h6>';
    html += list.map(function(l){
      var badges = '';
      if (sastByLang[l.n]) badges += '<span class="mini sast" title="'+sastByLang[l.n]+' language-specific SAST rules">SAST</span>';
      if (SBOM_ECO[l.n]) badges += ' <span class="mini sbom" title="dependency manifest parsed">SBOM</span>';
      return '<span class="lang-chip"><span class="lang-dot" data-dot-color="'+(l.col||CATS[cat].color)+'"></span>'+l.n+(l.code?'':' <span class="text-body-secondary text-xxs">(data)</span>')+(badges?' '+badges:'')+'</span>';
    }).join('');
    html += '</div>';
  });
  document.getElementById('lang-matrix').innerHTML = html;

  // Executable formats
  var formats = ['PE / Windows (.exe/.dll)','ELF (Linux/Unix)','Mach-O (macOS/iOS)','Java class','WebAssembly (.wasm)','Android DEX','LLVM bitcode','Python bytecode (.pyc)','ZIP / JAR / APK / Office','7-Zip','TAR','GZIP / BZIP2 / XZ','RAR','Microsoft Cabinet','PDF','Shebang scripts'];
  document.getElementById('exec-list').innerHTML = formats.map(function(f){ return '<span class="lang-chip"><i class="bi bi-file-earmark-binary"></i> '+f+'</span>'; }).join('');

  // Weakness coverage: CWEs the engine detects (rules + control cross-walk)
  var engineCwe = new Set();
  RULES.forEach(function(r){ if (r.cwe) String(r.cwe).match(/CWE-\d+/gi)?.forEach(function(c){ engineCwe.add(c.toUpperCase()); }); });
  var MAP = C.frameworks.MAP, CATS2 = C.frameworks.CATEGORIES;
  var owaspCovered = new Set();
  Object.keys(CATS2).forEach(function(cat){
    var m = MAP[cat] || {};
    (m.cwe || []).forEach(function(c){ var mm = String(c).match(/CWE-\d+/i); if (mm) engineCwe.add(mm[0].toUpperCase()); });
    (m.owasp || []).forEach(function(o){ owaspCovered.add(String(o).split(/\s+/)[0]); });
  });
  function chips(items, isCovered){
    return items.map(function(it){
      var on = isCovered(it.id);
      return '<span class="lang-chip '+(on?'lang-chip--on':'lang-chip--off')+'"><span class="lang-dot" data-dot-color="'+(on?'#16a34a':'#6c757d')+'"></span><code class="code-xs">'+it.id+'</code> '+it.title.slice(0,30)+'</span>';
    }).join('');
  }
  var cwe25 = (C.controlCatalog.cwe && C.controlCatalog.cwe.families[0].controls) || [];
  var owasp10 = (C.controlCatalog.owasp && C.controlCatalog.owasp.families[0].controls) || [];
  var cweHit = cwe25.filter(function(c){ return engineCwe.has(c.id.toUpperCase()); }).length;
  var owHit = owasp10.filter(function(o){ return owaspCovered.has(o.id); }).length;
  document.getElementById('coverage').innerHTML =
    '<h6 class="mt-2">CWE Top 25 — <span class="text-positive">'+cweHit+' / '+cwe25.length+'</span> detected</h6><div class="mb-3">'+chips(cwe25, function(id){return engineCwe.has(id.toUpperCase());})+'</div>'+
    '<h6>OWASP Top 10 — <span class="text-positive">'+owHit+' / '+owasp10.length+'</span> covered</h6><div>'+chips(owasp10, function(id){return owaspCovered.has(id);})+'</div>'+
    '<p class="small text-body-secondary mt-2">Total distinct CWEs referenced by the engine: <strong>'+engineCwe.size+'</strong>.</p>';

  // Frameworks
  document.getElementById('fw-list').innerHTML = C.frameworks.CATALOG.map(function(f){
    var n = C.frameworks.catalog ? C.frameworks.catalog(f.id) : null;
    var tot = n ? n.families.reduce(function(a,fm){return a+(fm.controls?fm.controls.length:0);},0) : 0;
    return '<a class="lang-chip lang-chip--link" href="'+f.url+'" target="_blank" rel="noopener"><span class="badge framework-tag">'+f.tag+'</span> '+f.name+(tot?' <span class="text-body-secondary text-3xs">'+tot+'</span>':'')+'</a>';
  }).join('');

  // Paint all dynamic dot colors (lang matrix + coverage chips) via CSSOM.
  paintDots(document);
})();
