'use strict';
/* CITADEL deep-scan backend.
 * Serves the static SPA and exposes a scan API that runs real open-source
 * scanners over an upload, then returns the same report shape the SPA renders.
 *
 *   GET  /api/health  -> { ok, version, scanners:[{tool,available}] }
 *   POST /api/scan    -> multipart "files" (a zip or many files) -> report JSON
 *
 * Uploaded code is treated as untrusted: it is only ever READ by scanners,
 * never executed, and is deleted as soon as the scan completes.
 */
const express = require('express');
const multer = require('multer');
const AdmZip = require('adm-zip');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

const { execFile } = require('child_process');
const scanners = require('./lib/scanners');
const engine = require('./lib/engine');
const ai = require('./lib/ai');
const users = require('./lib/users');
const jwt = require('./lib/jwt');
const rateLimit = require('./lib/ratelimit');
const audit = require('./lib/audit');
const sessions = require('./lib/sessions');
const db = require('./lib/db');
const log = require('./lib/log');
const metrics = require('./lib/metrics');
const oidc = require('./lib/oidc');

const PORT = parseInt(process.env.PORT || '8080', 10);
// Locate the SPA. Local dev: server.js lives in citadel/server, so the app is
// one level up. Container: server.js is /app and the SPA is /app/citadel.
const APP_DIR = process.env.CITADEL_APP_DIR || (
  fs.existsSync(path.resolve(__dirname, '..', 'index.html'))
    ? path.resolve(__dirname, '..')
    : path.resolve(__dirname, 'citadel')
);
const TMP_ROOT = process.env.CITADEL_TMP || path.join(os.tmpdir(), 'citadel');
const MAX_UPLOAD = parseInt(process.env.MAX_UPLOAD_BYTES || String(150 * 1024 * 1024), 10);

fs.mkdirSync(TMP_ROOT, { recursive: true });

const upload = multer({ dest: path.join(TMP_ROOT, 'uploads'), limits: { fileSize: MAX_UPLOAD, files: 5000 } });
const app = express();
app.disable('x-powered-by');
app.set('trust proxy', true);   // honour X-Forwarded-For behind Render/ALB so client IPs are real
app.use(metrics.httpMiddleware());

// Prometheus metrics scrape endpoint (gauges registered once).
metrics.gauge('citadel_active_sessions', () => sessions.stats().active);
metrics.gauge('citadel_uptime_seconds', () => Math.floor(process.uptime()));
metrics.gauge('citadel_resident_memory_bytes', () => process.memoryUsage().rss);
app.get('/metrics', (req, res) => { res.set('Content-Type', 'text/plain; version=0.0.4'); res.send(metrics.render()); });

// Best-effort client IP (first hop of X-Forwarded-For, else the socket address).
function clientIp(req) {
  const xff = (req.headers['x-forwarded-for'] || '').split(',')[0].trim();
  return xff || req.ip || (req.socket && req.socket.remoteAddress) || 'unknown';
}

// Token lifetimes. Short-lived access tokens + a long-lived refresh token so a
// leaked access token expires fast; the SPA silently refreshes.
const ACCESS_TTL = parseInt(process.env.CITADEL_ACCESS_TTL || '1800', 10);        // 30 min
const REFRESH_TTL = parseInt(process.env.CITADEL_REFRESH_TTL || String(30 * 86400), 10); // 30 days
const MFA_TTL = parseInt(process.env.CITADEL_MFA_TTL || '300', 10);              // 5 min step-up window

// Issue a session + access/refresh token pair for a verified user.
function issueTokens(u, req) {
  const jti = crypto.randomBytes(12).toString('hex');
  const now = Math.floor(Date.now() / 1000);
  sessions.register({ jti, userId: u.id, email: u.email, role: u.role, ip: clientIp(req), ua: req.headers['user-agent'], iat: now, exp: now + REFRESH_TTL });
  const token = jwt.sign({ sub: u.id, role: u.role, email: u.email, jti, typ: 'access' }, users.secret(), ACCESS_TTL);
  const refreshToken = jwt.sign({ sub: u.id, jti, typ: 'refresh' }, users.secret(), REFRESH_TTL);
  return { token, refreshToken, expiresIn: ACCESS_TTL, user: u };
}

