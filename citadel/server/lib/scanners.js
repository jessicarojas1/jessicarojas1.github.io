'use strict';
/* CITADEL backend — real scanner adapters.
 * Each adapter shells out to an open-source CLI (never executes the target
 * code — scanners only read files), parses its JSON, and returns findings in
 * CITADEL's canonical shape. Missing tools degrade gracefully (return empty +
 * a warning) so the service works with whatever subset is installed.
 */
const { execFile } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const N = require('./normalize');

const TIMEOUT = parseInt(process.env.SCAN_TIMEOUT_MS || '180000', 10);
const MAXBUF = 256 * 1024 * 1024;

function run(cmd, args, opts = {}) {
  return new Promise(resolve => {
    execFile(cmd, args, { timeout: TIMEOUT, maxBuffer: MAXBUF, ...opts }, (err, stdout, stderr) => {
      resolve({ code: err && typeof err.code === 'number' ? err.code : (err ? 1 : 0),
                stdout: stdout || '', stderr: stderr || '', err: err || null,
                timedOut: !!(err && err.killed) });
    });
  });
}
function has(cmd) {
  return new Promise(resolve => {
    execFile('sh', ['-c', `command -v ${cmd}`], (e, out) => resolve(!e && !!out.trim()));
  });
}
function safeJson(s) { try { return JSON.parse(s); } catch (e) { return null; } }
function f(o) { return Object.assign({ confidence: 'high', line: 0, snippet: '', remediation: 'Review and remediate.' }, o); }

/* ---------------- Semgrep (multi-language SAST) ---------------- */
async function semgrep(dir) {
  if (!await has('semgrep')) return { tool: 'semgrep', available: false, findings: [] };
  const r = await run('semgrep', ['scan', '--config', 'auto', '--json', '--quiet',
    '--timeout', '60', '--max-target-bytes', '2000000', dir], { env: { ...process.env, SEMGREP_SEND_METRICS: 'off' } });
  const j = safeJson(r.stdout);
  if (!j || !Array.isArray(j.results)) return { tool: 'semgrep', available: true, findings: [], warning: 'no parseable output' };
  const findings = j.results.map(res => {
    const ex = res.extra || {};
    const md = ex.metadata || {};
    const cwe = N.firstCwe(md.cwe);
    return f({
      ruleId: res.check_id, source: 'semgrep',
      name: (ex.message || res.check_id || 'Semgrep finding').split('\n')[0].slice(0, 140),
      category: N.categorize({ cwe: md.cwe, owasp: md.owasp, text: (res.check_id || '') + ' ' + (ex.message || ''), fallback: 'quality' }),
      severity: N.normSeverity(ex.severity), cwe,
      confidence: (md.confidence || 'medium').toLowerCase(),
      file: N.relPath(res.path, dir), line: (res.start && res.start.line) || 0,
      snippet: (ex.lines || '').trim().slice(0, 180),
      remediation: (md.fix || (Array.isArray(md.references) && md.references[0]) || 'Apply the Semgrep rule guidance and validate untrusted input.')
    });
  });
  return { tool: 'semgrep', available: true, findings };
}

/* ---------------- Bandit (Python SAST) ---------------- */
async function bandit(dir) {
  if (!await has('bandit')) return { tool: 'bandit', available: false, findings: [] };
  const r = await run('bandit', ['-r', '-f', 'json', '-q', dir]);
  const j = safeJson(r.stdout);
  if (!j || !Array.isArray(j.results)) return { tool: 'bandit', available: true, findings: [] };
  const findings = j.results.map(res => {
    const cwe = res.issue_cwe && res.issue_cwe.id ? 'CWE-' + res.issue_cwe.id : null;
    return f({
      ruleId: res.test_id, source: 'bandit',
      name: (res.test_name || res.issue_text || 'Bandit finding').slice(0, 140),
      category: N.categorize({ cwe: res.issue_cwe && res.issue_cwe.id, text: (res.test_name || '') + ' ' + (res.issue_text || '') }),
      severity: N.normSeverity(res.issue_severity), cwe,
      confidence: (res.issue_confidence || 'medium').toLowerCase(),
      file: N.relPath(res.filename, dir), line: res.line_number || 0,
      snippet: (res.code || '').trim().slice(0, 180),
      remediation: res.issue_text || 'Follow Bandit guidance.'
    });
  });
  return { tool: 'bandit', available: true, findings };
}

