'use strict';
/* CITADEL — provider-agnostic OIDC SSO (Authorization Code + PKCE).
 *
 * Works with any compliant OpenID Connect provider (Microsoft Entra ID, Okta,
 * Google, Auth0, Keycloak, ...) via the discovery document. Enable by setting:
 *   OIDC_ISSUER        e.g. https://login.microsoftonline.com/<tenant>/v2.0
 *   OIDC_CLIENT_ID
 *   OIDC_CLIENT_SECRET
 *   OIDC_REDIRECT_URI  e.g. https://your-app/api/auth/oidc/callback
 * Optional:
 *   OIDC_SCOPES            (default "openid email profile")
 *   OIDC_ADMIN_EMAILS      comma list -> mapped to the admin role on first login
 *   OIDC_DEFAULT_ROLE      role for everyone else (default "viewer")
 *   OIDC_ALLOWED_DOMAINS   comma list of email domains permitted to sign in
 *
 * id_token signatures are verified against the provider JWKS (RS256/PS256).
 */
const crypto = require('crypto');

const CFG = {
  issuer: (process.env.OIDC_ISSUER || '').replace(/\/+$/, ''),
  clientId: process.env.OIDC_CLIENT_ID || '',
  clientSecret: process.env.OIDC_CLIENT_SECRET || '',
  redirectUri: process.env.OIDC_REDIRECT_URI || '',
  scopes: process.env.OIDC_SCOPES || 'openid email profile',
  adminEmails: (process.env.OIDC_ADMIN_EMAILS || '').toLowerCase().split(',').map(s => s.trim()).filter(Boolean),
  defaultRole: process.env.OIDC_DEFAULT_ROLE || 'viewer',
  allowedDomains: (process.env.OIDC_ALLOWED_DOMAINS || '').toLowerCase().split(',').map(s => s.trim()).filter(Boolean)
};

function enabled() { return !!(CFG.issuer && CFG.clientId && CFG.clientSecret && CFG.redirectUri); }

let _meta = null, _metaAt = 0, _jwks = null, _jwksAt = 0;
async function discovery() {
  if (_meta && (Date.now() - _metaAt) < 3600000) return _meta;
  const res = await fetch(CFG.issuer + '/.well-known/openid-configuration');
  if (!res.ok) throw new Error('OIDC discovery failed (' + res.status + ')');
  _meta = await res.json(); _metaAt = Date.now();
  return _meta;
}
async function jwks() {
  const meta = await discovery();
  if (_jwks && (Date.now() - _jwksAt) < 3600000) return _jwks;
  const res = await fetch(meta.jwks_uri);
  if (!res.ok) throw new Error('OIDC JWKS fetch failed (' + res.status + ')');
  _jwks = (await res.json()).keys || []; _jwksAt = Date.now();
  return _jwks;
}

/* ---- PKCE + state ---- */
function b64url(buf) { return Buffer.from(buf).toString('base64').replace(/=+$/g, '').replace(/\+/g, '-').replace(/\//g, '_'); }
function fromB64url(s) { return Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64'); }

const _states = new Map();   // state -> { nonce, verifier, createdAt }
function beginAuth() {
  const state = b64url(crypto.randomBytes(16));
  const nonce = b64url(crypto.randomBytes(16));
  const verifier = b64url(crypto.randomBytes(32));
  const challenge = b64url(crypto.createHash('sha256').update(verifier).digest());
  _states.set(state, { nonce, verifier, createdAt: Date.now() });
  // prune old states (10 min TTL)
  const cutoff = Date.now() - 600000;
  for (const [k, v] of _states) if (v.createdAt < cutoff) _states.delete(k);
  return { state, nonce, challenge };
}
function takeState(state) { const s = _states.get(state); if (s) _states.delete(state); return s || null; }

async function authUrl() {
  const meta = await discovery();
  const { state, nonce, challenge } = beginAuth();
  const p = new URLSearchParams({
    response_type: 'code', client_id: CFG.clientId, redirect_uri: CFG.redirectUri,
    scope: CFG.scopes, state, nonce, code_challenge: challenge, code_challenge_method: 'S256'
  });
  return meta.authorization_endpoint + '?' + p.toString();
}

async function exchangeCode(code, verifier) {
  const meta = await discovery();
  const body = new URLSearchParams({
    grant_type: 'authorization_code', code, redirect_uri: CFG.redirectUri,
    client_id: CFG.clientId, client_secret: CFG.clientSecret, code_verifier: verifier
  });
  const res = await fetch(meta.token_endpoint, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body
  });
  if (!res.ok) throw new Error('OIDC token exchange failed (' + res.status + ')');
  return res.json();
}

// Verify an id_token's RS256/PS256 signature against JWKS + validate core claims.
async function verifyIdToken(idToken, expectedNonce) {
  const parts = String(idToken).split('.');
  if (parts.length !== 3) throw new Error('Malformed id_token');
  const header = JSON.parse(fromB64url(parts[0]).toString('utf8'));
  const payload = JSON.parse(fromB64url(parts[1]).toString('utf8'));
  if (!/^(RS|PS)256$/.test(header.alg || '')) throw new Error('Unsupported id_token alg: ' + header.alg);
  const keys = await jwks();
  const jwk = keys.find(k => k.kid === header.kid) || keys.find(k => k.kty === 'RSA');
  if (!jwk) throw new Error('No matching JWKS key');
  const pub = crypto.createPublicKey({ key: jwk, format: 'jwk' });
  const algo = header.alg === 'PS256'
    ? { key: pub, padding: crypto.constants.RSA_PKCS1_PSS_PADDING, saltLength: crypto.constants.RSA_PSS_SALTLEN_DIGEST }
    : pub;
  const ok = crypto.verify('RSA-SHA256', Buffer.from(parts[0] + '.' + parts[1]), algo, fromB64url(parts[2]));
  if (!ok) throw new Error('id_token signature invalid');
  const now = Math.floor(Date.now() / 1000);
  const meta = await discovery();
  if (payload.iss !== meta.issuer && payload.iss !== CFG.issuer) throw new Error('id_token issuer mismatch');
  const aud = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
  if (!aud.includes(CFG.clientId)) throw new Error('id_token audience mismatch');
  if (payload.exp && now > payload.exp + 60) throw new Error('id_token expired');
  if (expectedNonce && payload.nonce !== expectedNonce) throw new Error('id_token nonce mismatch');
  return payload;
}

// Map verified claims to a CITADEL identity + role. Returns null if not allowed.
function mapIdentity(claims) {
  const email = String(claims.email || claims.preferred_username || '').toLowerCase();
  if (!email || !email.includes('@')) return null;
  if (CFG.allowedDomains.length && !CFG.allowedDomains.includes(email.split('@')[1])) return null;
  const role = CFG.adminEmails.includes(email) ? 'admin' : CFG.defaultRole;
  return { email, name: claims.name || claims.given_name || email, role };
}

module.exports = { enabled, authUrl, takeState, exchangeCode, verifyIdToken, mapIdentity, CFG };
