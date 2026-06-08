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

const { test } = require('node:test');
const assert = require('node:assert');

const jwt = require('../lib/jwt');
const totp = require('../lib/totp');
const ratelimit = require('../lib/ratelimit');
const sessions = require('../lib/sessions');
const audit = require('../lib/audit');
const users = require('../lib/users');
const oidc = require('../lib/oidc');

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
