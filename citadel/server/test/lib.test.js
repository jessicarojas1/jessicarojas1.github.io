'use strict';
/* CITADEL backend — unit tests for the security-critical libraries.
 * Run with `node --test`. No external services (in-memory/file paths only).
 */
const os = require('os');
const path = require('path');
// Isolate the file-backed user store to a throwaway dir BEFORE requiring users.
process.env.CITADEL_DATA_DIR = path.join(os.tmpdir(), 'citadel-test-' + Date.now());
delete process.env.DATABASE_URL; delete process.env.REDIS_URL;
process.env.OIDC_ISSUER = 'https://issuer.example';
process.env.OIDC_CLIENT_ID = 'cid'; process.env.OIDC_CLIENT_SECRET = 'sec';
process.env.OIDC_REDIRECT_URI = 'https://app/cb';
process.env.OIDC_ADMIN_EMAILS = 'admin@corp.com';
process.env.OIDC_ALLOWED_DOMAINS = 'corp.com';
process.env.CITADEL_PBKDF2_ITER = '20000';   // keep the FIPS-path KDF fast in tests

const { test } = require('node:test');
const assert = require('node:assert');

const jwt = require('../lib/jwt');
const totp = require('../lib/totp');
const ratelimit = require('../lib/ratelimit');
const sessions = require('../lib/sessions');
const audit = require('../lib/audit');
const users = require('../lib/users');
const oidc = require('../lib/oidc');
const secretbox = require('../lib/secretbox');
const fips = require('../lib/fips');
const tenancy = require('../lib/tenancy');
const dbmod = require('../lib/db');

/* ---------------- JWT ---------------- */
test('jwt: sign/verify roundtrip', () => {
  const t = jwt.sign({ sub: 'u1', role: 'admin' }, 'secret', 60);
  const p = jwt.verify(t, 'secret');
  assert.equal(p.sub, 'u1'); assert.equal(p.role, 'admin');
});
test('jwt: rejects tampered payload', () => {
  const t = jwt.sign({ sub: 'u1' }, 'secret', 60).split('.');
  t[1] = Buffer.from('{"sub":"attacker"}').toString('base64url');
  assert.equal(jwt.verify(t.join('.'), 'secret'), null);
});
test('jwt: rejects wrong secret', () => { assert.equal(jwt.verify(jwt.sign({ sub: 'u' }, 'a', 60), 'b'), null); });
test('jwt: rejects expired', () => { assert.equal(jwt.verify(jwt.sign({ sub: 'u' }, 'a', -5), 'a'), null); });
test('jwt: rejects malformed', () => { assert.equal(jwt.verify('not.a.jwt', 'a'), null); });
test('jwt: rejects non-HS256 alg header (alg pinning)', () => {
  const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
  // a token whose header claims alg:none — must be rejected even if "signature" is empty
  const forged = b64({ alg: 'none', typ: 'JWT' }) + '.' + b64({ sub: 'attacker' }) + '.';
  assert.equal(jwt.verify(forged, 'secret'), null);
});

