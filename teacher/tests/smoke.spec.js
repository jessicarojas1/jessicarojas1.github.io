// Teacher Hub — Playwright smoke test
// Covers the critical flows after the inline handlers were externalized to
// data-* attributes + delegated addEventListener (see app.js):
//   1. Page loads, all 10 tabs switch
//   2. A lesson plan saves and survives reload (localStorage)
//   3. A gradebook assignment + grade saves and survives reload
//   4. Gradebook CSV export triggers a download
//   5. Export-all JSON backup downloads and re-imports
//   6. Branding (name + accent) applies live
//
// The page must be served from the REPO ROOT so ../theme.css and ../favicon.ico
// resolve. playwright.config.js starts `python3 -m http.server` at the repo root
// and points baseURL at /teacher/. See tests/README.md to run.

const { test, expect } = require('@playwright/test');

test.beforeEach(async ({ page }) => {
  await page.goto('/teacher/');
  // Start each test from a clean slate so demo-seed + assertions are deterministic.
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await page.waitForSelector('#mainTabs .nav-link');
});

test('all 10 tabs switch via delegated handlers', async ({ page }) => {
  const tabs = page.locator('#mainTabs .nav-link');
  await expect(tabs).toHaveCount(10);
  const count = await tabs.count();
  for (let i = 0; i < count; i++) {
    const btn = tabs.nth(i);
    const expr = await btn.getAttribute('data-onclick');
    const id = expr.match(/switchTab\('([^']+)'/)[1];
    await btn.click();
    await expect(page.locator('#' + id)).toHaveClass(/tab-visible/);
  }
});

test('lesson plan saves and persists across reload', async ({ page }) => {
  await page.locator('#mainTabs .nav-link', { hasText: 'Planner' }).click();
  await page.fill('#lpTitle', 'Smoke Fractions Lesson');
  await page.selectOption('#lpSubject', 'Math');
  await page.locator('#tabPlanner button', { hasText: 'Save' }).first().click();
  await expect(page.locator('#savedPlansList')).toContainText('Smoke Fractions Lesson');

  await page.reload();
  await page.locator('#mainTabs .nav-link', { hasText: 'Planner' }).click();
  await expect(page.locator('#savedPlansList')).toContainText('Smoke Fractions Lesson');
});

test('gradebook assignment + grade saves and persists', async ({ page }) => {
  // Seed two students via Settings.
  await page.evaluate(() => {
    localStorage.setItem('teacher_settings', JSON.stringify(
      { name: 'T', school: 'S', grade: '5th Grade', pbisGoal: 100, students: ['Alpha', 'Beta'] }));
  });
  await page.reload();
  await page.locator('#mainTabs .nav-link', { hasText: 'Gradebook' }).click();

  await page.locator('#tabGradebook button', { hasText: 'Add Assignment' }).click();
  await page.fill('#newAssignName', 'Quiz 1');
  await page.fill('#newAssignMax', '10');
  await page.locator('#addAssignModal button', { hasText: 'Add' }).click();

  const score = page.locator('#gbTbody input[data-onchange^="saveScore"]').first();
  await score.fill('9');
  await score.dispatchEvent('change');

  await page.reload();
  await page.locator('#mainTabs .nav-link', { hasText: 'Gradebook' }).click();
  await expect(page.locator('#gbTbody input[data-onchange^="saveScore"]').first()).toHaveValue('9');
});

test('gradebook CSV export downloads', async ({ page }) => {
  await page.evaluate(() => {
    localStorage.setItem('teacher_settings', JSON.stringify(
      { name: 'T', school: 'S', grade: '5th Grade', pbisGoal: 100, students: ['Alpha', 'Beta'] }));
  });
  await page.reload();
  await page.locator('#mainTabs .nav-link', { hasText: 'Gradebook' }).click();

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('#tabGradebook button', { hasText: 'Export CSV' }).click(),
  ]);
  expect(download.suggestedFilename()).toBe('gradebook.csv');
});

test('export-all backup downloads then re-imports', async ({ page }) => {
  await page.evaluate(() => {
    localStorage.setItem('teacher_settings', JSON.stringify(
      { name: 'BackupTest', school: 'S', grade: '5th Grade', pbisGoal: 100, students: ['Alpha'] }));
  });
  await page.reload();

  // Export
  await page.locator('button[title="Settings"], [data-bs-target="#settingsModal"]').first().click();
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('[data-onclick="exportAllData()"]').click(),
  ]);
  const stream = await download.createReadStream();
  let json = '';
  for await (const chunk of stream) json += chunk;
  const parsed = JSON.parse(json);
  expect(parsed.data.teacher_settings).toContain('BackupTest');

  // Wipe, then import the same file
  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await page.locator('button[title="Settings"], [data-bs-target="#settingsModal"]').first().click();
  page.on('dialog', d => d.accept());           // confirm() + alert()
  await page.setInputFiles('#importFile', {
    name: 'backup.json', mimeType: 'application/json', buffer: Buffer.from(json),
  });
  await page.waitForFunction(() =>
    (localStorage.getItem('teacher_settings') || '').includes('BackupTest'));
});

test('branding name + accent apply live', async ({ page }) => {
  await page.locator('#brandingSettingsBtn').click();
  await page.fill('#brandName', 'Room 12 Hub');
  await page.fill('#brandAccent', '#123456');
  await page.locator('#brandSave').click();

  await expect(page).toHaveTitle(/Room 12 Hub/);
  await expect(page.locator('nav .navbar-brand .brand-text')).toHaveText('Room 12 Hub');
  const accent = await page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--bs-primary').trim());
  expect(accent).toBe('#123456');
});
