/* CITADEL — Dependency Review: Dependency Inventory
 * Detects dependency/build manifests across major ecosystems, parses them into
 * a unified Dep[] list (prod/dev, direct/transitive), infers package managers,
 * and surfaces manifest-embedded licenses. Pure, defensive, offline.
 * window.CITADEL.depreviewDeps
 *
 * analyze(entries, sbomComponents)
 *   -> { manifests, dependencies:{prod,dev,counts}, packageManagers, licensesRaw }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // ---- manifest detection -------------------------------------------------
  // Exact basename (lowercased) -> { ecosystem, manager, lock:boolean }
  const EXACT = {
    'package.json': { ecosystem: 'npm', manager: 'npm', lock: false },
    'package-lock.json': { ecosystem: 'npm', manager: 'npm', lock: true },
    'yarn.lock': { ecosystem: 'npm', manager: 'yarn', lock: true },
    'pnpm-lock.yaml': { ecosystem: 'npm', manager: 'pnpm', lock: true },
    'bun.lockb': { ecosystem: 'npm', manager: 'bun', lock: true },
    'pyproject.toml': { ecosystem: 'pypi', manager: 'poetry', lock: false },
    'poetry.lock': { ecosystem: 'pypi', manager: 'poetry', lock: true },
    'pipfile': { ecosystem: 'pypi', manager: 'pipenv', lock: false },
    'pipfile.lock': { ecosystem: 'pypi', manager: 'pipenv', lock: true },
    'setup.py': { ecosystem: 'pypi', manager: 'pip', lock: false },
    'setup.cfg': { ecosystem: 'pypi', manager: 'pip', lock: false },
    'composer.json': { ecosystem: 'composer', manager: 'composer', lock: false },
    'composer.lock': { ecosystem: 'composer', manager: 'composer', lock: true },
    'pom.xml': { ecosystem: 'maven', manager: 'maven', lock: false },
    'build.gradle': { ecosystem: 'maven', manager: 'gradle', lock: false },
    'build.gradle.kts': { ecosystem: 'maven', manager: 'gradle', lock: false },
    'gradle.properties': { ecosystem: 'maven', manager: 'gradle', lock: false },
    'packages.config': { ecosystem: 'nuget', manager: 'nuget', lock: false },
    'directory.build.props': { ecosystem: 'nuget', manager: 'dotnet', lock: false },
    'go.mod': { ecosystem: 'golang', manager: 'go modules', lock: false },
    'go.sum': { ecosystem: 'golang', manager: 'go modules', lock: true },
    'gemfile': { ecosystem: 'gem', manager: 'bundler', lock: false },
    'gemfile.lock': { ecosystem: 'gem', manager: 'bundler', lock: true },
    'cargo.toml': { ecosystem: 'cargo', manager: 'cargo', lock: false },
    'cargo.lock': { ecosystem: 'cargo', manager: 'cargo', lock: true },
    'makefile': { ecosystem: 'native', manager: 'make', lock: false },
    'cmakelists.txt': { ecosystem: 'native', manager: 'cmake', lock: false },
    'conanfile.txt': { ecosystem: 'native', manager: 'conan', lock: false },
    'conanfile.py': { ecosystem: 'native', manager: 'conan', lock: false },
    'vcpkg.json': { ecosystem: 'native', manager: 'vcpkg', lock: false }
  };

  // Suffix-based (lowercased) for globbed names.
  const SUFFIX = [
    { re: /\.csproj$/, m: { ecosystem: 'nuget', manager: 'dotnet', lock: false } },
    { re: /\.vbproj$/, m: { ecosystem: 'nuget', manager: 'dotnet', lock: false } },
    { re: /\.fsproj$/, m: { ecosystem: 'nuget', manager: 'dotnet', lock: false } },
    { re: /\.sln$/, m: { ecosystem: 'nuget', manager: 'dotnet', lock: false } }
  ];

  function basename(path) {
    return String(path || '').split('!/').pop().split('/').pop().toLowerCase();
  }

  function detect(path) {
    const base = basename(path);
    if (EXACT[base]) return EXACT[base];
    // requirements*.txt (requirements.txt, requirements-dev.txt, ...)
    if (/^requirements.*\.txt$/.test(base)) {
      return { ecosystem: 'pypi', manager: 'pip', lock: false };
    }
    for (let i = 0; i < SUFFIX.length; i++) {
      if (SUFFIX[i].re.test(base)) return SUFFIX[i].m;
    }
    return null;
  }

  // ---- helpers ------------------------------------------------------------
  function registryFor(ecosystem) {
    switch (ecosystem) {
      case 'npm': return 'npm registry';
      case 'pypi': return 'PyPI';
      case 'composer': return 'Packagist';
      case 'maven': return 'Maven Central';
      case 'golang': return 'Go proxy';
      case 'gem': return 'RubyGems';
      case 'cargo': return 'crates.io';
      case 'nuget': return 'NuGet';
      default: return ecosystem || 'unknown';
    }
  }

  // Determine source/origin from a version spec string.
  function sourceFor(ecosystem, version) {
    const v = String(version == null ? '' : version).trim();
    const low = v.toLowerCase();
    if (/^(git\+|git:|github:|gitlab:|bitbucket:)/.test(low) || /\.git(#|$)/.test(low)) return 'git';
    if (/^(https?:)?\/\//.test(low) && /\.(tgz|tar\.gz|zip)(#|$|\?)/.test(low)) return 'http';
    if (/^https?:/.test(low)) return 'http';
    if (/^(file:|path:|link:)/.test(low) || /^(\.|\.\.)?\//.test(v) || /^[a-z]:\\/.test(low)) return 'path';
    return registryFor(ecosystem);
  }

  function dep(name, version, ecosystem, type, opts) {
    opts = opts || {};
    const ver = (version == null || version === '') ? '*' : String(version).trim();
    return {
      name: String(name).trim(),
      version: ver,
      latest: null,
      ecosystem: ecosystem,
      type: type === 'dev' ? 'dev' : 'prod',
      direct: opts.direct === undefined ? true : !!opts.direct,
      source: opts.source || sourceFor(ecosystem, ver),
      license: opts.license || null,
      purpose: null,
      status: 'unknown',
      risk: 'none'
    };
  }

  function safeJson(content) {
    try { return JSON.parse(content); } catch (e) { return null; }
  }

  // ---- per-ecosystem parsers ---------------------------------------------
  // Each parser pushes Dep objects into `out` and license rows into `lic`.

  function parsePackageJson(content, out, lic, hasLock) {
    const j = safeJson(content);
    if (!j || typeof j !== 'object') return;
    const license = typeof j.license === 'string' ? j.license : null;
    const groups = [
      ['dependencies', 'prod'],
      ['devDependencies', 'dev'],
      ['peerDependencies', 'dev'],
      ['optionalDependencies', 'dev']
    ];
    groups.forEach(g => {
      const obj = j[g[0]];
      if (!obj || typeof obj !== 'object') return;
      for (const n in obj) {
        if (!Object.prototype.hasOwnProperty.call(obj, n)) continue;
        const d = dep(n, obj[n], 'npm', g[1], { direct: true });
        out.push(d);
        // package.json declares its own license, not per-dependency; skip lic here.
      }
    });
    // Record the project's own license if present (helps license review of the root).
    if (license && typeof j.name === 'string') {
      lic.push({ name: j.name, license: license, ecosystem: 'npm', scope: 'prod' });
    }
    return hasLock; // no-op, keep signature flexible
  }

  function parsePackageLock(content, out) {
    const j = safeJson(content);
    if (!j || typeof j !== 'object') return;
    // lockfile v2/v3: `packages` keyed by node_modules/<name>; v1: `dependencies`.
    const pkgs = j.packages;
    if (pkgs && typeof pkgs === 'object') {
      for (const key in pkgs) {
        if (!Object.prototype.hasOwnProperty.call(pkgs, key)) continue;
        if (key === '') continue; // root project
        const m = key.match(/node_modules\/((?:@[^/]+\/)?[^/]+)$/);
        if (!m) continue;
        const meta = pkgs[key] || {};
        const isDev = meta.dev === true || meta.devOptional === true;
        out.push(dep(m[1], meta.version, 'npm', isDev ? 'dev' : 'prod', { direct: false }));
      }
      return;
    }
    const deps = j.dependencies;
    if (deps && typeof deps === 'object') {
      for (const n in deps) {
        if (!Object.prototype.hasOwnProperty.call(deps, n)) continue;
        const meta = deps[n] || {};
        out.push(dep(n, meta.version, 'npm', meta.dev === true ? 'dev' : 'prod', { direct: false }));
      }
    }
  }

  function parseRequirements(path, content, out) {
    const dev = /dev/i.test(basename(path));
    String(content).split('\n').forEach(raw => {
      let line = raw.trim();
      if (!line || line.startsWith('#') || line.startsWith('-')) return;
      // strip inline comments and environment markers/extras
      line = line.split(' #')[0].split(';')[0].split('[')[0].trim();
      if (!line) return;
      const m = line.match(/^([A-Za-z0-9._-]+)\s*([<>=!~]=?.*)?$/);
      if (!m) return;
      const spec = (m[2] || '').trim();
      const version = spec.replace(/^[<>=!~ ]+/, '').trim() || '*';
      out.push(dep(m[1], version, 'pypi', dev ? 'dev' : 'prod', { direct: true }));
    });
  }

  // Minimal TOML table reader: returns map of "section" -> array of raw lines.
  function tomlSections(content) {
    const sections = {};
    let current = '';
    String(content).split('\n').forEach(raw => {
      const line = raw.trim();
      const h = line.match(/^\[+([^\]]+)\]+\s*$/);
      if (h) { current = h[1].trim(); sections[current] = sections[current] || []; return; }
      if (current) (sections[current] = sections[current] || []).push(raw);
    });
    return sections;
  }

  // Parse "name = version" or "name = { version = "x", ... }" inside a TOML block.
  function tomlDepLine(line) {
    const m = line.match(/^\s*([A-Za-z0-9._-]+)\s*=\s*(.+?)\s*$/);
    if (!m) return null;
    const name = m[1];
    let rhs = m[2];
    let version = '*';
    let source = null;
    let license = null;
    if (/^["']/.test(rhs)) {
      version = rhs.replace(/^["']|["'].*$/g, '');
    } else if (/^\{/.test(rhs)) {
      const vm = rhs.match(/version\s*=\s*["']([^"']+)["']/);
      if (vm) version = vm[1];
      if (/\bgit\s*=/.test(rhs)) source = 'git';
      else if (/\bpath\s*=/.test(rhs)) source = 'path';
      const lm = rhs.match(/license\s*=\s*["']([^"']+)["']/);
      if (lm) license = lm[1];
    }
    return { name: name, version: version, source: source, license: license };
  }

  function parsePyproject(content, out) {
    const sections = tomlSections(content);
    // Poetry: [tool.poetry.dependencies] (prod), [tool.poetry.group.*.dependencies] / [tool.poetry.dev-dependencies] (dev)
    for (const sec in sections) {
      if (!Object.prototype.hasOwnProperty.call(sections, sec)) continue;
      let eco = 'pypi';
      let type = null;
      if (sec === 'tool.poetry.dependencies') type = 'prod';
      else if (sec === 'tool.poetry.dev-dependencies') type = 'dev';
      else if (/^tool\.poetry\.group\..*\.dependencies$/.test(sec)) type = 'dev';
      else if (sec === 'project.optional-dependencies') type = 'dev'; // PEP621 (array values, handled below)
      if (type === null) continue;
      if (sec === 'project.optional-dependencies') continue; // these are arrays, not name=ver
      sections[sec].forEach(line => {
        const t = tomlDepLine(line);
        if (!t || t.name.toLowerCase() === 'python') return;
        out.push(dep(t.name, t.version, eco, type, { direct: true, source: t.source || undefined }));
      });
    }
    // PEP621 [project] dependencies = [ "requests>=2", ... ]
    if (sections['project']) {
      const joined = sections['project'].join('\n');
      const arr = joined.match(/dependencies\s*=\s*\[([\s\S]*?)\]/);
      if (arr) {
        arr[1].split(',').forEach(item => {
          const s = item.replace(/["']/g, '').trim();
          if (!s) return;
          const m = s.match(/^([A-Za-z0-9._-]+)\s*([<>=!~].*)?$/);
          if (m) out.push(dep(m[1], (m[2] || '*').replace(/^[<>=!~ ]+/, '').trim() || '*', 'pypi', 'prod', { direct: true }));
        });
      }
    }
  }

  function parsePipfile(content, out) {
    const sections = tomlSections(content);
    [['packages', 'prod'], ['dev-packages', 'dev']].forEach(g => {
      const lines = sections[g[0]];
      if (!lines) return;
      lines.forEach(line => {
        const t = tomlDepLine(line);
        if (!t) return;
        out.push(dep(t.name, t.version, 'pypi', g[1], { direct: true, source: t.source || undefined }));
      });
    });
  }

  function parseComposerJson(content, out, lic) {
    const j = safeJson(content);
    if (!j || typeof j !== 'object') return;
    [['require', 'prod'], ['require-dev', 'dev']].forEach(g => {
      const obj = j[g[0]];
      if (!obj || typeof obj !== 'object') return;
      for (const n in obj) {
        if (!Object.prototype.hasOwnProperty.call(obj, n)) continue;
        if (n === 'php' || n.indexOf('ext-') === 0 || n === 'composer') continue;
        out.push(dep(n, obj[n], 'composer', g[1], { direct: true }));
      }
    });
    // composer.json embeds the project's own license.
    let license = null;
    if (typeof j.license === 'string') license = j.license;
    else if (Array.isArray(j.license) && j.license.length) license = j.license.join(' OR ');
    if (license) {
      const nm = typeof j.name === 'string' ? j.name : 'project';
      lic.push({ name: nm, license: license, ecosystem: 'composer', scope: 'prod' });
    }
  }

  function parseGoMod(content, out) {
    const lines = String(content).split('\n');
    let inBlock = false;
    lines.forEach(raw => {
      let line = raw.trim();
      if (!line) return;
      if (/^require\s*\($/.test(line)) { inBlock = true; return; }
      if (inBlock && line === ')') { inBlock = false; return; }
      let body = null;
      if (inBlock) body = line;
      else {
        const single = line.match(/^require\s+(.+)$/);
        if (single) body = single[1];
      }
      if (body === null) return;
      const indirect = /\/\/\s*indirect/.test(body);
      const clean = body.replace(/\/\/.*$/, '').trim();
      const m = clean.match(/^([^\s]+)\s+(v[^\s]+)$/);
      if (!m) return;
      out.push(dep(m[1], m[2], 'golang', 'prod', { direct: !indirect }));
    });
  }

  function parseGemfile(content, out) {
    const lines = String(content).split('\n');
    let groupStack = [];
    lines.forEach(raw => {
      const line = raw.trim();
      const gm = line.match(/^group\s+(.+?)\s+do\s*$/);
      if (gm) {
        const names = (gm[1].match(/:([A-Za-z0-9_]+)/g) || []).map(s => s.slice(1));
        groupStack.push(names);
        return;
      }
      if (/^end\s*$/.test(line) && groupStack.length) { groupStack.pop(); return; }
      const m = line.match(/^gem\s+["']([^"']+)["'](?:\s*,\s*["']([^"']+)["'])?(.*)$/);
      if (!m) return;
      const rest = m[3] || '';
      let groups = [];
      groupStack.forEach(g => { groups = groups.concat(g); });
      const inlineGroup = rest.match(/group\s*:\s*\[?\s*([^\]\n]*)/);
      if (inlineGroup) {
        (inlineGroup[1].match(/:([A-Za-z0-9_]+)/g) || []).forEach(s => groups.push(s.slice(1)));
      }
      const isDev = groups.some(g => /^(development|dev|test)$/i.test(g));
      out.push(dep(m[1], m[2] || '*', 'gem', isDev ? 'dev' : 'prod', { direct: true }));
    });
  }

  function parseCargoToml(content, out, lic) {
    const sections = tomlSections(content);
    // project license under [package]
    if (sections['package']) {
      const joined = sections['package'].join('\n');
      const lm = joined.match(/license\s*=\s*["']([^"']+)["']/);
      const nm = joined.match(/name\s*=\s*["']([^"']+)["']/);
      if (lm) lic.push({ name: nm ? nm[1] : 'crate', license: lm[1], ecosystem: 'cargo', scope: 'prod' });
    }
    const map = [
      ['dependencies', 'prod'],
      ['dev-dependencies', 'dev'],
      ['build-dependencies', 'dev']
    ];
    map.forEach(g => {
      const lines = sections[g[0]];
      if (!lines) return;
      lines.forEach(line => {
        const t = tomlDepLine(line);
        if (!t) return;
        out.push(dep(t.name, t.version, 'cargo', g[1], { direct: true, source: t.source || undefined, license: t.license || undefined }));
      });
    });
  }

  function parseCsproj(content, out) {
    const re = /<PackageReference\s+[^>]*Include\s*=\s*"([^"]+)"[^>]*?(?:Version\s*=\s*"([^"]*)")?[^>]*\/?>/gi;
    let m;
    while ((m = re.exec(content)) !== null) {
      // also handle child <Version> element form: best-effort, default '*'
      out.push(dep(m[1], m[2] || '*', 'nuget', 'prod', { direct: true }));
    }
  }

  function parsePackagesConfig(content, out) {
    const re = /<package\s+id\s*=\s*"([^"]+)"(?:\s+version\s*=\s*"([^"]*)")?[^>]*\/?>/gi;
    let m;
    while ((m = re.exec(content)) !== null) {
      const dev = /developmentDependency\s*=\s*"true"/i.test(m[0]);
      out.push(dep(m[1], m[2] || '*', 'nuget', dev ? 'dev' : 'prod', { direct: true }));
    }
  }

  function parsePomXml(content, out) {
    const re = /<dependency>([\s\S]*?)<\/dependency>/g;
    let m;
    while ((m = re.exec(content)) !== null) {
      const body = m[1];
      const g = (body.match(/<groupId>([\s\S]*?)<\/groupId>/) || [])[1];
      const a = (body.match(/<artifactId>([\s\S]*?)<\/artifactId>/) || [])[1];
      const v = (body.match(/<version>([\s\S]*?)<\/version>/) || [])[1];
      const scope = (body.match(/<scope>([\s\S]*?)<\/scope>/) || [])[1] || '';
      if (!a) continue;
      const name = (g ? g.trim() + ':' : '') + a.trim();
      const isDev = /test|provided/i.test(scope);
      out.push(dep(name, (v || '*').trim(), 'maven', isDev ? 'dev' : 'prod', { direct: true }));
    }
  }

  function parseGradle(content, out) {
    const re = /\b(implementation|api|compile|runtimeOnly|compileOnly|annotationProcessor|testImplementation|testCompile|testRuntimeOnly|androidTestImplementation)\b\s*\(?\s*["']([^:"']+):([^:"']+):([^"']+)["']/g;
    let m;
    while ((m = re.exec(content)) !== null) {
      const isDev = /^(test|androidTest)/i.test(m[1]);
      out.push(dep(m[2] + ':' + m[3], m[4], 'maven', isDev ? 'dev' : 'prod', { direct: true }));
    }
  }

  // ---- merge sbomComponents ----------------------------------------------
  function key(eco, name) {
    return (eco || '') + '\u0000' + String(name || '').toLowerCase();
  }

  function mergeSbom(existing, sbomComponents) {
    if (!Array.isArray(sbomComponents)) return;
    sbomComponents.forEach(c => {
      if (!c || !c.name) return;
      const eco = c.ecosystem || 'unknown';
      const k = key(eco, c.name);
      if (existing[k]) return; // prefer our richer parse
      const type = c.scope === 'dev' ? 'dev' : 'prod';
      existing[k] = dep(c.name, c.version, eco, type, { direct: true });
    });
  }

  // ---- main ---------------------------------------------------------------
  function analyze(entries, sbomComponents) {
    const manifests = [];
    const byKey = {};       // dedupe map: ecosystem+name -> Dep
    const lic = [];         // licensesRaw rows
    const managers = {};    // manager name -> lockfile basename|null
    const seenManifest = {};

    const list = Array.isArray(entries) ? entries : [];
    list.forEach(e => {
      try {
        if (!e || !e.path) return;
        const info = detect(e.path);
        if (!info) return;
        const base = basename(e.path);

        // record manifest (dedupe by path)
        if (!seenManifest[e.path]) {
          seenManifest[e.path] = true;
          manifests.push({ file: e.path, ecosystem: info.ecosystem, manager: info.manager });
        }

        // track package managers + their lockfile
        if (!(info.manager in managers)) managers[info.manager] = null;
        if (info.lock && !managers[info.manager]) managers[info.manager] = base;

        const content = e.content;
        if (typeof content !== 'string' || !content) return;

        const out = [];
        try {
          switch (base) {
            case 'package.json': parsePackageJson(content, out, lic); break;
            case 'package-lock.json': parsePackageLock(content, out); break;
            case 'composer.json': parseComposerJson(content, out, lic); break;
            case 'composer.lock': /* lock detail skipped; composer.json covers direct */ break;
            case 'go.mod': parseGoMod(content, out); break;
            case 'gemfile': parseGemfile(content, out); break;
            case 'cargo.toml': parseCargoToml(content, out, lic); break;
            case 'pyproject.toml': parsePyproject(content, out); break;
            case 'pipfile': parsePipfile(content, out); break;
            case 'pom.xml': parsePomXml(content, out); break;
            case 'build.gradle':
            case 'build.gradle.kts': parseGradle(content, out); break;
            case 'packages.config': parsePackagesConfig(content, out); break;
            default:
              if (/^requirements.*\.txt$/.test(base)) parseRequirements(e.path, content, out);
              else if (/\.(csproj|vbproj|fsproj)$/.test(base)) parseCsproj(content, out);
              break;
          }
        } catch (parseErr) { /* skip malformed manifest */ }

        out.forEach(d => {
          if (!d || !d.name) return;
          const k = key(d.ecosystem, d.name);
          const prev = byKey[k];
          if (!prev) { byKey[k] = d; return; }
          // merge: prefer prod over dev, direct over transitive, concrete over '*'
          if (prev.type === 'dev' && d.type === 'prod') prev.type = 'prod';
          if (!prev.direct && d.direct) prev.direct = true;
          if ((prev.version === '*' || !prev.version) && d.version && d.version !== '*') {
            prev.version = d.version;
            prev.source = d.source;
          }
          if (!prev.license && d.license) prev.license = d.license;
        });
      } catch (entryErr) { /* skip bad entry */ }
    });

    // merge sbom as fallback (does not overwrite our richer parse)
    try { mergeSbom(byKey, sbomComponents); } catch (e) { /* ignore */ }

    // collect licenses embedded directly on Deps (e.g. Cargo) too
    Object.keys(byKey).forEach(k => {
      const d = byKey[k];
      if (d.license) {
        lic.push({ name: d.name, license: d.license, ecosystem: d.ecosystem, scope: d.type });
      }
    });

    // split + sort
    const all = Object.keys(byKey).map(k => byKey[k]);
    all.sort((a, b) => a.name.toLowerCase() < b.name.toLowerCase() ? -1 : (a.name.toLowerCase() > b.name.toLowerCase() ? 1 : 0));
    const prod = all.filter(d => d.type === 'prod');
    const dev = all.filter(d => d.type === 'dev');

    const counts = {
      prod: prod.length,
      dev: dev.length,
      total: all.length,
      direct: all.filter(d => d.direct).length,
      transitive: all.filter(d => !d.direct).length
    };

    const packageManagers = Object.keys(managers).map(name => ({
      name: name, lockfile: managers[name] || null
    }));

    // dedupe licensesRaw by ecosystem+name+license
    const licSeen = {};
    const licensesRaw = [];
    lic.forEach(r => {
      if (!r || !r.name || !r.license) return;
      const lk = (r.ecosystem || '') + '\u0000' + r.name.toLowerCase() + '\u0000' + r.license;
      if (licSeen[lk]) return;
      licSeen[lk] = true;
      licensesRaw.push(r);
    });

    return {
      manifests: manifests,
      dependencies: { prod: prod, dev: dev, counts: counts },
      packageManagers: packageManagers,
      licensesRaw: licensesRaw
    };
  }

  CITADEL.depreviewDeps = { analyze: analyze, detect: detect };
})(window);
