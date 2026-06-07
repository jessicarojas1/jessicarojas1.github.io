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

// Best-effort client IP (first hop of X-Forwarded-For, else the socket address).
function clientIp(req) {
  const xff = (req.headers['x-forwarded-for'] || '').split(',')[0].trim();
  return xff || req.ip || (req.socket && req.socket.remoteAddress) || 'unknown';
}

// Reusable fixed-window limiter middleware keyed by IP + route bucket.
function rateLimited(bucket, max, windowMs) {
  return (req, res, next) => {
    const r = rateLimit.limit(bucket + ':' + clientIp(req), max, windowMs);
    res.setHeader('X-RateLimit-Remaining', String(r.remaining));
    if (!r.ok) {
      res.setHeader('Retry-After', String(r.retryAfter));
      audit.record('ratelimit.block', { ip: clientIp(req), detail: bucket + ' (' + max + '/' + Math.round(windowMs / 1000) + 's)', ok: false });
      return res.status(429).json({ error: 'Too many requests — slow down and retry shortly.', retryAfter: r.retryAfter });
    }
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
    if (p && p.sub && !(p.jti && sessions.isRevoked(p.jti))) {
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
  res.json({ ok: true, version: '1.0', engine: 'deep', ai: ai.available(), auth: { enforce: users.settings().enforce }, scanners: await toolStatus() });
});

app.use(express.json({ limit: '256kb' }));

/* ---------------- Auth & user management ---------------- */
// Login: IP rate-limit (blunt) + per-(email,IP) brute-force lockout (targeted).
app.post('/api/auth/login', rateLimited('login', 20, 15 * 60000), (req, res) => {
  const ip = clientIp(req);
  const email = String((req.body && req.body.email) || '').trim().toLowerCase();
  const password = (req.body && req.body.password) || '';
  const lockKey = 'login:' + email + '|' + ip;

  const lock = rateLimit.lockState(lockKey);
  if (lock.locked) {
    audit.record('login.locked', { actor: email || null, ip, detail: 'attempt while locked', ok: false });
    res.setHeader('Retry-After', String(lock.retryAfter));
    return res.status(429).json({ error: 'Too many failed attempts. Try again later.', retryAfter: lock.retryAfter });
  }

  const u = users.verifyPassword(email, password);
  if (!u) {
    const f = rateLimit.fail(lockKey);
    audit.record(f.locked ? 'login.locked' : 'login.failure', { actor: email || null, ip, detail: 'failed attempt #' + f.fails, ok: false });
    const body = { error: 'Invalid credentials or inactive account.' };
    if (f.locked) { res.setHeader('Retry-After', String(f.retryAfter)); return res.status(429).json(Object.assign(body, { error: 'Too many failed attempts. Try again later.', retryAfter: f.retryAfter })); }
    return res.status(401).json(body);
  }
  rateLimit.clearFails(lockKey);
  const jti = crypto.randomBytes(12).toString('hex');
  const token = jwt.sign({ sub: u.id, role: u.role, email: u.email, jti }, users.secret());
  const now = Math.floor(Date.now() / 1000);
  sessions.register({ jti, userId: u.id, email: u.email, role: u.role, ip, ua: req.headers['user-agent'], iat: now, exp: now + 43200 });
  audit.record('login.success', { actor: u.email, ip, detail: 'role=' + u.role, ok: true });
  res.json({ token, user: u });
});
app.get('/api/auth/me', (req, res) => { if (!req.user) return res.status(401).json({ error: 'Not authenticated.' }); res.json(req.user); });
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
app.get('/api/audit', requireAdmin, (req, res) => {
  const n = Math.min(parseInt(req.query.limit, 10) || 200, 500);
  res.json({ stats: audit.stats(), events: audit.list(n, req.query.type || null) });
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
    res.json(report);
  } catch (err) {
    console.error('[citadel] scan error:', err.message);
    res.status(500).json({ error: 'Scan failed: ' + err.message });
  } finally {
    if (work) rmrf(work);
  }
});

/* ---------------- Static SPA ---------------- */
app.use(express.static(APP_DIR, {
  setHeaders(res, fp) { if (fp.endsWith('.html')) res.setHeader('Cache-Control', 'no-cache'); }
}));

app.listen(PORT, () => {
  console.log(`[citadel] deep-scan backend on :${PORT} — serving SPA from ${APP_DIR}`);
  toolStatus().then(t => console.log('[citadel] scanners:', t.map(x => `${x.tool}=${x.available ? 'on' : 'off'}`).join(' ')));
});
