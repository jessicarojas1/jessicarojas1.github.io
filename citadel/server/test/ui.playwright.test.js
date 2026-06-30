'use strict';
/* CITADEL — real-browser UI smoke tests.
 *
 * Everything else in test/ exercises the UI through Node DOM stubs (jsdom-free
 * hand-rolled shims) or hits the HTTP API directly. Neither proves the actual
 * browser behavior of citadel/js/ui.js (toast/confirm/prompt) or the keyboard-
 * shortcuts overlay + Tab focus-trap in citadel/js/app.js. This file launches a
 * real Chromium via playwright-core against the real server (file-store mode,
 * same boot pattern as test/api.test.js) and drives the real DOM with real
 * keyboard/mouse input.
 *
 * Requires the pre-installed Chromium at CHROME_PATH below. Not wired into the
 * regular `npm test` / CI gate (see docs/TESTING.md) — run manually with
 * `npm run test:ui`. Skips (does not fail) if the browser binary is missing,
 * so this can never become a flaky/breaking gate on a runner without it.
 */
const { test, before, after } = require('node:test');
const assert = require('node:assert');
const { spawn } = require('node:child_process');
const os = require('node:os');
const path = require('node:path');
const fs = require('node:fs');

const CHROME_PATH = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const HAVE_CHROME = fs.existsSync(CHROME_PATH);

const PORT = 8700 + Math.floor(Math.random() * 300);
const BASE = 'http://127.0.0.1:' + PORT;
const DATA = fs.mkdtempSync(path.join(os.tmpdir(), 'citadel-ui-'));
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'citadel-ui-tmp-'));

let child;
let chromium;
let browser;
let context;
let page;

before(async () => {
  if (!HAVE_CHROME) return; // nothing to boot — every test below will skip itself

  child = spawn(process.execPath, ['server.js'], {
    cwd: path.join(__dirname, '..'),
    env: Object.assign({}, process.env, {
      PORT: String(PORT), CITADEL_DATA_DIR: DATA, CITADEL_TMP: TMP,
      DATABASE_URL: '', REDIS_URL: '', CITADEL_ADMIN_PASSWORD: '', NODE_ENV: 'test',
      CITADEL_ALLOW_OPEN: '1'
    }),
    stdio: 'ignore'
  });
  for (let i = 0; i < 80; i++) {
    try { const r = await fetch(BASE + '/api/health'); if (r.ok) break; } catch (e) { /* not up yet */ }
    await new Promise(r => setTimeout(r, 100));
  }

  chromium = require('playwright-core').chromium;
  browser = await chromium.launch({ executablePath: CHROME_PATH, args: ['--no-sandbox'] });
  context = await browser.newContext();
  page = await context.newPage();
});

after(async () => {
  if (page) await page.close().catch(() => {});
  if (context) await context.close().catch(() => {});
  if (browser) await browser.close().catch(() => {});
  if (child) child.kill('SIGKILL');
});

test('app loads at / with zero console errors and zero page errors', { skip: !HAVE_CHROME && 'Chromium not installed at ' + CHROME_PATH }, async () => {
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const loc = msg.location && msg.location();
    consoleErrors.push({ text: msg.text(), url: (loc && loc.url) || '' });
  });
  page.on('pageerror', (err) => { pageErrors.push({ text: String(err && err.stack || err), url: '' }); });

  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  // Let any deferred init / async work settle.
  await page.waitForTimeout(500);

  // Three sources of noise are environment artifacts of this specific test
  // harness, not app bugs, and are filtered out by matching the *failing
  // resource's URL* (Chromium attaches it as msg.location().url for every
  // "Failed to load resource" / MIME-refusal console error) so this assertion
  // stays a genuine app-correctness check rather than an environment check:
  //  - `../theme.css`: the SPA references its sibling theme.css one directory
  //    above citadel/ (correct on the real static-site deployment, where
  //    citadel/ is a subpath of the same root). The standalone Node server
  //    here only serves citadel/ as APP_DIR, so that one path 404s locally,
  //    which Chromium then reports as a MIME-type refusal for the stylesheet.
  //  - cdn.jsdelivr.net (bootstrap/jszip/chart.js, all allowed by the page's
  //    own CSP): this sandbox's egress proxy policy-denies that host, so the
  //    CDN tags fail to load here regardless of what the app does.
  //  - /favicon.ico: an automatic browser request the app never makes itself;
  //    standard for any server that doesn't special-case it.
  const NOISE = /theme\.css|cdn\.jsdelivr\.net|\/favicon\.ico/;
  const isKnownHarnessNoise = (entry) => NOISE.test(entry.url) || NOISE.test(entry.text);

  const unexpectedConsoleErrors = consoleErrors.filter((e) => !isKnownHarnessNoise(e)).map((e) => e.text);
  const unexpectedPageErrors = pageErrors.filter((e) => !isKnownHarnessNoise(e)).map((e) => e.text);

  assert.deepEqual(unexpectedConsoleErrors, [], 'no unexpected console.error output during initial load');
  assert.deepEqual(unexpectedPageErrors, [], 'no unexpected uncaught page errors during initial load');
});

