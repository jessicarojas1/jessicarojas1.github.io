'use strict';
/* CITADEL backend — HTTP integration tests.
 * Spawns the real server (file-store mode, no DB/Redis), exercises the auth +
 * scan endpoints over HTTP, then tears the server down. Run with `node --test`.
 */
const { test, before, after } = require('node:test');
const assert = require('node:assert');
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');
const totp = require('../lib/totp');

const PORT = 8400 + Math.floor(Math.random() * 300);
const BASE = 'http://127.0.0.1:' + PORT;
const DATA = fs.mkdtempSync(path.join(os.tmpdir(), 'citadel-api-'));
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'citadel-tmp-'));
let child;

function api(p, opts) { return fetch(BASE + p, opts); }
async function json(p, opts) { const r = await api(p, opts); return { status: r.status, body: await r.json().catch(() => null) }; }
function authed(token, extra) { return Object.assign({ Authorization: 'Bearer ' + token }, extra || {}); }

before(async () => {
  child = spawn(process.execPath, ['server.js'], {
    cwd: path.join(__dirname, '..'),
    env: Object.assign({}, process.env, {
      PORT: String(PORT), CITADEL_DATA_DIR: DATA, CITADEL_TMP: TMP,
      DATABASE_URL: '', REDIS_URL: '', CITADEL_ADMIN_PASSWORD: '', NODE_ENV: 'test'
    }),
    stdio: 'ignore'
  });
  for (let i = 0; i < 80; i++) {
    try { const r = await api('/api/health'); if (r.ok) return; } catch (e) {}
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('server did not come up');
});
after(() => { if (child) child.kill('SIGKILL'); });

test('health reports file store', async () => {
  const { status, body } = await json('/api/health');
  assert.equal(status, 200);
  assert.equal(body.store.users, 'file');
  assert.equal(body.store.durable, false);
});

test('login → access+refresh; /me resolves; refresh token cannot auth', async () => {
  const login = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'citadel-admin' }) });
  assert.equal(login.status, 200);
  assert.ok(login.body.token && login.body.refreshToken);
  const me = await json('/api/auth/me', { headers: authed(login.body.token) });
  assert.equal(me.status, 200); assert.equal(me.body.role, 'admin');
  // a refresh token must NOT be accepted as an access token
  const bad = await api('/api/auth/me', { headers: authed(login.body.refreshToken) });
  assert.equal(bad.status, 401);
  // refresh exchange yields a working access token
  const refreshed = await json('/api/auth/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ refreshToken: login.body.refreshToken }) });
  assert.equal(refreshed.status, 200);
  const me2 = await api('/api/auth/me', { headers: authed(refreshed.body.token) });
  assert.equal(me2.status, 200);
});

test('bad credentials are rejected', async () => {
  const r = await api('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'nope' }) });
  assert.equal(r.status, 401);
});

test('admin-only routes require an admin token', async () => {
  assert.equal((await api('/api/users')).status, 401);
  assert.equal((await api('/api/audit')).status, 401);
});

test('scan-url blocks SSRF to internal/metadata hosts', async () => {
  for (const url of ['https://localhost/x.git', 'https://169.254.169.254/latest.git', 'https://127.0.0.1/r.git']) {
    const r = await json('/api/scan-url', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url }) });
    assert.equal(r.status, 400, url);
    assert.match(r.body.error, /not allowed|internal|metadata|valid public/i);
  }
});

test('scan-url rejects a traversal subpath (before any clone)', async () => {
  for (const subpath of ['../etc', 'a/../../b', '..']) {
    const r = await json('/api/scan-url', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: 'https://github.com/owner/repo', subpath }) });
    assert.equal(r.status, 400, subpath);
    assert.match(r.body.error, /invalid subpath/i);
  }
});

test('default-cred admin must change password before sensitive routes', async () => {
  const l = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'citadel-admin' }) });
  assert.equal(l.body.user.mustChange, true);
  // admin route blocked with 403 mustChange until the password is rotated
  const u = await json('/api/users', { headers: authed(l.body.token) });
  assert.equal(u.status, 403); assert.equal(u.body.mustChange, true);
  // changing the password lifts the block
  await json('/api/auth/password', { method: 'POST', headers: authed(l.body.token, { 'Content-Type': 'application/json' }), body: JSON.stringify({ current: 'citadel-admin', next: 'RotatedStrong1' }) });
  const l2 = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'RotatedStrong1' }) });
  assert.equal((await api('/api/users', { headers: authed(l2.body.token) })).status, 200);
  // restore the well-known password so later tests stay consistent (mustChange now cleared)
  await json('/api/auth/password', { method: 'POST', headers: authed(l2.body.token, { 'Content-Type': 'application/json' }), body: JSON.stringify({ current: 'RotatedStrong1', next: 'citadel-admin' }) });
});

