/* CITADEL — Java/JVM web-vulnerability pack (taint-aware).
 *
 * Closes the gaps the OWASP BenchmarkJava run exposed: reflected XSS,
 * path traversal, insecure-cookie misconfiguration, trust-boundary and
 * XPath injection on the JVM. The injection-style rules are *taint-gated*
 * (`requireTaint: true`) — the scanner only keeps them when the matched
 * sink line carries a user-tainted variable (request parameter / header /
 * cookie), which is what separates the vulnerable test cases from the
 * sanitized/literal-argument safe variants a bare regex can't tell apart.
 *
 * Findings reuse the standard categories so the compliance engine maps them
 * to OWASP ASVS / CWE Top 25 / PCI / SOC 2 like every other rule.
 * window.CITADEL.rules (extended)
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const JVM = ['Java', 'Kotlin', 'Scala', 'Groovy'];
  const EXTRA = [
    /* ---- Reflected XSS into the servlet response (taint-gated) ---- */
    { id: 'java-xss-writer', name: 'Reflected XSS via servlet response writer', category: 'injection',
      severity: 'high', cwe: 'CWE-79', langs: JVM, confidence: 'medium', requireTaint: true,
      re: /\.getWriter\(\)\s*\.\s*(?:write|print|println|printf|format|append)\s*\(/,
      remediation: 'Encode untrusted data for the HTML/JS context before writing it to the response (OWASP Java Encoder: Encode.forHtml). Never pass request input straight to getWriter().' },
    { id: 'java-xss-outstream', name: 'Reflected XSS via response output stream', category: 'injection',
      severity: 'high', cwe: 'CWE-79', langs: JVM, confidence: 'medium', requireTaint: true,
      re: /\.getOutputStream\(\)\s*\.\s*(?:write|print)\s*\(/,
      remediation: 'Output-encode untrusted data before streaming it back; set a correct Content-Type and consider a Content-Security-Policy.' },

    /* ---- Path traversal into filesystem sinks (taint-gated) ---- */
    { id: 'java-path-file', name: 'Path traversal via java.io.File / file stream', category: 'injection',
      severity: 'high', cwe: 'CWE-22', langs: JVM, confidence: 'medium', requireTaint: true,
      re: /\bnew\s+(?:java\.io\.)?(?:File|FileInputStream|FileOutputStream|FileReader|FileWriter|RandomAccessFile)\s*\(/,
      remediation: 'Canonicalize the resolved path and confirm it stays within an allowed base directory (File.getCanonicalPath().startsWith(base)); reject paths containing "..".' },
    { id: 'java-path-nio', name: 'Path traversal via java.nio Paths/Files', category: 'injection',
      severity: 'high', cwe: 'CWE-22', langs: JVM, confidence: 'medium', requireTaint: true,
      re: /\b(?:Paths\.get|Path\.of)\s*\(|\bFiles\.(?:newInputStream|newOutputStream|newBufferedReader|newBufferedWriter|readAllBytes|readAllLines|lines|delete|copy|move)\s*\(/,
      remediation: 'Resolve against a fixed base with Path.normalize() and verify the result still startsWith(base); reject traversal sequences.' },

    /* ---- Insecure cookie flags (deterministic, not taint-based) ---- */
    { id: 'java-cookie-insecure', name: 'Cookie explicitly marked non-secure', category: 'config',
      severity: 'medium', cwe: 'CWE-614', langs: JVM, confidence: 'high',
      re: /\.setSecure\s*\(\s*false\s*\)/,
      remediation: 'Set the cookie Secure flag (setSecure(true)) so it is only sent over HTTPS; sensitive cookies must never travel in clear text.' },
    { id: 'java-cookie-httponly-off', name: 'Cookie HttpOnly disabled', category: 'config',
      severity: 'low', cwe: 'CWE-1004', langs: JVM, confidence: 'high',
      re: /\.setHttpOnly\s*\(\s*false\s*\)/,
      remediation: 'Enable HttpOnly (setHttpOnly(true)) so client-side script cannot read session/auth cookies, limiting XSS-driven theft.' },

    /* ---- Insecure randomness for security-sensitive values ---- */
    { id: 'java-weak-random', name: 'Insecure java.util.Random / Math.random for security value', category: 'random',
      severity: 'medium', cwe: 'CWE-330', langs: JVM, confidence: 'medium',
      re: /\bnew\s+(?:java\.util\.)?Random\s*\(|\bMath\.random\s*\(\s*\)/,
      remediation: 'Use java.security.SecureRandom for tokens, IDs, salts, nonces or keys — java.util.Random / Math.random() are statistically predictable.' },

    /* ---- XPath injection (taint-gated) ---- */
    // The tainted value usually lands in an `expression` string that is then
    // passed to xp.evaluate(expr,...) / xp.compile(expr) — taint-gating keeps
    // only the calls whose expression carries user input. `.evaluate(`/`.compile(`
    // are distinctive enough to XPath; the gate suppresses unrelated calls.
    { id: 'java-xpath-inject', name: 'XPath injection via concatenated expression', category: 'injection',
      severity: 'high', cwe: 'CWE-643', langs: JVM, confidence: 'medium', requireTaint: true,
      re: /\.(?:evaluate|compile)\s*\(/,
      remediation: 'Use parameterized XPath (XPathVariableResolver / precompiled expressions with variables) instead of concatenating untrusted input into the query string.' }
  ];
  (CITADEL.rules = CITADEL.rules || []).push.apply(CITADEL.rules, EXTRA);
})(window);
