# Teacher Hub — Smoke Tests

A lightweight [Playwright](https://playwright.dev) smoke suite that exercises the
critical flows after the app's inline `on*` handlers were externalized to
`data-*` attributes + delegated `addEventListener` (see `../app.js`).

`smoke.spec.js` covers:

1. Page loads and **all 10 tabs** switch (delegated `data-onclick` dispatch).
2. A **lesson plan** saves and survives a reload (`localStorage`).
3. A **gradebook** assignment + grade saves and survives a reload.
4. **Gradebook CSV export** triggers a download named `gradebook.csv`.
5. **Export-all JSON backup** downloads and re-imports (item 4.2).
6. **Branding** (display name + accent color) applies live.

## Run

These tests are not wired into a package manager (Teacher Hub has **no build
step**), so install Playwright on demand from the repo root:

```bash
# from the repository root (jessicarojas1.github.io/)
npm init -y                         # if you don't already have a package.json
npm install -D @playwright/test
npx playwright install chromium     # downloads the browser (needs network)

# run the suite (config starts `python3 -m http.server` at the repo root itself)
npx playwright test --config teacher/tests/playwright.config.js
```

The config serves the site from the **repo root** on port `8137` so the app's
parent references (`../theme.css`, `../favicon.ico`, `../` home link) resolve,
then points the tests at `/teacher/`. Python 3 must be on `PATH`.

## CI

To gate merges, add a GitHub Actions job that runs
`npx playwright install --with-deps chromium` then the command above. The suite
is self-contained (its own web server) and needs no secrets.

## Note on this environment

The suite was authored and reviewed here, but the Playwright **browser binaries
could not be downloaded** in the sandbox (egress policy blocks the CDN), so the
browser-driven run was not executed in-repo. The same flows were verified
headlessly with a jsdom harness driving the real `index.html` + `app.js`
(tab switching, plan/grade save+persist, CSV export, backup export/import) —
all passed. Run the command above in an environment with network access to
execute the full browser suite.
