/* CITADEL — Language Classifier
 * Maps file extensions / filenames to languages and ecosystems.
 * Pure client-side. Attaches to window.CITADEL.lang
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // extension -> { lang, color, category }
  const EXT = {
    js: 'JavaScript', mjs: 'JavaScript', cjs: 'JavaScript', jsx: 'JavaScript',
    ts: 'TypeScript', tsx: 'TypeScript',
    py: 'Python', pyw: 'Python', pyi: 'Python',
    java: 'Java', jsp: 'Java',
    cs: 'C#', csx: 'C#',
    c: 'C', h: 'C',
    cpp: 'C++', cc: 'C++', cxx: 'C++', hpp: 'C++', hh: 'C++',
    go: 'Go',
    rb: 'Ruby', erb: 'Ruby', rake: 'Ruby',
    php: 'PHP', phtml: 'PHP', php3: 'PHP', php4: 'PHP', php5: 'PHP',
    rs: 'Rust',
    swift: 'Swift',
    kt: 'Kotlin', kts: 'Kotlin',
    scala: 'Scala', sc: 'Scala',
    m: 'Objective-C', mm: 'Objective-C',
    pl: 'Perl', pm: 'Perl',
    lua: 'Lua',
    dart: 'Dart',
    r: 'R',
    groovy: 'Groovy', gradle: 'Groovy',
    sh: 'Shell', bash: 'Shell', zsh: 'Shell', ksh: 'Shell',
    ps1: 'PowerShell', psm1: 'PowerShell', psd1: 'PowerShell',
    bat: 'Batch', cmd: 'Batch',
    sql: 'SQL',
    html: 'HTML', htm: 'HTML', xhtml: 'HTML',
    css: 'CSS', scss: 'SCSS', sass: 'SCSS', less: 'Less',
    vue: 'Vue', svelte: 'Svelte',
    json: 'JSON', yaml: 'YAML', yml: 'YAML', toml: 'TOML', xml: 'XML', ini: 'INI',
    md: 'Markdown', markdown: 'Markdown',
    tf: 'Terraform', tfvars: 'Terraform', hcl: 'HCL',
    bicep: 'Bicep',
    dockerfile: 'Dockerfile',
    vb: 'Visual Basic', vbs: 'VBScript',
    asm: 'Assembly', s: 'Assembly',
    elm: 'Elm', ex: 'Elixir', exs: 'Elixir', erl: 'Erlang', clj: 'Clojure',
    fs: 'F#', fsx: 'F#', ml: 'OCaml', hs: 'Haskell', jl: 'Julia', nim: 'Nim',
    proto: 'Protobuf', graphql: 'GraphQL', gql: 'GraphQL'
  };

  const FILENAME = {
    'dockerfile': 'Dockerfile',
    'makefile': 'Makefile',
    'rakefile': 'Ruby',
    'gemfile': 'Ruby',
    'vagrantfile': 'Ruby',
    'jenkinsfile': 'Groovy',
    'cmakelists.txt': 'CMake',
    '.gitlab-ci.yml': 'YAML',
    'go.mod': 'Go', 'go.sum': 'Go'
  };

  // Languages we run source SAST against (text, code-bearing)
  const CODE_LANGS = new Set([
    'JavaScript','TypeScript','Python','Java','C#','C','C++','Go','Ruby','PHP',
    'Rust','Swift','Kotlin','Scala','Objective-C','Perl','Lua','Dart','Groovy',
    'Shell','PowerShell','Batch','SQL','HTML','Vue','Svelte','Visual Basic','VBScript'
  ]);

  // Brand colors (GitHub linguist-ish) for charts
  const COLOR = {
    JavaScript:'#f1e05a', TypeScript:'#3178c6', Python:'#3572A5', Java:'#b07219',
    'C#':'#178600', C:'#555555', 'C++':'#f34b7d', Go:'#00ADD8', Ruby:'#701516',
    PHP:'#4F5D95', Rust:'#dea584', Swift:'#F05138', Kotlin:'#A97BFF', Scala:'#c22d40',
    'Objective-C':'#438eff', Perl:'#0298c3', Lua:'#000080', Dart:'#00B4AB',
    Groovy:'#4298b8', Shell:'#89e051', PowerShell:'#012456', Batch:'#C1F12E',
    SQL:'#e38c00', HTML:'#e34c26', CSS:'#563d7c', SCSS:'#c6538c', Vue:'#41b883',
    Svelte:'#ff3e00', JSON:'#cbcb41', YAML:'#cb171e', XML:'#0060ac', Markdown:'#083fa1',
    Terraform:'#7B42BC', Bicep:'#519aba', Dockerfile:'#384d54', HCL:'#844FBA'
  };

  function ext(path) {
    const base = path.split('/').pop().toLowerCase();
    if (FILENAME[base]) return { name: base, ext: '' };
    const i = base.lastIndexOf('.');
    return { name: base, ext: i >= 0 ? base.slice(i + 1) : '' };
  }

  function detect(path) {
    const { name, ext: e } = ext(path);
    if (FILENAME[name]) return FILENAME[name];
    if (name.startsWith('dockerfile')) return 'Dockerfile';
    return EXT[e] || (e ? 'Other' : 'Unknown');
  }

  function isCode(lang) { return CODE_LANGS.has(lang); }
  function colorFor(lang) { return COLOR[lang] || '#8b95a5'; }

  CITADEL.lang = { detect, isCode, colorFor, EXT, CODE_LANGS };
})(window);