test('admin password reset forces the target user to change it at next login', async () => {
  // sign in as admin and clear its own must-change so it can manage users
  const a = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'citadel-admin' }) });
  let tok = a.body.token;
  await json('/api/auth/password', { method: 'POST', headers: authed(tok, { 'Content-Type': 'application/json' }), body: JSON.stringify({ current: 'citadel-admin', next: 'AdminStrong1' }) });
  tok = (await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'AdminStrong1' }) })).body.token;
  // create a user (logs in cleanly, no must-change)
  const created = await json('/api/users', { method: 'POST', headers: authed(tok, { 'Content-Type': 'application/json' }), body: JSON.stringify({ name: 'Bob', email: 'bob@corp.com', role: 'viewer', password: 'BobInitial12' }) });
  const id = created.body.id;
  assert.ok(!(await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'bob@corp.com', password: 'BobInitial12' }) })).body.user.mustChange, 'a newly created user is not flagged must-change');
  // admin resets Bob's password -> Bob must now change it
  const reset = await json('/api/users/' + id + '/password', { method: 'POST', headers: authed(tok, { 'Content-Type': 'application/json' }), body: JSON.stringify({ password: 'TempReset123' }) });
  assert.equal(reset.body.mustChange, true);
  const bob = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'bob@corp.com', password: 'TempReset123' }) });
  assert.equal(bob.body.user.mustChange, true);
  // restore the well-known admin password for later tests
  await json('/api/auth/password', { method: 'POST', headers: authed(tok, { 'Content-Type': 'application/json' }), body: JSON.stringify({ current: 'AdminStrong1', next: 'citadel-admin' }) });
});

test('scan an uploaded file returns a report with findings', async () => {
  const fd = new FormData();
  fd.append('files', new Blob(['const x = eval(userInput);\ndb.query("SELECT * FROM t WHERE id=" + id);\n'], { type: 'text/javascript' }), 'vuln.js');
  const r = await api('/api/scan', { method: 'POST', body: fd });
  assert.equal(r.status, 200);
  const rep = await r.json();
  assert.ok(Array.isArray(rep.findings) && rep.findings.length >= 1);
  assert.ok(rep.scoring && rep.scoring.grade);
});

test('logout revokes the session (token then rejected)', async () => {
  const l = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'citadel-admin' }) });
  const token = l.body.token;            // MFA not yet enabled at this point
  assert.ok(token);
  assert.equal((await api('/api/auth/me', { headers: authed(token) })).status, 200);
  assert.equal((await api('/api/auth/logout', { method: 'POST', headers: authed(token) })).status, 200);
  assert.equal((await api('/api/auth/me', { headers: authed(token) })).status, 401);  // revoked
});

test('MFA enrollment makes the next login two-step', async () => {
  const login = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'citadel-admin' }) });
  const token = login.body.token;
  const setup = await json('/api/auth/mfa/setup', { method: 'POST', headers: authed(token) });
  assert.ok(setup.body.secret);
  const enable = await json('/api/auth/mfa/enable', { method: 'POST', headers: authed(token, { 'Content-Type': 'application/json' }), body: JSON.stringify({ token: totp.totp(setup.body.secret) }) });
  assert.equal(enable.body.backupCodes.length, 10);
  // next login should now require MFA
  const l2 = await json('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@citadel.local', password: 'citadel-admin' }) });
  assert.equal(l2.body.mfaRequired, true);
  assert.ok(l2.body.mfaToken && !l2.body.token);
  // wrong code rejected, correct code completes
  const badv = await api('/api/auth/mfa/verify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mfaToken: l2.body.mfaToken, code: '000000' }) });
  assert.equal(badv.status, 401);
  const okv = await json('/api/auth/mfa/verify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mfaToken: l2.body.mfaToken, code: totp.totp(setup.body.secret) }) });
  assert.equal(okv.status, 200); assert.ok(okv.body.token);
});
