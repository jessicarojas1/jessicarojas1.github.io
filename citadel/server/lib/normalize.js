'use strict';
/* CITADEL backend — normalization helpers.
 * Converts heterogeneous scanner output into CITADEL's canonical finding shape
 * so the existing scoring + compliance-mapping engine works unchanged.
 *
 *   finding = { ruleId, name, category, severity, cwe, confidence,
 *               file, line, snippet, remediation, source }
 */

const SEV = { critical: 5, high: 4, medium: 3, low: 2, info: 1 };

function normSeverity(raw) {
  if (!raw) return 'medium';
  const s = String(raw).toLowerCase();
  if (['critical', 'crit'].includes(s)) return 'critical';
  if (['high', 'error', 'severe'].includes(s)) return 'high';
  if (['medium', 'moderate', 'warning', 'warn', 'med'].includes(s)) return 'medium';
  if (['low', 'minor'].includes(s)) return 'low';
  if (['info', 'informational', 'note', 'negligible', 'unknown', 'none'].includes(s)) return 'info';
  return 'medium';
}

function worse(a, b) { return SEV[a] >= SEV[b] ? a : b; }

// CWE number -> CITADEL weakness category
const CWE_CAT = {
  20: 'input-validation',
  22: 'path-traversal', 23: 'path-traversal', 35: 'path-traversal', 36: 'path-traversal', 98: 'path-traversal',
  77: 'injection', 78: 'injection', 88: 'injection', 89: 'injection', 90: 'injection', 91: 'injection',
  564: 'injection', 943: 'injection', 1336: 'injection',
  79: 'xss', 80: 'xss', 83: 'xss',
  502: 'deserialization',
  611: 'xxe', 776: 'xxe',
  918: 'ssrf',
  259: 'secrets', 256: 'secrets', 321: 'secrets', 798: 'secrets', 522: 'secrets', 312: 'privacy', 359: 'privacy',
  327: 'crypto', 328: 'crypto', 326: 'crypto', 916: 'crypto', 780: 'crypto',
  330: 'random', 338: 'random', 335: 'random',
  295: 'transport', 319: 'transport', 297: 'transport',
  287: 'authn', 306: 'authn', 620: 'authn', 521: 'authn', 640: 'authn',
  384: 'session', 613: 'session', 614: 'session', 1004: 'session',
  862: 'authz', 863: 'authz', 639: 'authz', 732: 'authz', 276: 'authz', 285: 'authz', 200: 'error-handling',
  209: 'error-handling', 215: 'error-handling', 532: 'logging', 778: 'logging', 117: 'logging',
  434: 'file-upload',
  506: 'malware', 507: 'malware', 912: 'malware',
  1104: 'deps', 937: 'deps', 1035: 'deps', 1395: 'deps',
  1357: 'supply-chain', 494: 'supply-chain', 829: 'supply-chain', 1395.1: 'supply-chain',
  16: 'config', 489: 'config', 942: 'config', 377: 'config', 552: 'config', 1188: 'config'
};

const KEYWORD_CAT = [
  [/sql\s*inj|sqli|command inj|os command|rce|code inj/i, 'injection'],
  [/cross.?site script|\bxss\b/i, 'xss'],
  [/deserial/i, 'deserialization'],
  [/\bssrf\b|server.side request/i, 'ssrf'],
  [/path travers|directory travers|file inclusion|lfi|rfi/i, 'path-traversal'],
  [/\bxxe\b|external entit/i, 'xxe'],
  [/secret|credential|password|api[\s_-]?key|token|private key/i, 'secrets'],
  [/crypto|cipher|hash|md5|sha1|\bdes\b|\brc4\b|\becb\b|tls|ssl|certificate/i, 'crypto'],
  [/random|entropy|prng/i, 'random'],
  [/auth(entication)?|login|session|jwt|cookie/i, 'authn'],
  [/authoriz|access control|idor|privilege|permission/i, 'authz'],
  [/\bcve-|vulnerab|outdated|known.vuln/i, 'deps'],
  [/supply.chain|provenance|integrity|tamper/i, 'supply-chain'],
  [/malware|virus|trojan|backdoor|infected/i, 'malware'],
  [/misconfig|configuration|hardening|insecure default|debug/i, 'config'],
  [/log(ging)?|monitor/i, 'logging'],
  [/upload/i, 'file-upload'],
  [/\bpii\b|personal data|privacy|gdpr/i, 'privacy'],
  [/input validation|tainted|sanitiz/i, 'input-validation']
];

function categorize({ cwe, owasp, text, fallback } = {}) {
  // 1) explicit CWE
  const cweNums = []
    .concat(cwe || [])
    .flatMap(c => String(c).match(/\d+/g) || [])
    .map(Number);
  for (const n of cweNums) if (CWE_CAT[n]) return CWE_CAT[n];
  // 2) OWASP hint
  const o = String(owasp || '').toLowerCase();
  if (/a01|broken access/.test(o)) return 'authz';
  if (/a02|crypto/.test(o)) return 'crypto';
  if (/a03|inject/.test(o)) return 'injection';
  if (/a05|misconfig/.test(o)) return 'config';
  if (/a06|vulnerable.*component/.test(o)) return 'deps';
  if (/a07|auth/.test(o)) return 'authn';
  if (/a08|integrity/.test(o)) return 'supply-chain';
  if (/a09|logging/.test(o)) return 'logging';
  if (/a10|ssrf/.test(o)) return 'ssrf';
  // 3) keyword scan
  const t = String(text || '');
  for (const [re, cat] of KEYWORD_CAT) if (re.test(t)) return cat;
  return fallback || 'config';
}

function firstCwe(cwe) {
  const nums = [].concat(cwe || []).flatMap(c => String(c).match(/CWE-?\d+/gi) || []);
  if (nums.length) return nums[0].toUpperCase().replace(/^CWE/, 'CWE-').replace('--', '-');
  const bare = [].concat(cwe || []).flatMap(c => String(c).match(/\d+/g) || []);
  return bare.length ? 'CWE-' + bare[0] : null;
}

function relPath(p, base) {
  if (!p) return '';
  let r = String(p);
  if (base && r.startsWith(base)) r = r.slice(base.length);
  return r.replace(/^[./]+/, '');
}

module.exports = { normSeverity, worse, categorize, firstCwe, relPath, CWE_CAT, SEV };
