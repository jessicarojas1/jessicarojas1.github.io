/* CITADEL — remediation auto-fixes (browser + Node).
 *
 * Produces a concrete, conservative suggested fix for a finding: a textual
 * rewrite of the offending source line. Only high-confidence, unambiguous,
 * idempotent token swaps are offered (e.g. setSecure(false)->true,
 * MD5->SHA-256, yaml.load->yaml.safe_load) — never a guess that could change
 * behaviour incorrectly. Findings with no safe mechanical fix simply return
 * null and fall back to the textual `remediation` guidance.
 *
 * The fix operates on the finding's full source line (`lineText`, attached by
 * the scanner) so the change preserves indentation and yields an exact region
 * for SARIF `fixes[]`. Browser: window.CITADEL.remediate. Node: module.exports.
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // Each transform: a regex that must match the line, the replacement, a title,
  // and an optional `langs`/`when` guard. Order matters — first match wins.
  const TRANSFORMS = [
    { id: 'cookie-secure', title: 'Set the Secure flag on the cookie',
      re: /\.setSecure\s*\(\s*false\s*\)/, to: '.setSecure(true)' },
    { id: 'cookie-httponly', title: 'Enable HttpOnly on the cookie',
      re: /\.setHttpOnly\s*\(\s*false\s*\)/, to: '.setHttpOnly(true)' },
    { id: 'hash-md5', title: 'Replace the broken hash with SHA-256',
      re: /(getInstance\s*\(\s*["'])(?:MD5|MD-5|SHA-?1)(["'])/i, to: '$1SHA-256$2' },
    { id: 'hash-fn-md5', title: 'Replace MD5/SHA-1 with a SHA-256 call',
      re: /\b(?:md5|sha1)\s*\(/i, to: 'sha256(' },
    { id: 'yaml-safe', title: 'Use yaml.safe_load to avoid arbitrary object construction',
      re: /\byaml\.load\s*\(/, to: 'yaml.safe_load(' },
    { id: 'requests-verify', title: 'Re-enable TLS certificate verification',
      re: /\bverify\s*=\s*False\b/, to: 'verify=True' },
    { id: 'django-debug', title: 'Disable DEBUG in production',
      re: /\bDEBUG\s*=\s*True\b/, to: 'DEBUG = False' },
    { id: 'pyyaml-loader', title: 'Pass SafeLoader to yaml.load',
      re: /\bLoader\s*=\s*yaml\.(?:Unsafe|Full)?Loader\b/, to: 'Loader=yaml.SafeLoader' },
    { id: 'tls-verify-node', title: 'Do not disable Node TLS verification',
      re: /rejectUnauthorized\s*:\s*false/, to: 'rejectUnauthorized: true' }
  ];

  // Returns { id, title, original, replacement, col } or null. `original` is the
  // full source line (preferred) or the trimmed snippet as a display fallback.
  function fix(finding) {
    if (!finding) return null;
    const line = typeof finding.lineText === 'string' ? finding.lineText : null;
    const basis = line != null ? line : (finding.snippet || '');
    if (!basis || (finding.snippet && /…$/.test(finding.snippet) && line == null)) return null;
    for (const t of TRANSFORMS) {
      if (t.re.test(basis)) {
        const replacement = basis.replace(t.re, t.to);
        if (replacement === basis) continue;           // no-op (already safe)
        return { id: t.id, title: t.title, original: basis, replacement, exact: line != null };
      }
    }
    return null;
  }

  CITADEL.remediate = { fix, TRANSFORMS };
  if (typeof module !== 'undefined' && module.exports) module.exports = CITADEL.remediate;
})(typeof window !== 'undefined' ? window : globalThis);
