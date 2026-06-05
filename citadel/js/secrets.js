/* CITADEL — Secrets Scanner (entropy)
 * Complements the regex rules with Shannon-entropy detection of high-entropy
 * string literals that look like credentials. window.CITADEL.secrets
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  function shannon(s) {
    const map = {};
    for (const c of s) map[c] = (map[c] || 0) + 1;
    let h = 0;
    const n = s.length;
    for (const k in map) {
      const p = map[k] / n;
      h -= p * Math.log2(p);
    }
    return h;
  }

  // Looks like base64/hex token of meaningful length
  const TOKENISH = /["'`]([A-Za-z0-9+\/=_\-]{20,})["'`]/g;
  const ASSIGN = /\b(secret|token|key|password|passwd|credential|apikey|api_key|auth)\b/i;
  // ignore obvious non-secrets
  const IGNORE = /^(sha256-|sha384-|sha512-|data:|https?:|[0-9a-f]{40}$)/i;

  function scan(content, lang) {
    const findings = [];
    const lines = content.split('\n');
    lines.forEach((line, idx) => {
      if (line.length > 500) return;
      let m;
      TOKENISH.lastIndex = 0;
      while ((m = TOKENISH.exec(line)) !== null) {
        const val = m[1];
        if (IGNORE.test(val)) continue;
        const ent = shannon(val);
        const looksAssigned = ASSIGN.test(line);
        // Threshold: high entropy OR moderate entropy near a secret-y keyword
        if ((ent > 4.3 && val.length >= 24) || (looksAssigned && ent > 3.6 && val.length >= 16)) {
          findings.push({
            ruleId: 'entropy-secret',
            name: 'High-entropy string (possible secret)',
            category: 'secrets',
            severity: looksAssigned ? 'high' : 'medium',
            cwe: 'CWE-798',
            confidence: looksAssigned ? 'medium' : 'low',
            line: idx + 1,
            snippet: truncate(line.trim()),
            entropy: Math.round(ent * 100) / 100,
            remediation: 'Confirm whether this is a live credential; if so rotate it and move to a secrets manager.'
          });
        }
      }
    });
    return findings;
  }

  function truncate(s) { return s.length > 160 ? s.slice(0, 157) + '…' : s; }

  CITADEL.secrets = { scan, shannon };
})(window);
