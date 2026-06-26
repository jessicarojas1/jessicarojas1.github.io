/* CITADEL docs — architecture page logic (externalized so the CSP can drop
   script-src 'unsafe-inline'). */
(function () {
  // Theme toggle
  function icon(){var t=document.documentElement.getAttribute('data-bs-theme');var i=document.querySelector('#themeToggleBtn .theme-icon');if(i)i.textContent=t==='dark'?'☀️':'🌙';}
  document.getElementById('themeToggleBtn').addEventListener('click',function(){var c=document.documentElement.getAttribute('data-bs-theme');var n=c==='dark'?'light':'dark';document.documentElement.setAttribute('data-bs-theme',n);try{localStorage.setItem('bsTheme',n);}catch(e){}icon();});
  icon();

  var F = window.CITADEL.frameworks;
  var R = window.CITADEL.rules;

  // Module catalog (static descriptions; rule count pulled live)
  var modules = [
    ['Ingest Engine','ingest.js','Intake','Expands archives (JSZip), skips build/vendor dirs, sniffs text vs. binary, normalizes everything to entries.'],
    ['Language Classifier','languages.js','Classify','Maps 70+ extensions & special filenames to languages, marks which are code-bearing, supplies chart colors.'],
    ['SAST Rules Engine','rules.js + scanner.js','Analyze','Runs '+R.length+' heuristic rules (regex, language-aware) for injection, XSS, crypto, deserialization, SSRF, traversal, config & more.'],
    ['Secrets Scanner','secrets.js','Analyze','Shannon-entropy + keyword heuristics to surface hardcoded credentials, tokens and keys the regex rules miss.'],
    ['SBOM &amp; Dependency Analyzer','sbom.js','Analyze','Parses npm/PyPI/Maven/Go/Gem/Composer/Cargo/NuGet manifests, flags unpinned/pre-release deps, emits CycloneDX 1.5.'],
    ['Binary / Executable Analyzer','binary.js','Analyze','Detects PE/ELF/Mach-O, computes entropy (packing), extracts strings, flags suspicious capability indicators.'],
    ['Quality &amp; Maintainability','scanner.js','Measure','LOC, comment ratio, oversized files, and a 0–100 maintainability index.'],
    ['Deployment &amp; IaC Detector','scanner.js','Measure','Infers how the project ships: Docker, K8s, Helm, Terraform, Bicep, CI/CD, PaaS blueprints.'],
    ['Scoring &amp; Grading Engine','scanner.js','Score','Severity-weighted, volume-normalized security score, maintainability, and an A–F grade.'],
    ['Compliance Mapping Engine','frameworks.js','Map','Cross-walks each finding category to concrete control IDs across every framework; computes per-framework posture.'],
    ['Report &amp; Export Engine','report.js','Present','Scorecard, Chart.js visuals, finding cards, compliance posture; exports JSON, CycloneDX SBOM, Markdown, print/PDF.']
  ];
  document.getElementById('modules-list').innerHTML = modules.map(function(m){
    return '<div class="mod-card"><h5><i class="bi bi-puzzle"></i> '+m[0]+' <span class="badge bg-secondary">'+m[2]+'</span></h5>'+
      '<div class="small text-body-secondary mb-1"><code>'+m[1]+'</code></div><p class="mb-0 small">'+m[3]+'</p></div>';
  }).join('');

  // Frameworks
  document.getElementById('fw-count').textContent = F.CATALOG.length;
  document.getElementById('frameworks-list').innerHTML = F.CATALOG.map(function(f){
    return '<div class="col-md-6"><div class="mod-card"><h5 class="mod-card-h5--sm"><a href="'+f.url+'" target="_blank" rel="noopener">'+f.name+'</a> <span class="pill">'+f.version+'</span> <span class="badge bg-secondary ms-auto">'+f.tag+'</span></h5><p class="mb-0 small text-body-secondary">'+f.desc+'</p></div></div>';
  }).join('');

  // Categories
  document.getElementById('categories-list').innerHTML = Object.keys(F.CATEGORIES).map(function(k){
    return '<span class="pill" title="'+k+'">'+F.CATEGORIES[k]+'</span>';
  }).join('');
})();