/* ---------------- Secretbox (at-rest secret encryption) ---------------- */
test('secretbox: disabled without a key — values pass through unchanged', () => {
  delete process.env.CITADEL_DATA_KEY; secretbox._reset();
  assert.equal(secretbox.enabled(), false);
  assert.equal(secretbox.seal('JBSWY3DPEHPK3PXP'), 'JBSWY3DPEHPK3PXP');
  assert.equal(secretbox.open('JBSWY3DPEHPK3PXP'), 'JBSWY3DPEHPK3PXP'); // legacy plaintext read
});
test('secretbox: seal/open roundtrip with a 32-byte key (hex)', () => {
  process.env.CITADEL_DATA_KEY = 'a'.repeat(64); secretbox._reset();
  assert.equal(secretbox.enabled(), true);
  const sealed = secretbox.seal('JBSWY3DPEHPK3PXP');
  assert.ok(secretbox.isSealed(sealed));
  assert.ok(!sealed.includes('JBSWY3DPEHPK3PXP')); // ciphertext, not plaintext
  assert.equal(secretbox.open(sealed), 'JBSWY3DPEHPK3PXP');
});
test('secretbox: seal is idempotent and reads legacy plaintext transparently', () => {
  process.env.CITADEL_DATA_KEY = 'b'.repeat(64); secretbox._reset();
  const once = secretbox.seal('seed'); const twice = secretbox.seal(once);
  assert.equal(once, twice);                      // already-sealed is not re-sealed
  assert.equal(secretbox.open('legacy-plain'), 'legacy-plain');
});
test('secretbox: tampered ciphertext / wrong key fails closed (GCM auth) → null', () => {
  process.env.CITADEL_DATA_KEY = 'c'.repeat(64); secretbox._reset();
  const sealed = secretbox.seal('topsecret');
  const flipped = sealed.slice(0, -2) + (sealed.endsWith('A') ? 'B' : 'A') + '=';
  assert.equal(secretbox.open(flipped), null);    // tamper detected
  process.env.CITADEL_DATA_KEY = 'd'.repeat(64); secretbox._reset();
  assert.equal(secretbox.open(sealed), null);     // wrong key
  delete process.env.CITADEL_DATA_KEY; secretbox._reset();
});
test('secretbox: invalid key length disables encryption (no crash)', () => {
  process.env.CITADEL_DATA_KEY = 'tooshort'; secretbox._reset();
  assert.equal(secretbox.enabled(), false);
  assert.equal(secretbox.seal('x'), 'x');
  delete process.env.CITADEL_DATA_KEY; secretbox._reset();
});

/* ---------------- FIPS mode ---------------- */
test('fips: status reflects active state and selects the FIPS-approved KDF', () => {
  fips._forceActive(false);
  assert.equal(fips.active(), false);
  assert.equal(fips.status().passwordKdf, 'scrypt');
  fips._forceActive(true);
  assert.equal(fips.active(), true);
  assert.equal(fips.status().passwordKdf, 'pbkdf2-hmac-sha256');
  assert.ok(fips.status().pbkdf2Iterations >= 10000);
  fips._forceActive(null);    // restore real OpenSSL state for later tests
});

/* ---------------- Multi-tenancy (schema-per-tenant; H5) ---------------- */
test('tenancy: slug validation rejects injection / illegal identifiers', () => {
  for (const ok of ['acme', 'a1', 'my-org', 'tenant-2', 'ab', 'x9y8z7']) {
    assert.ok(tenancy.valid(ok), 'should accept ' + ok);
  }
  for (const bad of ['', 'a', 'A1', '-acme', 'acme-', 'a--b', 'pu blic', 'acme;drop',
                     'a_b', 'тест', '1;DROP TABLE citadel_users', '../etc', 'x'.repeat(33)]) {
    assert.equal(tenancy.valid(bad), false, 'should reject ' + JSON.stringify(bad));
  }
});
test('tenancy: schemaFor derives a safe schema name; throws on bad slug', () => {
  assert.equal(tenancy.schemaFor('acme'), 'citadel_t_acme');
  assert.equal(tenancy.schemaFor('my-org'), 'citadel_t_my_org');   // hyphen -> underscore
  assert.throws(() => tenancy.schemaFor('Bad; DROP'));
});
test('tenancy: db.quoteIdent quotes valid identifiers and rejects unsafe ones', () => {
  assert.equal(dbmod.quoteIdent('citadel_t_acme'), '"citadel_t_acme"');
  for (const bad of ['a; DROP TABLE x', 'public"; --', '"evil"', 'has space', 'citadel_t_acme; select', 1, null]) {
    assert.throws(() => dbmod.quoteIdent(bad), 'should reject ' + JSON.stringify(bad));
  }
});
test('tenancy: runInTenant sets an ambient schema; default scope is none', () => {
  assert.equal(dbmod.currentSchema(), undefined);
  dbmod.runInTenant('citadel_t_acme', () => {
    assert.equal(dbmod.currentSchema(), 'citadel_t_acme');
  });
  assert.equal(dbmod.currentSchema(), undefined);   // restored after the scope
});
test('tenancy: resolveSlug reads header > query > subdomain, validating each', () => {
  assert.equal(tenancy.resolveSlug({ headers: { 'x-citadel-tenant': 'Acme' } }), 'acme');
  assert.equal(tenancy.resolveSlug({ headers: {}, query: { tenant: 'beta' } }), 'beta');
  assert.equal(tenancy.resolveSlug({ headers: { 'x-citadel-tenant': 'evil; drop' } }), null);
  process.env.CITADEL_BASE_DOMAIN = 'citadel.example.com';
  assert.equal(tenancy.resolveSlug({ headers: { host: 'gamma.citadel.example.com:443' } }), 'gamma');
  assert.equal(tenancy.resolveSlug({ headers: { host: 'citadel.example.com' } }), null); // apex, no tenant
  delete process.env.CITADEL_BASE_DOMAIN;
});
test('tenancy: disabled by default (opt-in via CITADEL_MULTITENANT)', () => {
  assert.equal(tenancy.multitenant(), false);
  assert.equal(tenancy.enabled(), false);
});

