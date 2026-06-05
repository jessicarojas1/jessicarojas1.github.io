/* CITADEL — SBOM & Dependency Analyzer
 * Parses common package manifests to enumerate components and produce a
 * CycloneDX-style SBOM. Flags risky/yanked patterns heuristically.
 * window.CITADEL.sbom
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MANIFESTS = {
    'package.json': 'npm',
    'package-lock.json': 'npm',
    'yarn.lock': 'npm',
    'requirements.txt': 'pypi',
    'pipfile': 'pypi',
    'pyproject.toml': 'pypi',
    'pom.xml': 'maven',
    'build.gradle': 'maven',
    'go.mod': 'golang',
    'gemfile': 'gem',
    'composer.json': 'composer',
    'cargo.toml': 'cargo',
    '*.csproj': 'nuget'
  };

  function manifestType(path) {
    const base = path.split('/').pop().toLowerCase();
    if (MANIFESTS[base]) return MANIFESTS[base];
    if (base.endsWith('.csproj')) return 'nuget';
    return null;
  }

  // Returns array of { name, version, ecosystem, scope }
  function parse(path, content) {
    const type = manifestType(path);
    if (!type) return [];
    try {
      switch (type) {
        case 'npm': return parseNpm(path, content);
        case 'pypi': return parsePypi(content);
        case 'maven': return parseMaven(content);
        case 'golang': return parseGoMod(content);
        case 'gem': return parseGemfile(content);
        case 'composer': return parseComposer(content);
        case 'cargo': return parseCargo(content);
        case 'nuget': return parseNuget(content);
        default: return [];
      }
    } catch (e) { return []; }
  }

  function comp(name, version, eco, scope) {
    return { name: name.trim(), version: (version || '*').trim(), ecosystem: eco, scope: scope || 'runtime' };
  }

  function parseNpm(path, content) {
    if (path.toLowerCase().endsWith('package.json')) {
      const j = JSON.parse(content);
      const out = [];
      ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'].forEach(k => {
        if (j[k]) for (const n in j[k]) out.push(comp(n, j[k][n], 'npm', k === 'dependencies' ? 'runtime' : 'dev'));
      });
      return out;
    }
    return []; // lockfiles: skip deep parse in the demo
  }
  function parsePypi(content) {
    return content.split('\n').map(l => l.trim())
      .filter(l => l && !l.startsWith('#') && !l.startsWith('-'))
      .map(l => {
        const m = l.match(/^([A-Za-z0-9._\-]+)\s*([<>=!~]=?.*)?$/);
        return m ? comp(m[1], (m[2] || '*').replace(/[<>=!~]/g, '').trim() || '*', 'pypi') : null;
      }).filter(Boolean);
  }
  function parseMaven(content) {
    const out = [];
    const re = /<dependency>[\s\S]*?<groupId>([\s\S]*?)<\/groupId>[\s\S]*?<artifactId>([\s\S]*?)<\/artifactId>(?:[\s\S]*?<version>([\s\S]*?)<\/version>)?/g;
    let m;
    while ((m = re.exec(content)) !== null) out.push(comp(m[1] + ':' + m[2], m[3] || '*', 'maven'));
    // gradle style
    const gr = /(implementation|api|compile|testImplementation)\s+["']([^:"']+):([^:"']+):([^"']+)["']/g;
    while ((m = gr.exec(content)) !== null) out.push(comp(m[2] + ':' + m[3], m[4], 'maven', m[1].startsWith('test') ? 'dev' : 'runtime'));
    return out;
  }
  function parseGoMod(content) {
    const out = [];
    const re = /^\s*([\w.\-\/]+)\s+v([\w.\-+]+)/gm;
    let m;
    while ((m = re.exec(content)) !== null) {
      if (m[1] === 'module' || m[1] === 'go') continue;
      out.push(comp(m[1], 'v' + m[2], 'golang'));
    }
    return out;
  }
  function parseGemfile(content) {
    const out = [];
    const re = /gem\s+["']([^"']+)["'](?:\s*,\s*["']([^"']+)["'])?/g;
    let m;
    while ((m = re.exec(content)) !== null) out.push(comp(m[1], m[2] || '*', 'gem'));
    return out;
  }
  function parseComposer(content) {
    const j = JSON.parse(content);
    const out = [];
    ['require', 'require-dev'].forEach(k => {
      if (j[k]) for (const n in j[k]) { if (n === 'php' || n.startsWith('ext-')) continue; out.push(comp(n, j[k][n], 'composer', k === 'require' ? 'runtime' : 'dev')); }
    });
    return out;
  }
  function parseCargo(content) {
    const out = [];
    const block = content.split(/\[dependencies\]/)[1] || '';
    const re = /^\s*([\w\-]+)\s*=\s*["']([^"']+)["']/gm;
    let m;
    while ((m = re.exec(block)) !== null) out.push(comp(m[1], m[2], 'cargo'));
    return out;
  }
  function parseNuget(content) {
    const out = [];
    const re = /<PackageReference\s+Include="([^"]+)"\s+Version="([^"]+)"/g;
    let m;
    while ((m = re.exec(content)) !== null) out.push(comp(m[1], m[2], 'nuget'));
    return out;
  }

  // Heuristic risk flags on components (no live CVE feed in the static demo).
  function riskFlags(components) {
    const flags = [];
    components.forEach(c => {
      const v = c.version;
      if (/^[\^~]?0\./.test(v)) flags.push({ component: c, reason: 'Pre-1.0 / unstable version', severity: 'low', category: 'deps' });
      if (/(\*|latest|^\^|^~|x$)/.test(v) || v === '*') flags.push({ component: c, reason: 'Unpinned / floating version range', severity: 'medium', category: 'supply-chain' });
      if (/-(alpha|beta|rc|snapshot|dev)/i.test(v)) flags.push({ component: c, reason: 'Pre-release dependency in use', severity: 'low', category: 'deps' });
    });
    return flags;
  }

  // Build a minimal CycloneDX 1.5 SBOM document
  function cyclonedx(components, projectName) {
    return {
      bomFormat: 'CycloneDX',
      specVersion: '1.5',
      version: 1,
      metadata: {
        timestamp: new Date().toISOString(),
        tools: [{ vendor: 'CITADEL', name: 'CITADEL Analyzer', version: '1.0' }],
        component: { type: 'application', name: projectName || 'analyzed-project' }
      },
      components: components.map(c => ({
        type: 'library',
        name: c.name,
        version: c.version,
        scope: c.scope === 'dev' ? 'optional' : 'required',
        purl: purl(c)
      }))
    };
  }

  function purl(c) {
    const map = { npm: 'npm', pypi: 'pypi', maven: 'maven', golang: 'golang', gem: 'gem', composer: 'composer', cargo: 'cargo', nuget: 'nuget' };
    const t = map[c.ecosystem] || 'generic';
    return `pkg:${t}/${encodeURIComponent(c.name)}@${encodeURIComponent(c.version)}`;
  }

  CITADEL.sbom = { parse, manifestType, riskFlags, cyclonedx, MANIFESTS };
})(window);
