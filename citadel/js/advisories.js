/* CITADEL — offline known-vulnerability advisory provider.
 *
 * The live OSV.dev path (osv.js) needs the network and only runs as a post-scan
 * enrichment. This module ships a CURATED, high-signal subset of well-known
 * vulnerable dependency versions so that even fully offline — CITADEL's default
 * browser-only mode — a scan still flags the most consequential
 * known-vulnerable packages (OWASP A06: Vulnerable & Outdated Components).
 *
 * It is intentionally NOT a complete vulnerability database: it is a vetted set
 * of widely-deployed, high-severity advisories with conservative version ranges.
 * For full coverage, run a deep scan (Trivy/Grype/OSV-Scanner) or let the live
 * OSV enrichment run. `register()` exposes a clean interface so additional
 * advisory sources/feeds can be layered in later.
 *
 * window.CITADEL.advisories
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // --- dependency-free semver (major.minor.patch; prerelease/build ignored) ---
  function parseVer(v) {
    const m = String(v == null ? '' : v).match(/(\d+)(?:\.(\d+))?(?:\.(\d+))?/);
    if (!m) return null;
    return [parseInt(m[1], 10), parseInt(m[2] || '0', 10), parseInt(m[3] || '0', 10)];
  }
  function cmp(a, b) { for (let i = 0; i < 3; i++) { if (a[i] !== b[i]) return a[i] < b[i] ? -1 : 1; } return 0; }

  // Resolve a manifest version spec to a single concrete version to test.
  // Exact ("1.2.3") -> as-is, high confidence. A range ("^1.2.3", ">=1.2.0 <2",
  // "~> 5.2") -> its FLOOR ("1.2.0"), medium confidence: the installed version is
  // at least the floor, so a vulnerable floor is a real (if lockfile-confirmable)
  // risk. Wildcards ("*", "latest") -> unresolvable.
  function resolve(spec) {
    const s = String(spec == null ? '' : spec).trim();
    if (!s || s === '*' || /latest/i.test(s)) return null;
    const exact = /^[v=\s]*\d+\.\d+(\.\d+)?$/.test(s);
    const ver = parseVer(s);
    if (!ver) return null;
    return { ver: ver, exact: exact };
  }

  // affected if: version >= introduced (default 0.0.0) AND version < fixed (if any).
  function inRange(ver, range) {
    const intro = parseVer(range.introduced || '0.0.0');
    if (cmp(ver, intro) < 0) return false;
    if (range.fixed) { const fx = parseVer(range.fixed); if (fx && cmp(ver, fx) >= 0) return false; }
    if (range.lastAffected) { const la = parseVer(range.lastAffected); if (la && cmp(ver, la) > 0) return false; }
    return true;
  }

  // Curated advisory DB. ranges: [{ introduced?, fixed?, lastAffected? }].
  // Severity/CWE/id are the real published values. Conservative on ranges.
  const DB = [
    // ---- npm ----
    { eco: 'npm', name: 'lodash', id: 'CVE-2021-23337', sev: 'high', cwe: 'CWE-77', ranges: [{ fixed: '4.17.21' }], summary: 'Command injection via template (lodash <4.17.21).' },
    { eco: 'npm', name: 'minimist', id: 'CVE-2021-44906', sev: 'critical', cwe: 'CWE-1321', ranges: [{ fixed: '1.2.6' }], summary: 'Prototype pollution (minimist <1.2.6).' },
    { eco: 'npm', name: 'qs', id: 'CVE-2022-24999', sev: 'high', cwe: 'CWE-1321', ranges: [{ fixed: '6.5.3' }], summary: 'Prototype pollution via query parsing (qs <6.5.3).' },
    { eco: 'npm', name: 'json5', id: 'CVE-2022-46175', sev: 'high', cwe: 'CWE-1321', ranges: [{ introduced: '1.0.0', fixed: '1.0.2' }, { introduced: '2.0.0', fixed: '2.2.2' }], summary: 'Prototype pollution in parse (json5 <2.2.2 / <1.0.2).' },
    { eco: 'npm', name: 'semver', id: 'CVE-2022-25883', sev: 'high', cwe: 'CWE-1333', ranges: [{ fixed: '7.5.2' }], summary: 'ReDoS in range parsing (semver <7.5.2).' },
    { eco: 'npm', name: 'axios', id: 'CVE-2023-45857', sev: 'medium', cwe: 'CWE-200', ranges: [{ introduced: '1.0.0', fixed: '1.6.0' }], summary: 'CSRF token leaked to third-party host (axios <1.6.0).' },
    { eco: 'npm', name: 'follow-redirects', id: 'CVE-2024-28849', sev: 'medium', cwe: 'CWE-200', ranges: [{ fixed: '1.15.6' }], summary: 'Credential leakage on cross-host redirect (follow-redirects <1.15.6).' },
    { eco: 'npm', name: 'tar', id: 'CVE-2021-37713', sev: 'high', cwe: 'CWE-22', ranges: [{ fixed: '6.1.9' }], summary: 'Arbitrary file write / path traversal (tar <6.1.9).' },
    { eco: 'npm', name: 'ws', id: 'CVE-2024-37890', sev: 'high', cwe: 'CWE-1333', ranges: [{ fixed: '8.17.1' }], summary: 'ReDoS via crafted headers (ws <8.17.1).' },
    { eco: 'npm', name: 'jsonwebtoken', id: 'CVE-2022-23529', sev: 'high', cwe: 'CWE-327', ranges: [{ fixed: '9.0.0' }], summary: 'Insecure verification allowing key confusion (jsonwebtoken <9.0.0).' },
    { eco: 'npm', name: 'node-fetch', id: 'CVE-2022-0235', sev: 'medium', cwe: 'CWE-200', ranges: [{ fixed: '2.6.7' }], summary: 'Credential/cookie leak to third-party on redirect (node-fetch <2.6.7).' },
    // ---- PyPI ----
    { eco: 'pypi', name: 'pyyaml', id: 'CVE-2020-14343', sev: 'critical', cwe: 'CWE-20', ranges: [{ fixed: '5.4' }], summary: 'Arbitrary code execution via unsafe full_load (PyYAML <5.4).' },
    { eco: 'pypi', name: 'requests', id: 'CVE-2023-32681', sev: 'medium', cwe: 'CWE-200', ranges: [{ fixed: '2.31.0' }], summary: 'Proxy-Authorization leaked over HTTP redirect (requests <2.31.0).' },
    { eco: 'pypi', name: 'urllib3', id: 'CVE-2023-43804', sev: 'medium', cwe: 'CWE-200', ranges: [{ introduced: '2.0.0', fixed: '2.0.6' }, { fixed: '1.26.17' }], summary: 'Cookie leak on cross-origin redirect (urllib3 <1.26.17 / <2.0.6).' },
    { eco: 'pypi', name: 'jinja2', id: 'CVE-2024-22195', sev: 'medium', cwe: 'CWE-79', ranges: [{ fixed: '3.1.3' }], summary: 'XSS via xmlattr filter (Jinja2 <3.1.3).' },
    { eco: 'pypi', name: 'cryptography', id: 'CVE-2023-50782', sev: 'high', cwe: 'CWE-208', ranges: [{ fixed: '42.0.0' }], summary: 'Bleichenbacher timing oracle in RSA decryption (cryptography <42.0.0).' },
    { eco: 'pypi', name: 'flask', id: 'CVE-2023-30861', sev: 'high', cwe: 'CWE-525', ranges: [{ fixed: '2.2.5' }], summary: 'Cached response may leak session cookie to other clients (Flask <2.2.5).' },
    { eco: 'pypi', name: 'django', id: 'CVE-2022-28346', sev: 'high', cwe: 'CWE-89', ranges: [{ fixed: '3.2.13' }], summary: 'SQL injection via QuerySet.annotate/aggregate (Django <3.2.13).' },
    // ---- Maven (groupId:artifactId) ----
    { eco: 'maven', name: 'org.apache.logging.log4j:log4j-core', id: 'CVE-2021-44228', sev: 'critical', cwe: 'CWE-502', ranges: [{ introduced: '2.0.0', fixed: '2.17.1' }], summary: 'Log4Shell — JNDI lookup remote code execution (log4j-core 2.0–2.17.0).' },
    { eco: 'maven', name: 'org.apache.commons:commons-text', id: 'CVE-2022-42889', sev: 'critical', cwe: 'CWE-94', ranges: [{ introduced: '1.5.0', fixed: '1.10.0' }], summary: 'Text4Shell — code execution via string interpolation (commons-text 1.5–1.9).' },
    { eco: 'maven', name: 'org.yaml:snakeyaml', id: 'CVE-2022-1471', sev: 'high', cwe: 'CWE-502', ranges: [{ fixed: '2.0' }], summary: 'Unsafe deserialization to RCE via Constructor (snakeyaml <2.0).' },
    { eco: 'maven', name: 'com.fasterxml.jackson.core:jackson-databind', id: 'CVE-2020-36518', sev: 'high', cwe: 'CWE-787', ranges: [{ fixed: '2.13.2.1', introduced: '2.0.0' }], summary: 'Deeply nested JSON causes stack overflow DoS (jackson-databind <2.13.2.1).' },
    // ---- Packagist ----
    { eco: 'composer', name: 'guzzlehttp/guzzle', id: 'CVE-2022-31090', sev: 'medium', cwe: 'CWE-200', ranges: [{ introduced: '7.0.0', fixed: '7.4.5' }], summary: 'Curl cross-host Authorization header leak (guzzle <7.4.5).' },
    // ---- RubyGems ----
    { eco: 'gem', name: 'nokogiri', id: 'CVE-2022-24836', sev: 'high', cwe: 'CWE-1333', ranges: [{ fixed: '1.13.4' }], summary: 'ReDoS in HTML/XML parsing (nokogiri <1.13.4).' },
    { eco: 'gem', name: 'rack', id: 'CVE-2022-44570', sev: 'high', cwe: 'CWE-1333', ranges: [{ fixed: '2.2.6.1' }], summary: 'ReDoS in Range header parsing (rack <2.2.6.1).' }
  ];

  // Index by eco+lowercased name for fast lookup, plus a maven artifact-suffix
  // map so "groupId:artifactId" still matches if a manifest omits the group.
  const byKey = {};
  function key(eco, name) { return eco + '|' + String(name).toLowerCase(); }
  function index() { DB.forEach(a => { byKey[key(a.eco, a.name)] = byKey[key(a.eco, a.name)] || []; byKey[key(a.eco, a.name)].push(a); }); }
  index();

  function lookup(eco, name) {
    let hits = byKey[key(eco, name)] || [];
    if (!hits.length && eco === 'maven' && String(name).indexOf(':') < 0) {
      // manifest gave only the artifactId — match any advisory whose name ends with it
      hits = DB.filter(a => a.eco === 'maven' && a.name.split(':')[1] === String(name).toLowerCase());
    }
    return hits;
  }

  function toFinding(adv, component, resolved) {
    const inferred = !resolved.exact;
    return {
      ruleId: adv.id, source: 'advisory-db',
      name: `${adv.id}: ${component.name} ${component.version}`,
      category: 'deps', severity: adv.sev, cwe: adv.cwe || null,
      confidence: inferred ? 'medium' : 'high',
      file: `${component.name}@${component.version}`, line: 0,
      snippet: adv.summary + (inferred ? ' (version inferred from the manifest range floor — confirm the lockfile-resolved version)' : ''),
      remediation: `Upgrade ${component.name} to a fixed release (see ${adv.id}). Pin the patched version and verify via the lockfile.`
    };
  }

  // Scan SBOM components against the curated DB. Returns canonical findings.
  function scan(components) {
    const out = [];
    (components || []).forEach(c => {
      const advs = lookup(c.ecosystem, c.name);
      if (!advs.length) return;
      const r = resolve(c.version);
      if (!r) return;                                   // unresolvable (*/latest) — can't assert
      advs.forEach(adv => { if (adv.ranges.some(rg => inRange(r.ver, rg))) out.push(toFinding(adv, c, r)); });
    });
    return out;
  }

  // Provider interface: append advisories from another source (kept simple).
  function register(entries) {
    (entries || []).forEach(a => { if (a && a.eco && a.name && Array.isArray(a.ranges)) { DB.push(a); } });
    for (const k in byKey) delete byKey[k];
    index();
  }

  CITADEL.advisories = { scan, resolve, inRange, lookup, register, DB, count: () => DB.length };
})(window);
