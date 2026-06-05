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

/* ---------------- Orchestrate all ---------------- */
async function runAll(dir, onStage) {
  const stage = (s) => onStage && onStage(s);
  stage('Launching scanners…');
  const results = await Promise.all([
    semgrep(dir), bandit(dir), gitleaks(dir), trivy(dir), grype(dir), syft(dir), clamav(dir)
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

module.exports = { runAll, semgrep, bandit, gitleaks, trivy, grype, syft, clamav, has };
