/* CITADEL — Release Readiness Reviewer: Test Coverage & Verification
 * Heuristically determines whether the project has automated tests, what KINDS
 * of tests are present (unit / integration / api / auth / authorization /
 * input-validation / negative / security-regression / file-upload /
 * error-handling / logging-audit), which test FRAMEWORKS are used, whether a CI
 * pipeline runs the tests (a test/coverage GATE), and whether a coverage
 * threshold is configured. Emits release-readiness findings.
 *
 * Pure, defensive, offline. Never throws — every detector is wrapped in
 * try/catch and degrades. Only text entries (content && !isBinary) are scanned;
 * very large files are sliced to ~200KB before regex. Mirrors
 * js/depreview-security.js conventions (IIFE on window.CITADEL, curated lists,
 * scoring formula documented in comments).
 *
 * Compliance language is kept NON-false-certain ('Potential evidence support'
 * etc.) — test presence is supporting evidence, not certification.
 *
 * window.CITADEL.reviewTesting
 *   analyze(entries, report)
 *     -> { findings:[Finding], summary:{
 *          hasTests:boolean, kinds:[string], frameworks:[string],
 *          hasCiTestGate:boolean, coverageThreshold:number|null, score:0..100 } }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MAX_SCAN_BYTES = 200 * 1024;

  // ===========================================================================
  // Curated reference patterns — small and illustrative, not exhaustive.
  // ===========================================================================

  // A path looks like a test file / lives in a test directory.
  const TEST_PATH_RE = /(?:^|\/)(?:tests?|spec|specs|__tests__|testing|e2e|integration[ _-]?tests?)\//i;
  const TEST_FILE_RE = /(?:^|\/)[^/]*?(?:\.test\.|\.spec\.|_test\.|test_)[^/]*?\.(?:js|mjs|cjs|jsx|ts|tsx|py|php|go|rb|java|cs|rs)$/i;
  const TEST_FILE_GO_RE = /_test\.go$/i;
  const TEST_FILE_PHP_RE = /Test\.php$/;

  // Framework detection — { name, manifest regexes, source regexes }.
  const FRAMEWORKS = [
    { name: 'jest', dep: /\bjest\b/i, src: /\b(?:describe|it|test|expect)\s*\(|jest\.(?:fn|mock|config)/i },
    { name: 'vitest', dep: /\bvitest\b/i, src: /from\s+['"]vitest['"]|vitest\//i },
    { name: 'mocha', dep: /\bmocha\b/i, src: /require\(['"]mocha['"]\)|from\s+['"]mocha['"]/i },
    { name: 'jasmine', dep: /\bjasmine\b/i, src: /\bjasmine\b/i },
    { name: 'pytest', dep: /\bpytest\b/i, src: /import\s+pytest|@pytest\.|def\s+test_/i },
    { name: 'unittest', dep: null, src: /import\s+unittest|class\s+\w+\(\s*unittest\.TestCase\s*\)/i },
    { name: 'phpunit', dep: /\bphpunit\b/i, src: /extends\s+TestCase|use\s+PHPUnit\\/i },
    { name: 'go-testing', dep: null, src: /import\s+"testing"|func\s+Test[A-Z]\w*\s*\(\s*\w+\s+\*testing\.T\s*\)/ },
    { name: 'cargo-test', dep: null, src: /#\[\s*test\s*\]|#\[\s*cfg\s*\(\s*test\s*\)\s*\]/ },
    { name: 'junit', dep: /\bjunit\b/i, src: /import\s+org\.junit|@Test\b/ },
    { name: 'rspec', dep: /\brspec\b/i, src: /\bRSpec\.|describe\s+['"]/i },
    { name: 'xunit', dep: /\b(?:xunit|nunit)\b/i, src: /\[Fact\]|\[Theory\]|using\s+Xunit|using\s+NUnit/i }
  ];

  // Test kinds — matched against test-file paths + their content.
  const KINDS = [
    { kind: 'unit', re: /\bunit\b|(?:^|\/)unit\//i },
    { kind: 'integration', re: /\bintegration\b|(?:^|\/)integration\//i },
    { kind: 'api', re: /\bapi\b|\bendpoint\b|\b(?:supertest|httptest|requests?\.get|fetch\()|\broute[sr]?\b/i },
    { kind: 'auth', re: /\b(?:auth(?:entication)?|login|signin|sign[ -]?in|jwt|session|token|password)\b/i },
    { kind: 'authorization', re: /\b(?:authoriz|permission|role|rbac|access[ _-]?control|forbidden|403|can[ _-]?access|privilege)\b/i },
    { kind: 'input-validation', re: /\b(?:valid(?:ate|ation)|sanitiz|schema|malformed|invalid[ _-]?input|bad[ _-]?(?:input|request)|boundary)\b/i },
    { kind: 'negative', re: /\b(?:should[ _-]?(?:fail|reject|throw|not)|reject|throws?|toThrow|expect.*error|invalid|negative[ _-]?(?:case|test)|failure[ _-]?case)\b/i },
    { kind: 'security-regression', re: /\b(?:security|xss|csrf|sql[ _-]?injection|injection|vuln|exploit|cve-|owasp|regression[ _-]?test)\b/i },
    { kind: 'file-upload', re: /\b(?:upload|multipart|attachment|file[ _-]?upload)\b/i },
    { kind: 'error-handling', re: /\b(?:error[ _-]?handl|exception|try[ _-]?catch|graceful|edge[ _-]?case|500|status[ _-]?code)\b/i },
    { kind: 'logging-audit', re: /\b(?:log(?:ging)?|audit|trace|monitor)\b/i }
  ];

  // CI files that may contain a test gate.
  const CI_PATH_RE = /(?:^|\/)\.github\/workflows\/[^/]+\.ya?ml$|(?:^|\/)\.gitlab-ci\.ya?ml$|(?:^|\/)azure-pipelines\.ya?ml$|(?:^|\/)Jenkinsfile$|(?:^|\/)bitbucket-pipelines\.ya?ml$|(?:^|\/)\.circleci\/config\.ya?ml$/i;
  // A CI step that actually runs tests / enforces coverage.
  const CI_TEST_STEP_RE = /\b(?:npm|yarn|pnpm)\s+(?:run\s+)?test\b|\bnpx\s+(?:jest|vitest|mocha)\b|\bjest\b|\bvitest\b|\bpytest\b|\bphpunit\b|\bgo\s+test\b|\bcargo\s+test\b|\bmvn\s+test\b|\bgradle(?:w)?\s+test\b|\bdotnet\s+test\b|\brspec\b|\bcoverage\b|--cov\b|\bnyc\b/i;

  // Coverage configuration patterns.
  const COVERAGE_THRESHOLD_KEYS = /coverageThreshold|--cov-fail-under|fail_under|min(?:imum)?[ _-]?coverage|coverage[ _-]?threshold/i;

  const REFERENCES = [
    'https://owasp.org/www-project-web-security-testing-guide/',
    'https://cheatsheetseries.owasp.org/cheatsheets/Vulnerable_Dependency_Management_Cheat_Sheet.html',
    'https://csrc.nist.gov/glossary/term/developer_security_testing_and_evaluation'
  ];

  // Compliance mappings phrased to avoid false certainty.
  const COMPLIANCE = [
    { framework: 'NIST SP 800-53', control: 'SA-11', note: 'Potential evidence support: developer security testing and evaluation.' },
    { framework: 'SOC 2', control: 'CC8.1', note: 'Potential evidence support: change-management testing prior to release.' },
    { framework: 'CMMI', control: 'Verification & Validation', note: 'Potential evidence support: verifying work products meet requirements.' }
  ];

  // ===========================================================================
  // Helpers
  // ===========================================================================

  function asArray(v) { return Array.isArray(v) ? v : []; }

  function scanText(e) {
    if (!e || typeof e.content !== 'string' || e.content === '') return null;
    if (e.isBinary) return null;
    const c = e.content;
    return c.length > MAX_SCAN_BYTES ? c.slice(0, MAX_SCAN_BYTES) : c;
  }

  function isTestPath(path) {
    const p = String(path || '');
    return TEST_PATH_RE.test(p) || TEST_FILE_RE.test(p) || TEST_FILE_GO_RE.test(p) || TEST_FILE_PHP_RE.test(p);
  }

  // ===========================================================================
  // Detectors
  // ===========================================================================

  function detectTestsAndKinds(entries) {
    const out = { hasTests: false, testFiles: [], kinds: [] };
    const kindSet = {};
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path) return;
        if (!isTestPath(e.path)) return;
        out.hasTests = true;
        out.testFiles.push(e.path);
        const text = scanText(e);
        const haystack = String(e.path) + '\n' + (text || '');
        KINDS.forEach(k => {
          try { if (!kindSet[k.kind] && k.re.test(haystack)) kindSet[k.kind] = 1; } catch (er) { /* skip */ }
        });
      } catch (err) { /* skip entry */ }
    });
    out.kinds = KINDS.filter(k => kindSet[k.kind]).map(k => k.kind);
    return out;
  }

  function detectFrameworks(entries) {
    const found = {};
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path) return;
        const text = scanText(e);
        if (text == null) return;
        const base = String(e.path).split('/').pop().toLowerCase();
        const isManifest = /^(?:package\.json|requirements.*\.txt|pyproject\.toml|composer\.json|cargo\.toml|pom\.xml|build\.gradle|gemfile)$/i.test(base) ||
          /\.csproj$/i.test(base);
        const isTest = isTestPath(e.path);
        FRAMEWORKS.forEach(fw => {
          try {
            if (found[fw.name]) return;
            if (isManifest && fw.dep && fw.dep.test(text)) { found[fw.name] = 1; return; }
            if (isTest && fw.src && fw.src.test(text)) { found[fw.name] = 1; return; }
            // go/cargo/junit/unittest source signatures can appear in any source file.
            if (!isManifest && fw.src && (fw.name === 'go-testing' || fw.name === 'cargo-test' || fw.name === 'unittest' || fw.name === 'junit') && fw.src.test(text)) {
              found[fw.name] = 1;
            }
          } catch (er) { /* skip framework */ }
        });
      } catch (err) { /* skip entry */ }
    });
    return FRAMEWORKS.filter(fw => found[fw.name]).map(fw => fw.name);
  }

  // Coverage threshold detection. Returns a number (percentage) or null.
  function detectCoverageThreshold(entries) {
    let threshold = null;
    asArray(entries).forEach(e => {
      try {
        if (threshold != null) return;
        if (!e || !e.path) return;
        const text = scanText(e);
        if (text == null) return;
        const base = String(e.path).split('/').pop().toLowerCase();

        // jest coverageThreshold global (package.json or jest.config.*)
        if (/coverageThreshold/.test(text)) {
          const m = text.match(/(?:branches|functions|lines|statements)["']?\s*[:=]\s*(\d{1,3})/);
          if (m) { threshold = parseInt(m[1], 10); return; }
          threshold = 0; // present but unparseable percentage.
          return;
        }
        // .nycrc / nyc config
        if (/^\.nycrc/.test(base) || /\bnyc\b/.test(base)) {
          const m = text.match(/(?:branches|functions|lines|statements|check-coverage[^0-9]*)["']?\s*[:=]\s*(\d{1,3})/i);
          if (m) { threshold = parseInt(m[1], 10); return; }
        }
        // pytest --cov-fail-under / .coveragerc fail_under
        const cov = text.match(/(?:--cov-fail-under[=\s]+|fail_under\s*[:=]\s*)(\d{1,3})/i);
        if (cov) { threshold = parseInt(cov[1], 10); return; }
        // phpunit coverage (presence of coverage config => 0 marker)
        if (base === 'phpunit.xml' || base === 'phpunit.xml.dist') {
          if (/<coverage|<logging>[\s\S]*coverage|coverage-/i.test(text)) { threshold = 0; return; }
        }
      } catch (err) { /* skip */ }
    });
    return threshold;
  }

  function hasCoverageEvidence(entries, threshold) {
    if (threshold != null) return true;
    // coverage/ output dir or .coveragerc presence.
    return asArray(entries).some(e => {
      if (!e || !e.path) return false;
      return /(?:^|\/)coverage\//i.test(String(e.path)) ||
        /(?:^|\/)\.coveragerc$/i.test(String(e.path));
    });
  }

  function detectCiTestGate(entries) {
    let hasCi = false;
    let hasGate = false;
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path) return;
        if (!CI_PATH_RE.test(String(e.path))) return;
        hasCi = true;
        const text = scanText(e);
        if (text == null) return;
        if (CI_TEST_STEP_RE.test(text)) hasGate = true;
      } catch (err) { /* skip */ }
    });
    return { hasCi: hasCi, hasCiTestGate: hasGate };
  }

  // ===========================================================================
  // Finding builders (release-readiness oriented; mostly medium/low).
  // ===========================================================================

  function mkFinding(ruleId, name, severity, impact, remediation, effort, likelihood) {
    return {
      ruleId: 'testing.' + ruleId,
      name: name,
      category: 'testing',
      severity: severity,
      confidence: 'medium',
      cwe: 'CWE-1006',
      file: null,
      line: null,
      snippet: '',
      module: 'testing',
      impact: impact,
      likelihood: likelihood || 'medium',
      remediationEffort: effort || 'medium',
      remediation: remediation,
      references: REFERENCES,
      complianceMappings: COMPLIANCE,
      source: 'review-testing'
    };
  }

  // ===========================================================================
  // Score
  // ===========================================================================

  function clampInt(n) {
    if (isNaN(n)) return 0;
    n = Math.round(n);
    return n < 0 ? 0 : (n > 100 ? 100 : n);
  }

  // Score formula (0..100, higher = better verification posture):
  //   If NO tests at all -> score = 0 (hard floor; nothing to credit).
  //   Otherwise start from a presence base of 30 (tests exist), then add:
  //     + kind coverage: (kindsPresent / totalKinds) * 30   // up to 30 pts
  //     + security-relevant kinds bonus: +5 each for the security-critical
  //       kinds {auth, authorization, input-validation, negative,
  //       security-regression}, capped at +20
  //     + CI test gate:        +12 if hasCiTestGate else 0
  //     + coverage threshold:  +8  if coverageThreshold != null else 0
  //   Total possible = 30 + 30 + 20 + 12 + 8 = 100.
  function computeScore(t, hasCiTestGate, coverageThreshold) {
    if (!t.hasTests) return 0;
    let score = 30;
    score += KINDS.length ? (t.kinds.length / KINDS.length) * 30 : 0;
    const secKinds = { auth: 1, authorization: 1, 'input-validation': 1, negative: 1, 'security-regression': 1 };
    let secBonus = 0;
    t.kinds.forEach(k => { if (secKinds[k]) secBonus += 5; });
    score += Math.min(20, secBonus);
    if (hasCiTestGate) score += 12;
    if (coverageThreshold != null) score += 8;
    return clampInt(score);
  }

  // ===========================================================================
  // main
  // ===========================================================================

  function analyze(entries, report) {
    const list = asArray(entries);
    const findings = [];
    const summary = {
      hasTests: false, kinds: [], frameworks: [],
      hasCiTestGate: false, coverageThreshold: null, score: 0
    };

    try {
      const t = detectTestsAndKinds(list);
      summary.hasTests = t.hasTests;
      summary.kinds = t.kinds;
      summary.frameworks = detectFrameworks(list);

      const coverageThreshold = detectCoverageThreshold(list);
      summary.coverageThreshold = coverageThreshold;

      const ci = detectCiTestGate(list);
      summary.hasCiTestGate = ci.hasCiTestGate;

      const kindSet = {};
      t.kinds.forEach(k => { kindSet[k] = 1; });

      // No tests found (high if zero).
      if (!t.hasTests) {
        findings.push(mkFinding(
          'no-tests', 'No automated tests found', 'high',
          'Untested code may ship with undetected functional and security defects.',
          'Introduce an automated test suite (unit + integration) and run it in CI.',
          'high', 'high'
        ));
      } else {
        // No security tests.
        if (!kindSet['security-regression']) {
          findings.push(mkFinding(
            'no-security-tests', 'No security-specific tests detected', 'medium',
            'Security regressions (XSS, injection, CSRF) may reach production undetected.',
            'Add security regression tests covering known vulnerability classes.',
            'medium'
          ));
        }
        // No auth/permission tests.
        if (!kindSet['auth'] && !kindSet['authorization']) {
          findings.push(mkFinding(
            'no-auth-tests', 'No authentication/authorization tests detected', 'medium',
            'Broken access control may go unverified before release.',
            'Add tests asserting authentication and per-role authorization behavior.',
            'medium'
          ));
        }
        // No negative input-validation tests.
        if (!kindSet['negative'] && !kindSet['input-validation']) {
          findings.push(mkFinding(
            'no-negative-tests', 'No negative / input-validation tests detected', 'low',
            'Malformed or malicious input handling is unverified, raising injection/DoS risk.',
            'Add negative tests for invalid, malformed and boundary inputs.',
            'medium', 'low'
          ));
        }
      }

      // No CI test gate.
      if (!ci.hasCiTestGate) {
        findings.push(mkFinding(
          'no-ci-test-gate', 'No CI test gate enforcing tests on changes', 'medium',
          'Changes can merge/release without tests running, eroding the verification baseline.',
          'Add a CI step that runs the test suite (and fails the build on failure).',
          'low'
        ));
      }

      // No coverage threshold.
      if (coverageThreshold == null && !hasCoverageEvidence(list, coverageThreshold)) {
        findings.push(mkFinding(
          'no-coverage-threshold', 'No code coverage threshold configured', 'low',
          'Without an enforced coverage floor, test coverage can silently regress.',
          'Configure a coverage threshold (e.g. jest coverageThreshold, --cov-fail-under).',
          'low', 'low'
        ));
      }

      summary.score = computeScore(t, ci.hasCiTestGate, coverageThreshold);
    } catch (e) {
      // degrade.
    }

    return { findings: findings, summary: summary };
  }

  CITADEL.reviewTesting = { analyze: analyze };
})(window);