/* ---------------- Gitleaks (secrets) ---------------- */
async function gitleaks(dir) {
  if (!await has('gitleaks')) return { tool: 'gitleaks', available: false, findings: [] };
  const out = path.join(os.tmpdir(), `gitleaks-${Date.now()}.json`);
  await run('gitleaks', ['detect', '--source', dir, '--no-git', '--report-format', 'json', '--report-path', out, '--redact', '--exit-code', '0']);
  let j = null; try { j = safeJson(fs.readFileSync(out, 'utf8')); } catch (e) {}
  try { fs.unlinkSync(out); } catch (e) {}
  if (!Array.isArray(j)) return { tool: 'gitleaks', available: true, findings: [] };
  const findings = j.map(res => f({
    ruleId: res.RuleID || 'gitleaks', source: 'gitleaks',
    name: 'Secret: ' + (res.Description || res.RuleID || 'detected credential'),
    category: 'secrets', severity: 'high', cwe: 'CWE-798', confidence: 'high',
    file: N.relPath(res.File, dir), line: res.StartLine || 0,
    snippet: (res.Match || '').slice(0, 160),
    remediation: 'Rotate the exposed secret immediately and move it to a secrets manager; purge it from history.'
  }));
  return { tool: 'gitleaks', available: true, findings };
}

/* ---------------- Trivy (vuln + secret + misconfig) ---------------- */
async function trivy(dir) {
  if (!await has('trivy')) return { tool: 'trivy', available: false, findings: [] };
  const r = await run('trivy', ['fs', '--format', 'json', '--quiet', '--scanners', 'vuln,secret,misconfig', dir],
    { env: { ...process.env, TRIVY_DISABLE_VEX_NOTICE: 'true' } });
  const j = safeJson(r.stdout);
  const findings = [];
  if (j && Array.isArray(j.Results)) {
    j.Results.forEach(rs => {
      (rs.Vulnerabilities || []).forEach(v => findings.push(f({
        ruleId: v.VulnerabilityID, source: 'trivy',
        name: `${v.VulnerabilityID}: ${v.PkgName}${v.InstalledVersion ? ' ' + v.InstalledVersion : ''}`,
        category: 'deps', severity: N.normSeverity(v.Severity), cwe: N.firstCwe(v.CweIDs),
        file: N.relPath(rs.Target, dir), line: 0,
        snippet: (v.Title || '').slice(0, 180),
        remediation: v.FixedVersion ? `Upgrade ${v.PkgName} to ${v.FixedVersion}.` : 'No fix published yet — monitor the advisory and consider mitigations.'
      })));
      (rs.Secrets || []).forEach(s => findings.push(f({
        ruleId: s.RuleID || 'trivy-secret', source: 'trivy',
        name: 'Secret: ' + (s.Title || s.RuleID || 'detected'),
        category: 'secrets', severity: N.normSeverity(s.Severity || 'high'), cwe: 'CWE-798',
        file: N.relPath(rs.Target, dir), line: s.StartLine || 0,
        snippet: (s.Match || '').slice(0, 160),
        remediation: 'Rotate the secret and remove it from source.'
      })));
      (rs.Misconfigurations || []).forEach(m => findings.push(f({
        ruleId: m.ID || 'trivy-misconfig', source: 'trivy',
        name: (m.Title || m.ID || 'Misconfiguration').slice(0, 140),
        category: N.categorize({ text: (m.Title || '') + ' ' + (m.Description || ''), fallback: 'config' }),
        severity: N.normSeverity(m.Severity), cwe: 'CWE-16',
        file: N.relPath(rs.Target, dir), line: (m.CauseMetadata && m.CauseMetadata.StartLine) || 0,
        snippet: (m.Message || m.Description || '').slice(0, 180),
        remediation: m.Resolution || 'Apply the recommended secure configuration.'
      })));
    });
  }
  return { tool: 'trivy', available: true, findings };
}

