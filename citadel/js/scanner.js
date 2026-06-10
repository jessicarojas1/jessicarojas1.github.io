/* CITADEL — Scan Orchestrator
 * Runs every engine over the ingested entries and assembles a single report:
 * languages, SAST findings, secrets, SBOM, binaries, quality, deployment
 * posture, scoring/grading, and the compliance mapping. window.CITADEL.scanner
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const SEV_WEIGHT = { critical: 25, high: 10, medium: 4, low: 1, info: 0 };
  const COMMENT_RE = /^\s*(\/\/|#|\*|\/\*|--|<!--|;)/;

  function lineOf(content, index) {
    let line = 1;
    for (let i = 0; i < index && i < content.length; i++) if (content[i] === '\n') line++;
    return line;
  }
  function snippetAt(content, idx) {
    const start = content.lastIndexOf('\n', idx) + 1;
    let end = content.indexOf('\n', idx);
    if (end < 0) end = content.length;
    const s = content.slice(start, end).trim();
    return s.length > 180 ? s.slice(0, 177) + '…' : s;
  }

  function runRules(entry) {
    const findings = [];
    const { content, lang, path } = entry;
    if (!content) return findings;
    const rules = CITADEL.rules.filter(r => r.langs === '*' || (Array.isArray(r.langs) && r.langs.includes(lang)));
    rules.forEach(rule => {
      const re = new RegExp(rule.re.source, rule.re.flags.includes('g') ? rule.re.flags : rule.re.flags + 'g');
      let m, hits = 0;
      while ((m = re.exec(content)) !== null && hits < 50) {
        hits++;
        const ls = content.lastIndexOf('\n', m.index) + 1;
        let le = content.indexOf('\n', m.index); if (le === -1) le = content.length;
        const fullLine = content.slice(ls, le);
        // Skip matches that sit on a fully-commented line (commented-out code is
        // not a live vulnerability) — except secrets and PII, which leak even in comments.
        if (rule.category !== 'secrets' && rule.category !== 'privacy' && COMMENT_RE.test(fullLine)) {
          if (m.index === re.lastIndex) re.lastIndex++; continue;
        }
        const f = {
          ruleId: rule.id, name: rule.name, category: rule.category,
          severity: rule.severity, cwe: rule.cwe, confidence: rule.confidence,
          file: path, line: lineOf(content, m.index), snippet: snippetAt(content, m.index),
          remediation: rule.remediation,
          // Internal hint: taint-gated rules only survive if the matched sink
          // carries a user-tainted variable (set by markTaint). Stripped there.
          _requireTaint: !!rule.requireTaint
        };
        // Carry the full (untrimmed) source line + match column when it's short
        // enough to drive an exact, safe auto-fix region (see remediate.js / SARIF).
        if (fullLine.length <= 400) { f.lineText = fullLine; f.col = m.index - ls; }
        findings.push(f);
        if (m.index === re.lastIndex) re.lastIndex++;
      }
    });
    return findings;
  }

  // Data-flow taint with intra-file propagation: variables assigned from a
  // user-input source, then propagated across subsequent assignments (var2 =
  // f(var1)) so a tainted value is tracked across statements (multi-hop).
  const TAINT_SRC = /\b([A-Za-z_$][\w$]*)\s*(?:=|:=|<-)\s*[^;\n]{0,80}?(req\.(query|params|body|cookies|headers)|request\.(GET|POST|args|form|values|json)|\$_(GET|POST|REQUEST|COOKIE|FILES)|params\[|getParameter|getHeader|getCookies|getQueryString|nextElement\(\)|\.getValue\(\)|os\.environ|sys\.argv|process\.argv|input\(|fmt\.Scan|Console\.ReadLine|location\.(hash|search|href)|document\.(cookie|referrer))/;
  const ASSIGN = /^[^=\n]{0,120}?\b([A-Za-z_$][\w$]*)\s*(?:=|:=|<-)\s*(.+)$/;
  // Recognized neutralizers: output encoders, escapers, numeric/boolean coercion
  // and allowlist checks. If a tainted value appears only inside one of these
  // calls on the right-hand side, the assigned variable is treated as clean —
  // this is sound (statement-local) sanitizer awareness, unlike guessing at
  // control flow. Used to STOP taint propagation across a sanitizing assignment.
  const SANITIZER_CALL = /\b(?:encodeFor[A-Za-z]+|esapi[A-Za-z.]*encoder|escapeHtml\w*|escapeXml\w*|escapeSql\w*|escapeJava\w*|escapeEcmaScript|forHtml\w*|forJavaScript|forUri\w*|forSql|parseInt|parseLong|parseShort|parseDouble|parseFloat|parseBoolean|toInt|toLong|Integer\.valueOf|Long\.valueOf|Pattern\.quote|htmlspecialchars|htmlentities|escapeshellarg|escapeshellcmd|mysqli_real_escape_string|pg_escape_string|DOMPurify\.sanitize|sanitizeHtml)\s*\([^;]*?\)/g;
  function escRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function taintedVars(content) {
    const set = new Set();
    const lines = content.split('\n');
    for (let i = 0; i < lines.length; i++) {
      const m = lines[i].match(TAINT_SRC);
      if (m && m[1]) set.add(m[1]);
    }
    if (!set.size) return set;
    // Propagate up to 4 hops: var = <expr referencing a tainted var> → tainted,
    // unless the tainted var only appears wrapped in a sanitizer call (then the
    // assignment cleans it and taint does not flow forward).
    for (let pass = 0; pass < 4; pass++) {
      let grew = false;
      for (let i = 0; i < lines.length; i++) {
        const a = lines[i].match(ASSIGN);
        if (!a || !a[1] || set.has(a[1])) continue;
        const rhs = a[2];
        const stripped = rhs.replace(SANITIZER_CALL, '');   // drop sanitized sub-exprs
        for (const v of set) {
          if (new RegExp('\\b' + escRe(v) + '\\b').test(stripped)) { set.add(a[1]); grew = true; break; }
        }
        if (set.size > 400) { grew = false; break; }
      }
      if (!grew) break;
    }
    return set;
  }
  // Marks findings whose snippet references a user-tainted variable, then drops
  // taint-gated findings (rules with requireTaint) that never reached a tainted
  // sink — this is what suppresses the "sanitized / literal-argument" safe
  // variants the regex alone can't tell apart. Returns the surviving findings.
  function markTaint(content, findings) {
    if (!findings.length) return findings;
    const arr = [...taintedVars(content)];
    findings.forEach(f => {
      if (!f.snippet || !arr.length) return;
      for (const v of arr) {
        if (new RegExp('\\b' + v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b').test(f.snippet)) {
          f.tainted = true;
          if (f.confidence !== 'high') f.confidence = 'high';
          break;
        }
      }
    });
    const kept = findings.filter(f => !(f._requireTaint && !f.tainted));
    kept.forEach(f => { delete f._requireTaint; });
    return kept;
  }

  function detectDeployment(entries) {
    const signals = [];
    const add = (tech, file, detail) => signals.push({ tech, file, detail });
    entries.forEach(e => {
      const p = e.path.toLowerCase();
      const base = p.split('/').pop();
      const c = e.content || '';
      if (base === 'dockerfile' || base.startsWith('dockerfile.')) add('Docker', e.path, 'Containerized build');
      if (base === 'docker-compose.yml' || base === 'docker-compose.yaml' || base === 'compose.yml') add('Docker Compose', e.path, 'Multi-container orchestration');
      if (p.endsWith('.tf') || base === 'main.tf') add('Terraform', e.path, 'Infrastructure as Code');
      if (p.endsWith('.bicep')) add('Azure Bicep', e.path, 'Azure IaC');
      if (/\.github\/workflows\//.test(p)) add('GitHub Actions', e.path, 'CI/CD pipeline');
      if (base === '.gitlab-ci.yml') add('GitLab CI', e.path, 'CI/CD pipeline');
      if (base === 'azure-pipelines.yml' || base === 'azure-pipelines.yaml') add('Azure Pipelines', e.path, 'CI/CD pipeline');
      if (base === 'jenkinsfile') add('Jenkins', e.path, 'CI/CD pipeline');
      if (base === 'serverless.yml' || base === 'serverless.yaml') add('Serverless Framework', e.path, 'FaaS deployment');
      if (base === 'template.yaml' && /AWS::Serverless/.test(c)) add('AWS SAM', e.path, 'Serverless app');
      if (base === 'procfile') add('Heroku/Buildpacks', e.path, 'Process model');
      if (base === 'chart.yaml' || /(^|\/)templates\/.*\.yaml$/.test(p)) add('Helm', e.path, 'Kubernetes packaging');
      if (/kind:\s*(Deployment|Service|Pod|StatefulSet|DaemonSet|Ingress)/.test(c)) add('Kubernetes', e.path, 'K8s manifest');
      if (base === 'vercel.json') add('Vercel', e.path, 'Edge/serverless host');
      if (base === 'netlify.toml') add('Netlify', e.path, 'Static/edge host');
      if (base === 'render.yaml') add('Render.com', e.path, 'PaaS blueprint');
      if (base === 'app.yaml' && /runtime:/.test(c)) add('Google App Engine', e.path, 'PaaS');
      if (base === 'cloudformation.yaml' || (base.endsWith('.yaml') && /AWSTemplateFormatVersion/.test(c))) add('AWS CloudFormation', e.path, 'AWS IaC');
    });
    // de-dup by tech
    const seen = {};
    return signals.filter(s => (seen[s.tech] ? false : (seen[s.tech] = true)));
  }

  function quality(entries) {
    let loc = 0, comments = 0, blank = 0, codeFiles = 0, maxFile = 0, longFiles = 0, totalFiles = entries.length;
    entries.forEach(e => {
      if (!e.content || !CITADEL.lang.isCode(e.lang)) return;
      codeFiles++;
      const lines = e.content.split('\n');
      loc += lines.length;
      if (lines.length > maxFile) maxFile = lines.length;
      if (lines.length > 800) longFiles++;
      lines.forEach(l => {
        const t = l.trim();
        if (!t) blank++; else if (COMMENT_RE.test(l)) comments++;
      });
    });
    const codeLines = Math.max(1, loc - comments - blank);
    const commentRatio = loc ? comments / loc : 0;
    // Maintainability heuristic 0..100
    let mi = 100;
    mi -= longFiles * 4;
    mi -= commentRatio < 0.05 ? 12 : 0;
    mi -= maxFile > 2000 ? 10 : 0;
    mi = Math.max(0, Math.min(100, Math.round(mi)));
    return { loc, comments, blank, codeLines, codeFiles, totalFiles, maxFile, longFiles, commentRatio: Math.round(commentRatio * 1000) / 10, maintainability: mi };
  }

  function languageStats(entries) {
    const bytes = {};
    let total = 0;
    entries.forEach(e => {
      if (e.archive) return;
      const l = e.lang;
      if (l === 'Unknown') return;
      bytes[l] = (bytes[l] || 0) + e.size;
      total += e.size;
    });
    const arr = Object.keys(bytes).map(l => ({
      lang: l, bytes: bytes[l], pct: total ? Math.round(bytes[l] / total * 1000) / 10 : 0,
      color: CITADEL.lang.colorFor(l)
    })).sort((a, b) => b.bytes - a.bytes);
    return { total, languages: arr, primary: arr[0] ? arr[0].lang : 'Unknown' };
  }

  function score(findings, q) {
    const sev = { critical: 0, high: 0, medium: 0, low: 0, info: 0 };
    // Coerce any unknown/missing severity to a known bucket so the score can
    // never become NaN (which would corrupt the grade + every downstream export).
    // An unrecognized severity is treated as 'medium' — fail toward flagging.
    findings.forEach(f => { sev[SEV_WEIGHT[f.severity] !== undefined ? f.severity : 'medium']++; });
    let penalty = 0;
    for (const k in sev) penalty += sev[k] * (SEV_WEIGHT[k] || 0);
    // Normalize penalty against code volume so big repos aren't unfairly crushed.
    // Density is capped so a tiny-but-vulnerable sample still yields a readable,
    // non-zero score rather than collapsing to 0.
    const kloc = Math.max(1, (q.loc || 1) / 1000);
    const density = Math.min(45, (penalty / kloc) * 0.35);
    let security = Math.round(100 - Math.min(94, density + sev.critical * 7 + sev.high * 2));
    if (sev.critical > 0) security = Math.min(security, 55);
    if (findings.length > 0) security = Math.max(6, security);
    security = Math.max(0, Math.min(100, security));
    const quality = q.maintainability;
    const overall = Math.round(security * 0.6 + quality * 0.25 + (q.commentRatio > 5 ? 100 : q.commentRatio * 20) * 0.15);
    return { sev, security, quality, overall: Math.max(0, Math.min(100, overall)), grade: grade(security) };
  }

  function grade(s) {
    if (s >= 90) return 'A'; if (s >= 80) return 'B'; if (s >= 70) return 'C';
    if (s >= 60) return 'D'; if (s >= 40) return 'E'; return 'F';
  }

  async function scan(entries, onStage) {
    const stage = (s) => onStage && onStage(s);

    stage('Classifying languages…');
    const langs = languageStats(entries);

    stage('Running SAST rules…');
    let findings = [];
    const sbomComponents = [];
    const binaries = [];

    for (const e of entries) {
      if (e.content) {
        const fr = markTaint(e.content, runRules(e));
        findings = findings.concat(fr);
        // secrets (entropy)
        CITADEL.secrets.scan(e.content, e.lang).forEach(s => findings.push(Object.assign({ file: e.path }, s)));
        // SBOM
        const comps = CITADEL.sbom.parse(e.path, e.content);
        comps.forEach(c => sbomComponents.push(c));
      } else if (e.bytes && !e.archive) {
        const b = CITADEL.binary.analyze(e.path, e.bytes);
        binaries.push(b);
        b.findings.forEach(f => findings.push(Object.assign({ file: e.path, line: 0 }, f)));
      } else if (e.bytes && e.archive) {
        const b = CITADEL.binary.analyze(e.path, e.bytes);
        binaries.push(b);
      }
    }

    stage('Building SBOM & dependency risk…');
    const depFlags = CITADEL.sbom.riskFlags(sbomComponents);
    depFlags.forEach(f => findings.push({
      ruleId: 'dep-flag', name: f.reason, category: f.category, severity: f.severity,
      cwe: f.category === 'supply-chain' ? 'CWE-1357' : 'CWE-1104', confidence: 'medium',
      file: f.component.name + '@' + f.component.version, line: 0,
      snippet: `${f.component.ecosystem}: ${f.component.name} ${f.component.version}`,
      remediation: 'Pin to a fixed, vetted version and monitor advisories (OSV/NVD).'
    }));

    stage('Measuring quality & maintainability…');
    const q = quality(entries);

    stage('Detecting deployment & IaC…');
    const deployment = detectDeployment(entries);

    stage('Checking license policy…');
    const licenses = detectLicenses(entries);
    licenses.denied.forEach(l => findings.push({
      ruleId: 'license-denied', name: 'Disallowed license: ' + l.license, category: 'supply-chain',
      severity: 'high', cwe: 'CWE-1357', confidence: 'medium', file: l.file, line: 0,
      snippet: l.license, source: 'heuristic',
      remediation: 'This license is on the policy deny-list (strong/network copyleft, e.g. GPL/AGPL/SSPL, or a restricted source-available license). It can impose source-disclosure or use restrictions incompatible with proprietary distribution — replace the component or obtain a written exception.'
    }));
    licenses.review.forEach(l => findings.push({
      ruleId: 'license-review', name: 'License needs policy review: ' + l.license, category: 'supply-chain',
      severity: 'low', cwe: 'CWE-1357', confidence: 'low', file: l.file, line: 0,
      snippet: l.license, source: 'heuristic',
      remediation: 'Weak-copyleft / reciprocal license — confirm compatibility with your licensing policy before distribution.'
    }));

    stage('Scoring & grading…');
    const scoring = score(findings, q);

    stage('Mapping to compliance frameworks…');
    const posture = CITADEL.frameworks.posture(findings);

    return {
      meta: { scannedAt: new Date().toISOString(), fileCount: entries.filter(e => !e.archive).length, totalBytes: entries.reduce((a, e) => a + e.size, 0) },
      languages: langs, findings, sbom: { components: sbomComponents, doc: CITADEL.sbom.cyclonedx(sbomComponents) },
      binaries, quality: q, deployment, licenses, scoring, posture
    };
  }

  /* License policy. Default tiers reflect a typical proprietary-distribution
     policy. Override by setting CITADEL.licensePolicy = { deny:[...], review:[...] }
     (substring/keyword match, case-insensitive) before scanning. */
  function policy() { return (CITADEL.licensePolicy) || {}; }
  function listHit(list, t) { return Array.isArray(list) && list.some(k => t.includes(String(k).toUpperCase())); }
  // Return 'denied' | 'review' | 'allowed' | null for a license id/text.
  function licenseTier(text) {
    const t = String(text).toUpperCase();
    const p = policy();
    if (listHit(p.deny, t)) return 'denied';
    if (listHit(p.review, t)) return 'review';
    if (listHit(p.allow, t)) return 'allowed';
    // Strong / network copyleft + restricted source-available → denied.
    if (/\bAGPL|AFFERO|\bSSPL|\bBUSL|BUSINESS SOURCE|COMMONS CLAUSE|CC-?BY-?NC|CC-?BY-?ND/.test(t)) return 'denied';
    if (/\bLGPL/.test(t)) return 'review';
    if (/\bGPL|GNU GENERAL PUBLIC/.test(t)) return 'denied';
    // Weak copyleft / reciprocal → review.
    if (/\bMPL|MOZILLA|\bEPL|ECLIPSE|\bCDDL|\bEUPL|\bOSL|OPEN SOFTWARE|CECILL|MS-?RL|RECIPROCAL/.test(t)) return 'review';
    // Permissive → allowed.
    if (/\bMIT|APACHE|\bBSD|\bISC|UNLICENSE|0BSD|ZLIB|BOOST|BSL-1|PERMISSION IS HEREBY GRANTED/.test(t)) return 'allowed';
    return null;
  }
  function licenseId(text) {
    const t = String(text).toUpperCase();
    if (/AGPL|AFFERO/.test(t)) return 'AGPL';   if (/\bSSPL/.test(t)) return 'SSPL';
    if (/BUSL|BUSINESS SOURCE/.test(t)) return 'BUSL'; if (/COMMONS CLAUSE/.test(t)) return 'Commons-Clause';
    if (/CC-?BY-?NC/.test(t)) return 'CC-BY-NC'; if (/CC-?BY-?ND/.test(t)) return 'CC-BY-ND';
    if (/LGPL/.test(t)) return 'LGPL';          if (/\bGPL|GNU GENERAL PUBLIC/.test(t)) return 'GPL';
    if (/MPL|MOZILLA/.test(t)) return 'MPL';    if (/EPL|ECLIPSE/.test(t)) return 'EPL';
    if (/CDDL/.test(t)) return 'CDDL';          if (/EUPL/.test(t)) return 'EUPL';
    if (/OSL|OPEN SOFTWARE/.test(t)) return 'OSL';
    if (/APACHE/.test(t)) return 'Apache-2.0';  if (/MIT|PERMISSION IS HEREBY GRANTED/.test(t)) return 'MIT';
    if (/\bBSD/.test(t)) return 'BSD';          if (/\bISC\b/.test(t)) return 'ISC';
    return null;
  }
  function classifyLicense(text) {
    const id = licenseId(text); if (!id) return null;
    return { id, tier: licenseTier(id) || 'review' };
  }
  // Detect licenses from LICENSE/COPYING files and SPDX-License-Identifier tags.
  function detectLicenses(entries) {
    const found = {};            // id -> { license, file, tier }
    const spdxRe = /SPDX-License-Identifier:\s*([A-Za-z0-9.\-+ ]+)/;
    entries.forEach(e => {
      const base = e.path.split('/').pop().toLowerCase();
      const isLicenseFile = /^(license|licence|copying|unlicense)(\.|$)/.test(base);
      if (!e.content) return;
      if (isLicenseFile) {
        const c = classifyLicense(e.content.slice(0, 4000));
        if (c) found[c.id] = found[c.id] || { license: c.id, file: e.path, tier: c.tier };
      }
      const m = e.content.match(spdxRe);
      if (m) {
        const raw = m[1].trim().split(/\s/)[0];
        const tier = licenseTier(raw);
        if (tier) found[raw] = found[raw] || { license: raw, file: e.path, tier };
      }
    });
    const all = Object.values(found);
    const byTier = t => all.filter(l => l.tier === t);
    return {
      all,
      denied: byTier('denied'),
      review: byTier('review'),
      allowed: byTier('allowed'),
      copyleft: all.filter(l => l.tier === 'denied' || l.tier === 'review'),   // backward-compat
      permissive: byTier('allowed'),
      detected: all.length > 0
    };
  }

  CITADEL.scanner = { scan, SEV_WEIGHT, grade, score, quality, languageStats, detectDeployment, detectLicenses, licenseTier };
})(window);
