/* CITADEL — Package Integrity & Provenance (Phase 7, Module 1)
 * Parses lockfiles OFFLINE to recover per-package integrity hashes, then cross-
 * references the SBOM components to surface reproducibility / supply-chain gaps:
 * missing lockfiles, low hash coverage, manifest<->lockfile drift, git/http/path
 * dependencies (no registry integrity), and insecure / non-default registries.
 *
 * Pure & defensive: no network, no DOM, NO Date (timestamps would be passed in
 * via opts if needed). Never throws — each parser is wrapped in try/catch and
 * degrades to a partial/empty result. Worker-safe (the worker sets
 * self.window=self). 2-space indent, single quotes, semicolons.
 *
 * window.CITADEL.integrity
 *
 * analyze(entries, components) -> {
 *   hashes:     { [ecosystem|name|version]: { alg, value, source } },
 *   lockfiles:  [{ ecosystem, file, packages, withHash }],
 *   drift:      [{ name, version, ecosystem, issue }],
 *   registries: [{ file, registry, secure }],
 *   findings:   [Finding],
 *   summary:    { lockfileCount, hashCoverage, drift, manifestsWithoutLock,
 *                 gitOrHttpDeps, score }
 * }
 *
 * SCORE FORMULA (documented; clamped 0..100, integer):
 *   start 100
 *     - 18 for EACH manifest ecosystem that has no lockfile (unreproducible)
 *           capped at -54 (so at most 3 weigh fully)
 *     - up to -25 for low hash coverage: when a lockfile is present but coverage
 *           c < 0.60, subtract round((0.60 - c) / 0.60 * 25)
 *     - 2 per drift entry (missing-from-lockfile / version-mismatch), capped -20
 *     - 4 per git/http/path dependency, capped -20
 *     - 8 for EACH insecure (http) registry, 4 for each non-default (https)
 *           registry, capped -24 combined
 *   clamp to [0,100].
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // Cap regexes on very large lockfiles to keep this cheap in a worker.
  const MAX_SCAN = 500 * 1024; // ~500KB

  // ---------------------------------------------------------------------------
  // Lockfile / manifest classification. Mirrors sbom.js MANIFESTS so ecosystem
  // labels stay consistent: npm | pypi | maven | golang | gem | composer |
  // cargo | nuget.
  // ---------------------------------------------------------------------------

  // basename -> ecosystem, for LOCKFILES (the files that carry integrity data).
  const LOCKFILES = {
    'package-lock.json': 'npm',
    'npm-shrinkwrap.json': 'npm',
    'yarn.lock': 'npm',
    'pnpm-lock.yaml': 'npm',
    'cargo.lock': 'cargo',
    'composer.lock': 'composer',
    'poetry.lock': 'pypi',
    'pipfile.lock': 'pypi',
    'go.sum': 'golang',
    'gemfile.lock': 'gem'
  };

  // basename -> ecosystem, for MANIFESTS (declare deps; may lack a lockfile).
  const MANIFESTS = {
    'package.json': 'npm',
    'requirements.txt': 'pypi',
    'pipfile': 'pypi',
    'pyproject.toml': 'pypi',
    'pom.xml': 'maven',
    'build.gradle': 'maven',
    'go.mod': 'golang',
    'gemfile': 'gem',
    'composer.json': 'composer',
    'cargo.toml': 'cargo'
  };

  // Which lockfile basenames satisfy a given manifest ecosystem.
  const LOCK_FOR_ECOSYSTEM = {
    npm: ['package-lock.json', 'npm-shrinkwrap.json', 'yarn.lock', 'pnpm-lock.yaml'],
    pypi: ['poetry.lock', 'pipfile.lock'],
    cargo: ['cargo.lock'],
    composer: ['composer.lock'],
    golang: ['go.sum'],
    gem: ['gemfile.lock']
    // maven / nuget have no universally-committed hash lockfile we parse here.
  };

  // ---------------------------------------------------------------------------
  // Generic helpers
  // ---------------------------------------------------------------------------

  function asArray(v) { return Array.isArray(v) ? v : []; }
  function lc(s) { return String(s == null ? '' : s).toLowerCase(); }

  function basename(path) {
    return String(path || '').split('!/').pop().split('/').pop();
  }

  // Only consider real text entries with content; skip binaries.
  function isTextEntry(e) {
    return !!(e && e.path && typeof e.content === 'string' && e.content && !e.isBinary);
  }

  // Slice oversized lockfiles before running regexes.
  function capped(content) {
    const s = String(content == null ? '' : content);
    return s.length > MAX_SCAN ? s.slice(0, MAX_SCAN) : s;
  }

  function ecosystemForLockBase(base) {
    if (base.endsWith('.csproj')) return null; // not a hash lockfile
    return LOCKFILES[base] || null;
  }

  function ecosystemForManifestBase(base) {
    if (base.endsWith('.csproj')) return 'nuget';
    return MANIFESTS[base] || null;
  }

  // Key used across hashes + drift cross-referencing.
  function keyFor(ecosystem, name, version) {
    return ecosystem + '|' + lc(name) + '|' + String(version == null ? '' : version);
  }

  // ---------------------------------------------------------------------------
  // Per-lockfile hash parsers. Each returns an array of
  // { name, version, alg, value } (best-effort; partial on malformed input).
  // ---------------------------------------------------------------------------

  function algOfIntegrity(integrity) {
    // npm-style SRI: "sha512-...", "sha256-...", "sha1-...".
    const m = /^(sha512|sha384|sha256|sha1)-/i.exec(String(integrity || ''));
    return m ? lc(m[1]) : null;
  }

  // package-lock.json — v2/v3 `packages` map + v1 `dependencies` tree.
  function parsePackageLock(content) {
    const out = [];
    let j;
    try { j = JSON.parse(content); } catch (e) { return out; }
    if (!j || typeof j !== 'object') return out;

    // v2/v3: packages: { "node_modules/<name>": { version, integrity } }
    const packages = j.packages && typeof j.packages === 'object' ? j.packages : null;
    if (packages) {
      Object.keys(packages).forEach(p => {
        try {
          if (!p) return; // "" is the root project, no integrity
          const node = packages[p];
          if (!node || typeof node !== 'object') return;
          // name is the path after the LAST node_modules/ segment.
          const idx = p.lastIndexOf('node_modules/');
          const name = idx >= 0 ? p.slice(idx + 'node_modules/'.length) : p;
          if (!name) return;
          const alg = algOfIntegrity(node.integrity);
          if (alg && node.integrity) {
            out.push({ name: name, version: node.version || '', alg: alg, value: String(node.integrity) });
          }
        } catch (e) { /* skip entry */ }
      });
    }

    // v1: dependencies: { "<name>": { version, integrity, dependencies:{...} } }
    function walkV1(deps) {
      if (!deps || typeof deps !== 'object') return;
      Object.keys(deps).forEach(name => {
        try {
          const node = deps[name];
          if (!node || typeof node !== 'object') return;
          const alg = algOfIntegrity(node.integrity);
          if (alg && node.integrity) {
            out.push({ name: name, version: node.version || '', alg: alg, value: String(node.integrity) });
          }
          if (node.dependencies) walkV1(node.dependencies);
        } catch (e) { /* skip */ }
      });
    }
    if (!packages && j.dependencies) walkV1(j.dependencies);

    return out;
  }

  // yarn.lock — classic format. Block headers like:
  //   "express@^4.0.0", express@^4.18.0:
  //     version "4.18.2"
  //     resolved "..."
  //     integrity sha512-...
  function parseYarnLock(content) {
    const out = [];
    const text = capped(content);
    const blocks = text.split(/\n(?=\S)/); // split on lines starting in col 0
    blocks.forEach(block => {
      try {
        if (!/integrity\s+/.test(block)) return;
        const header = block.split('\n')[0];
        // First spec in the header: strip quotes, take up to last '@'.
        const firstSpec = header.split(',')[0].replace(/["':]/g, '').trim();
        if (!firstSpec) return;
        const at = firstSpec.lastIndexOf('@');
        const name = at > 0 ? firstSpec.slice(0, at) : firstSpec;
        const vm = /\n\s*version\s+"?([^"\n]+)"?/.exec(block);
        const version = vm ? vm[1].trim() : '';
        const im = /\n\s*integrity\s+([^\s"]+)/.exec(block);
        if (!im) return;
        const integrity = im[1].trim();
        const alg = algOfIntegrity(integrity);
        if (alg) out.push({ name: name, version: version, alg: alg, value: integrity });
      } catch (e) { /* skip block */ }
    });
    return out;
  }

  // pnpm-lock.yaml — packages section with resolution.integrity. Package keys
  // look like "/express@4.18.2" or "/@scope/pkg@1.0.0" (older: "/express/4.18.2").
  function parsePnpmLock(content) {
    const out = [];
    const text = capped(content);
    // Split into "  /pkg...:" blocks under packages:.
    const lines = text.split('\n');
    let curName = null;
    let curVer = null;
    for (let i = 0; i < lines.length; i++) {
      try {
        const line = lines[i];
        const keyM = /^\s{2}(\/[^:]+):\s*$/.exec(line) || /^\s{2}'(\/[^']+)':\s*$/.exec(line);
        if (keyM) {
          const ref = keyM[1].replace(/^\//, '');
          // "@scope/pkg@1.0.0(peer)" or "express@4.18.2" or "express/4.18.2"
          let nm = ref.replace(/\(.*$/, '');
          let name = '';
          let ver = '';
          if (nm.indexOf('@') > 0) {
            const at = nm.lastIndexOf('@');
            name = nm.slice(0, at);
            ver = nm.slice(at + 1);
          } else {
            const slash = nm.lastIndexOf('/');
            if (slash > 0) { name = nm.slice(0, slash); ver = nm.slice(slash + 1); }
            else { name = nm; }
          }
          curName = name;
          curVer = ver;
          continue;
        }
        if (curName != null) {
          const intM = /integrity:\s*([^\s'"]+)/.exec(line);
          if (intM) {
            const integrity = intM[1].trim();
            const alg = algOfIntegrity(integrity);
            if (alg) out.push({ name: curName, version: curVer || '', alg: alg, value: integrity });
            curName = null; curVer = null;
          } else if (/^\s{2}\S/.test(line) && !/^\s{4,}/.test(line)) {
            // a new top-level-ish key without integrity yet; keep scanning,
            // but reset if we've clearly left the block.
          }
        }
      } catch (e) { /* skip line */ }
    }
    return out;
  }

  // Cargo.lock — TOML array of tables. [[package]] name/version/checksum (sha256).
  function parseCargoLock(content) {
    const out = [];
    const text = capped(content);
    const blocks = text.split(/\n\s*\[\[package\]\]\s*\n/);
    blocks.forEach(block => {
      try {
        const nm = /(^|\n)\s*name\s*=\s*"([^"]+)"/.exec(block);
        const vm = /(^|\n)\s*version\s*=\s*"([^"]+)"/.exec(block);
        const cm = /(^|\n)\s*checksum\s*=\s*"([0-9a-fA-F]+)"/.exec(block);
        if (nm && cm) {
          out.push({ name: nm[2], version: vm ? vm[2] : '', alg: 'sha256', value: cm[2] });
        }
      } catch (e) { /* skip */ }
    });
    return out;
  }

  // composer.lock — JSON. packages[].name/version + dist.shasum or dist.reference.
  function parseComposerLock(content) {
    const out = [];
    let j;
    try { j = JSON.parse(content); } catch (e) { return out; }
    if (!j || typeof j !== 'object') return out;
    const groups = [];
    if (Array.isArray(j.packages)) groups.push(j.packages);
    if (Array.isArray(j['packages-dev'])) groups.push(j['packages-dev']);
    groups.forEach(list => {
      list.forEach(p => {
        try {
          if (!p || typeof p !== 'object' || !p.name) return;
          const dist = p.dist && typeof p.dist === 'object' ? p.dist : null;
          const hash = dist && (dist.shasum || dist.reference);
          if (!hash) return;
          // composer shasums are typically sha1 hex (40); references are commit SHAs.
          const isSha1 = /^[0-9a-f]{40}$/i.test(String(hash));
          out.push({
            name: p.name,
            version: p.version || '',
            alg: isSha1 ? 'sha1' : 'sha1',
            value: String(hash)
          });
        } catch (e) { /* skip */ }
      });
    });
    return out;
  }

  // poetry.lock — TOML; [[package]] name/version, then [package.<name>] or a
  // metadata.files / [package] files table with "sha256:..." hashes. We attach
  // the first sha256 we find within each package block.
  function parsePoetryLock(content) {
    const out = [];
    const text = capped(content);
    const blocks = text.split(/\n\s*\[\[package\]\]\s*\n/);
    blocks.forEach(block => {
      try {
        const nm = /(^|\n)\s*name\s*=\s*"([^"]+)"/.exec(block);
        const vm = /(^|\n)\s*version\s*=\s*"([^"]+)"/.exec(block);
        if (!nm) return;
        const hm = /sha256:([0-9a-fA-F]{64})/.exec(block);
        if (hm) out.push({ name: nm[2], version: vm ? vm[2] : '', alg: 'sha256', value: hm[1] });
      } catch (e) { /* skip */ }
    });
    return out;
  }

  // Pipfile.lock — JSON. default/develop maps: { "<name>": { version, hashes:[
  // "sha256:..." ] } }.
  function parsePipfileLock(content) {
    const out = [];
    let j;
    try { j = JSON.parse(content); } catch (e) { return out; }
    if (!j || typeof j !== 'object') return out;
    ['default', 'develop'].forEach(section => {
      const grp = j[section];
      if (!grp || typeof grp !== 'object') return;
      Object.keys(grp).forEach(name => {
        try {
          const node = grp[name];
          if (!node || typeof node !== 'object') return;
          let version = node.version || '';
          version = String(version).replace(/^==/, '');
          const hashes = Array.isArray(node.hashes) ? node.hashes : [];
          let found = null;
          for (let i = 0; i < hashes.length; i++) {
            const m = /sha256:([0-9a-fA-F]{64})/.exec(String(hashes[i]));
            if (m) { found = m[1]; break; }
          }
          if (found) out.push({ name: name, version: version, alg: 'sha256', value: found });
        } catch (e) { /* skip */ }
      });
    });
    return out;
  }

  // go.sum — lines: "<module> <version> h1:<base64>=" and a "/go.mod h1:" line.
  // We index by the module-only entries (skip the "/go.mod" hash lines).
  function parseGoSum(content) {
    const out = [];
    const text = capped(content);
    text.split('\n').forEach(line => {
      try {
        const m = /^(\S+)\s+(\S+?)\s+h1:(\S+)$/.exec(line.trim());
        if (!m) return;
        const mod = m[1];
        const ver = m[2];
        if (/\/go\.mod$/.test(ver)) return; // skip the go.mod-hash variant
        out.push({ name: mod, version: ver, alg: 'h1', value: 'h1:' + m[3] });
      } catch (e) { /* skip */ }
    });
    return out;
  }

  // Dispatch to the right parser by lockfile basename.
  function parseLockfile(base, content) {
    try {
      switch (base) {
        case 'package-lock.json':
        case 'npm-shrinkwrap.json': return parsePackageLock(content);
        case 'yarn.lock': return parseYarnLock(content);
        case 'pnpm-lock.yaml': return parsePnpmLock(content);
        case 'cargo.lock': return parseCargoLock(content);
        case 'composer.lock': return parseComposerLock(content);
        case 'poetry.lock': return parsePoetryLock(content);
        case 'pipfile.lock': return parsePipfileLock(content);
        case 'go.sum': return parseGoSum(content);
        case 'gemfile.lock': return []; // present-only: no per-gem hash
        default: return [];
      }
    } catch (e) { return []; }
  }

  // ---------------------------------------------------------------------------
  // Registry config scanning (.npmrc / .yarnrc[.yml] / pip.conf / composer repos)
  // Returns [{ file, registry, secure }].
  // ---------------------------------------------------------------------------

  function scanRegistries(entries) {
    const out = [];
    asArray(entries).forEach(e => {
      try {
        if (!isTextEntry(e)) return;
        const base = basename(e.path).toLowerCase();
        const c = capped(e.content);

        if (base === '.npmrc') {
          (c.match(/(?:^|\n)\s*(?:[^=\n]*:)?registry\s*=\s*(\S+)/gi) || []).forEach(line => {
            const url = line.split('=').slice(1).join('=').trim();
            if (!url) return;
            const secure = !/^http:\/\//i.test(url);
            const isDefault = /registry\.npmjs\.org/i.test(url);
            if (!isDefault || !secure) out.push({ file: e.path, registry: url, secure: secure });
          });
        } else if (base === '.yarnrc' || base === '.yarnrc.yml') {
          (c.match(/(?:npmRegistryServer|registry)\s*:?\s*"?(\S+?)"?\s*$/gim) || []).forEach(line => {
            const m = /(https?:\/\/\S+)/i.exec(line);
            if (!m) return;
            const url = m[1].replace(/["']/g, '');
            const secure = !/^http:\/\//i.test(url);
            const isDefault = /registry\.(npmjs|yarnpkg)\.(org|com)/i.test(url);
            if (!isDefault || !secure) out.push({ file: e.path, registry: url, secure: secure });
          });
        } else if (base === 'pip.conf' || base === 'pip.ini') {
          (c.match(/(?:index-url|extra-index-url)\s*=\s*(\S+)/gi) || []).forEach(line => {
            const url = line.split('=').slice(1).join('=').trim();
            if (!url) return;
            const secure = !/^http:\/\//i.test(url);
            const isDefault = /pypi\.org/i.test(url);
            if (!isDefault || !secure) out.push({ file: e.path, registry: url, secure: secure });
          });
        } else if (base === 'composer.json') {
          if (/"repositories"\s*:/.test(c)) {
            (c.match(/"url"\s*:\s*"(https?:\/\/[^"]+)"/gi) || []).forEach(u => {
              const m = /(https?:\/\/[^"]+)/i.exec(u);
              if (!m) return;
              const url = m[1];
              const secure = !/^http:\/\//i.test(url);
              const isDefault = /packagist\.org|repo\.packagist/i.test(url);
              if (!isDefault || !secure) out.push({ file: e.path, registry: url, secure: secure });
            });
          }
        }
      } catch (err) { /* skip entry */ }
    });
    return out;
  }

  // ---------------------------------------------------------------------------
  // git / http / path dependency detection. Reuses component.source/version when
  // present, otherwise inspects manifests heuristically.
  // ---------------------------------------------------------------------------

  function looksGitHttpPath(spec) {
    const v = lc(spec);
    if (!v) return null;
    if (/^git[+:]/.test(v) || /\.git(#|$)/.test(v) || /^git@/.test(v)) return 'git';
    if (/^github:/.test(v) || /^[\w.-]+\/[\w.-]+(#|$)/.test(v) && !/^\d/.test(v) && v.indexOf(' ') < 0) {
      // "user/repo" or "user/repo#ref" shorthand (npm). Avoid plain ranges.
      if (/^[\w.-]+\/[\w.-]+(#[\w./-]+)?$/.test(v)) return 'git';
    }
    if (/^http:\/\//.test(v)) return 'http';
    if (/^https:\/\//.test(v) && /\.(tgz|tar\.gz|zip)(#|$)/.test(v)) return 'http'; // tarball url
    if (/^(file:|link:|path:|\.\.?\/)/.test(v)) return 'path';
    return null;
  }

  function detectGitHttpPathDeps(entries, components) {
    const out = []; // { name, ecosystem, kind, version }
    const seen = {};

    // 1) From components that already carry a source.
    asArray(components).forEach(c => {
      try {
        if (!c || !c.name) return;
        const src = lc(c.source);
        let kind = null;
        if (src === 'git' || src === 'http' || src === 'path') kind = src;
        else kind = looksGitHttpPath(c.source) || looksGitHttpPath(c.version);
        if (kind) {
          const k = lc(c.name) + '|' + kind;
          if (!seen[k]) { seen[k] = 1; out.push({ name: c.name, ecosystem: c.ecosystem || '', kind: kind, version: c.version || '' }); }
        }
      } catch (e) { /* skip */ }
    });

    // 2) From manifests directly (package.json dep specs, requirements.txt VCS).
    asArray(entries).forEach(e => {
      try {
        if (!isTextEntry(e)) return;
        const base = basename(e.path).toLowerCase();
        if (base === 'package.json') {
          let j;
          try { j = JSON.parse(capped(e.content)); } catch (err) { return; }
          ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'].forEach(section => {
            const grp = j && j[section];
            if (!grp || typeof grp !== 'object') return;
            Object.keys(grp).forEach(name => {
              const kind = looksGitHttpPath(grp[name]);
              if (kind) {
                const k = lc(name) + '|' + kind;
                if (!seen[k]) { seen[k] = 1; out.push({ name: name, ecosystem: 'npm', kind: kind, version: String(grp[name]) }); }
              }
            });
          });
        } else if (base === 'requirements.txt' || /^requirements.*\.txt$/.test(base)) {
          capped(e.content).split('\n').forEach(line => {
            const t = line.trim();
            if (!t || t.charAt(0) === '#') return;
            const kind = looksGitHttpPath(t) || (/(?:git\+|https?:\/\/|file:)/i.test(t) ? (/^http:\/\//i.test(t) ? 'http' : (/git/i.test(t) ? 'git' : 'http')) : null);
            if (kind) {
              const nm = (t.match(/egg=([\w.-]+)/) || [])[1] || t.split(/[@#]/)[0].slice(0, 60);
              const k = lc(nm) + '|' + kind;
              if (!seen[k]) { seen[k] = 1; out.push({ name: nm, ecosystem: 'pypi', kind: kind, version: '' }); }
            }
          });
        }
      } catch (err) { /* skip entry */ }
    });

    return out;
  }

  // ---------------------------------------------------------------------------
  // Finding factory + compliance mappings
  // ---------------------------------------------------------------------------

  function scvs(control, note) { return { framework: 'OWASP SCVS', control: control, note: note }; }
  function slsa(control, note) { return { framework: 'SLSA', control: control, note: note }; }
  function ssdf(control, note) { return { framework: 'NIST SSDF', control: control, note: note }; }
  function sr(control, note) { return { framework: 'NIST 800-53', control: control, note: note }; }
  function n171(control, note) { return { framework: 'NIST 800-171', control: control, note: note }; }
  function cis(control, note) { return { framework: 'CIS Controls', control: control, note: note }; }

  function makeFinding(o) {
    return {
      ruleId: o.ruleId,
      name: o.name,
      category: 'supply-chain',
      severity: o.severity,
      confidence: o.confidence || 'medium',
      cwe: o.cwe || null,
      file: o.file || null,
      line: o.line || null,
      snippet: o.snippet || null,
      remediation: o.remediation,
      source: o.source || 'CITADEL integrity',
      module: 'integrity',
      impact: o.impact,
      likelihood: o.likelihood,
      remediationEffort: o.remediationEffort,
      references: o.references || [],
      complianceMappings: o.complianceMappings || []
    };
  }

  // Shared compliance set for integrity / provenance findings (note phrasing is
  // deliberately non-false-certain — "supports", "may indicate", "consistent
  // with", rather than asserting compliance/non-compliance).
  function integrityMappings(extraNote) {
    const tail = extraNote ? (' ' + extraNote) : '';
    return [
      scvs('V2 Package Management', 'Verifiable package management supports SCVS V2 expectations.' + tail),
      scvs('V4 Component Integrity', 'Recorded integrity hashes support SCVS V4 component-integrity verification.'),
      slsa('Provenance', 'Pinned, hash-verified artifacts are consistent with SLSA provenance/build-integrity goals.'),
      ssdf('PS.2', 'Verifying third-party component integrity aligns with SSDF PS.2.'),
      ssdf('PS.3', 'Archiving/protecting release integrity data aligns with SSDF PS.3.'),
      sr('SR-3', 'Supply-chain controls (SR-3) may benefit from enforced integrity verification.'),
      sr('SR-4', 'Provenance tracking (SR-4) is supported by per-package hashes.'),
      n171('3.13.x', 'Integrity protections relate to NIST 800-171 3.13.x system/communication integrity expectations.'),
      cis('16', 'CIS Control 16 (application software security) encourages verified, reproducible dependencies.')
    ];
  }

  // ---------------------------------------------------------------------------
  // Scoring (see header for the documented formula).
  // ---------------------------------------------------------------------------

  function clampInt(n) {
    if (isNaN(n)) return 0;
    n = Math.round(n);
    return n < 0 ? 0 : (n > 100 ? 100 : n);
  }

  function computeScore(parts) {
    // parts: { manifestsWithoutLock, hasLockfile, hashCoverage, drift,
    //          gitOrHttpDeps, insecureRegistries, offDefaultRegistries }
    let score = 100;

    score -= Math.min(54, 18 * parts.manifestsWithoutLock);

    if (parts.hasLockfile && parts.hashCoverage < 0.60) {
      const deficit = (0.60 - parts.hashCoverage) / 0.60; // 0..1
      score -= Math.round(deficit * 25);
    }

    score -= Math.min(20, 2 * parts.drift);
    score -= Math.min(20, 4 * parts.gitOrHttpDeps);

    const regPenalty = 8 * parts.insecureRegistries + 4 * parts.offDefaultRegistries;
    score -= Math.min(24, regPenalty);

    return clampInt(score);
  }

  // ---------------------------------------------------------------------------
  // main
  // ---------------------------------------------------------------------------

  function analyze(entries, components) {
    const result = {
      hashes: {},
      lockfiles: [],
      drift: [],
      registries: [],
      findings: [],
      summary: {
        lockfileCount: 0,
        hashCoverage: 0,
        drift: 0,
        manifestsWithoutLock: 0,
        gitOrHttpDeps: 0,
        score: 100
      }
    };

    const list = asArray(entries);
    const comps = asArray(components).filter(c => c && typeof c === 'object' && c.name);

    // ---- 1. Parse every lockfile present -> hashes map + lockfiles inventory.
    const lockEcosystems = {};   // ecosystem -> true (a lockfile exists)
    try {
      list.forEach(e => {
        try {
          if (!isTextEntry(e)) return;
          const base = basename(e.path).toLowerCase();
          const eco = ecosystemForLockBase(base);
          if (!eco) return;
          lockEcosystems[eco] = true;
          const parsed = parseLockfile(base, e.content);
          let withHash = 0;
          parsed.forEach(p => {
            try {
              if (!p || !p.name || !p.alg || !p.value) return;
              const key = keyFor(eco, p.name, p.version);
              if (!result.hashes[key]) {
                result.hashes[key] = { alg: p.alg, value: p.value, source: e.path };
              }
              withHash++;
            } catch (err) { /* skip */ }
          });
          result.lockfiles.push({
            ecosystem: eco,
            file: e.path,
            packages: parsed.length,
            withHash: withHash
          });
        } catch (err) { /* skip lockfile */ }
      });
    } catch (e) { /* keep partial */ }

    // ---- 2. Manifest inventory -> which ecosystems declare deps.
    const manifestEcosystems = {}; // ecosystem -> first manifest path
    try {
      list.forEach(e => {
        try {
          if (!isTextEntry(e)) return;
          const base = basename(e.path).toLowerCase();
          const eco = ecosystemForManifestBase(base);
          if (!eco) return;
          if (!manifestEcosystems[eco]) manifestEcosystems[eco] = e.path;
        } catch (err) { /* skip */ }
      });
    } catch (e) { /* keep partial */ }

    // ---- 3. Registry config scan.
    try { result.registries = scanRegistries(list); } catch (e) { result.registries = []; }
    const insecureRegistries = result.registries.filter(r => r && r.secure === false);
    const offDefaultRegistries = result.registries.filter(r => r && r.secure !== false);

    // ---- 4. git / http / path dependencies.
    let gitHttpPath = [];
    try { gitHttpPath = detectGitHttpPathDeps(list, comps); } catch (e) { gitHttpPath = []; }

    // ---- 5. Drift: manifest-declared component absent from lockfile / version
    //         mismatch. Only meaningful for ecosystems that HAVE a lockfile.
    try {
      comps.forEach(c => {
        try {
          const eco = c.ecosystem;
          if (!eco || !lockEcosystems[eco]) return; // no lockfile to compare against
          const wantKey = keyFor(eco, c.name, c.version);
          if (result.hashes[wantKey]) return; // exact name+version present
          // Is the name present at ANY version in this ecosystem's lockfile?
          const namePrefix = eco + '|' + lc(c.name) + '|';
          let nameSeen = false;
          const keys = Object.keys(result.hashes);
          for (let i = 0; i < keys.length; i++) {
            if (keys[i].indexOf(namePrefix) === 0) { nameSeen = true; break; }
          }
          // Skip floating specs (^, ~, *, ranges) — a missing exact match there
          // is expected, not drift.
          const ver = String(c.version == null ? '' : c.version).trim();
          const floating = ver === '' || ver === '*' || /^[\^~><=]/.test(ver) ||
            /\b(x|latest)\b/i.test(ver) || /\|\|/.test(ver);
          if (floating) return;
          result.drift.push({
            name: c.name,
            version: c.version || '',
            ecosystem: eco,
            issue: nameSeen ? 'version-mismatch' : 'missing-from-lockfile'
          });
        } catch (err) { /* skip component */ }
      });
    } catch (e) { /* keep partial drift */ }

    // ---- 6. Hash coverage over components with a KNOWN ecosystem.
    let knownEcoComps = 0;
    let covered = 0;
    try {
      comps.forEach(c => {
        const eco = c.ecosystem;
        if (!eco) return;
        knownEcoComps++;
        if (result.hashes[keyFor(eco, c.name, c.version)]) covered++;
      });
    } catch (e) { /* keep zeros */ }
    const hashCoverage = knownEcoComps > 0 ? covered / knownEcoComps : 0;

    // ---- 7. Findings ---------------------------------------------------------

    // 7a. no-lockfile per manifest ecosystem lacking a satisfying lockfile.
    let manifestsWithoutLock = 0;
    try {
      Object.keys(manifestEcosystems).forEach(eco => {
        const lockNames = LOCK_FOR_ECOSYSTEM[eco];
        if (!lockNames) return; // maven/nuget — no hash-lockfile concept here
        const hasLock = lockNames.some(n => lockEcosystems[eco] &&
          result.lockfiles.some(l => l.ecosystem === eco));
        if (!hasLock) {
          manifestsWithoutLock++;
          result.findings.push(makeFinding({
            ruleId: 'integrity/no-lockfile',
            name: 'Dependency manifest without a lockfile (' + eco + ')',
            severity: 'high',
            confidence: 'high',
            cwe: 'CWE-1357',
            file: manifestEcosystems[eco],
            remediation: 'Generate and commit the ecosystem lockfile (' + lockNames.join(' / ') +
              ') so installs are reproducible and integrity hashes are verified.',
            impact: 'Without a lockfile, dependency versions and integrity hashes are not pinned, so installs are not reproducible and tampered/yanked artifacts may be pulled.',
            likelihood: 'medium',
            remediationEffort: 'low',
            references: [
              'https://owasp.org/www-project-software-component-verification-standard/',
              'https://slsa.dev/spec/v1.0/provenance'
            ],
            complianceMappings: integrityMappings('A committed lockfile supports reproducible, verifiable installs.')
          }));
        }
      });
    } catch (e) { /* keep partial */ }

    // 7b. low-hash-coverage per lockfile under 60%.
    try {
      result.lockfiles.forEach(l => {
        try {
          if (l.ecosystem === 'gem') return; // Gemfile.lock has no per-gem hash
          if (!l.packages) return;
          const cov = l.withHash / l.packages;
          if (cov < 0.60) {
            result.findings.push(makeFinding({
              ruleId: 'integrity/low-hash-coverage',
              name: 'Low integrity-hash coverage in ' + basename(l.file),
              severity: 'medium',
              confidence: 'medium',
              cwe: 'CWE-353',
              file: l.file,
              remediation: 'Regenerate the lockfile with a tool/version that records integrity hashes for every resolved package, and verify hashes on install.',
              impact: 'Packages lacking an integrity hash are installed without artifact verification, weakening tamper detection.',
              likelihood: 'low',
              remediationEffort: 'low',
              references: ['https://owasp.org/www-project-software-component-verification-standard/'],
              complianceMappings: integrityMappings('Higher hash coverage strengthens SCVS V4 component-integrity assurance.')
            }));
          }
        } catch (err) { /* skip lockfile */ }
      });
    } catch (e) { /* keep partial */ }

    // 7c. drift finding (aggregated) when any drift exists.
    try {
      if (result.drift.length) {
        const sample = result.drift.slice(0, 8).map(d => d.name + '@' + (d.version || '*') + ' (' + d.issue + ')');
        result.findings.push(makeFinding({
          ruleId: 'integrity/drift',
          name: 'Manifest / lockfile dependency drift',
          severity: 'medium',
          confidence: 'medium',
          cwe: 'CWE-1104',
          file: null,
          snippet: sample.join(', '),
          remediation: 'Re-resolve the lockfile so it matches the manifest (e.g. npm install / cargo update / composer update) and commit the result.',
          impact: 'When declared dependencies are missing from the lockfile or resolve to a different version, the verified/installed artifacts may differ from what was reviewed.',
          likelihood: 'medium',
          remediationEffort: 'low',
          references: ['https://slsa.dev/spec/v1.0/provenance'],
          complianceMappings: integrityMappings('Manifest/lockfile agreement supports reproducible, verifiable builds.')
        }));
      }
    } catch (e) { /* skip */ }

    // 7d. git / http / path dependency findings (aggregated by kind).
    try {
      ['git', 'http', 'path'].forEach(kind => {
        const items = gitHttpPath.filter(d => d.kind === kind);
        if (!items.length) return;
        const sev = kind === 'http' ? 'high' : 'medium';
        const label = kind === 'git' ? 'Git-sourced' : kind === 'http' ? 'HTTP/tarball-sourced' : 'Local-path / link';
        result.findings.push(makeFinding({
          ruleId: 'integrity/' + kind + '-dependency',
          name: label + ' dependencies bypass registry integrity',
          severity: sev,
          confidence: 'medium',
          cwe: kind === 'http' ? 'CWE-494' : 'CWE-1357',
          file: null,
          snippet: items.slice(0, 8).map(d => d.name).join(', '),
          remediation: kind === 'http'
            ? 'Fetch dependencies over HTTPS from a trusted registry, or vendor the artifact with a recorded integrity hash.'
            : (kind === 'git'
              ? 'Pin Git dependencies to an immutable commit SHA (not a branch) or publish them to a trusted registry with integrity hashes.'
              : 'Replace path/link dependencies with published, hash-verified registry releases for shipped builds.'),
          impact: 'Dependencies sourced outside a registry have no published integrity hash, so tampering or upstream changes can go undetected and builds are non-reproducible.',
          likelihood: kind === 'http' ? 'medium' : 'low',
          remediationEffort: 'medium',
          references: [
            'https://slsa.dev/spec/v1.0/provenance',
            'https://owasp.org/www-project-software-component-verification-standard/'
          ],
          complianceMappings: integrityMappings('Registry-sourced, hash-verified dependencies support SCVS/SLSA provenance goals.')
        }));
      });
    } catch (e) { /* skip */ }

    // 7e. insecure / non-default registry findings.
    try {
      if (insecureRegistries.length) {
        result.findings.push(makeFinding({
          ruleId: 'integrity/insecure-registry',
          name: 'Package registry configured over insecure HTTP',
          severity: 'high',
          confidence: 'high',
          cwe: 'CWE-319',
          file: insecureRegistries[0].file,
          snippet: insecureRegistries.slice(0, 5).map(r => r.registry).join(', '),
          remediation: 'Switch the registry URL(s) to HTTPS and confirm the host is a trusted, authenticated mirror.',
          impact: 'Plain-HTTP registries allow man-in-the-middle tampering of downloaded packages, defeating integrity verification.',
          likelihood: 'medium',
          remediationEffort: 'low',
          references: ['https://owasp.org/www-project-software-component-verification-standard/'],
          complianceMappings: integrityMappings('Transport security for package retrieval supports SCVS V2 package-management expectations.')
        }));
      }
      if (offDefaultRegistries.length) {
        result.findings.push(makeFinding({
          ruleId: 'integrity/non-default-registry',
          name: 'Non-default package registry configured',
          severity: 'medium',
          confidence: 'medium',
          cwe: 'CWE-494',
          file: offDefaultRegistries[0].file,
          snippet: offDefaultRegistries.slice(0, 5).map(r => r.registry).join(', '),
          remediation: 'Confirm the custom registry/mirror is trusted and access-controlled; pin scoped registries explicitly so unexpected sources cannot be substituted.',
          impact: 'Installs pointed at a non-default registry may pull artifacts from an unvetted or substitutable source (a dependency-confusion / substitution surface).',
          likelihood: 'low',
          remediationEffort: 'low',
          references: ['https://owasp.org/www-project-software-component-verification-standard/'],
          complianceMappings: integrityMappings('Verifying registry trust supports SCVS V2 package-management expectations.')
        }));
      }
    } catch (e) { /* skip */ }

    // ---- 8. Summary + score.
    const hasLockfile = result.lockfiles.length > 0;
    result.summary.lockfileCount = result.lockfiles.length;
    result.summary.hashCoverage = hashCoverage;
    result.summary.drift = result.drift.length;
    result.summary.manifestsWithoutLock = manifestsWithoutLock;
    result.summary.gitOrHttpDeps = gitHttpPath.length;
    result.summary.score = computeScore({
      manifestsWithoutLock: manifestsWithoutLock,
      hasLockfile: hasLockfile,
      hashCoverage: hashCoverage,
      drift: result.drift.length,
      gitOrHttpDeps: gitHttpPath.length,
      insecureRegistries: insecureRegistries.length,
      offDefaultRegistries: offDefaultRegistries.length
    });

    return result;
  }

  CITADEL.integrity = { analyze: analyze };
})(window);