/* ---------------- Grype (vulnerability matching) ---------------- */
async function grype(dir) {
  if (!await has('grype')) return { tool: 'grype', available: false, findings: [] };
  const r = await run('grype', ['dir:' + dir, '-o', 'json', '-q'], { env: { ...process.env, GRYPE_CHECK_FOR_APP_UPDATE: 'false' } });
  const j = safeJson(r.stdout);
  if (!j || !Array.isArray(j.matches)) return { tool: 'grype', available: true, findings: [] };
  const findings = j.matches.map(m => {
    const v = m.vulnerability || {}, a = m.artifact || {};
    const fix = v.fix && Array.isArray(v.fix.versions) && v.fix.versions.length ? v.fix.versions.join(', ') : null;
    const loc = (a.locations && a.locations[0] && a.locations[0].path) || '';
    return f({
      ruleId: v.id, source: 'grype',
      name: `${v.id}: ${a.name}${a.version ? ' ' + a.version : ''}`,
      category: 'deps', severity: N.normSeverity(v.severity), cwe: null,
      file: N.relPath(loc, dir), line: 0,
      snippet: (v.description || '').slice(0, 180),
      remediation: fix ? `Upgrade ${a.name} to ${fix}.` : 'No fixed version available — track the advisory.'
    });
  });
  return { tool: 'grype', available: true, findings };
}

