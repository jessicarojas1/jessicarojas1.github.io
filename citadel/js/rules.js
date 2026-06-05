/* CITADEL — SAST Rules Engine
 * Heuristic, regex-based static analysis rules. Each rule carries the metadata
 * needed by the Compliance Mapping Engine (category) and reports (cwe/remediation).
 * window.CITADEL.rules
 *
 * Rule shape:
 *   { id, name, category, severity, cwe, langs:[..]|'*', re:RegExp,
 *     remediation, confidence:'high'|'medium'|'low' }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const RULES = [
    /* ---------------- Injection ---------------- */
    { id:'js-eval', name:'Use of eval() / Function constructor', category:'injection',
      severity:'high', cwe:'CWE-95', langs:['JavaScript','TypeScript'], confidence:'medium',
      re:/\b(eval|new\s+Function)\s*\(/,
      remediation:'Avoid eval/Function on dynamic input; use JSON.parse or explicit dispatch tables.' },
    { id:'sql-concat', name:'SQL query built by string concatenation', category:'injection',
      severity:'high', cwe:'CWE-89', langs:'*', confidence:'medium',
      re:/(SELECT|INSERT|UPDATE|DELETE)\b[\s\S]{0,80}?["'`][\s\S]{0,40}?\+\s*\w+/i,
      remediation:'Use parameterized queries / prepared statements; never concatenate user input.' },
    { id:'py-format-sql', name:'SQL via f-string / % formatting', category:'injection',
      severity:'high', cwe:'CWE-89', langs:['Python'], confidence:'medium',
      re:/(execute|executemany)\s*\(\s*f?["'][\s\S]{0,80}?(%s|%d|\{)/i,
      remediation:'Pass parameters as the second argument to cursor.execute(), do not interpolate.' },
    { id:'os-command', name:'OS command execution with shell', category:'injection',
      severity:'critical', cwe:'CWE-78', langs:'*', confidence:'medium',
      re:/\b(system|exec|popen|shell_exec|passthru|proc_open|subprocess\.(call|run|Popen)|os\.system|child_process\.(exec|execSync)|Runtime\.getRuntime\(\)\.exec)\s*\(/,
      remediation:'Avoid shells; pass argument arrays, validate/allowlist inputs, never interpolate user data.' },
    { id:'py-shell-true', name:'subprocess with shell=True', category:'injection',
      severity:'high', cwe:'CWE-78', langs:['Python'], confidence:'high',
      re:/subprocess\.[A-Za-z]+\([\s\S]{0,120}?shell\s*=\s*True/,
      remediation:'Use shell=False and pass an argv list; allowlist commands.' },
    { id:'ldap-inject', name:'Possible LDAP injection', category:'injection',
      severity:'medium', cwe:'CWE-90', langs:'*', confidence:'low',
      re:/\(\s*(uid|cn|sAMAccountName)\s*=\s*["'`]?\s*\+/i,
      remediation:'Encode LDAP special characters; use parameterized LDAP filters.' },

    /* ---------------- XSS ---------------- */
    { id:'js-innerhtml', name:'Assignment to innerHTML/outerHTML', category:'xss',
      severity:'medium', cwe:'CWE-79', langs:['JavaScript','TypeScript','Vue','Svelte'], confidence:'low',
      re:/\.(inner|outer)HTML\s*=\s*(?!["'`]\s*["'`])/,
      remediation:'Use textContent or a sanitizer (DOMPurify); avoid injecting raw HTML.' },
    { id:'js-document-write', name:'document.write()', category:'xss',
      severity:'medium', cwe:'CWE-79', langs:['JavaScript','TypeScript','HTML'], confidence:'medium',
      re:/document\.write(ln)?\s*\(/,
      remediation:'Replace document.write with safe DOM APIs.' },
    { id:'react-dangerous', name:'dangerouslySetInnerHTML', category:'xss',
      severity:'medium', cwe:'CWE-79', langs:['JavaScript','TypeScript'], confidence:'medium',
      re:/dangerouslySetInnerHTML/,
      remediation:'Sanitize HTML with DOMPurify before rendering; prefer escaped JSX.' },
    { id:'php-echo-get', name:'Unescaped echo of request data', category:'xss',
      severity:'high', cwe:'CWE-79', langs:['PHP'], confidence:'medium',
      re:/echo\s+[\s\S]{0,40}?\$_(GET|POST|REQUEST|COOKIE)\b/,
      remediation:'Wrap output in htmlspecialchars()/htmlentities() with ENT_QUOTES.' },

    /* ---------------- Secrets ---------------- */
    { id:'aws-akid', name:'AWS Access Key ID', category:'secrets',
      severity:'critical', cwe:'CWE-798', langs:'*', confidence:'high',
      re:/\b(AKIA|ASIA)[0-9A-Z]{16}\b/,
      remediation:'Revoke immediately. Use IAM roles / Secrets Manager; never commit keys.' },
    { id:'aws-secret', name:'AWS Secret Access Key', category:'secrets',
      severity:'critical', cwe:'CWE-798', langs:'*', confidence:'medium',
      re:/aws_secret_access_key\s*[:=]\s*["']?[A-Za-z0-9\/+]{40}["']?/i,
      remediation:'Rotate the key and move it to a secrets manager.' },
    { id:'private-key', name:'Private key material', category:'secrets',
      severity:'critical', cwe:'CWE-321', langs:'*', confidence:'high',
      re:/-----BEGIN\s+(RSA|EC|DSA|OPENSSH|PGP)?\s*PRIVATE KEY-----/,
      remediation:'Remove the key from source; store in an HSM/KMS/secrets vault.' },
    { id:'generic-apikey', name:'Hardcoded API key/token', category:'secrets',
      severity:'high', cwe:'CWE-798', langs:'*', confidence:'low',
      re:/\b(api[_-]?key|api[_-]?secret|access[_-]?token|auth[_-]?token|client[_-]?secret)\b\s*[:=]\s*["'][A-Za-z0-9_\-\.]{16,}["']/i,
      remediation:'Load secrets from environment variables or a secrets manager at runtime.' },
    { id:'hardcoded-pw', name:'Hardcoded password', category:'secrets',
      severity:'high', cwe:'CWE-259', langs:'*', confidence:'low',
      re:/\b(password|passwd|pwd|pass)\b\s*[:=]\s*["'][^"'\s]{4,}["']/i,
      remediation:'Never hardcode passwords; inject via secure configuration at deploy time.' },
    { id:'slack-token', name:'Slack token', category:'secrets',
      severity:'high', cwe:'CWE-798', langs:'*', confidence:'high',
      re:/xox[baprs]-[0-9A-Za-z-]{10,}/,
      remediation:'Revoke the token and store it securely.' },
    { id:'gh-token', name:'GitHub personal access token', category:'secrets',
      severity:'high', cwe:'CWE-798', langs:'*', confidence:'high',
      re:/\bgh[pousr]_[0-9A-Za-z]{30,}\b/,
      remediation:'Revoke immediately and use short-lived OIDC tokens.' },
    { id:'jwt-literal', name:'Embedded JWT', category:'secrets',
      severity:'medium', cwe:'CWE-798', langs:'*', confidence:'low',
      re:/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/,
      remediation:'Do not commit signed tokens; they may leak claims/keys.' },
    { id:'pg-url', name:'Database connection string with credentials', category:'secrets',
      severity:'high', cwe:'CWE-798', langs:'*', confidence:'medium',
      re:/(postgres(ql)?|mysql|mongodb|redis|amqp):\/\/[^\s:@\/]+:[^\s:@\/]+@/i,
      remediation:'Externalize connection strings; reference secrets, not inline passwords.' },

    /* ---------------- Crypto ---------------- */
    { id:'weak-hash', name:'Weak hash algorithm (MD5/SHA1)', category:'crypto',
      severity:'medium', cwe:'CWE-327', langs:'*', confidence:'medium',
      re:/\b(md5|sha1)\b\s*\(|MessageDigest\.getInstance\(\s*["'](MD5|SHA-?1)["']/i,
      remediation:'Use SHA-256/SHA-3; for passwords use Argon2/bcrypt/scrypt/PBKDF2.' },
    { id:'des-cipher', name:'Broken cipher (DES/RC4/ECB)', category:'crypto',
      severity:'high', cwe:'CWE-327', langs:'*', confidence:'medium',
      re:/\b(DES|DESede|RC4|ARCFOUR)\b|AES\/ECB|"ECB"/,
      remediation:'Use AES-GCM or ChaCha20-Poly1305 (AEAD); avoid ECB mode.' },
    { id:'ssl-verify-off', name:'TLS certificate verification disabled', category:'transport',
      severity:'high', cwe:'CWE-295', langs:'*', confidence:'high',
      re:/(verify\s*=\s*False|CURLOPT_SSL_VERIFYPEER\s*,\s*(0|false)|rejectUnauthorized\s*:\s*false|InsecureSkipVerify\s*:\s*true|ServerCertificateValidationCallback)/i,
      remediation:'Never disable certificate validation; pin or trust a proper CA bundle.' },
    { id:'http-url', name:'Cleartext HTTP endpoint', category:'transport',
      severity:'low', cwe:'CWE-319', langs:'*', confidence:'low',
      re:/["'`]http:\/\/(?!localhost|127\.0\.0\.1|0\.0\.0\.0)[^"'`\s]+/,
      remediation:'Use HTTPS/TLS for all external communication.' },

    /* ---------------- Randomness ---------------- */
    { id:'weak-random', name:'Insecure pseudo-random generator', category:'random',
      severity:'medium', cwe:'CWE-330', langs:'*', confidence:'low',
      re:/\b(Math\.random|random\.random|rand\(\)|mt_rand|new\s+Random\()\b|Math\.random\(\)/,
      remediation:'For security/tokens use a CSPRNG (crypto.getRandomValues, secrets, SecureRandom).' },

    /* ---------------- Deserialization ---------------- */
    { id:'py-pickle', name:'Unsafe deserialization (pickle/yaml.load)', category:'deserialization',
      severity:'high', cwe:'CWE-502', langs:['Python'], confidence:'medium',
      re:/\b(pickle\.loads?|yaml\.load)\s*\(/,
      remediation:'Use yaml.safe_load / json; avoid pickle on untrusted data.' },
    { id:'java-deser', name:'Java native deserialization', category:'deserialization',
      severity:'high', cwe:'CWE-502', langs:['Java'], confidence:'low',
      re:/new\s+ObjectInputStream\s*\(|\.readObject\s*\(/,
      remediation:'Avoid native serialization; use JSON with strict schemas / allowlists.' },
    { id:'php-unserialize', name:'PHP unserialize() on input', category:'deserialization',
      severity:'high', cwe:'CWE-502', langs:['PHP'], confidence:'medium',
      re:/unserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/,
      remediation:'Use json_decode; never unserialize user-controlled data.' },

    /* ---------------- Path traversal / SSRF ---------------- */
    { id:'path-traversal', name:'File access with request-derived path', category:'path-traversal',
      severity:'high', cwe:'CWE-22', langs:'*', confidence:'low',
      re:/(fopen|file_get_contents|readFile(Sync)?|open|File\()\s*\([\s\S]{0,40}?(\$_(GET|POST|REQUEST)|req\.(query|params|body)|request\.)/,
      remediation:'Resolve and validate canonical paths against an allowlisted base directory.' },
    { id:'ssrf-fetch', name:'Outbound request to user-controlled URL', category:'ssrf',
      severity:'high', cwe:'CWE-918', langs:'*', confidence:'low',
      re:/(requests\.(get|post)|axios\.(get|post)|fetch|urllib\.request\.urlopen|HttpClient)\s*\([\s\S]{0,40}?(\$_(GET|POST)|req\.(query|params|body)|request\.)/,
      remediation:'Allowlist destination hosts; block link-local/metadata ranges (169.254.169.254).' },

    /* ---------------- XXE ---------------- */
    { id:'xxe-parser', name:'XML parser without entity hardening', category:'xxe',
      severity:'medium', cwe:'CWE-611', langs:'*', confidence:'low',
      re:/(DocumentBuilderFactory|SAXParserFactory|XMLReader|etree\.parse|libxml_disable_entity_loader|simplexml_load)/,
      remediation:'Disable DTDs and external entities (FEATURE_SECURE_PROCESSING / defusedxml).' },

    /* ---------------- Config / Misc ---------------- */
    { id:'debug-on', name:'Debug mode enabled', category:'config',
      severity:'medium', cwe:'CWE-489', langs:'*', confidence:'low',
      re:/\b(DEBUG|debug)\s*[:=]\s*(True|true|1|"true")\b/,
      remediation:'Disable debug in production; gate behind environment configuration.' },
    { id:'cors-wildcard', name:'Permissive CORS (Access-Control-Allow-Origin: *)', category:'config',
      severity:'medium', cwe:'CWE-942', langs:'*', confidence:'medium',
      re:/Access-Control-Allow-Origin["'\s:,]+\*|cors\(\s*\{\s*origin\s*:\s*["']?\*/i,
      remediation:'Reflect an allowlist of trusted origins; avoid wildcard with credentials.' },
    { id:'insecure-cookie', name:'Cookie missing Secure/HttpOnly', category:'session',
      severity:'low', cwe:'CWE-614', langs:'*', confidence:'low',
      re:/(set_cookie|setcookie|res\.cookie|Set-Cookie)[\s\S]{0,80}/i,
      remediation:'Set HttpOnly, Secure and SameSite attributes on session cookies.' },
    { id:'tmp-insecure', name:'Predictable temp file path', category:'config',
      severity:'low', cwe:'CWE-377', langs:'*', confidence:'low',
      re:/["'`]\/tmp\/[A-Za-z0-9_\-\.]+["'`]/,
      remediation:'Use mkstemp/secure temp APIs with random names and 0600 perms.' },

    /* ---------------- Error handling / disclosure ---------------- */
    { id:'stacktrace', name:'Stack trace / verbose error to client', category:'error-handling',
      severity:'low', cwe:'CWE-209', langs:'*', confidence:'low',
      re:/(printStackTrace\(\)|traceback\.print_exc|console\.error\(\s*err|display_errors\s*[:=]\s*(On|1|true))/i,
      remediation:'Log detailed errors server-side; return generic messages to clients.' },

    /* ---------------- Quality ---------------- */
    { id:'todo-fixme', name:'Unresolved TODO/FIXME/HACK marker', category:'quality',
      severity:'info', cwe:'CWE-546', langs:'*', confidence:'high',
      re:/\b(TODO|FIXME|HACK|XXX|BUG)\b[:\s]/,
      remediation:'Track and resolve outstanding work items before release.' },
    { id:'disabled-test', name:'Disabled/skipped test', category:'quality',
      severity:'info', cwe:'CWE-1126', langs:'*', confidence:'medium',
      re:/\b(it|describe|test)\.(skip|only)\b|@Ignore\b|@pytest\.mark\.skip/,
      remediation:'Re-enable or remove skipped tests; do not ship focused/only tests.' }
  ];

  CITADEL.rules = RULES;
})(window);
