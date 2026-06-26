/* CITADEL — Dependency Review: Security, Licenses, Docs, Gaps, Scores
 * Consumes the dependency inventory (deps module), runtime facts (runtime
 * module), raw manifest licenses, the report's findings, and the raw entries,
 * then derives: CVE buckets, supply-chain heuristics, license classification,
 * documentation coverage, blocking gaps, bucketed recommendations, and a set of
 * deterministic 0..100 scores. Pure, defensive, offline. Never throws — each
 * section is wrapped in try/catch and degrades to an empty result on failure.
 * window.CITADEL.depreviewSecurity
 *
 * analyze({ entries, dependencies, licensesRaw, runtime, findings })
 *   -> { security, licenses, docs, missing, recommendations, scores }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // ===========================================================================
  // Curated reference lists. ALL of these are intentionally SMALL and
  // ILLUSTRATIVE — they are not exhaustive vulnerability/abandonment databases.
  // They exist to surface well-known, high-signal cases offline. Names are
  // lowercased for matching.
  // ===========================================================================

  // Well-known deprecated / abandoned / maintenance-mode packages (npm-centric).
  const DEPRECATED = {
    'request': 'Fully deprecated (2020); use native fetch / undici / axios.',
    'node-uuid': 'Deprecated; renamed to the "uuid" package.',
    'istanbul': 'Deprecated; superseded by nyc / c8.',
    'gulp-util': 'Deprecated; split into smaller modules.',
    'tslint': 'Deprecated; migrate to ESLint with @typescript-eslint.',
    'bower': 'Deprecated front-end package manager; use npm/yarn.',
    'coffee-script': 'Deprecated; renamed to "coffeescript" and largely unused.',
    'moment': 'In maintenance mode; prefer Luxon / day.js / date-fns.',
    'left-pad': 'Trivial micro-package; use String.prototype.padStart.',
    'babel-core': 'Deprecated; use @babel/core.',
    'core-js@2': 'core-js v2 is unmaintained; upgrade to v3.'
  };

  // Historically COMPROMISED packages (specific versions were malicious). We
  // flag by NAME and call out the version-specific nature in the detail string.
  const COMPROMISED = {
    'event-stream': 'Versions ~3.3.6 shipped the malicious "flatmap-stream" payload (2018).',
    'flatmap-stream': 'Malicious package used in the event-stream incident (2018).',
    'ua-parser-js': 'Versions 0.7.29/0.8.0/1.0.0 were hijacked to install miners/stealers (2021).',
    'coa': 'Hijacked release (2021) shipped a credential stealer.',
    'rc': 'Hijacked release (2021) shipped a credential stealer.',
    'node-ipc': 'Maintainer added destructive "protestware" payloads (2022).',
    'colors': 'Maintainer sabotage introduced infinite loops in some releases (2022).',
    'faker': 'Maintainer wiped the package; some releases broke/behaved oddly (2022).'
  };

  // Packages that commonly compile NATIVE code at install (build-toolchain +
  // potential supply-chain surface via gyp/compilers). info/low severity.
  const NATIVE_EXT = {
    'node-gyp': 1, 'bcrypt': 1, 'sharp': 1, 'grpc': 1, 'node-sass': 1,
    'canvas': 1, 're2': 1, 'sqlite3': 1, 'better-sqlite3': 1, 'usb': 1,
    'serialport': 1, 'psycopg2': 1, 'lxml': 1, 'cryptography': 1, 'pillow': 1,
    'numpy': 1, 'pyyaml': 1
  };

  // Tiny typosquat map: common typo -> the popular package it imitates.
  const TYPOSQUAT = {
    'expres': 'express', 'exprss': 'express',
    'reqeust': 'request', 'requst': 'request',
    'loadsh': 'lodash', 'lodahs': 'lodash', 'lodas': 'lodash',
    'momnet': 'moment', 'momentt': 'moment',
    'crossenv': 'cross-env', 'cros-env': 'cross-env',
    'electron-native-notify': 'electron (malicious imitator)',
    'jquey': 'jquery', 'jqeury': 'jquery',
    'reactt': 'react', 'recat': 'react',
    'babelcli': '@babel/cli', 'mongose': 'mongoose'
  };

  // Documentation topics we look for + the keyword regexes that count as
  // evidence in a README heading / filename.
  const DOC_TOPICS = [
    { topic: 'installation', re: /\b(install(ation|ing)?|getting[ -]started|setup|quick[ -]?start)\b/i },
    { topic: 'configuration', re: /\b(config(uration|ure)?|settings|options)\b/i },
    { topic: 'deployment', re: /\b(deploy(ment|ing)?|release|publishing|hosting)\b/i },
    { topic: 'development', re: /\b(develop(ment|ing)?|contributing|local[ -]dev|architecture)\b/i },
    { topic: 'testing', re: /\b(test(ing|s)?|spec|coverage)\b/i },
    { topic: 'troubleshooting', re: /\b(troubleshoot(ing)?|faq|common[ -]issues|known[ -]issues|debugging)\b/i },
    { topic: 'env-vars', re: /\b(environment[ -]variables?|env[ -]?vars?|\.env|configuration[ -]via[ -]env)\b/i },
    { topic: 'database-setup', re: /\b(database|db[ -]setup|migrations?|schema|seed(ing)?)\b/i },
    { topic: 'ci-cd', re: /\b(ci\/?cd|continuous[ -](integration|delivery|deployment)|pipeline|workflow)\b/i },
    { topic: 'docker', re: /\b(docker(file|[ -]compose)?|container(ize|s)?)\b/i },
    { topic: 'kubernetes', re: /\b(kubernetes|k8s|helm|kustomize)\b/i },
    { topic: 'production', re: /\b(production|prod[ -](deploy|build)|going[ -]live|scaling)\b/i }
  ];

  // SPDX-ish license normalization -> { spdx, category }.
  // category: permissive | weak-copyleft | strong-copyleft | network-copyleft |
  //           commercial | public-domain | unknown
  const LICENSE_CATEGORIES = [
    { re: /^(agpl)/i, spdx: 'AGPL-3.0', category: 'network-copyleft' },
    { re: /^(gpl-?3|gplv3|gnu general public.*3)/i, spdx: 'GPL-3.0', category: 'strong-copyleft' },
    { re: /^(gpl-?2|gplv2|gnu general public.*2|gpl)/i, spdx: 'GPL-2.0', category: 'strong-copyleft' },
    { re: /^(lgpl)/i, spdx: 'LGPL', category: 'weak-copyleft' },
    { re: /^(mpl|mozilla public)/i, spdx: 'MPL-2.0', category: 'weak-copyleft' },
    { re: /^(epl|eclipse public)/i, spdx: 'EPL-2.0', category: 'weak-copyleft' },
    { re: /^(cddl)/i, spdx: 'CDDL-1.0', category: 'weak-copyleft' },
    { re: /^(apache)/i, spdx: 'Apache-2.0', category: 'permissive' },
    { re: /^(mit)/i, spdx: 'MIT', category: 'permissive' },
    { re: /^(bsd-?3|bsd 3|new bsd)/i, spdx: 'BSD-3-Clause', category: 'permissive' },
    { re: /^(bsd-?2|bsd 2|simplified bsd|freebsd)/i, spdx: 'BSD-2-Clause', category: 'permissive' },
    { re: /^(bsd)/i, spdx: 'BSD', category: 'permissive' },
    { re: /^(isc)/i, spdx: 'ISC', category: 'permissive' },
    { re: /^(zlib)/i, spdx: 'Zlib', category: 'permissive' },
    { re: /^(python|psf|python-2)/i, spdx: 'PSF-2.0', category: 'permissive' },
    { re: /^(unlicense)/i, spdx: 'Unlicense', category: 'public-domain' },
    { re: /^(cc0)/i, spdx: 'CC0-1.0', category: 'public-domain' },
    { re: /^(wtfpl)/i, spdx: 'WTFPL', category: 'public-domain' },
    { re: /^(0bsd)/i, spdx: '0BSD', category: 'public-domain' },
    { re: /^(proprietary|commercial|see license|all rights reserved|unlicensed)/i, spdx: null, category: 'commercial' }
  ];

  // ===========================================================================
  // Generic helpers
  // ===========================================================================

  function basename(path) {
    return String(path || '').split('!/').pop().split('/').pop();
  }

  function lc(s) { return String(s == null ? '' : s).toLowerCase(); }

  function asArray(v) { return Array.isArray(v) ? v : []; }

  function allDeps(dependencies) {
    const d = dependencies && typeof dependencies === 'object' ? dependencies : {};
    return asArray(d.prod).concat(asArray(d.dev)).filter(Boolean);
  }

  // Levenshtein edit distance, capped (used only for short package names).
  function editDistance(a, b) {
    a = String(a); b = String(b);
    if (a === b) return 0;
    if (Math.abs(a.length - b.length) > 2) return 99;
    const m = a.length, n = b.length;
    let prev = new Array(n + 1);
    let cur = new Array(n + 1);
    for (let j = 0; j <= n; j++) prev[j] = j;
    for (let i = 1; i <= m; i++) {
      cur[0] = i;
      for (let j = 1; j <= n; j++) {
        const cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
        cur[j] = Math.min(cur[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
      }
      const tmp = prev; prev = cur; cur = tmp;
    }
    return prev[n];
  }

  // Find the text entry for a basename (case-insensitive). Returns entry|null.
  function findEntry(entries, predicate) {
    const list = asArray(entries);
    for (let i = 0; i < list.length; i++) {
      const e = list[i];
      if (e && e.path && predicate(basename(e.path).toLowerCase(), e)) return e;
    }
    return null;
  }

  // ===========================================================================
  // 1 + 2. SECURITY  (CVE buckets + supply-chain heuristics)
  // ===========================================================================

  const CVE_RE = /CVE-\d{4}-\d+/i;
  const GHSA_RE = /GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}/i;

  function normSeverity(s) {
    const v = lc(s);
    if (v.indexOf('crit') === 0 || v === 'c') return 'critical';
    if (v.indexOf('high') === 0 || v === 'h') return 'high';
    if (v.indexOf('med') === 0 || v === 'moderate' || v === 'm') return 'medium';
    if (v.indexOf('low') === 0 || v === 'l' || v === 'minor') return 'low';
    // CVSS numeric fallback
    const n = parseFloat(s);
    if (!isNaN(n)) {
      if (n >= 9) return 'critical';
      if (n >= 7) return 'high';
      if (n >= 4) return 'medium';
      if (n > 0) return 'low';
    }
    return 'medium';
  }

  // Collect candidate text fields off a finding for id/alias matching.
  function findingText(f) {
    const parts = [];
    ['id', 'ruleId', 'title', 'name', 'message', 'description', 'category', 'source', 'type', 'check']
      .forEach(k => { if (f[k] != null) parts.push(String(f[k])); });
    if (Array.isArray(f.aliases)) parts.push(f.aliases.join(' '));
    if (Array.isArray(f.references)) {
      f.references.forEach(r => parts.push(typeof r === 'string' ? r : (r && r.url) || ''));
    }
    if (Array.isArray(f.refs)) f.refs.forEach(r => parts.push(String(r)));
    if (f.vulnerability && typeof f.vulnerability === 'object') {
      if (f.vulnerability.id) parts.push(String(f.vulnerability.id));
      if (f.vulnerability.severity) parts.push(String(f.vulnerability.severity));
    }
    return parts.join(' ');
  }

  function isDependencyFinding(f, text) {
    if (CVE_RE.test(text) || GHSA_RE.test(text)) return true;
    const cat = lc(f.category) + ' ' + lc(f.source) + ' ' + lc(f.type) + ' ' + lc(f.tool);
    return /\b(sca|osv|dependency|dependencies|vuln(erab)?|cve|advisory|supply)\b/.test(cat);
  }

  function extractCveId(f, text) {
    const cve = text.match(CVE_RE);
    if (cve) return cve[0].toUpperCase();
    const ghsa = text.match(GHSA_RE);
    if (ghsa) return ghsa[0].toUpperCase();
    if (f.id) return String(f.id);
    if (f.ruleId) return String(f.ruleId);
    return 'UNKNOWN';
  }

  function extractPackage(f) {
    const cand = f.package || f.component || f.pkg || f.dependency || f.module ||
      (f.affected && (f.affected.package || f.affected.name)) || null;
    if (cand && typeof cand === 'object') return cand.name || cand.package || null;
    if (typeof cand === 'string' && cand) return cand;
    // best-effort: pull "<name>@<ver>" out of the title
    const t = String(f.title || f.message || f.name || '');
    const m = t.match(/([@a-z0-9._/-]+)@[\dvx*.~^>=< -]+/i);
    if (m) return m[1];
    return null;
  }

  function extractVersion(f) {
    const cand = f.version || f.affectedVersion || (f.affected && f.affected.version) || null;
    if (typeof cand === 'string' && cand) return cand;
    const t = String(f.title || f.message || f.name || '');
    const m = t.match(/@\s*([\dvx][\w.+-]*)/);
    if (m) return m[1];
    return null;
  }

  function extractFixedIn(f) {
    const cand = f.fixedIn || f.fixed_in || f.fixedVersion || f.patchedVersion ||
      (f.fix && (f.fix.version || f.fix)) || null;
    if (typeof cand === 'string' && cand) return cand;
    return null;
  }

  function buildCve(findings) {
    const cve = { critical: 0, high: 0, medium: 0, low: 0, items: [] };
    const seen = {};
    asArray(findings).forEach(f => {
      if (!f || typeof f !== 'object') return;
      try {
        const text = findingText(f);
        if (!isDependencyFinding(f, text)) return;
        const id = extractCveId(f, text);
        const pkg = extractPackage(f);
        const version = extractVersion(f);
        const sev = normSeverity(f.severity || f.level || (f.vulnerability && f.vulnerability.severity));
        const dedupeKey = id + '|' + (pkg || '') + '|' + (version || '');
        if (seen[dedupeKey]) return;
        seen[dedupeKey] = true;
        cve[sev]++;
        cve.items.push({
          id: id,
          severity: sev,
          package: pkg,
          version: version,
          fixedIn: extractFixedIn(f)
        });
      } catch (e) { /* skip malformed finding */ }
    });
    return cve;
  }

  // ---- supply-chain checks --------------------------------------------------

  function looksLikeBranch(version) {
    const v = lc(version);
    if (!v) return false;
    // semver-ish version => not a branch
    if (/^[v^~>=< ]*\d+\.\d+/.test(v)) return false;
    return /^(main|master|develop|dev|trunk|next|canary|latest|[a-z][\w./-]*)$/.test(v) &&
      !/^\d/.test(v);
  }

  function isFloating(version) {
    const v = String(version == null ? '' : version).trim();
    if (v === '' || v === '*' || lc(v) === 'latest' || v === 'x' || v === 'X') return true;
    if (/^[\^~]/.test(v)) return true;                 // ^1.0.0 / ~1.0.0
    if (/^>=?[^<]*$/.test(v) && !/[<]/.test(v)) {       // >=1.0 with no upper bound
      return true;
    }
    if (/(\d+\.)?x(\.x)?$/i.test(v)) return true;       // 1.x / 1.2.x
    if (/\|\|/.test(v)) return true;                    // disjunction range
    return false;
  }

  function scanRegistryConfigs(entries) {
    // Returns array of { file, registry } for off-default / insecure registries.
    const hits = [];
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path || typeof e.content !== 'string') return;
        const base = basename(e.path).toLowerCase();
        const c = e.content;
        if (base === '.npmrc') {
          const m = c.match(/registry\s*=\s*(\S+)/gi) || [];
          m.forEach(line => {
            const url = line.split('=').slice(1).join('=').trim();
            if (url && !/registry\.npmjs\.org/i.test(url)) hits.push({ file: e.path, registry: url });
          });
        } else if (base === 'pip.conf' || base === 'pip.ini') {
          const m = c.match(/(?:index-url|extra-index-url)\s*=\s*(\S+)/gi) || [];
          m.forEach(line => {
            const url = line.split('=').slice(1).join('=').trim();
            if (url && !/pypi\.org/i.test(url)) hits.push({ file: e.path, registry: url });
          });
        } else if (base === 'composer.json') {
          if (/"repositories"\s*:/.test(c) && /"url"\s*:\s*"https?:\/\//.test(c)) {
            const urls = c.match(/"url"\s*:\s*"(https?:\/\/[^"]+)"/gi) || [];
            urls.forEach(u => {
              const url = (u.match(/https?:\/\/[^"]+/) || [])[0];
              if (url && !/packagist\.org|repo\.packagist/i.test(url)) hits.push({ file: e.path, registry: url });
            });
          }
        }
      } catch (err) { /* skip */ }
    });
    return hits;
  }

  function scanInstallScripts(entries) {
    // package.json (any) declaring preinstall/postinstall/install scripts.
    const hits = [];
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path || typeof e.content !== 'string') return;
        if (basename(e.path).toLowerCase() !== 'package.json') return;
        const j = JSON.parse(e.content);
        const s = j && j.scripts;
        if (!s || typeof s !== 'object') return;
        ['preinstall', 'install', 'postinstall', 'prepare'].forEach(k => {
          if (typeof s[k] === 'string' && s[k].trim()) {
            hits.push({ file: e.path, hook: k, cmd: s[k] });
          }
        });
      } catch (err) { /* skip malformed package.json */ }
    });
    return hits;
  }

  function hasIntegrityEvidence(entries) {
    return !!findEntry(entries, b =>
      b === 'package-lock.json' || b === 'yarn.lock' || b === 'pnpm-lock.yaml' ||
      b === 'composer.lock' || b === 'poetry.lock' || b === 'gemfile.lock' ||
      b === 'cargo.lock' || b === 'go.sum' || b === 'pipfile.lock');
  }

  function pushChain(out, check) {
    if (check.packages && check.packages.length) {
      check.packages = check.packages.slice(0, 50);
    } else {
      check.packages = check.packages || [];
    }
    out.push(check);
  }

  function buildSupplyChain(entries, dependencies) {
    const out = [];
    const deps = allDeps(dependencies);

    // git-dependency / branch-dependency
    const gitDeps = deps.filter(d => lc(d.source) === 'git');
    if (gitDeps.length) {
      const branchPinned = gitDeps.filter(d => looksLikeBranch(d.version));
      pushChain(out, {
        id: 'git-dependency',
        severity: 'medium',
        title: 'Dependencies installed directly from Git',
        detail: 'Git-sourced dependencies bypass registry integrity checks and can change' +
          ' unexpectedly' + (branchPinned.length ? '; some are pinned to a moving branch ref rather than a commit/tag.' : '.'),
        packages: gitDeps.map(d => d.name),
        recommendation: 'Pin Git dependencies to an immutable commit SHA or publish them to a trusted registry.'
      });
      if (branchPinned.length) {
        pushChain(out, {
          id: 'branch-dependency',
          severity: 'medium',
          title: 'Git dependencies pinned to a moving branch',
          detail: 'Branch refs (e.g. main/master/develop) are mutable; builds are non-reproducible and a compromised upstream branch is pulled automatically.',
          packages: branchPinned.map(d => d.name),
          recommendation: 'Replace branch refs with a specific commit SHA or release tag.'
        });
      }
    }

    // http-repository (insecure transport)
    const httpDeps = deps.filter(d =>
      lc(d.source) === 'http' || /^http:\/\//.test(lc(d.version)) || /^http:\/\//.test(lc(d.source)));
    if (httpDeps.length) {
      pushChain(out, {
        id: 'http-repository',
        severity: 'high',
        title: 'Dependency fetched over insecure HTTP',
        detail: 'Plain-HTTP package or repository sources are vulnerable to man-in-the-middle tampering of the downloaded artifact.',
        packages: httpDeps.map(d => d.name),
        recommendation: 'Switch all package/repository URLs to HTTPS and verify integrity hashes.'
      });
    }

    // untrusted-registry (from .npmrc / pip.conf / composer repositories)
    const reg = scanRegistryConfigs(entries);
    if (reg.length) {
      const insecure = reg.filter(r => /^http:\/\//i.test(r.registry));
      pushChain(out, {
        id: 'untrusted-registry',
        severity: insecure.length ? 'high' : 'medium',
        title: 'Custom / non-default package registry configured',
        detail: 'Configuration points installs at a registry other than the public default' +
          (insecure.length ? ', and at least one uses insecure HTTP.' : '. Verify it is a trusted mirror.'),
        packages: reg.map(r => r.registry),
        recommendation: 'Confirm the registry is trusted, pin scoped registries explicitly, and use HTTPS with auth.'
      });
    }

    // floating-version / unpinned (aggregated)
    const floating = deps.filter(d => isFloating(d.version));
    if (floating.length) {
      const names = floating.map(d => d.name + '@' + (d.version || '*'));
      pushChain(out, {
        id: 'floating-version',
        severity: 'low',
        title: 'Unpinned / floating dependency versions',
        detail: floating.length + ' dependenc' + (floating.length === 1 ? 'y uses' : 'ies use') +
          ' a floating range or wildcard (e.g. *, latest, ^, ~, >=), so installs are not reproducible.',
        packages: names,
        recommendation: 'Pin exact versions and commit a lockfile so builds are deterministic.'
      });
    }

    // install-script
    const scripts = scanInstallScripts(entries);
    if (scripts.length) {
      const files = {};
      scripts.forEach(s => { files[s.hook + ' (' + basename(s.file) + ')'] = 1; });
      pushChain(out, {
        id: 'install-script',
        severity: 'medium',
        title: 'Lifecycle install scripts present',
        detail: 'preinstall/install/postinstall scripts run arbitrary code at install time — a common supply-chain RCE surface.',
        packages: Object.keys(files),
        recommendation: 'Audit install scripts and consider running installs with --ignore-scripts in CI.'
      });
    }

    // native-extension
    const native = deps.filter(d => NATIVE_EXT[lc(d.name)]);
    if (native.length) {
      pushChain(out, {
        id: 'native-extension',
        severity: 'info',
        title: 'Dependencies that compile native code',
        detail: 'These packages build native binaries at install time, requiring a toolchain and widening the build-time attack surface.',
        packages: native.map(d => d.name),
        recommendation: 'Prefer prebuilt binaries where available and ensure the build toolchain is trusted.'
      });
    }

    // deprecated / abandoned / eol
    const deprecated = deps.filter(d => DEPRECATED[lc(d.name)]);
    if (deprecated.length) {
      pushChain(out, {
        id: 'deprecated',
        severity: 'medium',
        title: 'Deprecated or abandoned dependencies',
        detail: deprecated.map(d => d.name + ': ' + DEPRECATED[lc(d.name)]).join(' '),
        packages: deprecated.map(d => d.name),
        recommendation: 'Replace deprecated packages with their maintained successors.'
      });
    }

    // typosquatting
    const typo = [];
    deps.forEach(d => {
      const n = lc(d.name);
      if (TYPOSQUAT[n]) { typo.push(d.name + ' (looks like "' + TYPOSQUAT[n] + '")'); return; }
      // edit-distance-1 against a handful of very popular names
      ['express', 'lodash', 'react', 'moment', 'request', 'jquery', 'axios', 'webpack']
        .forEach(pop => {
          if (n !== pop && editDistance(n, pop) === 1) typo.push(d.name + ' (1 edit from "' + pop + '")');
        });
    });
    if (typo.length) {
      pushChain(out, {
        id: 'typosquatting',
        severity: 'high',
        title: 'Possible typosquatted package names',
        detail: 'These names closely resemble popular packages and may be malicious typosquats.',
        packages: typo,
        recommendation: 'Verify each package name is the intended, legitimate one before installing.'
      });
    }

    // known-compromised
    const compromised = deps.filter(d => COMPROMISED[lc(d.name)]);
    if (compromised.length) {
      pushChain(out, {
        id: 'known-compromised',
        severity: 'critical',
        title: 'Historically compromised package present',
        detail: compromised.map(d => d.name + ': ' + COMPROMISED[lc(d.name)]).join(' ') +
          ' (impact is version-specific — confirm the installed version is unaffected).',
        packages: compromised.map(d => d.name),
        recommendation: 'Verify the exact installed version against the advisory and rotate any exposed secrets.'
      });
    }

    // unsigned / no integrity
    if (deps.length && !hasIntegrityEvidence(entries)) {
      pushChain(out, {
        id: 'unsigned',
        severity: 'info',
        title: 'No lockfile / integrity metadata found',
        detail: 'Without a lockfile, dependency integrity hashes are not verified at install and builds are not reproducible.',
        packages: [],
        recommendation: 'Commit a lockfile (package-lock.json, yarn.lock, poetry.lock, etc.).'
      });
    }

    return out;
  }

  function severitySummary(items) {
    const s = { total: 0, critical: 0, high: 0, medium: 0, low: 0 };
    asArray(items).forEach(i => {
      const sev = lc(i.severity);
      s.total++;
      if (sev === 'critical') s.critical++;
      else if (sev === 'high') s.high++;
      else if (sev === 'medium') s.medium++;
      else if (sev === 'low' || sev === 'info') s.low++;
    });
    return s;
  }

  function buildSecurity(entries, dependencies, findings) {
    const security = {
      cve: { critical: 0, high: 0, medium: 0, low: 0, items: [] },
      supplyChain: [],
      summary: { total: 0, critical: 0, high: 0, medium: 0, low: 0 }
    };
    try { security.cve = buildCve(findings); } catch (e) { /* keep empty cve */ }
    try { security.supplyChain = buildSupplyChain(entries, dependencies); } catch (e) { /* keep empty */ }
    try { security.summary = severitySummary(security.supplyChain); } catch (e) { /* keep zeroed */ }
    return security;
  }

  // ===========================================================================
  // 3. LICENSES
  // ===========================================================================

  function classifyLicense(raw) {
    const s = String(raw == null ? '' : raw).trim();
    if (!s) return null;
    for (let i = 0; i < LICENSE_CATEGORIES.length; i++) {
      if (LICENSE_CATEGORIES[i].re.test(s)) {
        return { spdx: LICENSE_CATEGORIES[i].spdx, category: LICENSE_CATEGORIES[i].category };
      }
    }
    return { spdx: s, category: 'unknown' };
  }

  function buildLicenses(dependencies, licensesRaw) {
    const result = { inventory: [], conflicts: [], unknown: [] };
    try {
      // Gather (name, license) rows from both sources.
      const rows = [];
      asArray(licensesRaw).forEach(r => {
        if (r && r.name) rows.push({ name: r.name, license: r.license });
      });
      allDeps(dependencies).forEach(d => {
        if (d && d.name) rows.push({ name: d.name, license: d.license || null });
      });

      // Dedupe per package name (first non-empty license wins).
      const byPkg = {};
      rows.forEach(r => {
        const key = lc(r.name);
        if (!(key in byPkg)) byPkg[key] = { name: r.name, license: null };
        if (!byPkg[key].license && r.license) byPkg[key].license = r.license;
      });

      const groups = {};       // normalized display license -> { spdx, category, packages{} }
      const unknownSet = {};

      Object.keys(byPkg).forEach(key => {
        const pkg = byPkg[key];
        const cls = classifyLicense(pkg.license);
        if (!cls) { unknownSet[pkg.name] = 1; return; }
        const display = cls.spdx || String(pkg.license).trim();
        const gKey = display + '|' + cls.category;
        if (!groups[gKey]) groups[gKey] = { license: display, spdx: cls.spdx, category: cls.category, packages: {} };
        groups[gKey].packages[pkg.name] = 1;
        if (cls.category === 'unknown') unknownSet[pkg.name] = 1;
      });

      result.inventory = Object.keys(groups).map(k => {
        const g = groups[k];
        const pkgs = Object.keys(g.packages);
        return { license: g.license, spdx: g.spdx, category: g.category, count: pkgs.length, packages: pkgs };
      }).sort((a, b) => b.count - a.count || (a.license < b.license ? -1 : 1));

      result.unknown = Object.keys(unknownSet).sort();

      // Conflicts: strong/network copyleft is review-required in a typical
      // proprietary/commercial application context.
      result.inventory.forEach(inv => {
        if (inv.category === 'network-copyleft') {
          result.conflicts.push({
            license: inv.license,
            reason: 'Network copyleft (AGPL) can require releasing source for networked/SaaS use — review-required for proprietary apps.',
            packages: inv.packages.slice(0, 50),
            severity: 'high'
          });
        } else if (inv.category === 'strong-copyleft') {
          result.conflicts.push({
            license: inv.license,
            reason: 'Strong copyleft (GPL) can require distributing your source under the same license — review-required for proprietary apps.',
            packages: inv.packages.slice(0, 50),
            severity: 'high'
          });
        }
      });
    } catch (e) { /* degrade to empty license result */ }
    return result;
  }

  // ===========================================================================
  // 4. DOCS
  // ===========================================================================

  function splitHeadings(content) {
    // Markdown headings (#, ##, ...) plus Setext-style underlines collapsed to text.
    const lines = String(content || '').split('\n');
    const heads = [];
    lines.forEach(l => {
      const m = l.match(/^#{1,6}\s+(.+?)\s*#*\s*$/);
      if (m) heads.push(m[1]);
    });
    return heads;
  }

  function buildDocs(entries) {
    const docs = { present: [], missing: [] };
    try {
      const list = asArray(entries);
      const covered = {};   // topic -> file (first evidence)

      function markIfMatch(topicRe, topic, content, file) {
        if (covered[topic]) return;
        if (topicRe.test(content)) covered[topic] = file;
      }

      // README headings + body keyword evidence.
      list.forEach(e => {
        if (!e || !e.path || typeof e.content !== 'string') return;
        const base = basename(e.path).toLowerCase();
        const isReadme = /^readme(\.|$)/.test(base);
        const isContrib = /^contributing(\.|$)/.test(base);
        const isInstall = /^install(\.|$)/.test(base);
        const inDocsDir = /(^|\/)docs?\//i.test(String(e.path));
        if (!(isReadme || isContrib || isInstall || inDocsDir)) return;

        const headings = splitHeadings(e.content).join('\n');
        const haystack = headings + '\n' + e.content;
        DOC_TOPICS.forEach(t => markIfMatch(t.re, t.topic, haystack, e.path));
        if (isContrib && !covered['development']) covered['development'] = e.path;
        if (isInstall && !covered['installation']) covered['installation'] = e.path;
      });

      // Filename-based evidence (docs/** filenames map to topics).
      list.forEach(e => {
        if (!e || !e.path) return;
        const path = String(e.path);
        if (!/(^|\/)docs?\//i.test(path)) return;
        const base = basename(path).toLowerCase().replace(/\.(md|rst|txt|adoc)$/, '');
        DOC_TOPICS.forEach(t => {
          if (!covered[t.topic] && t.re.test(base)) covered[t.topic] = path;
        });
      });

      // .env.example documents env-vars.
      const envEx = findEntry(list, b => /^\.env(\.(example|sample|template|dist))?$/.test(b) || b === 'env.example');
      if (envEx && !covered['env-vars']) covered['env-vars'] = envEx.path;

      DOC_TOPICS.forEach(t => {
        if (covered[t.topic]) docs.present.push({ topic: t.topic, file: covered[t.topic] });
        else docs.missing.push(t.topic);
      });
    } catch (e) { /* degrade */ }
    return docs;
  }

  // ===========================================================================
  // 5. MISSING (blocking gaps)
  // ===========================================================================

  function envExampleNames(entries) {
    // Returns a Set-like map of env var names declared across .env.example files.
    const names = {};
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path || typeof e.content !== 'string') return;
        const base = basename(e.path).toLowerCase();
        if (!(/^\.env(\.(example|sample|template|dist))?$/.test(base) || base === 'env.example')) return;
        e.content.split('\n').forEach(line => {
          const m = line.match(/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/);
          if (m) names[m[1]] = 1;
        });
      } catch (err) { /* skip */ }
    });
    return names;
  }

  function hasRuntimePin(entries) {
    // engines in package.json, .nvmrc, runtime.txt, .python-version, go directive, .ruby-version
    if (findEntry(entries, b => b === '.nvmrc' || b === 'runtime.txt' ||
      b === '.python-version' || b === '.ruby-version' || b === '.tool-versions')) return true;
    const pkg = findEntry(entries, b => b === 'package.json');
    if (pkg && typeof pkg.content === 'string' && /"engines"\s*:/.test(pkg.content)) return true;
    const gomod = findEntry(entries, b => b === 'go.mod');
    if (gomod && typeof gomod.content === 'string' && /^\s*go\s+\d+\./m.test(gomod.content)) return true;
    const pyproject = findEntry(entries, b => b === 'pyproject.toml');
    if (pyproject && typeof pyproject.content === 'string' && /requires-python|python\s*=/.test(pyproject.content)) return true;
    return false;
  }

  function hasInfra(entries) {
    return !!findEntry(entries, (b, e) =>
      b === 'dockerfile' || b === 'docker-compose.yml' || b === 'docker-compose.yaml' ||
      b === 'compose.yml' || b === 'procfile' || b === 'render.yaml' || b === 'vercel.json' ||
      b === 'netlify.toml' || /\.tf$/.test(b) ||
      /(^|\/)(k8s|kubernetes|helm|deploy)\//i.test(String(e.path)));
  }

  function hasManifestNoLock(entries) {
    // npm/composer/python manifest present but no corresponding lockfile.
    const hasPkg = !!findEntry(entries, b => b === 'package.json');
    const hasNpmLock = !!findEntry(entries, b =>
      b === 'package-lock.json' || b === 'yarn.lock' || b === 'pnpm-lock.yaml' || b === 'bun.lockb');
    return hasPkg && !hasNpmLock;
  }

  function buildMissing(entries, runtime, dependencies, docs) {
    const missing = {
      documentation: [], envVars: [], deploymentSteps: [],
      dependencies: [], runtime: [], configuration: []
    };
    try {
      // documentation: topics that matter and are missing.
      const matter = { installation: 1, configuration: 1, deployment: 1, 'env-vars': 1, 'database-setup': 1, development: 1 };
      asArray(docs && docs.missing).forEach(t => { if (matter[t]) missing.documentation.push(t); });

      // envVars: referenced in code (runtime.envVars) but absent from .env.example.
      const declared = envExampleNames(entries);
      const rtEnv = runtime && Array.isArray(runtime.envVars) ? runtime.envVars : [];
      const seenEnv = {};
      rtEnv.forEach(v => {
        const name = v && v.name;
        if (!name || seenEnv[name]) return;
        seenEnv[name] = 1;
        if (!declared[name]) missing.envVars.push(name);
      });

      // deploymentSteps: infra present but no deployment doc.
      const docPresent = {};
      asArray(docs && docs.present).forEach(p => { docPresent[p.topic] = 1; });
      if (hasInfra(entries) && !docPresent['deployment']) {
        missing.deploymentSteps.push('Deployment infrastructure detected but no deployment/runbook documentation found.');
      }
      if (hasInfra(entries) && !docPresent['production']) {
        missing.deploymentSteps.push('No production-deployment guidance documented.');
      }

      // dependencies: manifest but no lockfile -> unreproducible installs.
      if (hasManifestNoLock(entries)) {
        missing.dependencies.push('Dependency manifest present without a lockfile — installs are not reproducible.');
      }

      // runtime: no runtime/version pin.
      if (allDeps(dependencies).length && !hasRuntimePin(entries)) {
        missing.runtime.push('No runtime version pinned (engines / .nvmrc / runtime.txt / go directive / requires-python).');
      }

      // configuration: services/databases detected but no connection/config doc.
      const services = runtime && Array.isArray(runtime.services) ? runtime.services : [];
      const databases = runtime && Array.isArray(runtime.databases) ? runtime.databases : [];
      if ((services.length || databases.length) && !docPresent['configuration'] && !docPresent['database-setup']) {
        const what = [];
        databases.forEach(d => { if (d && d.engine) what.push(d.engine); });
        services.forEach(s => { if (s && (s.name || s.type)) what.push(s.name || s.type); });
        const uniq = what.filter((v, i) => what.indexOf(v) === i).slice(0, 10);
        missing.configuration.push('Backing services detected (' + (uniq.join(', ') || 'services') +
          ') but no connection/configuration documentation found.');
      }
    } catch (e) { /* degrade */ }
    return missing;
  }

  // ===========================================================================
  // 6. RECOMMENDATIONS
  // ===========================================================================

  function buildRecommendations(security, licenses, missing) {
    const rec = { immediate: [], high: [], medium: [], low: [], bestPractices: [] };
    try {
      const cve = security.cve || {};
      const chain = asArray(security.supplyChain);

      // immediate
      if (cve.critical > 0) rec.immediate.push('Patch ' + cve.critical + ' critical CVE(s) in dependencies before deploying.');
      chain.forEach(c => {
        if (c.id === 'known-compromised') rec.immediate.push('Remove/verify historically compromised package(s): ' + c.packages.join(', ') + '.');
        if (c.id === 'http-repository') rec.immediate.push('Stop fetching dependencies over plain HTTP; switch to HTTPS.');
        if (c.id === 'untrusted-registry' && c.severity === 'high') rec.immediate.push('Audit the custom/insecure package registry configuration.');
      });

      // high
      if (cve.high > 0) rec.high.push('Resolve ' + cve.high + ' high-severity CVE(s).');
      asArray(licenses.conflicts).forEach(c => {
        rec.high.push('Legal review required for ' + c.license + ' (' + c.packages.slice(0, 5).join(', ') + ').');
      });
      chain.forEach(c => {
        if (c.id === 'typosquatting') rec.high.push('Confirm package names are not typosquats: ' + c.packages.slice(0, 5).join(', ') + '.');
      });
      asArray(missing.dependencies).forEach(m => rec.high.push(m));

      // medium
      if (cve.medium > 0) rec.medium.push('Address ' + cve.medium + ' medium-severity CVE(s).');
      chain.forEach(c => {
        if (c.id === 'deprecated') rec.medium.push('Replace deprecated/abandoned dependencies: ' + c.packages.slice(0, 5).join(', ') + '.');
        if (c.id === 'install-script') rec.medium.push('Audit dependency install/lifecycle scripts; consider --ignore-scripts in CI.');
        if (c.id === 'git-dependency') rec.medium.push('Pin Git-sourced dependencies to an immutable commit/tag.');
      });
      asArray(missing.documentation).forEach(t => rec.medium.push('Document missing topic: ' + t + '.'));
      asArray(missing.envVars).length && rec.medium.push('Document required environment variables in .env.example: ' + missing.envVars.slice(0, 8).join(', ') + '.');
      asArray(missing.deploymentSteps).forEach(m => rec.medium.push(m));
      asArray(missing.configuration).forEach(m => rec.medium.push(m));

      // low
      chain.forEach(c => {
        if (c.id === 'floating-version') rec.low.push('Pin floating dependency versions for reproducible builds.');
        if (c.id === 'native-extension') rec.low.push('Review native-compiling dependencies and prefer prebuilt binaries.');
      });
      asArray(missing.runtime).forEach(m => rec.low.push(m));

      // bestPractices (always-useful general guidance)
      rec.bestPractices.push('Enable automated dependency/vulnerability scanning in CI (e.g. OSV, Dependabot).');
      rec.bestPractices.push('Commit and routinely update a lockfile so builds are deterministic.');
      rec.bestPractices.push('Maintain a clear README covering install, configuration, and deployment.');
      rec.bestPractices.push('Track and periodically review third-party licenses for policy compliance.');

      // De-duplicate each bucket while preserving order.
      Object.keys(rec).forEach(k => {
        const seen = {};
        rec[k] = rec[k].filter(s => { if (seen[s]) return false; seen[s] = 1; return true; });
      });
    } catch (e) { /* degrade */ }
    return rec;
  }

  // ===========================================================================
  // 7. SCORES  (deterministic; all integers 0..100)
  // ===========================================================================

  function clampInt(n) {
    if (isNaN(n)) return 0;
    n = Math.round(n);
    return n < 0 ? 0 : (n > 100 ? 100 : n);
  }

  function buildScores(ctx) {
    // ctx: { security, licenses, docs, missing, dependencies, entries }
    const scores = { health: 0, security: 0, readiness: 0, docs: 0, risk: 0, riskBand: 'low', confidence: 0 };
    try {
      const cve = (ctx.security && ctx.security.cve) || { critical: 0, high: 0, medium: 0, low: 0 };
      const chain = asArray(ctx.security && ctx.security.supplyChain);

      // ---- security: start 100; subtract CVE weight (critical heavy) + chain.
      // Formula: 100 - (15*crit + 8*high + 3*med + 1*low)
      //              - sum over supplyChain(critical 12, high 7, medium 3, low/info 1)
      let sec = 100;
      sec -= (15 * cve.critical + 8 * cve.high + 3 * cve.medium + 1 * cve.low);
      chain.forEach(c => {
        const s = lc(c.severity);
        sec -= (s === 'critical' ? 12 : s === 'high' ? 7 : s === 'medium' ? 3 : 1);
      });
      scores.security = clampInt(sec);

      // ---- docs: present_topics / total_topics * 100.
      const present = asArray(ctx.docs && ctx.docs.present).length;
      const total = DOC_TOPICS.length;
      scores.docs = clampInt(total ? (present / total) * 100 : 0);

      // ---- readiness: composite. Start 100, penalize blocking gaps; reward
      // presence of Dockerfile / CI / build commands.
      // Penalties: missing lockfile -20, missing runtime pin -12,
      //   each missing env-var doc -2 (cap -16), missing deploy doc -12,
      //   unresolved critical CVE -10 each (cap -30), high CVE -5 each (cap -20).
      // Rewards: Dockerfile +6, CI config +6 (added then clamped).
      let ready = 100;
      const m = ctx.missing || {};
      if (asArray(m.dependencies).length) ready -= 20;
      if (asArray(m.runtime).length) ready -= 12;
      ready -= Math.min(16, asArray(m.envVars).length * 2);
      if (asArray(m.deploymentSteps).length) ready -= 12;
      ready -= Math.min(30, cve.critical * 10);
      ready -= Math.min(20, cve.high * 5);
      const hasDocker = !!findEntry(ctx.entries, b => b === 'dockerfile');
      const hasCI = !!findEntry(ctx.entries, (b, e) =>
        /(^|\/)\.github\/workflows\//i.test(String(e.path)) ||
        b === '.gitlab-ci.yml' || b === 'azure-pipelines.yml' || b === '.circleci' || b === 'jenkinsfile');
      if (hasDocker) ready += 6;
      if (hasCI) ready += 6;
      scores.readiness = clampInt(ready);

      // ---- license cleanliness: 100 minus conflict/unknown penalty.
      const conflicts = asArray(ctx.licenses && ctx.licenses.conflicts).length;
      const unknown = asArray(ctx.licenses && ctx.licenses.unknown).length;
      const totalPkgs = allDeps(ctx.dependencies).length || 1;
      let licClean = 100 - (conflicts * 15) - Math.min(40, Math.round((unknown / totalPkgs) * 40));
      licClean = clampInt(licClean);

      // ---- deprecated penalty for health.
      const deprecatedChain = chain.filter(c => c.id === 'deprecated');
      const deprecatedCount = deprecatedChain.reduce((a, c) => a + c.packages.length, 0);

      // ---- health: blend of security + license cleanliness, minus deprecated.
      // Formula: 0.6*security + 0.4*licenseCleanliness - min(20, 3*deprecatedCount)
      let health = 0.6 * scores.security + 0.4 * licClean - Math.min(20, 3 * deprecatedCount);
      scores.health = clampInt(health);

      // ---- risk: 100 - blended(min(security, readiness)); higher = worse.
      // We blend the weaker of security/readiness (60%) with health (40%) and invert.
      const weakest = Math.min(scores.security, scores.readiness);
      const goodness = 0.6 * weakest + 0.4 * scores.health;
      scores.risk = clampInt(100 - goodness);
      scores.riskBand = scores.risk < 25 ? 'low' :
        scores.risk < 50 ? 'moderate' :
          scores.risk < 75 ? 'high' : 'critical';

      // ---- confidence: how complete the inputs were. Evidence checklist:
      //   manifest found (+25), lockfile (+20), README (+20), .env.example (+15),
      //   any dependencies parsed (+10), any findings considered (+10).
      let conf = 0;
      const hasManifest = !!findEntry(ctx.entries, b =>
        b === 'package.json' || b === 'requirements.txt' || b === 'pyproject.toml' ||
        b === 'composer.json' || b === 'go.mod' || b === 'gemfile' || b === 'cargo.toml' ||
        b === 'pom.xml' || /^requirements.*\.txt$/.test(b) || /\.csproj$/.test(b));
      const hasLock = hasIntegrityEvidence(ctx.entries);
      const hasReadme = !!findEntry(ctx.entries, b => /^readme(\.|$)/.test(b));
      const hasEnvEx = Object.keys(envExampleNames(ctx.entries)).length > 0 ||
        !!findEntry(ctx.entries, b => /^\.env(\.(example|sample|template|dist))?$/.test(b));
      if (hasManifest) conf += 25;
      if (hasLock) conf += 20;
      if (hasReadme) conf += 20;
      if (hasEnvEx) conf += 15;
      if (allDeps(ctx.dependencies).length) conf += 10;
      if (asArray(ctx.findings).length) conf += 10;
      scores.confidence = clampInt(conf);
    } catch (e) { /* degrade to zeroed scores (riskBand stays 'low') */ }
    return scores;
  }

  // ===========================================================================
  // main
  // ===========================================================================

  function analyze(input) {
    const inp = input && typeof input === 'object' ? input : {};
    const entries = asArray(inp.entries);
    const dependencies = inp.dependencies && typeof inp.dependencies === 'object' ? inp.dependencies : { prod: [], dev: [], counts: {} };
    const licensesRaw = asArray(inp.licensesRaw);
    const runtime = inp.runtime && typeof inp.runtime === 'object' ? inp.runtime : {};
    const findings = asArray(inp.findings);

    const security = buildSecurity(entries, dependencies, findings);
    const licenses = buildLicenses(dependencies, licensesRaw);
    const docs = buildDocs(entries);
    const missing = buildMissing(entries, runtime, dependencies, docs);
    const recommendations = buildRecommendations(security, licenses, missing);
    const scores = buildScores({
      security: security, licenses: licenses, docs: docs, missing: missing,
      dependencies: dependencies, entries: entries, findings: findings
    });

    return {
      security: security,
      licenses: licenses,
      docs: docs,
      missing: missing,
      recommendations: recommendations,
      scores: scores
    };
  }

  CITADEL.depreviewSecurity = { analyze: analyze };
})(window);