// Reusable fixed-window limiter middleware keyed by IP + route bucket.
function rateLimited(bucket, max, windowMs) {
  return async (req, res, next) => {
    try {
      const r = await rateLimit.limit(bucket + ':' + clientIp(req), max, windowMs);
      res.setHeader('X-RateLimit-Remaining', String(r.remaining));
      if (!r.ok) {
        res.setHeader('Retry-After', String(r.retryAfter));
        audit.record('ratelimit.block', { ip: clientIp(req), detail: bucket + ' (' + max + '/' + Math.round(windowMs / 1000) + 's)', ok: false });
        return res.status(429).json({ error: 'Too many requests — slow down and retry shortly.', retryAfter: r.retryAfter });
      }
    } catch (e) { /* fail open */ }
    next();
  };
}

/* Security headers (mirrors the hardened nginx config for non-container runs) */
app.use((req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Cross-Origin-Opener-Policy', 'same-origin');
  res.setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
  next();
});

/* ---- Auth: parse a Bearer JWT (if present) into req.user — non-blocking ----
 * Honours session revocation (a revoked jti is rejected) and lazily re-registers
 * a valid token's session so it shows up in the active-sessions view after a
 * restart. Default-allow: an unknown-but-valid jti is accepted. */
app.use((req, res, next) => {
  const h = req.headers.authorization || '';
  const m = h.match(/^Bearer\s+(.+)$/i);
  if (m) {
    const p = jwt.verify(m[1], users.secret());
    // Only access tokens authenticate API calls (refresh/mfa tokens must not).
    // Legacy tokens without a typ are still honored during rollout.
    if (p && p.sub && (!p.typ || p.typ === 'access') && !(p.jti && sessions.isRevoked(p.jti))) {
      req.user = users.get(p.sub);
      if (req.user && p.jti) {
        sessions.register({ jti: p.jti, userId: p.sub, email: p.email, role: p.role, ip: clientIp(req), ua: req.headers['user-agent'], iat: p.iat, exp: p.exp });
        req.jti = p.jti;
      }
    }
  }
  next();
});
// Permission gate: open when enforce is off; else require auth + the page perm.
function requirePerm(page) {
  return (req, res, next) => {
    if (!users.settings().enforce) return next();
    if (!req.user) return res.status(401).json({ error: 'Sign in required.' });
    if (!users.can(req.user, page)) return res.status(403).json({ error: 'You do not have permission for this action.' });
    next();
  };
}
function requireAdmin(req, res, next) {
  if (!req.user) return res.status(401).json({ error: 'Sign in required.' });
  if (req.user.role !== 'admin') return res.status(403).json({ error: 'Administrator only.' });
  next();
}

/* ---- safe extraction within a workdir (prevents zip-slip / traversal) ---- */
function safeJoin(base, target) {
  const p = path.resolve(base, target);
  if (p !== base && !p.startsWith(base + path.sep)) throw new Error('path traversal blocked: ' + target);
  return p;
}

function buildWorkdir(files) {
  const work = path.join(TMP_ROOT, 'scan-' + crypto.randomBytes(8).toString('hex'));
  fs.mkdirSync(work, { recursive: true });
  for (const file of files) {
    const orig = file.originalname || path.basename(file.path);
    if (/\.(zip|jar|war|apk|nupkg)$/i.test(orig)) {
      try {
        const zip = new AdmZip(file.path);
        zip.getEntries().forEach(e => {
          if (e.isDirectory) return;
          const dest = safeJoin(work, e.entryName);
          fs.mkdirSync(path.dirname(dest), { recursive: true });
          fs.writeFileSync(dest, e.getData());
        });
      } catch (err) {
        // not a valid zip — keep the raw file for binary analysis
        const dest = safeJoin(work, orig);
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.copyFileSync(file.path, dest);
      }
    } else {
      const dest = safeJoin(work, orig);                 // originalname may carry a relative path
      fs.mkdirSync(path.dirname(dest), { recursive: true });
      fs.copyFileSync(file.path, dest);
    }
    fs.unlink(file.path, () => {});
  }
  return work;
}

