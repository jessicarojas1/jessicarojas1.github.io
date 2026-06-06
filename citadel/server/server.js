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

/* Security headers (mirrors the hardened nginx config for non-container runs) */
app.use((req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Cross-Origin-Opener-Policy', 'same-origin');
  next();
});

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
  res.json({ ok: true, version: '1.0', engine: 'deep', ai: ai.available(), scanners: await toolStatus() });
});

app.use(express.json({ limit: '256kb' }));

// Scan a public Git repository by URL (shallow clone, read-only).
app.post('/api/scan-url', async (req, res) => {
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
app.post('/api/explain', async (req, res) => {
  if (!ai.available()) return res.status(503).json({ error: 'AI remediation is not enabled on this server.' });
  try {
    const out = await ai.explain((req.body && req.body.finding) || {});
    res.json(out);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/scan', upload.array('files'), async (req, res) => {
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
