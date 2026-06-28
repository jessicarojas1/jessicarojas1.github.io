'use strict';
/* CITADEL release-readiness gate CLI — end-to-end tests (spawn the real CLI,
 * which loads the full in-browser engine in Node, over throwaway fixtures). */
const { test } = require('node:test');
const assert = require('node:assert');
const { execFileSync } = require('node:child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CLI = path.resolve(__dirname, '..', '..', 'cli', 'citadel-gate.js');

function mkFixture(files) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'citadel-cli-'));
  Object.keys(files).forEach(rel => {
    const p = path.join(dir, rel);
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, files[rel]);
  });
  return dir;
}
function run(dir, args) {
  try { return { code: 0, out: execFileSync('node', [CLI, dir].concat(args), { encoding: 'utf8' }) }; }
  catch (e) { return { code: e.status, out: (e.stdout || '') + (e.stderr || '') }; }
}

test('cli: exposed secret => Rejected, exit 1', () => {
  const dir = mkFixture({
    'src/config.js': 'const k = "AKIAIOSFODNN7EXAMPLE";\n'
      + 'const s = "-----BEGIN RSA PRIVATE KEY-----\\nMIIEpAIBAAKCAQEA0123456789abcdef\\n-----END RSA PRIVATE KEY-----";\n'
      + 'module.exports = { k, s };\n',
    'package.json': '{"name":"x","version":"1.0.0"}'
  });
  const r = run(dir, ['--fail-on=rejected', '--quiet']);
  fs.rmSync(dir, { recursive: true, force: true });
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /Rejected/);
});

test('cli: clean project passes under --fail-on=rejected, exit 0', () => {
  const dir = mkFixture({
    'src/app.js': 'const logger = require("./logger");\nfunction f() { logger.info("x"); return 1; }\nmodule.exports = { f };\n',
    'package.json': '{"name":"x","version":"1.0.0","devDependencies":{"jest":"^29"},"scripts":{"test":"jest"}}'
  });
  const r = run(dir, ['--fail-on=rejected', '--quiet']);
  fs.rmSync(dir, { recursive: true, force: true });
  assert.equal(r.code, 0, r.out);
});

test('cli: invalid --fail-on => exit 2', () => {
  const dir = mkFixture({ 'a.js': 'var x = 1;\n' });
  const r = run(dir, ['--fail-on=nope', '--quiet']);
  fs.rmSync(dir, { recursive: true, force: true });
  assert.equal(r.code, 2, r.out);
});

test('cli: --json emits parseable readiness with a decision', () => {
  const dir = mkFixture({ 'a.js': 'const x = require("express");\n', 'package.json': '{"name":"x","version":"1.0.0"}' });
  const r = run(dir, ['--json']);
  fs.rmSync(dir, { recursive: true, force: true });
  const j = JSON.parse(r.out);
  assert.ok(j.decision && typeof j.overall === 'number' && Array.isArray(j.dimensions));
});