/* ---------------- Syft (SBOM) ---------------- */
async function syft(dir) {
  if (!await has('syft')) return { tool: 'syft', available: false, components: [], doc: null };
  const r = await run('syft', ['dir:' + dir, '-o', 'cyclonedx-json', '-q'], { env: { ...process.env, SYFT_CHECK_FOR_APP_UPDATE: 'false' } });
  const doc = safeJson(r.stdout);
  const components = [];
  if (doc && Array.isArray(doc.components)) {
    doc.components.forEach(c => {
      let eco = 'generic';
      const purl = c.purl || '';
      const mp = purl.match(/^pkg:([^/]+)\//);
      if (mp) eco = mp[1];
      components.push({ name: c.name, version: c.version || '*', ecosystem: eco, scope: 'runtime', purl });
    });
  }
  return { tool: 'syft', available: true, components, doc };
}

/* ---------------- ClamAV (malware) ---------------- */
async function clamav(dir) {
  if (!await has('clamscan')) return { tool: 'clamav', available: false, findings: [] };
  const r = await run('clamscan', ['-r', '--no-summary', '--infected', dir]);
  const findings = [];
  (r.stdout || '').split('\n').forEach(line => {
    const m = line.match(/^(.*):\s+(.+)\s+FOUND$/);
    if (m) findings.push(f({
      ruleId: 'clamav', source: 'clamav',
      name: 'Malware signature: ' + m[2],
      category: 'malware', severity: 'critical', cwe: 'CWE-506', confidence: 'high',
      file: N.relPath(m[1], dir), line: 0, snippet: m[2],
      remediation: 'Quarantine the artifact immediately; do not execute. Investigate the source and supply chain.'
    }));
  });
  return { tool: 'clamav', available: true, findings };
}

/* ---------------- Checkov (IaC misconfig) ---------------- */
async function checkov(dir) {
  if (!await has('checkov')) return { tool: 'checkov', available: false, findings: [] };
  const r = await run('checkov', ['-d', dir, '-o', 'json', '--compact', '--quiet']);
  const j = safeJson(r.stdout);
  if (!j) return { tool: 'checkov', available: true, findings: [] };
  // Top-level may be a single object or an array of {check_type, results:{...}}.
  const blocks = Array.isArray(j) ? j : [j];
  const findings = [];
  blocks.forEach(b => {
    const failed = (b && b.results && Array.isArray(b.results.failed_checks)) ? b.results.failed_checks : [];
    failed.forEach(c => findings.push(f({
      ruleId: c.check_id, source: 'checkov',
      name: (c.check_name || c.check_id || 'Checkov misconfiguration').slice(0, 140),
      category: N.categorize({ text: c.check_name, fallback: 'config' }),
      severity: N.normSeverity(c.severity || 'medium'), cwe: null,
      file: N.relPath(c.file_path, dir),
      line: (Array.isArray(c.file_line_range) && c.file_line_range[0]) || 0,
      snippet: (c.check_name || '').slice(0, 180),
      remediation: c.guideline || 'Apply the recommended secure configuration.'
    })));
  });
  return { tool: 'checkov', available: true, findings };
}

/* ---------------- OSV-Scanner (Google OSV — lockfile vulns) ---------------- */
function osvSeverityFromScore(score) {
  const n = parseFloat(score);
  if (!isFinite(n)) return null;
  if (n >= 9) return 'critical';
  if (n >= 7) return 'high';
  if (n >= 4) return 'medium';
  if (n > 0) return 'low';
  return 'medium';
}
async function osvScanner(dir) {
  if (!await has('osv-scanner')) return { tool: 'osv-scanner', available: false, findings: [] };
  // Exits non-zero when vulns are found — parse stdout regardless.
  const r = await run('osv-scanner', ['--format', 'json', '-r', dir], { env: { ...process.env } });
  const j = safeJson(r.stdout);
  if (!j || !Array.isArray(j.results)) return { tool: 'osv-scanner', available: true, findings: [] };
  const findings = [];
  j.results.forEach(rs => {
    (rs.packages || []).forEach(p => {
      const pkg = p.package || {};
      const name = pkg.name || 'package';
      const version = pkg.version || '*';
      // Map vuln id -> max_severity score from its group, if present.
      const groupSev = {};
      (p.groups || []).forEach(g => {
        (g.ids || []).forEach(id => { if (g.max_severity != null) groupSev[id] = g.max_severity; });
      });
      (p.vulnerabilities || []).forEach(v => {
        let sev = osvSeverityFromScore(groupSev[v.id]);
        if (!sev && v.database_specific && v.database_specific.severity) {
          sev = N.normSeverity(v.database_specific.severity);
        }
        if (!sev) sev = 'medium';
        const aliases = Array.isArray(v.aliases) ? v.aliases : [];
        const cve = aliases.find(a => /^CVE-/i.test(a));
        const id = cve || v.id;
        findings.push(f({
          ruleId: id, source: 'osv-scanner',
          name: `${id}: ${name}${version !== '*' ? ' ' + version : ''}`,
          category: 'deps', severity: sev, cwe: null,
          file: `${name}@${version}`, line: 0,
          snippet: (v.summary || v.details || '').slice(0, 180),
          remediation: `Upgrade ${name} to a fixed version (see advisory ${v.id}).`
        }));
      });
    });
  });
  return { tool: 'osv-scanner', available: true, findings };
}

/* ---------------- Hadolint (Dockerfile linter) ---------------- */
function findDockerfiles(dir, cap = 50) {
  const out = [];
  const rx = /^(dockerfile|containerfile)(\.|$)/i;
  function walk(d) {
    if (out.length >= cap) return;
    let entries;
    try { entries = fs.readdirSync(d, { withFileTypes: true }); } catch (e) { return; }
    for (const e of entries) {
      if (out.length >= cap) return;
      const full = path.join(d, e.name);
      if (e.isDirectory()) {
        if (e.name === '.git' || e.name === 'node_modules') continue;
        walk(full);
      } else if (e.isFile()) {
        const base = e.name;
        if (rx.test(base) || /\.dockerfile$/i.test(base)) out.push(full);
      }
    }
  }
  walk(dir);
  return out;
}
function hadolintSeverity(level) {
  const l = String(level || '').toLowerCase();
  if (l === 'error') return 'high';
  if (l === 'warning') return 'medium';
  return 'low'; // info / style
}
async function hadolint(dir) {
  if (!await has('hadolint')) return { tool: 'hadolint', available: false, findings: [] };
  const files = findDockerfiles(dir);
  const findings = [];
  for (const file of files) {
    const r = await run('hadolint', ['--no-fail', '-f', 'json', file]);
    const j = safeJson(r.stdout);
    if (!Array.isArray(j)) continue;
    j.forEach(res => findings.push(f({
      ruleId: res.code, source: 'hadolint',
      name: `${res.code}: ${res.message}`.slice(0, 140),
      category: 'config', severity: hadolintSeverity(res.level), cwe: null,
      file: N.relPath(res.file || file, dir), line: res.line || 0,
      snippet: (res.message || '').slice(0, 180),
      remediation: `See hadolint rule ${res.code}.`
    })));
  }
  return { tool: 'hadolint', available: true, findings };
}

/* ---------------- CodeQL (deep dataflow SAST — OPT-IN, heavy) ---------------- */
function codeqlLangForDir(dir) {
  // Pick one language by scanning for telltale source extensions (cheap walk).
  const order = [
    [/\.py$/i, 'python'],
    [/\.(js|ts|jsx|tsx|mjs|cjs)$/i, 'javascript'],
    [/\.go$/i, 'go'],
    [/\.java$/i, 'java'],
    [/\.rb$/i, 'ruby'],
    [/\.cs$/i, 'csharp'],
    [/\.(cpp|cc|cxx|c|h|hpp)$/i, 'cpp']
  ];
  const seen = new Set();
  let count = 0;
  function walk(d) {
    if (count > 5000) return;
    let entries;
    try { entries = fs.readdirSync(d, { withFileTypes: true }); } catch (e) { return; }
    for (const e of entries) {
      count++;
      if (count > 5000) return;
      if (e.isDirectory()) {
        if (e.name === '.git' || e.name === 'node_modules') continue;
        walk(path.join(d, e.name));
      } else if (e.isFile()) {
        for (const [rx, lang] of order) if (rx.test(e.name)) seen.add(lang);
      }
    }
  }
  try { walk(dir); } catch (e) {}
  for (const [, lang] of order) if (seen.has(lang)) return lang;
  return null;
}
function codeqlSeverity(level, secSev) {
  const n = parseFloat(secSev);
  if (isFinite(n)) {
    if (n >= 9) return 'critical';
    if (n >= 7) return 'high';
    if (n >= 4) return 'medium';
    if (n > 0) return 'low';
  }
  const l = String(level || '').toLowerCase();
  if (l === 'error') return 'high';
  if (l === 'warning') return 'medium';
  if (l === 'note') return 'low';
  return 'medium';
}
async function codeql(dir) {
  if (process.env.CITADEL_ENABLE_CODEQL !== '1' || !await has('codeql')) {
    return { tool: 'codeql', available: false, findings: [] };
  }
  // Best-effort and fully non-fatal: any failure -> available:true, findings:[].
  let db = null, sarifPath = null;
  try {
    const lang = codeqlLangForDir(dir);
    if (!lang) return { tool: 'codeql', available: true, findings: [], warning: 'no supported language detected' };
    const base = path.join(os.tmpdir(), `citadel-codeql-${Date.now()}-${Math.random().toString(36).slice(2)}`);
    db = base + '-db';
    sarifPath = base + '.sarif';
    const longTimeout = Math.max(TIMEOUT, 1800000);
    const cr = await run('codeql', ['database', 'create', db, `--language=${lang}`,
      `--source-root=${dir}`, '--overwrite'], { timeout: longTimeout });
    if (cr.code !== 0 && !fs.existsSync(db)) {
      return { tool: 'codeql', available: true, findings: [], warning: 'database create failed' };
    }
    const ar = await run('codeql', ['database', 'analyze', db, `codeql/${lang}-queries`,
      '--format=sarifv2.1.0', `--output=${sarifPath}`, '--threads=2'], { timeout: longTimeout });
    if (ar.code !== 0 && !fs.existsSync(sarifPath)) {
      return { tool: 'codeql', available: true, findings: [], warning: 'analyze failed' };
    }
    let sarif = null;
    try { sarif = safeJson(fs.readFileSync(sarifPath, 'utf8')); } catch (e) {}
    const findings = [];
    const runs = (sarif && Array.isArray(sarif.runs)) ? sarif.runs : [];
    runs.forEach(rn => {
      // Build rule lookup for tags/descriptions.
      const rules = {};
      const driverRules = rn.tool && rn.tool.driver && Array.isArray(rn.tool.driver.rules) ? rn.tool.driver.rules : [];
      driverRules.forEach(rule => { if (rule.id) rules[rule.id] = rule; });
      (rn.results || []).forEach(res => {
        const ruleId = res.ruleId || (res.rule && res.rule.id) || 'codeql';
        const rule = rules[ruleId] || {};
        const props = rule.properties || {};
        const tags = Array.isArray(props.tags) ? props.tags : [];
        const cweTag = tags.map(t => String(t).match(/external\/cwe\/cwe-(\d+)/i)).find(Boolean);
        const cwe = cweTag ? 'CWE-' + cweTag[1] : null;
        const secSev = props['security-severity'];
        const msg = (res.message && res.message.text) || (rule.shortDescription && rule.shortDescription.text) || ruleId;
        const loc = res.locations && res.locations[0] && res.locations[0].physicalLocation;
        const file = loc && loc.artifactLocation ? loc.artifactLocation.uri : '';
        const line = loc && loc.region ? (loc.region.startLine || 0) : 0;
        findings.push(f({
          ruleId, source: 'codeql',
          name: String(msg).split('\n')[0].slice(0, 140),
          category: N.categorize({ cwe, text: (ruleId || '') + ' ' + msg, fallback: 'quality' }),
          severity: codeqlSeverity(res.level, secSev), cwe,
          file: N.relPath(file, dir), line,
          snippet: String(msg).slice(0, 180),
          remediation: (rule.fullDescription && rule.fullDescription.text) || 'Review the CodeQL dataflow path and sanitize untrusted input.'
        }));
      });
    });
    return { tool: 'codeql', available: true, findings };
  } catch (e) {
    return { tool: 'codeql', available: true, findings: [], warning: 'codeql error' };
  } finally {
    if (db) { try { fs.rmSync(db, { recursive: true, force: true }); } catch (e) {} }
    if (sarifPath) { try { fs.unlinkSync(sarifPath); } catch (e) {} }
  }
}

/* ---------------- Orchestrate all ---------------- */
async function runAll(dir, onStage) {
  const stage = (s) => onStage && onStage(s);
  stage('Launching scanners…');
  const results = await Promise.all([
    semgrep(dir), bandit(dir), gitleaks(dir), trivy(dir), grype(dir), syft(dir), clamav(dir),
    checkov(dir), osvScanner(dir), hadolint(dir), codeql(dir)
  ]);
  const findings = [];
  const tools = [];
  let sbom = { components: [], doc: null };
  results.forEach(res => {
    tools.push({ tool: res.tool, available: res.available, findings: (res.findings || []).length });
    if (res.findings) findings.push(...res.findings);
    if (res.tool === 'syft' && res.available) sbom = { components: res.components, doc: res.doc };
  });
  return { findings, sbom, tools };
}

module.exports = { runAll, semgrep, bandit, gitleaks, trivy, grype, syft, clamav, checkov, osvScanner, hadolint, codeql, has };