function rmrf(p) { try { fs.rmSync(p, { recursive: true, force: true }); } catch (e) {} }

/* ---------------- API ---------------- */
let _toolCache = null;
async function toolStatus() {
  if (_toolCache) return _toolCache;
  const names = ['semgrep', 'bandit', 'gitleaks', 'trivy', 'grype', 'syft', 'clamscan', 'checkov', 'osv-scanner', 'hadolint', 'codeql'];
  const out = [];
  for (const n of names) out.push({ tool: n === 'clamscan' ? 'clamav' : n, available: await scanners.has(n) });
  _toolCache = out;
  return out;
}

app.get('/api/health', async (req, res) => {
  res.json({
    ok: true, version: '1.0', engine: 'deep', ai: ai.available(),
    auth: { enforce: users.settings().enforce, sso: oidc.enabled() },
    store: { users: users.backend(), durable: db.enabled(), auditSink: audit.sinkEnabled(), rateLimit: rateLimit.backend() },
    scanners: await toolStatus()
  });
});

app.use(express.json({ limit: '256kb' }));

/* ---------------- Auth & user management ---------------- */
// Login: IP rate-limit (blunt) + per-(email,IP) brute-force lockout (targeted).
app.post('/api/auth/login', rateLimited('login', 20, 15 * 60000), async (req, res) => {
  const ip = clientIp(req);
  const email = String((req.body && req.body.email) || '').trim().toLowerCase();
  const password = (req.body && req.body.password) || '';
  const lockKey = 'login:' + email + '|' + ip;

  const lock = await rateLimit.lockState(lockKey);
  if (lock.locked) {
    audit.record('login.locked', { actor: email || null, ip, detail: 'attempt while locked', ok: false });
    res.setHeader('Retry-After', String(lock.retryAfter));
    return res.status(429).json({ error: 'Too many failed attempts. Try again later.', retryAfter: lock.retryAfter });
  }

  const u = users.verifyPassword(email, password);
  if (!u) {
    const f = await rateLimit.fail(lockKey);
    metrics.inc('citadel_logins_total', { result: 'failure' });
    audit.record(f.locked ? 'login.locked' : 'login.failure', { actor: email || null, ip, detail: 'failed attempt #' + f.fails, ok: false });
    const body = { error: 'Invalid credentials or inactive account.' };
    if (f.locked) { res.setHeader('Retry-After', String(f.retryAfter)); return res.status(429).json(Object.assign(body, { error: 'Too many failed attempts. Try again later.', retryAfter: f.retryAfter })); }
    return res.status(401).json(body);
  }
  await rateLimit.clearFails(lockKey);
  // Password OK. If MFA is enabled, return a short-lived step-up token instead of
  // a session — the client must POST a TOTP/backup code to /api/auth/mfa/verify.
  if (users.mfaEnabled(u.id)) {
    const mfaToken = jwt.sign({ sub: u.id, typ: 'mfa' }, users.secret(), MFA_TTL);
    audit.record('login.mfa_required', { actor: u.email, ip, detail: 'role=' + u.role, ok: true });
    return res.json({ mfaRequired: true, mfaToken });
  }
  metrics.inc('citadel_logins_total', { result: 'success' });
  audit.record('login.success', { actor: u.email, ip, detail: 'role=' + u.role, ok: true });
  res.json(issueTokens(u, req));
});