test('window.CITADEL.ui exposes toast/confirm/prompt', { skip: !HAVE_CHROME && 'Chromium not installed at ' + CHROME_PATH }, async () => {
  const shape = await page.evaluate(() => {
    const ui = window.CITADEL && window.CITADEL.ui;
    return ui && {
      hasToast: typeof ui.toast === 'function',
      hasConfirm: typeof ui.confirm === 'function',
      hasPrompt: typeof ui.prompt === 'function'
    };
  });
  assert.ok(shape, 'window.CITADEL.ui exists');
  assert.equal(shape.hasToast, true);
  assert.equal(shape.hasConfirm, true);
  assert.equal(shape.hasPrompt, true);
});

test('CITADEL.ui.toast() renders a real toast element in the DOM', { skip: !HAVE_CHROME && 'Chromium not installed at ' + CHROME_PATH }, async () => {
  await page.evaluate(() => { window.CITADEL.ui.toast('hello world', 'success'); });
  const toastEl = await page.waitForSelector('.citadel-toast.citadel-toast-success', { timeout: 5000 });
  const text = await toastEl.innerText();
  assert.match(text, /hello world/);
});

test('CITADEL.ui.confirm(): Cancel click resolves false; Escape resolves false', { skip: !HAVE_CHROME && 'Chromium not installed at ' + CHROME_PATH }, async () => {
  // --- Cancel button path ---
  await page.evaluate(() => {
    window.__confirmResult = undefined;
    window.CITADEL.ui.confirm('Are you sure?').then((v) => { window.__confirmResult = v; });
  });
  const card1 = await page.waitForSelector('.citadel-modal-card', { timeout: 5000 });
  assert.equal(await card1.getAttribute('aria-modal'), 'true');
  assert.equal(await card1.getAttribute('role'), 'dialog');

  const cancelBtn = await page.waitForSelector('.citadel-modal-actions button:has-text("Cancel")', { timeout: 5000 });
  await cancelBtn.click();

  await page.waitForFunction(() => window.__confirmResult !== undefined, null, { timeout: 5000 });
  const result1 = await page.evaluate(() => window.__confirmResult);
  assert.equal(result1, false, 'Cancel click resolves confirm() to false');
  await page.waitForSelector('.citadel-modal-back', { state: 'detached', timeout: 5000 });

  // --- Escape key path ---
  await page.evaluate(() => {
    window.__confirmResult2 = undefined;
    window.CITADEL.ui.confirm('Are you REALLY sure?').then((v) => { window.__confirmResult2 = v; });
  });
  await page.waitForSelector('.citadel-modal-card', { timeout: 5000 });
  await page.keyboard.press('Escape');

  await page.waitForFunction(() => window.__confirmResult2 !== undefined, null, { timeout: 5000 });
  const result2 = await page.evaluate(() => window.__confirmResult2);
  assert.equal(result2, false, 'Escape resolves confirm() to false');
  await page.waitForSelector('.citadel-modal-back', { state: 'detached', timeout: 5000 });
});

test('"?" opens the keyboard overlay, Tab is trapped inside .kbd-card, Escape closes it', { skip: !HAVE_CHROME && 'Chromium not installed at ' + CHROME_PATH }, async () => {
  // Make sure focus is on the page body (not inside an input) so the listener
  // doesn't treat the keypress as "typing in a field".
  await page.evaluate(() => { if (document.activeElement && document.activeElement.blur) document.activeElement.blur(); document.body.focus(); });

  await page.keyboard.press('?');

  await page.waitForFunction(() => {
    const ov = document.querySelector('.kbd-overlay');
    return ov && !ov.classList.contains('d-none');
  }, null, { timeout: 5000 });

  const overlayVisible = await page.evaluate(() => {
    const ov = document.querySelector('.kbd-overlay');
    return !!ov && !ov.classList.contains('d-none');
  });
  assert.equal(overlayVisible, true, '.kbd-overlay is visible (not d-none) after pressing ?');

  // Tab repeatedly; activeElement must never leave the .kbd-card subtree.
  for (let i = 0; i < 15; i++) {
    await page.keyboard.press('Tab');
    const trapped = await page.evaluate(() => {
      const card = document.querySelector('.kbd-card');
      return !!card && card.contains(document.activeElement);
    });
    assert.equal(trapped, true, `Tab #${i + 1} kept focus inside .kbd-card`);
  }

  // Shift+Tab too, for good measure — same invariant.
  for (let i = 0; i < 5; i++) {
    await page.keyboard.press('Shift+Tab');
    const trapped = await page.evaluate(() => {
      const card = document.querySelector('.kbd-card');
      return !!card && card.contains(document.activeElement);
    });
    assert.equal(trapped, true, `Shift+Tab #${i + 1} kept focus inside .kbd-card`);
  }

  await page.keyboard.press('Escape');
  await page.waitForFunction(() => {
    const ov = document.querySelector('.kbd-overlay');
    return !ov || ov.classList.contains('d-none');
  }, null, { timeout: 5000 });

  const overlayClosed = await page.evaluate(() => {
    const ov = document.querySelector('.kbd-overlay');
    return !ov || ov.classList.contains('d-none');
  });
  assert.equal(overlayClosed, true, 'Escape closes the overlay');
});