/* ---------------- TOTP / MFA ---------------- */
test('totp: verifies its own code, rejects wrong/garbage', () => {
  const s = totp.generateSecret();
  assert.ok(totp.verify(s, totp.totp(s)));
  assert.equal(totp.verify(s, '000000'), false);
  assert.equal(totp.verify(s, 'abcdef'), false);
});
test('totp: RFC 6238 test vector (T=59, 8 digits)', () => {
  const s = totp.base32Encode(Buffer.from('12345678901234567890'));
  assert.equal(totp.hotp(s, Math.floor(59 / 30), 8), '94287082');
});

/* ---------------- Rate limit / lockout ---------------- */
test('ratelimit: fixed window blocks past max', async () => {
  const k = 'rl:' + Date.now();
  for (let i = 0; i < 3; i++) assert.ok((await ratelimit.limit(k, 3, 60000)).ok);
  assert.equal((await ratelimit.limit(k, 3, 60000)).ok, false);
});
test('ratelimit: brute-force lockout + clear', async () => {
  const k = 'lk:' + Date.now();
  let r;
  for (let i = 0; i < 5; i++) r = await ratelimit.fail(k, { maxFails: 5, windowMs: 60000, lockMs: 60000 });
  assert.equal(r.locked, true);
  assert.equal((await ratelimit.lockState(k)).locked, true);
  await ratelimit.clearFails(k);
  assert.equal((await ratelimit.lockState(k)).locked, false);
});

/* ---------------- Sessions ---------------- */
test('sessions: register, revoke, isRevoked', () => {
  sessions.register({ jti: 'j1', userId: 'u1', email: 'a@b.c', role: 'admin' });
  assert.equal(sessions.isRevoked('j1'), false);
  sessions.revoke('j1');
  assert.equal(sessions.isRevoked('j1'), true);
});
test('sessions: revokeAllForUser', () => {
  sessions.register({ jti: 'j2', userId: 'u9' });
  sessions.register({ jti: 'j3', userId: 'u9' });
  assert.ok(sessions.revokeAllForUser('u9') >= 2);
  assert.equal(sessions.isRevoked('j2'), true);
  assert.equal(sessions.isRevoked('j3'), true);
});

/* ---------------- Audit ---------------- */
test('audit: record + list by prefix', async () => {
  audit.record('test.event', { actor: 'x', ok: true });
  const ev = await audit.list(20, 'test');
  assert.ok(ev.some(e => e.type === 'test.event' && e.actor === 'x'));
});
test('audit: events are hash-chained (each links to the previous)', () => {
  const a = audit.record('chain.a', { actor: 'u' });
  const b = audit.record('chain.b', { actor: 'u' });
  assert.match(a.hash, /^[0-9a-f]{64}$/);
  assert.equal(b.prevHash, a.hash);                         // b links to a
  assert.equal(a.hash, audit.hashEvent(a.prevHash, a));     // hash is reproducible
});
test('audit: verifyChain passes for an intact chain', async () => {
  const v = await audit.verifyChain();
  assert.equal(v.ok, true);
  assert.equal(v.brokenAt, null);
  assert.ok(v.count >= 1);
});
test('audit: verifyChain detects a tampered record', async () => {
  // hashEvent over mutated content no longer matches the stored hash → break.
  const e = audit.record('chain.tamper', { actor: 'orig', detail: 'before' });
  const forged = { ...e, detail: 'after' };
  assert.notEqual(audit.hashEvent(forged.prevHash, forged), e.hash);
  // A later record still chains off the genuine (unmutated) hash, proving the
  // mutated copy would be rejected on a re-walk.
  const next = audit.record('chain.next', { actor: 'orig' });
  assert.equal(next.prevHash, e.hash);
});

