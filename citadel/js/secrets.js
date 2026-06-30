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

  // Candidate Primary Account Number: 13–19 digits, optionally separated by a
  // single space or dash, anchored to a known card-network prefix to cut noise.
  const PAN = /\b((?:4\d{3}|5[1-5]\d{2}|2(?:22[1-9]|2[3-9]\d|[3-6]\d{2}|7[01]\d|720)|3[47]\d{2}|6011|65\d{2})[ -]?(?:\d[ -]?){8,14}\d)\b/g;
  // Luhn (mod-10) check — a real PAN satisfies it, so validating here keeps the
  // false-positive rate of "any long digit run" near zero.
  function luhnValid(digits) {
    let sum = 0, alt = false;
    for (let i = digits.length - 1; i >= 0; i--) {
      let d = digits.charCodeAt(i) - 48;
      if (d < 0 || d > 9) return false;
      if (alt) { d *= 2; if (d > 9) d -= 9; }
      sum += d; alt = !alt;
    }
    return digits.length >= 13 && sum % 10 === 0;
  }

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
      // Luhn-validated credit-card numbers (PCI DSS): only flag digit runs that
      // match a card prefix AND pass the mod-10 check, so arbitrary IDs/hashes
      // don't trip it.
      let pm;
      PAN.lastIndex = 0;
      while ((pm = PAN.exec(line)) !== null) {
        const digits = pm[1].replace(/[ -]/g, '');
        if (digits.length < 13 || digits.length > 19 || !luhnValid(digits)) continue;
        findings.push({
          ruleId: 'pan-cardnumber',
          name: 'Credit-card number (Luhn-valid PAN)',
          category: 'pii',
          severity: 'high',
          cwe: 'CWE-312',
          confidence: 'high',
          line: idx + 1,
          snippet: truncate(line.replace(pm[1], maskPan(digits)).trim()),
          remediation: 'Never store raw PANs in source or logs (PCI DSS). Tokenize or encrypt cardholder data and purge it from history.'
        });
      }
    });
    return findings;
  }

  // Mask all but the last 4 digits so the finding never echoes a full PAN.
  function maskPan(d) { return d.slice(0, -4).replace(/\d/g, '•') + d.slice(-4); }

  function truncate(s) { return s.length > 160 ? s.slice(0, 157) + '…' : s; }

  CITADEL.secrets = { scan, shannon };
})(window);