// Step-up: complete an MFA login with a TOTP code or a one-time backup code.
app.post('/api/auth/mfa/verify', rateLimited('mfa', 20, 15 * 60000), (req, res) => {
  const ip = clientIp(req);
  const p = jwt.verify((req.body && req.body.mfaToken) || '', users.secret());
  if (!p || p.typ !== 'mfa' || !p.sub) return res.status(401).json({ error: 'MFA session expired — sign in again.' });
  const u = users.get(p.sub);
  if (!u || !u.active) return res.status(401).json({ error: 'Account unavailable.' });
  if (!users.mfaVerify(p.sub, (req.body && req.body.code) || '')) {
    metrics.inc('citadel_logins_total', { result: 'mfa_failure' });
    audit.record('login.mfa_failure', { actor: u.email, ip, detail: 'bad code', ok: false });
    return res.status(401).json({ error: 'Invalid authenticator code.' });
  }
  metrics.inc('citadel_logins_total', { result: 'success' });
  audit.record('login.success', { actor: u.email, ip, detail: 'mfa', ok: true });
  res.json(issueTokens(u, req));
});

// Exchange a valid refresh token for a fresh access token (same session jti).
app.post('/api/auth/refresh', rateLimited('refresh', 60, 15 * 60000), (req, res) => {
  const p = jwt.verify((req.body && req.body.refreshToken) || '', users.secret());
  if (!p || p.typ !== 'refresh' || !p.sub || !p.jti) return res.status(401).json({ error: 'Invalid refresh token.' });
  if (sessions.isRevoked(p.jti)) return res.status(401).json({ error: 'Session revoked.' });
  const u = users.get(p.sub);
  if (!u || !u.active) return res.status(401).json({ error: 'Account unavailable.' });
  sessions.register({ jti: p.jti, userId: u.id, email: u.email, role: u.role, ip: clientIp(req), ua: req.headers['user-agent'] });
  const token = jwt.sign({ sub: u.id, role: u.role, email: u.email, jti: p.jti, typ: 'access' }, users.secret(), ACCESS_TTL);
  res.json({ token, expiresIn: ACCESS_TTL });
});
app.get('/api/auth/me', (req, res) => { if (!req.user) return res.status(401).json({ error: 'Not authenticated.' }); res.json(req.user); });
// Self-service password change (also clears the must-change flag on the default admin).
app.post('/api/auth/password', (req, res) => {
  if (!req.user) return res.status(401).json({ error: 'Not authenticated.' });
  const { current, next } = req.body || {};
  try {
    users.changeOwnPassword(req.user.id, current || '', next || '');
    audit.record('user.password', { actor: req.user.email, ip: clientIp(req), detail: 'self change', ok: true });
    res.json({ ok: true });
  } catch (e) { res.status(400).json({ error: e.message }); }
});

/* ---- MFA (TOTP) self-service ---- */
app.get('/api/auth/mfa', (req, res) => { if (!req.user) return res.status(401).json({ error: 'Not authenticated.' }); res.json(users.mfaStatus(req.user.id)); });
app.post('/api/auth/mfa/setup', (req, res) => {
  if (!req.user) return res.status(401).json({ error: 'Not authenticated.' });
  try { res.json(users.mfaBeginSetup(req.user.id)); } catch (e) { res.status(400).json({ error: e.message }); }
});
app.post('/api/auth/mfa/enable', (req, res) => {
  if (!req.user) return res.status(401).json({ error: 'Not authenticated.' });
  try {
    const out = users.mfaEnable(req.user.id, (req.body && req.body.token) || '');
    audit.record('mfa.enabled', { actor: req.user.email, ip: clientIp(req), detail: 'totp', ok: true });
    res.json(out);   // { backupCodes: [...] } — shown once
  } catch (e) { res.status(400).json({ error: e.message }); }
});
// Disabling MFA requires re-confirming the password (a hijacked session alone can't drop 2FA).
app.post('/api/auth/mfa/disable', (req, res) => {
  if (!req.user) return res.status(401).json({ error: 'Not authenticated.' });
  if (!users.verifyPassword(req.user.email, (req.body && req.body.password) || '')) {
    return res.status(401).json({ error: 'Password confirmation failed.' });
  }
  users.mfaDisable(req.user.id);
  audit.record('mfa.disabled', { actor: req.user.email, ip: clientIp(req), detail: 'self', ok: true });
  res.json({ ok: true });
});

