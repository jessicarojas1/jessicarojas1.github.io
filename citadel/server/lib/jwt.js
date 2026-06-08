'use strict';
/* CITADEL — minimal HS256 JWT (no dependency).
 * Signs and verifies compact JWTs using Node's crypto HMAC. Good enough for the
 * deep-scan backend's session tokens; rotate CITADEL_JWT_SECRET to invalidate all.
 */
const crypto = require('crypto');

function b64url(buf) {
  return Buffer.from(buf).toString('base64').replace(/=+$/g, '').replace(/\+/g, '-').replace(/\//g, '_');
}
function b64urlJson(o) { return b64url(JSON.stringify(o)); }
function fromB64url(s) { return Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64'); }

function sign(payload, secret, expSeconds = 43200 /* 12h */) {
  const header = { alg: 'HS256', typ: 'JWT' };
  const now = Math.floor(Date.now() / 1000);
  const body = Object.assign({ iat: now, exp: now + expSeconds }, payload);
  const data = b64urlJson(header) + '.' + b64urlJson(body);
  const sig = b64url(crypto.createHmac('sha256', secret).update(data).digest());
  return data + '.' + sig;
}

function verify(token, secret) {
  try {
    const parts = String(token).split('.');
    if (parts.length !== 3) return null;
    // Pin the algorithm: only HS256 is ever issued. Rejecting any other alg here
    // forecloses alg-confusion if an asymmetric path is ever added.
    const header = JSON.parse(fromB64url(parts[0]).toString('utf8'));
    if (header.alg !== 'HS256') return null;
    const data = parts[0] + '.' + parts[1];
    const expected = crypto.createHmac('sha256', secret).update(data).digest();
    const got = fromB64url(parts[2]);
    if (got.length !== expected.length || !crypto.timingSafeEqual(got, expected)) return null;
    const body = JSON.parse(fromB64url(parts[1]).toString('utf8'));
    if (body.exp && Math.floor(Date.now() / 1000) > body.exp) return null;
    return body;
  } catch (e) { return null; }
}

module.exports = { sign, verify };