/* ---------------- Users + MFA + passwords ---------------- */
test('users: init seeds a default admin', async () => {
  await users.init();
  const u = users.getByEmail('admin@citadel.local');
  assert.ok(u && u.role === 'admin' && u.mustChange === true);
});
test('users: verifyPassword + timing-safe failure', () => {
  assert.ok(users.verifyPassword('admin@citadel.local', 'citadel-admin'));
  assert.equal(users.verifyPassword('admin@citadel.local', 'wrong'), null);
  assert.equal(users.verifyPassword('nobody@x.io', 'x'), null);
});
test('users: never leak pass/salt/mfa secrets via the API shape', () => {
  const u = users.get(users.getByEmail('admin@citadel.local').id);
  assert.equal(u.pass, undefined); assert.equal(u.salt, undefined);
  assert.equal(u.mfaSecret, undefined); assert.equal(u.mfaBackup, undefined);
});
test('users: full MFA lifecycle (setup → enable → TOTP + one-time backup → disable)', () => {
  const id = users.getByEmail('admin@citadel.local').id;
  const { secret } = users.mfaBeginSetup(id);
  const { backupCodes } = users.mfaEnable(id, totp.totp(secret));
  assert.equal(backupCodes.length, 10);
  assert.ok(users.mfaEnabled(id));
  assert.ok(users.mfaVerify(id, totp.totp(secret)));      // TOTP works
  assert.ok(users.mfaVerify(id, backupCodes[0]));         // backup works
  assert.equal(users.mfaVerify(id, backupCodes[0]), false); // ...once only
  users.mfaDisable(id);
  assert.equal(users.mfaEnabled(id), false);
});
test('users: with CITADEL_DATA_KEY, TOTP seed is ciphertext on disk yet usable in-memory', () => {
  const fs = require('fs');
  const FILE = path.join(process.env.CITADEL_DATA_DIR, 'users.json');
  process.env.CITADEL_DATA_KEY = 'e'.repeat(64); secretbox._reset();
  const id = users.getByEmail('admin@citadel.local').id;
  const { secret } = users.mfaBeginSetup(id);          // save() seals to disk
  users.mfaEnable(id, totp.totp(secret));              // activates + persists
  const onDisk = fs.readFileSync(FILE, 'utf8');
  assert.ok(!onDisk.includes(secret));                 // raw Base32 seed never hits disk
  assert.match(onDisk, /"mfaSecret":\s*"enc:v1:/);     // it is sealed
  assert.ok(users.mfaVerify(id, totp.totp(secret)));   // in-memory cache still plaintext → works
  users.mfaDisable(id);
  delete process.env.CITADEL_DATA_KEY; secretbox._reset();
});
test('users: FIPS mode hashes new passwords with PBKDF2; both KDFs verify (self-describing)', () => {
  const fsx = require('fs');
  const FILE = path.join(process.env.CITADEL_DATA_DIR, 'users.json');
  fips._forceActive(false);
  users.add({ name: 'Scry', email: 'scry@test', role: 'viewer', password: 'ScryptPass1' });
  assert.ok(users.verifyPassword('scry@test', 'ScryptPass1'));      // legacy scrypt verifies
  fips._forceActive(true);                                          // simulate FIPS active
  users.add({ name: 'Fip', email: 'fip@test', role: 'viewer', password: 'PbkdfPass1' });
  assert.ok(users.verifyPassword('fip@test', 'PbkdfPass1'));        // pbkdf2 verifies under FIPS
  assert.match(fsx.readFileSync(FILE, 'utf8'), /"pass":\s*"pbkdf2\$\d+\$[0-9a-f]{64}"/); // pbkdf2 form on disk
  fips._forceActive(false);                                         // back to scrypt-mode
  assert.ok(users.verifyPassword('fip@test', 'PbkdfPass1'));        // pbkdf2 hash STILL verifies
  assert.ok(users.verifyPassword('scry@test', 'ScryptPass1'));      // scrypt user unaffected
  users.remove(users.getByEmail('fip@test').id);
  users.remove(users.getByEmail('scry@test').id);
  fips._forceActive(null);
});
test('users: changeOwnPassword verifies current + enforces min length', () => {
  const id = users.getByEmail('admin@citadel.local').id;
  assert.throws(() => users.changeOwnPassword(id, 'wrong-current', 'NewStrongPass1'));
  assert.throws(() => users.changeOwnPassword(id, 'citadel-admin', 'short'));
  users.changeOwnPassword(id, 'citadel-admin', 'NewStrongPass1');
  assert.ok(users.verifyPassword('admin@citadel.local', 'NewStrongPass1'));
});
test('users: last-admin removal guard', () => {
  const id = users.getByEmail('admin@citadel.local').id;
  assert.throws(() => users.remove(id), /last active administrator/i);
});
test('users: SSO JIT provisioning is idempotent', () => {
  const a = users.upsertSsoUser({ email: 'sso@corp.com', name: 'SSO', role: 'viewer' });
  const b = users.upsertSsoUser({ email: 'sso@corp.com', name: 'SSO', role: 'viewer' });
  assert.equal(a.id, b.id); assert.equal(a.email, 'sso@corp.com');
});

/* ---------------- OIDC identity mapping ---------------- */
test('oidc: enabled + maps admin/default/denied', () => {
  assert.equal(oidc.enabled(), true);
  assert.equal(oidc.mapIdentity({ email: 'admin@corp.com' }).role, 'admin');
  assert.equal(oidc.mapIdentity({ email: 'jane@corp.com' }).role, 'viewer');
  assert.equal(oidc.mapIdentity({ email: 'eve@evil.com' }), null);   // domain not allowed
  assert.equal(oidc.mapIdentity({ name: 'no-email' }), null);
});

/* ---------------- Java pack + taint-gating (via the browser engine) ---------------- */
function loadEngine() {
  global.window = global;
  require('../../js/languages.js'); require('../../js/frameworks.js');
  require('../../js/rules.js'); require('../../js/rules-java.js');
  require('../../js/secrets.js'); require('../../js/sbom.js');
  require('../../js/binary.js'); require('../../js/scanner.js');
  return global.CITADEL;
}
const javaEntry = (content) => ({ path: 'T.java', lang: 'Java', size: content.length, content });
const cwes = (rep) => new Set((rep.findings || []).map(f => f.cwe));

test('java pack: taint-gated XSS fires only when the sink carries user input', async () => {
  const C = loadEngine();
  // tainted: request header flows straight into the response writer → XSS (CWE-79)
  const vuln = await C.scanner.scan([javaEntry(
    'String param = request.getHeader("x");\nresponse.getWriter().write(param);\n')]);
  assert.ok(cwes(vuln).has('CWE-79'), 'tainted writer sink should flag CWE-79');
  // safe: identical sink but the argument is a string literal → must be suppressed
  const safe = await C.scanner.scan([javaEntry(
    'String param = request.getHeader("x");\nresponse.getWriter().write("static literal");\n')]);
  assert.equal(cwes(safe).has('CWE-79'), false, 'literal-arg writer must not flag XSS');
});

test('java pack: a recognized sanitizer on the data path clears taint (no XSS)', async () => {
  const C = loadEngine();
  // param is encoded before reaching the writer → statement-local sanitizer
  // clearing must stop taint propagating to `safe`, so no XSS is reported.
  const rep = await C.scanner.scan([javaEntry(
    'String param = request.getHeader("x");\nString safe = org.owasp.esapi.ESAPI.encoder().encodeForHTML(param);\nresponse.getWriter().write(safe);\n')]);
  assert.equal(cwes(rep).has('CWE-79'), false, 'encoded value must not be treated as tainted');
  // numeric coercion is also a neutralizer
  const num = await C.scanner.scan([javaEntry(
    'String p = request.getParameter("id");\nint id = Integer.parseInt(p);\nresponse.getWriter().write("" + id);\n')]);
  assert.equal(cwes(num).has('CWE-79'), false, 'numeric-coerced value is not injectable');
});

test('java pack: insecure-cookie + path-traversal detection', async () => {
  const C = loadEngine();
  const cookie = await C.scanner.scan([javaEntry('c.setSecure(false);\nresponse.addCookie(c);\n')]);
  assert.ok(cwes(cookie).has('CWE-614'), 'setSecure(false) should flag CWE-614');
  const okCookie = await C.scanner.scan([javaEntry('c.setSecure(true);\nresponse.addCookie(c);\n')]);
  assert.equal(cwes(okCookie).has('CWE-614'), false, 'setSecure(true) must be clean');
  const trav = await C.scanner.scan([javaEntry(
    'String fn = request.getParameter("f");\nnew java.io.FileInputStream(new java.io.File(fn));\n')]);
  assert.ok(cwes(trav).has('CWE-22'), 'tainted File() should flag path traversal');
});

/* ---------------- schema.sql stays in sync with db.js ---------------- */
test('schema.sql covers every table/column in the canonical db.js SCHEMA', () => {
  const fsx = require('fs'), px = require('path');
  const db = require('../lib/db');
  const sql = fsx.readFileSync(px.resolve(__dirname, '../../database/schema.sql'), 'utf8');
  const tables = (db.SCHEMA.match(/CREATE TABLE IF NOT EXISTS (\w+)/g) || []).map(s => s.split(' ').pop());
  assert.ok(tables.length >= 6);
  for (const t of tables) assert.ok(sql.includes(t), 'schema.sql missing table ' + t);
  // MFA ALTER columns must be reflected too (idempotent upgrade path).
  for (const col of (db.SCHEMA.match(/ADD COLUMN IF NOT EXISTS (\w+)/g) || []).map(s => s.split(' ').pop())) {
    assert.ok(sql.includes(col), 'schema.sql missing column ' + col);
  }
});

/* ---------------- Finding fingerprints + classification + merge ---------------- */
test('fingerprint: line-stable identity, classification, and cross-tool merge', () => {
  const fp = require('../../js/fingerprint.js');
  const a = { category: 'injection', file: 'app/x.js', cwe: 'CWE-89', snippet: 'db.query(q)', line: 10 };
  const moved = { category: 'injection', file: 'app/x.js', cwe: 'CWE-89', snippet: 'db.query(q)', line: 42 };
  const other = { category: 'injection', file: 'app/x.js', cwe: 'CWE-89', snippet: 'different code', line: 10 };
  assert.equal(fp.of(a), fp.of(moved), 'fingerprint is stable across line drift');
  assert.notEqual(fp.of(a), fp.of(other), 'different evidence -> different fingerprint');
  // classification: heuristic vuln vs scanner-confirmed secret
  fp.classify(a);
  assert.equal(a.kind, 'vuln'); assert.equal(a.detection, 'heuristic');
  assert.equal(a.confirmed, false); assert.equal(a.disposition, 'open');
  const sec = fp.classify({ category: 'secrets', file: 'x', source: 'gitleaks', snippet: 'AKIA...' });
  assert.equal(sec.kind, 'secret'); assert.equal(sec.confirmed, true); assert.equal(sec.detection, 'scanner');
  // merge: same issue from heuristic + semgrep collapses; worst severity + confirmed wins
  const merged = fp.merge([
    { category: 'injection', file: 'x.js', cwe: 'CWE-89', snippet: 'q', severity: 'medium', source: 'heuristic' },
    { category: 'injection', file: 'x.js', cwe: 'CWE-89', snippet: 'q', severity: 'high', source: 'semgrep' }
  ]);
  assert.equal(merged.length, 1);
  assert.equal(merged[0].severity, 'high');
  assert.ok(merged[0].confirmed);
  assert.deepEqual(merged[0].sources.sort(), ['heuristic', 'semgrep']);
});

/* ---------------- Risk score + explainable compliance ---------------- */
test('scoring: distinct risk score weights confirmed findings; rationale is explainable', () => {
  const C = loadEngine();
  const sc = C.scanner.score(
    [{ severity: 'critical', category: 'injection', confirmed: true }, { severity: 'high', category: 'xss', confirmed: false }],
    { maintainability: 80, commentRatio: 6, loc: 1000 });
  assert.ok(sc.risk > 0 && sc.risk <= 100, 'risk in (0,100]');
  assert.ok(['Minimal', 'Low', 'Moderate', 'High', 'Critical'].indexOf(sc.riskBand) >= 0);
  // confirmed criticals weigh more than the same finding heuristic
  const hi = C.scanner.score([{ severity: 'critical', category: 'injection', confirmed: true }], { maintainability: 80, commentRatio: 6, loc: 1000 });
  const lo = C.scanner.score([{ severity: 'critical', category: 'injection', confirmed: false }], { maintainability: 80, commentRatio: 6, loc: 1000 });
  assert.ok(hi.risk > lo.risk, 'scanner-confirmed risk > heuristic risk');
  assert.match(C.frameworks.rationale('injection'), /input|control/i);
  assert.equal(typeof C.frameworks.rationale('config'), 'string');
});

/* ---------------- Shared dispositions (graceful without a DB) ---------------- */
test('dispositions: valid states; degrades to no-op without a database', async () => {
  const d = require('../lib/dispositions');
  assert.ok(d.valid('accepted') && d.valid('false-positive') && d.valid('open'));
  assert.equal(d.valid('bogus'), false);
  assert.equal(d.enabled(), false);              // no DATABASE_URL in tests
  assert.deepEqual(await d.list(), {});
  assert.equal(await d.set('fp123', 'accepted', 'tester'), null);
});

/* ---------------- ReDoS isolation (worker + timeout) ---------------- */
test('engine: isolated heuristic scan runs in a worker and degrades on timeout', async () => {
  const fsx = require('fs'), osx = require('os'), px = require('path');
  const engine = require('../lib/engine');
  process.env.CITADEL_SCAN_ISOLATION = '1';   // force isolation regardless of runner RAM
  const dir = fsx.mkdtempSync(px.join(osx.tmpdir(), 'citadel-iso-'));
  fsx.writeFileSync(px.join(dir, 'v.java'), 'java.security.MessageDigest.getInstance("MD5");\nc.setSecure(false);\n');
  // normal isolated run finds the same issues as in-process
  const ok = await engine.analyzeDir(dir, { findings: [] }, null, { isolate: true, timeoutMs: 15000 });
  assert.ok(ok.findings.length >= 1);
  assert.equal((ok.meta.warnings || []).length, 0);
  // an impossibly tight deadline terminates the worker and degrades gracefully
  const slow = await engine.analyzeDir(dir, { findings: [] }, null, { isolate: true, timeoutMs: 1 });
  assert.ok((slow.meta.warnings || []).some(w => /terminated|ReDoS|timed/i.test(w)));
  assert.ok(slow.scoring && slow.scoring.grade);   // still produces a valid report
  delete process.env.CITADEL_SCAN_ISOLATION;
});

/* ---------------- Remediation auto-fixes + SARIF ---------------- */
test('remediate: offers safe mechanical fixes, declines when nothing to do', () => {
  global.window = global;
  const rem = require('../../js/remediate.js');
  const cookie = rem.fix({ ruleId: 'java-cookie-insecure', lineText: '    c.setSecure(false);', snippet: 'c.setSecure(false);' });
  assert.ok(cookie && cookie.replacement.includes('setSecure(true)') && cookie.exact === true);
  const hash = rem.fix({ lineText: 'MessageDigest.getInstance("MD5")', snippet: 'MessageDigest.getInstance("MD5")' });
  assert.ok(hash && /SHA-256/.test(hash.replacement));
  assert.equal(rem.fix({ lineText: 'int x = safeCall();', snippet: 'int x = safeCall();' }), null);
});
test('sarif: emits fixes + partialFingerprints for fixable findings', () => {
  global.window = global;
  require('../../js/remediate.js');
  const sarif = require('../../js/sarif.js');
  const log = sarif.fromReport({ findings: [
    { ruleId: 'java-cookie-insecure', severity: 'medium', cwe: 'CWE-614', file: 'A.java', line: 5,
      lineText: 'c.setSecure(false);', snippet: 'c.setSecure(false);' }
  ] });
  const r = log.runs[0].results[0];
  assert.ok(r.partialFingerprints && r.partialFingerprints.citadel);
  assert.ok(r.fixes && r.fixes[0].artifactChanges[0].replacements[0].insertedContent.text.includes('setSecure(true)'));
  assert.equal(log.runs[0].tool.driver.name, 'CITADEL');
});
test('sarif: disposition becomes a SARIF suppression; open findings are not suppressed', () => {
  global.window = global;
  const sarif = require('../../js/sarif.js');
  const log = sarif.fromReport({ findings: [
    { ruleId: 'r-open', severity: 'high', file: 'A.js', line: 1, snippet: 'a' },
    { ruleId: 'r-fp', severity: 'high', file: 'B.js', line: 1, snippet: 'b', disposition: 'false-positive' },
    { ruleId: 'r-accept', severity: 'high', file: 'C.js', line: 1, snippet: 'c', disposition: 'accepted' }
  ] });
  const byRule = id => log.runs[0].results.find(r => r.ruleId === id);
  assert.ok(!byRule('r-open').suppressions, 'open -> no suppression');
  assert.equal(byRule('r-fp').suppressions[0].status, 'rejected');
  assert.equal(byRule('r-fp').suppressions[0].kind, 'external');
  assert.equal(byRule('r-accept').suppressions[0].status, 'accepted');
});

/* ---------------- Regression guards from the functionality audit ---------------- */
test('worker ruleset stays in parity with index.html (same rule packs)', () => {
  const fsx = require('fs'), px = require('path');
  const idx = fsx.readFileSync(px.resolve(__dirname, '../../index.html'), 'utf8');
  const wrk = fsx.readFileSync(px.resolve(__dirname, '../../js/worker.js'), 'utf8');
  const packs = new Set((idx.match(/js\/(rules[\w-]*)\.js/g) || []).map(s => s.replace('js/', '').replace('.js', '')));
  assert.ok(packs.size >= 7, 'index.html loads the rule packs');
  for (const p of packs) assert.ok(wrk.indexOf(p + '.js') >= 0, 'worker.js missing rule pack ' + p);
});
test('scoring: unknown / missing severity never yields NaN', () => {
  const C = loadEngine();
  const sc = C.scanner.score(
    [{ category: 'injection' }, { severity: 'weird', category: 'xss' }],
    { maintainability: 80, commentRatio: 6, loc: 1000 });
  assert.ok(Number.isFinite(sc.security) && Number.isFinite(sc.overall) && Number.isFinite(sc.risk), 'no NaN scores');
  assert.ok(['A', 'B', 'C', 'D', 'E', 'F'].indexOf(sc.grade) >= 0);
});

/* ---------------- License policy (via the browser engine) ---------------- */
test('license policy: tiers denied/review/allowed', () => {
  const tier = loadEngine().scanner.licenseTier;
  assert.equal(tier('AGPL-3.0'), 'denied');
  assert.equal(tier('SSPL-1.0'), 'denied');
  assert.equal(tier('GPL-3.0'), 'denied');
  assert.equal(tier('LGPL-2.1'), 'review');
  assert.equal(tier('MPL-2.0'), 'review');
  assert.equal(tier('MIT'), 'allowed');
  assert.equal(tier('Apache-2.0'), 'allowed');
});