/* ---- OIDC SSO (Authorization Code + PKCE) ---- */
const OIDC_POST_LOGIN = process.env.OIDC_POST_LOGIN || '/';
// Begin SSO: redirect the browser to the identity provider.
app.get('/api/auth/oidc/start', rateLimited('oidc', 30, 10 * 60000), async (req, res) => {
  if (!oidc.enabled()) return res.status(404).json({ error: 'SSO is not configured.' });
  try { res.redirect(await oidc.authUrl()); }
  catch (e) { log.error('oidc start', { err: e.message }); res.status(502).json({ error: 'SSO provider unavailable.' }); }
});
// IdP redirects back here with ?code&state. Exchange, verify, JIT-provision, issue session.
app.get('/api/auth/oidc/callback', async (req, res) => {
  if (!oidc.enabled()) return res.status(404).send('SSO is not configured.');
  const ip = clientIp(req);
  try {
    const { code, state, error } = req.query;
    if (error) throw new Error('Provider returned: ' + String(error).slice(0, 80));
    const s = oidc.takeState(String(state || ''));
    if (!s || !code) throw new Error('Invalid or expired SSO state.');
    const tokenSet = await oidc.exchangeCode(String(code), s.verifier);
    const claims = await oidc.verifyIdToken(tokenSet.id_token, s.nonce);
    const identity = oidc.mapIdentity(claims);
    if (!identity) { audit.record('login.sso_denied', { actor: (claims && claims.email) || null, ip, detail: 'domain not allowed', ok: false }); return res.status(403).send('Your account is not permitted to sign in.'); }
    const u = users.upsertSsoUser(identity);
    const out = issueTokens(u, req);
    metrics.inc('citadel_logins_total', { result: 'sso' });
    audit.record('login.success', { actor: u.email, ip, detail: 'sso role=' + u.role, ok: true });
    // Hand the tokens to the SPA via a same-origin page (not a URL fragment).
    const nonce = crypto.randomBytes(16).toString('base64');
    res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'nonce-" + nonce + "'");
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    const payload = JSON.stringify({ t: out.token, r: out.refreshToken, dest: OIDC_POST_LOGIN })
      .replace(/</g, '\\u003c');
    res.send('<!doctype html><meta charset="utf-8"><title>Signing in…</title>' +
      '<body style="font:14px system-ui;padding:2rem">Signing you in…' +
      '<script nonce="' + nonce + '">(function(){var d=' + payload + ';try{localStorage.setItem("citadel.jwt",d.t);localStorage.setItem("citadel.refresh",d.r);}catch(e){}location.replace(d.dest);})();</script></body>');
  } catch (e) {
    log.error('oidc callback', { err: e.message });
    audit.record('login.sso_error', { ip, detail: e.message.slice(0, 120), ok: false });
    res.status(400).send('SSO sign-in failed: ' + e.message);
  }
});
app.get('/api/auth/settings', (req, res) => res.json(users.settings()));
app.patch('/api/auth/settings', requireAdmin, (req, res) => {
  const out = users.setSetting('enforce', !!(req.body && req.body.enforce));
  audit.record('settings.change', { actor: req.user.email, ip: clientIp(req), detail: 'enforce=' + out.enforce, ok: true });
  res.json(out);
});

