// Playwright config for Teacher Hub smoke tests.
// Serves the site from the REPO ROOT (two levels up) so the app's parent
// references (../theme.css, ../favicon.ico) and ../ home link resolve, then
// points the tests at /teacher/.
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const REPO_ROOT = path.resolve(__dirname, '..', '..');

module.exports = defineConfig({
  testDir: __dirname,
  timeout: 30000,
  fullyParallel: true,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8137',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: {
    command: 'python3 -m http.server 8137',
    cwd: REPO_ROOT,
    url: 'http://127.0.0.1:8137/teacher/',
    reuseExistingServer: !process.env.CI,
    timeout: 30000,
  },
});
