/* CITADEL — Language Classifier (enterprise coverage)
 * Single source of truth for every language/format CITADEL recognizes, with
 * category, whether it is code-bearing (SAST/LOC eligible), color, and the
 * extensions/filenames that identify it. Derived maps power detection, the
 * capabilities document, and charts. window.CITADEL.lang
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // Category → display label + color (used for the doc + chart fallback).
  const CATS = {
    systems:   { label: 'Systems & Compiled', color: '#f34b7d' },
    jvm:       { label: 'JVM',                color: '#b07219' },
    dotnet:    { label: '.NET',               color: '#178600' },
    web:       { label: 'Web & Frontend',     color: '#e34c26' },
    scripting: { label: 'Scripting',          color: '#3572A5' },
    shell:     { label: 'Shell & Automation', color: '#89e051' },
    functional:{ label: 'Functional',         color: '#5e8b7e' },
    mobile:    { label: 'Mobile',             color: '#F05138' },
    data:      { label: 'Data & Scientific',  color: '#198CE7' },
    query:     { label: 'Query & Database',   color: '#e38c00' },
    legacy:    { label: 'Legacy & Enterprise',color: '#6e6e6e' },
    hdl:       { label: 'Hardware (HDL)',     color: '#b2b7f8' },
    contract:  { label: 'Smart Contracts',    color: '#AA6746' },
    shader:    { label: 'Game & Shaders',     color: '#7b42bc' },
    config:    { label: 'Config & Markup',    color: '#cb171e' },
    iac:       { label: 'IaC & DevOps',       color: '#844FBA' },
    policy:    { label: 'Policy as Code',     color: '#7c4dff' },
    template:  { label: 'Templating',         color: '#e9711c' },
    proof:     { label: 'Proof & Verification',color: '#2e7d6f' },
    doc:       { label: 'Docs & Data',        color: '#083fa1' }
  };

  // The master catalog. n=name, c=category, code=SAST/LOC-eligible, x=extensions,
  // col=optional brand color (else category color), fn=special filenames.
  const LANGS = [
    // ── Systems & compiled ──
    { n:'C', c:'systems', code:true, x:['c','h'], col:'#555555' },
    { n:'C++', c:'systems', code:true, x:['cpp','cc','cxx','c++','hpp','hh','hxx','tcc','ipp'], col:'#f34b7d' },
    { n:'Objective-C', c:'systems', code:true, x:['m','mm'], col:'#438eff' },
    { n:'Go', c:'systems', code:true, x:['go'], col:'#00ADD8' },
    { n:'Rust', c:'systems', code:true, x:['rs'], col:'#dea584' },
    { n:'Zig', c:'systems', code:true, x:['zig'], col:'#ec915c' },
    { n:'D', c:'systems', code:true, x:['d','di'], col:'#ba595e' },
    { n:'Nim', c:'systems', code:true, x:['nim','nims'], col:'#ffc200' },
    { n:'Crystal', c:'systems', code:true, x:['cr'], col:'#000100' },
    { n:'V', c:'systems', code:true, x:['v','vsh'], col:'#4f87c4' },
    { n:'Ada', c:'systems', code:true, x:['adb','ads','ada'], col:'#02f88c' },
    { n:'Assembly', c:'systems', code:true, x:['asm','s','nasm'], col:'#6E4C13' },
    // ── JVM ──
    { n:'Java', c:'jvm', code:true, x:['java','jsp','jav'], col:'#b07219' },
    { n:'Kotlin', c:'jvm', code:true, x:['kt','kts','ktm'], col:'#A97BFF' },
    { n:'Scala', c:'jvm', code:true, x:['scala','sc'], col:'#c22d40' },
    { n:'Groovy', c:'jvm', code:true, x:['groovy','gvy','gy'], col:'#4298b8' },
    { n:'Clojure', c:'jvm', code:true, x:['clj','cljs','cljc','edn'], col:'#db5855' },
    // ── .NET ──
    { n:'C#', c:'dotnet', code:true, x:['cs','csx','cshtml'], col:'#178600' },
    { n:'F#', c:'dotnet', code:true, x:['fs','fsi','fsx'], col:'#b845fc' },
    { n:'Visual Basic', c:'dotnet', code:true, x:['vb','bas'], col:'#945db7' },
    { n:'Razor', c:'dotnet', code:true, x:['razor'], col:'#512bd4' },
    // ── Web & frontend ──
    { n:'JavaScript', c:'web', code:true, x:['js','mjs','cjs','jsx'], col:'#f1e05a' },
    { n:'TypeScript', c:'web', code:true, x:['ts','tsx','mts','cts'], col:'#3178c6' },
    { n:'HTML', c:'web', code:true, x:['html','htm','xhtml'], col:'#e34c26' },
    { n:'CSS', c:'web', code:false, x:['css'], col:'#563d7c' },
    { n:'SCSS', c:'web', code:false, x:['scss','sass'], col:'#c6538c' },
    { n:'Less', c:'web', code:false, x:['less'], col:'#1d365d' },
    { n:'Vue', c:'web', code:true, x:['vue'], col:'#41b883' },
    { n:'Svelte', c:'web', code:true, x:['svelte'], col:'#ff3e00' },
    { n:'Astro', c:'web', code:true, x:['astro'], col:'#ff5d01' },
    { n:'CoffeeScript', c:'web', code:true, x:['coffee'], col:'#244776' },
    { n:'Elm', c:'web', code:true, x:['elm'], col:'#60B5CC' },
    { n:'Dart', c:'web', code:true, x:['dart'], col:'#00B4AB' },
    { n:'WebAssembly', c:'web', code:true, x:['wat','wasm'], col:'#04133b' },
    { n:'PureScript', c:'web', code:true, x:['purs'], col:'#1D222D' },
    // ── Scripting ──
    { n:'Python', c:'scripting', code:true, x:['py','pyw','pyi','pyx','pxd'], col:'#3572A5' },
    { n:'Ruby', c:'scripting', code:true, x:['rb','erb','rake','gemspec','ru'], col:'#701516' },
    { n:'PHP', c:'scripting', code:true, x:['php','phtml','php3','php4','php5','php7','phps'], col:'#4F5D95' },
    { n:'Perl', c:'scripting', code:true, x:['pl','pm','t','pod'], col:'#0298c3' },
    { n:'Raku', c:'scripting', code:true, x:['raku','rakumod','p6','pl6'], col:'#0000fb' },
    { n:'Lua', c:'scripting', code:true, x:['lua'], col:'#000080' },
    { n:'Tcl', c:'scripting', code:true, x:['tcl','tk'], col:'#e4cc98' },
    { n:'Hack', c:'scripting', code:true, x:['hack','hh'], col:'#878787' },
    { n:'Haxe', c:'scripting', code:true, x:['hx','hxsl'], col:'#df7900' },
    // ── Shell & automation ──
    { n:'Shell', c:'shell', code:true, x:['sh','bash','zsh','ksh','fish','ash'], col:'#89e051' },
    { n:'PowerShell', c:'shell', code:true, x:['ps1','psm1','psd1'], col:'#012456' },
    { n:'Batch', c:'shell', code:true, x:['bat','cmd'], col:'#C1F12E' },
    { n:'AWK', c:'shell', code:true, x:['awk'], col:'#c30e9b' },
    { n:'Makefile', c:'shell', code:true, x:['mk','mak'], col:'#427819' },
    { n:'Just', c:'shell', code:true, x:['just'], col:'#384d54' },
    // ── Functional ──
    { n:'Haskell', c:'functional', code:true, x:['hs','lhs'], col:'#5e5086' },
    { n:'OCaml', c:'functional', code:true, x:['ml','mli'], col:'#3be133' },
    { n:'Erlang', c:'functional', code:true, x:['erl','hrl'], col:'#B83998' },
    { n:'Elixir', c:'functional', code:true, x:['ex','exs','eex','leex','heex'], col:'#6e4a7e' },
    { n:'Scheme', c:'functional', code:true, x:['scm','ss'], col:'#1e4aec' },
    { n:'Racket', c:'functional', code:true, x:['rkt'], col:'#3c5caa' },
    { n:'Common Lisp', c:'functional', code:true, x:['lisp','lsp','cl','el'], col:'#3fb68b' },
    { n:'F* ', c:'functional', code:true, x:['fst'], col:'#572e30' },
    { n:'Idris', c:'functional', code:true, x:['idr'], col:'#b30000' },
    { n:'Reason', c:'functional', code:true, x:['re','rei'], col:'#ff5847' },
    // ── Mobile ──
    { n:'Swift', c:'mobile', code:true, x:['swift'], col:'#F05138' },
    // ── Data & scientific ──
    { n:'R', c:'data', code:true, x:['r','rmd'], col:'#198CE7' },
    { n:'Julia', c:'data', code:true, x:['jl'], col:'#a270ba' },
    { n:'MATLAB', c:'data', code:true, x:['mat'], col:'#e16737' },
    { n:'SAS', c:'data', code:true, x:['sas'], col:'#B34936' },
    { n:'Stata', c:'data', code:true, x:['do','ado'], col:'#1a5f91' },
    { n:'Jupyter Notebook', c:'data', code:true, x:['ipynb'], col:'#DA5B0B' },
    // ── Query & database ──
    { n:'SQL', c:'query', code:true, x:['sql','ddl','dml'], col:'#e38c00' },
    { n:'PL/SQL', c:'query', code:true, x:['pls','plsql','pkb','pks'], col:'#dad8d8' },
    { n:'T-SQL', c:'query', code:true, x:['tsql'], col:'#e38c00' },
    { n:'GraphQL', c:'query', code:true, x:['graphql','gql'], col:'#e10098' },
    { n:'SPARQL', c:'query', code:true, x:['rq','sparql'], col:'#0c479c' },
    { n:'Cypher', c:'query', code:true, x:['cypher','cyp'], col:'#008cc1' },
    // ── Legacy & enterprise ──
    { n:'COBOL', c:'legacy', code:true, x:['cob','cbl','cpy','cobol'], col:'#005ca5' },
    { n:'Fortran', c:'legacy', code:true, x:['f','for','f90','f95','f03','f08','ftn'], col:'#4d41b1' },
    { n:'Pascal', c:'legacy', code:true, x:['pas','pp','dpr','dfm'], col:'#E3F171' },
    { n:'Ada', c:'legacy', code:true, x:[], col:'#02f88c' },
    { n:'ABAP', c:'legacy', code:true, x:['abap'], col:'#E8274B' },
    { n:'PL/I', c:'legacy', code:true, x:['pli','pl1'], col:'#6e6e6e' },
    { n:'RPG', c:'legacy', code:true, x:['rpgle','sqlrpgle'], col:'#6e6e6e' },
    { n:'VBScript', c:'legacy', code:true, x:['vbs','wsf','hta'], col:'#15dcdc' },
    { n:'ColdFusion', c:'legacy', code:true, x:['cfm','cfc'], col:'#ed2cd6' },
    { n:'Smalltalk', c:'legacy', code:true, x:['st'], col:'#596706' },
    { n:'Prolog', c:'legacy', code:true, x:['pro','prolog'], col:'#74283c' },
    { n:'Forth', c:'legacy', code:true, x:['fth','4th'], col:'#341708' },
    { n:'APL', c:'legacy', code:true, x:['apl','dyalog'], col:'#5A8164' },
    // ── Hardware (HDL) ──
    { n:'Verilog', c:'hdl', code:true, x:['v','vh'], col:'#b2b7f8' },
    { n:'SystemVerilog', c:'hdl', code:true, x:['sv','svh'], col:'#DAE1C2' },
    { n:'VHDL', c:'hdl', code:true, x:['vhd','vhdl'], col:'#adb2cb' },
    { n:'TLA+', c:'hdl', code:true, x:['tla'], col:'#4b0079' },
    // ── Smart contracts ──
    { n:'Solidity', c:'contract', code:true, x:['sol'], col:'#AA6746' },
    { n:'Vyper', c:'contract', code:true, x:['vy'], col:'#2980b9' },
    { n:'Move', c:'contract', code:true, x:['move'], col:'#4a90d9' },
    { n:'Cairo', c:'contract', code:true, x:['cairo'], col:'#ff4a11' },
    // ── Game & shaders ──
    { n:'GLSL', c:'shader', code:true, x:['glsl','vert','frag','geom','comp'], col:'#5686a5' },
    { n:'HLSL', c:'shader', code:true, x:['hlsl','fx','cginc'], col:'#aace60' },
    { n:'Metal', c:'shader', code:true, x:['metal'], col:'#8f14e9' },
    { n:'GDScript', c:'shader', code:true, x:['gd'], col:'#355570' },
    // ── Config & markup ──
    { n:'JSON', c:'config', code:false, x:['json','json5','jsonc','geojson','webmanifest'], col:'#cbcb41' },
    { n:'YAML', c:'config', code:false, x:['yaml','yml'], col:'#cb171e' },
    { n:'TOML', c:'config', code:false, x:['toml'], col:'#9c4221' },
    { n:'XML', c:'config', code:false, x:['xml','xsd','xsl','xslt','svg','plist','wsdl'], col:'#0060ac' },
    { n:'INI', c:'config', code:false, x:['ini','cfg','conf','properties','env'], col:'#6e6e6e' },
    { n:'Protobuf', c:'config', code:true, x:['proto'], col:'#e9573f' },
    { n:'Thrift', c:'config', code:true, x:['thrift'], col:'#D12127' },
    { n:'Avro', c:'config', code:false, x:['avdl','avsc'], col:'#0060ac' },
    { n:'CSV', c:'config', code:false, x:['csv','tsv'], col:'#237346' },
    // ── IaC & DevOps ──
    { n:'Terraform', c:'iac', code:true, x:['tf','tfvars','hcl'], col:'#7B42BC' },
    { n:'Bicep', c:'iac', code:true, x:['bicep'], col:'#519aba' },
    { n:'Dockerfile', c:'iac', code:true, x:['dockerfile'], col:'#384d54' },
    { n:'Pulumi', c:'iac', code:true, x:['pp'], col:'#8A3391' },
    { n:'Nix', c:'iac', code:true, x:['nix'], col:'#7e7eff' },
    { n:'Starlark', c:'iac', code:true, x:['bzl','star'], col:'#76d275' },
    // ── Docs & data ──
    { n:'Markdown', c:'doc', code:false, x:['md','markdown','mdx'], col:'#083fa1' },
    { n:'reStructuredText', c:'doc', code:false, x:['rst'], col:'#141414' },
    { n:'AsciiDoc', c:'doc', code:false, x:['adoc','asciidoc'], col:'#73a0c5' },
    { n:'LaTeX', c:'doc', code:false, x:['tex','sty','cls'], col:'#3D6117' },
    // ── More systems / emerging ──
    { n:'Mojo', c:'systems', code:true, x:['mojo'], col:'#ff5e00' },
    { n:'Carbon', c:'systems', code:true, x:['carbon'], col:'#222222' },
    { n:'Odin', c:'systems', code:true, x:['odin'], col:'#60AFFE' },
    { n:'Hare', c:'systems', code:true, x:['ha'], col:'#9d6cff' },
    { n:'Pony', c:'systems', code:true, x:['pony'], col:'#1a1a2e' },
    { n:'Vala', c:'systems', code:true, x:['vala','vapi'], col:'#a56de2' },
    { n:'Eiffel', c:'systems', code:true, x:['e'], col:'#4d6977' },
    { n:'Chapel', c:'systems', code:true, x:['chpl'], col:'#8dc63f' },
    { n:'Modula-2', c:'systems', code:true, x:['mod','m2'], col:'#6e6e6e' },
    { n:'Pike', c:'systems', code:true, x:['pike','pmod'], col:'#005390' },
    // ── More functional ──
    { n:'Gleam', c:'functional', code:true, x:['gleam'], col:'#ffaff3' },
    { n:'Roc', c:'functional', code:true, x:['roc'], col:'#7c38f5' },
    { n:'Unison', c:'functional', code:true, x:['u'], col:'#1a1a1a' },
    { n:'ATS', c:'functional', code:true, x:['dats','sats'], col:'#1ac620' },
    { n:'Mercury', c:'functional', code:true, x:['moo'], col:'#ff2b2b' },
    // ── Proof & verification ──
    { n:'Coq', c:'proof', code:true, x:['vo'], col:'#d0b68c' },
    { n:'Lean', c:'proof', code:true, x:['lean'], col:'#572e30' },
    { n:'Isabelle', c:'proof', code:true, x:['thy'], col:'#fefe33' },
    { n:'Agda', c:'proof', code:true, x:['agda'], col:'#315665' },
    // ── More scripting ──
    { n:'AutoHotkey', c:'scripting', code:true, x:['ahk'], col:'#6594b9' },
    { n:'AutoIt', c:'scripting', code:true, x:['au3'], col:'#1C3552' },
    { n:'Red', c:'scripting', code:true, x:['red','reds'], col:'#f50000' },
    { n:'Ballerina', c:'scripting', code:true, x:['bal'], col:'#FF5000' },
    { n:'NSIS', c:'scripting', code:true, x:['nsi','nsh'], col:'#6e6e6e' },
    // ── More data & scientific ──
    { n:'Octave', c:'data', code:true, x:['octave'], col:'#e16737' },
    { n:'Stan', c:'data', code:true, x:['stan'], col:'#b2011d' },
    { n:'Q#', c:'data', code:true, x:['qs'], col:'#fed659' },
    { n:'OpenQASM', c:'data', code:true, x:['qasm'], col:'#512888' },
    { n:'Wolfram', c:'data', code:true, x:['wl','wls','nb'], col:'#dd1100' },
    { n:'IDL', c:'data', code:true, x:['idl'], col:'#a3522f' },
    // ── More query / database ──
    { n:'HiveQL', c:'query', code:true, x:['hql','q'], col:'#dce200' },
    { n:'Datalog', c:'query', code:true, x:['dl'], col:'#0c479c' },
    { n:'PromQL', c:'query', code:true, x:['promql'], col:'#e6522c' },
    // ── Policy as code ──
    { n:'Rego (OPA)', c:'policy', code:true, x:['rego'], col:'#7c4dff' },
    { n:'Sentinel', c:'policy', code:true, x:['sentinel'], col:'#7B42BC' },
    { n:'CUE', c:'policy', code:true, x:['cue'], col:'#00b3b3' },
    { n:'Dhall', c:'policy', code:true, x:['dhall'], col:'#dfafff' },
    { n:'Jsonnet', c:'policy', code:true, x:['jsonnet','libsonnet'], col:'#0064bd' },
    { n:'Nickel', c:'policy', code:true, x:['ncl'], col:'#1a1a1a' },
    { n:'KCL', c:'policy', code:true, x:['k'], col:'#3f6ec6' },
    { n:'Cedar', c:'policy', code:true, x:['cedar'], col:'#ff9900' },
    // ── Templating ──
    { n:'Jinja', c:'template', code:true, x:['jinja','jinja2','j2'], col:'#b41717' },
    { n:'Handlebars', c:'template', code:true, x:['hbs','handlebars'], col:'#f0772b' },
    { n:'Mustache', c:'template', code:true, x:['mustache'], col:'#724b3b' },
    { n:'Twig', c:'template', code:true, x:['twig'], col:'#c1d026' },
    { n:'Blade', c:'template', code:true, x:['blade.php','blade'], col:'#f7523f' },
    { n:'EJS', c:'template', code:true, x:['ejs'], col:'#a91e50' },
    { n:'Pug', c:'template', code:true, x:['pug','jade'], col:'#a86454' },
    { n:'Haml', c:'template', code:true, x:['haml'], col:'#ece2a9' },
    { n:'Liquid', c:'template', code:true, x:['liquid'], col:'#67b8de' },
    { n:'Smarty', c:'template', code:true, x:['tpl'], col:'#f0c040' },
    { n:'FreeMarker', c:'template', code:true, x:['ftl'], col:'#0050b2' },
    { n:'Velocity', c:'template', code:true, x:['vm','vtl'], col:'#3d6e9e' },
    // ── More smart contracts ──
    { n:'Yul', c:'contract', code:true, x:['yul'], col:'#AA6746' },
    { n:'Clarity', c:'contract', code:true, x:['clar'], col:'#5546ff' },
    { n:'Sway', c:'contract', code:true, x:['sw'], col:'#00f58c' },
    { n:'Fe', c:'contract', code:true, x:['fe'], col:'#ff5722' },
    // ── More config / schema ──
    { n:'Cap’n Proto', c:'config', code:true, x:['capnp'], col:'#c42727' },
    { n:'FlatBuffers', c:'config', code:true, x:['fbs'], col:'#4f5d95' },
    { n:'Smithy', c:'config', code:true, x:['smithy'], col:'#c925d1' },
    { n:'Nginx config', c:'config', code:false, x:['nginxconf'], col:'#009639' },
    { n:'CMake', c:'iac', code:true, x:['cmake'], col:'#064F8C' },
    { n:'Meson', c:'iac', code:true, x:['meson'], col:'#394965' },
    { n:'Earthfile', c:'iac', code:true, x:['earth'], col:'#2af0c8' },
    // ── More legacy / enterprise ──
    { n:'REXX', c:'legacy', code:true, x:['rexx','rex'], col:'#6e6e6e' },
    { n:'JCL', c:'legacy', code:true, x:['jcl'], col:'#6e6e6e' },
    { n:'Clipper', c:'legacy', code:true, x:['prg'], col:'#6e6e6e' },
    // ── More web ──
    { n:'QML', c:'web', code:true, x:['qml'], col:'#44a51c' },
    { n:'Stylus', c:'web', code:false, x:['styl'], col:'#ff6347' },
    { n:'Marko', c:'web', code:true, x:['marko'], col:'#42bff5' }
  ];

  // Special filenames (no/ambiguous extension) → language.
  const FILENAME = {
    'dockerfile': 'Dockerfile', 'containerfile': 'Dockerfile',
    'makefile': 'Makefile', 'gnumakefile': 'Makefile', 'cmakelists.txt': 'CMake',
    'rakefile': 'Ruby', 'gemfile': 'Ruby', 'guardfile': 'Ruby', 'capfile': 'Ruby', 'vagrantfile': 'Ruby',
    'jenkinsfile': 'Groovy', 'fastfile': 'Ruby', 'podfile': 'Ruby', 'brewfile': 'Ruby',
    'justfile': 'Just', '.gitlab-ci.yml': 'YAML', '.travis.yml': 'YAML',
    'go.mod': 'Go', 'go.sum': 'Go', 'cargo.toml': 'TOML', 'pipfile': 'TOML',
    'procfile': 'YAML', 'berksfile': 'Ruby', 'thorfile': 'Ruby'
  };

  // Build derived maps from the catalog.
  const EXT = {};         // ext -> language name
  const COLOR = {};       // name -> color
  const CATEGORY = {};    // name -> category id
  const CODE_LANGS = new Set();
  LANGS.forEach(l => {
    (l.x || []).forEach(e => { if (!(e in EXT)) EXT[e] = l.n; });   // first wins on conflict
    COLOR[l.n] = l.col || (CATS[l.c] ? CATS[l.c].color : '#8b95a5');
    CATEGORY[l.n] = l.c;
    if (l.code) CODE_LANGS.add(l.n);
  });
  // A few high-signal extension overrides where two languages share an ext.
  EXT['h'] = 'C'; EXT['m'] = 'Objective-C'; EXT['v'] = 'Verilog'; EXT['pl'] = 'Perl';
  EXT['cls'] = 'Visual Basic'; EXT['sc'] = 'Scala'; EXT['s'] = 'Assembly';

  function extOf(path) {
    const base = path.split('/').pop().toLowerCase();
    if (FILENAME[base]) return { name: base, ext: '' };
    const i = base.lastIndexOf('.');
    return { name: base, ext: i >= 0 ? base.slice(i + 1) : '' };
  }
  function detect(path) {
    const { name, ext: e } = extOf(path);
    if (FILENAME[name]) return FILENAME[name];
    if (name.startsWith('dockerfile') || name.endsWith('.dockerfile')) return 'Dockerfile';
    if (/(^|\.)(kubernetes|k8s)\.ya?ml$/.test(name)) return 'YAML';
    return EXT[e] || (e ? 'Other' : 'Unknown');
  }
  function isCode(lang) { return CODE_LANGS.has(lang); }
  function colorFor(lang) { return COLOR[lang] || '#8b95a5'; }
  function categoryOf(lang) { return CATEGORY[lang] || null; }

  CITADEL.lang = { detect, isCode, colorFor, categoryOf, EXT, CODE_LANGS, LANGS, CATS, CATEGORY, FILENAME, count: LANGS.length };
})(window);