/* ---------------- Branding (shared logo / name / accent) ---------------- */
// Public read so the served SPA can theme itself; admin-only write (full replace).
app.get('/api/branding', (req, res) => res.json(users.settings().branding || {}));
app.patch('/api/branding', requireAdmin, (req, res) => {
  const b = req.body || {};
  const url = typeof b.logoUrl === 'string' ? b.logoUrl.trim() : '';
  const accent = typeof b.accent === 'string' ? b.accent.trim() : '';
  // Allow public https(/http) URLs or inline data: image URIs (file uploads).
  // SVG is intentionally excluded: an SVG can carry script and, while an <img>
  // never executes it, accepting it would be a latent stored-XSS vector if a
  // logo is ever inlined. Raster formats only.
  const isHttp = /^https?:\/\/\S+$/i.test(url);
  const isDataImg = /^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$/i.test(url);
  const clean = {
    logoUrl: isHttp ? url.slice(0, 500) : (isDataImg ? url.slice(0, 200000) : ''),
    orgName: (typeof b.orgName === 'string' ? b.orgName.trim() : '').slice(0, 80),
    accent: /^#?[0-9a-fA-F]{3,8}$/.test(accent) ? (accent[0] === '#' ? accent : '#' + accent) : ''
  };
  users.setSetting('branding', clean);
  audit.record('settings.change', { actor: req.user.email, ip: clientIp(req), detail: 'branding', ok: true });
  res.json(clean);
});

// Read-only security audit trail (most recent first; optional ?type= prefix filter).
app.get('/api/audit', requireAdmin, async (req, res) => {
  const n = Math.min(parseInt(req.query.limit, 10) || 200, 1000);
  const [stats, events] = await Promise.all([audit.stats(), audit.list(n, req.query.type || null)]);
  res.json({ stats, events });
});

/* ---------------- Sessions ---------------- */
// Log out the current session (revokes this token server-side).
app.post('/api/auth/logout', (req, res) => {
  if (req.jti) { sessions.revoke(req.jti); audit.record('session.logout', { actor: req.user && req.user.email, ip: clientIp(req), detail: 'self', ok: true }); }
  res.json({ ok: true });
});
// The caller's own active sessions (marks which one is the current request).
app.get('/api/auth/sessions', (req, res) => {
  if (!req.user) return res.status(401).json({ error: 'Not authenticated.' });
  res.json(sessions.listForUser(req.user.id).map(s => Object.assign({ current: s.jti === req.jti }, s)));
});
// Admin: all active sessions, or revoke one / all-for-a-user.
app.get('/api/sessions', requireAdmin, (req, res) => res.json({ stats: sessions.stats(), sessions: sessions.listAll().map(s => Object.assign({ current: s.jti === req.jti }, s)) }));
app.delete('/api/sessions/:jti', requireAdmin, (req, res) => {
  const s = sessions.revoke(req.params.jti);
  audit.record('session.revoke', { actor: req.user.email, ip: clientIp(req), detail: 'jti=' + req.params.jti.slice(0, 8) + (s && s.email ? ' (' + s.email + ')' : ''), ok: true });
  res.json({ ok: true });
});
app.post('/api/users/:id/revoke-sessions', requireAdmin, (req, res) => {
  const n = sessions.revokeAllForUser(req.params.id);
  audit.record('session.revoke', { actor: req.user.email, ip: clientIp(req), detail: 'all for id=' + req.params.id + ' (' + n + ')', ok: true });
  res.json({ ok: true, revoked: n });
});

app.get('/api/users', requireAdmin, (req, res) => res.json(users.list()));
app.post('/api/users', requireAdmin, (req, res) => { try { const u = users.add(req.body || {}); audit.record('user.add', { actor: req.user.email, ip: clientIp(req), detail: u.email + ' role=' + u.role, ok: true }); res.json(u); } catch (e) { res.status(400).json({ error: e.message }); } });
app.patch('/api/users/:id', requireAdmin, (req, res) => { try { const u = users.update(req.params.id, req.body || {}); audit.record('user.update', { actor: req.user.email, ip: clientIp(req), detail: u.email + ' ' + Object.keys(req.body || {}).join(','), ok: true }); res.json(u); } catch (e) { res.status(400).json({ error: e.message }); } });
app.delete('/api/users/:id', requireAdmin, (req, res) => { try { users.remove(req.params.id); audit.record('user.remove', { actor: req.user.email, ip: clientIp(req), detail: 'id=' + req.params.id, ok: true }); res.json({ ok: true }); } catch (e) { res.status(400).json({ error: e.message }); } });
app.post('/api/users/:id/password', requireAdmin, (req, res) => { try { users.setPassword(req.params.id, (req.body && req.body.password) || ''); audit.record('user.password', { actor: req.user.email, ip: clientIp(req), detail: 'id=' + req.params.id, ok: true }); res.json({ ok: true }); } catch (e) { res.status(400).json({ error: e.message }); } });

// Scan a public Git repository by URL (shallow clone, read-only).
// Heavy (clone + full scanner fan-out): throttle to protect the free-tier box.
app.post('/api/scan-url', rateLimited('scan-url', 10, 10 * 60000), requirePerm('deepscan'), async (req, res) => {
  const url = String((req.body && req.body.url) || '').trim();
  // Allowlist: https git URLs only (github/gitlab/bitbucket/codeberg or generic https .git)
  if (!/^https:\/\/[\w.-]+\/[\w./~-]+?(\.git)?$/.test(url) || url.length > 300) {
    return res.status(400).json({ error: 'Provide a valid public https Git URL.' });
  }
  const work = path.join(TMP_ROOT, 'clone-' + crypto.randomBytes(8).toString('hex'));
  try {
    await new Promise((resolve, reject) => {
      execFile('git', ['clone', '--depth', '1', '--single-branch', url, work],
        { timeout: 120000, env: { ...process.env, GIT_TERMINAL_PROMPT: '0' } },
        (err) => err ? reject(new Error('Clone failed (is the repository public?).')) : resolve());
    });
    const scannerResult = await scanners.runAll(work);
    const report = await engine.analyzeDir(work, scannerResult);
    report.meta.source = url;
    res.json(report);
  } catch (err) {
    res.status(500).json({ error: err.message });
  } finally {
    rmrf(work);
  }
});

// AI-assisted remediation for a single finding (opt-in; needs ANTHROPIC_API_KEY).
app.post('/api/explain', rateLimited('explain', 30, 10 * 60000), requirePerm('tab-aifix'), async (req, res) => {
  if (!ai.available()) return res.status(503).json({ error: 'AI remediation is not enabled on this server.' });
  try {
    const out = await ai.explain((req.body && req.body.finding) || {});
    res.json(out);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/scan', rateLimited('scan', 20, 10 * 60000), requirePerm('analyze'), upload.array('files'), async (req, res) => {
  if (!req.files || !req.files.length) return res.status(400).json({ error: 'No files uploaded (field "files").' });
  let work;
  try {
    work = buildWorkdir(req.files);
    const scannerResult = await scanners.runAll(work);
    const report = await engine.analyzeDir(work, scannerResult);
    metrics.inc('citadel_scans_total', { mode: 'upload' });
    res.json(report);
  } catch (err) {
    metrics.inc('citadel_scan_errors_total');
    log.error('scan failed', { err: err.message });
    res.status(500).json({ error: 'Scan failed: ' + err.message });
  } finally {
    if (work) rmrf(work);
  }
});

/* ---------------- Static SPA ---------------- */
app.use(express.static(APP_DIR, {
  setHeaders(res, fp) { if (fp.endsWith('.html')) res.setHeader('Cache-Control', 'no-cache'); }
}));

// Bootstrap durable state (Postgres schema + load) BEFORE accepting traffic, so
// the synchronous auth path always reads a populated cache. Degrades to the
// in-memory/file store when DATABASE_URL is unset or the DB is briefly down.
(async function start() {
  try {
    if (db.enabled()) { await db.init(); log.info('postgres connected', { store: 'postgres' }); }
    await users.init();
    await sessions.init();
  } catch (e) {
    log.error('bootstrap failed; continuing with in-memory store', { err: e.message });
  }
  app.listen(PORT, () => {
    log.info('deep-scan backend listening', {
      port: PORT, appDir: APP_DIR, userStore: users.backend(),
      auditSink: audit.sinkEnabled(), ai: ai.available()
    });
    toolStatus().then(t => log.info('scanners', { tools: t.reduce((o, x) => (o[x.tool] = x.available, o), {}) }));
  });
})();
