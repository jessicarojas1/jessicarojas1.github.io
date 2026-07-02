# Teacher Hub — Open Items / Production-Readiness Register

Honest status of what's done vs outstanding, grouped by theme. Every claim is
verified against the real files (`index.html`, `branding.js`). Items are ordered by
impact within each group.

Legend: ✅ done · ⚠️ partial · ❌ outstanding

---

## 1. Content Security & headers

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 1.1 | **Content-Security-Policy** | ✅ | `index.html` now ships a **strict CSP `<meta>`**: `script-src 'self' https://cdn.jsdelivr.net` (NO `'unsafe-inline'`), `style-src` keeps `'unsafe-inline'` for inline `style=""`/`<style>`. Matches the edge CSP in `nginx.conf`/`render.yaml` (also tightened). Enabled by externalizing all handlers (item 2.1). | Done. Keep meta + edge CSP in sync when assets change. |
| 1.2 | **Edge security headers** | ⚠️ | HTML `<meta>` cannot set HSTS/`X-Frame-Options`; those ship in `nginx.conf`/`render.yaml`, but the live GitHub Pages host cannot set arbitrary headers. | Deploy behind a host that sets `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`/`frame-ancestors`, HSTS (VM/CloudFront/Front Door/Render). |

## 2. Inline handlers / CSP compatibility

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 2.1 | **Inline event handlers** | ✅ | **Zero** inline `on*` handlers remain in `index.html` (verified: `grep onclick=/onchange=/oninput=` → 0 bare matches). Every handler — static markup **and** the ~60 dynamically-generated ones in `app.js` innerHTML strings — is now a `data-onclick`/`data-onchange`/`data-oninput` attribute dispatched by a single delegated `addEventListener` per event type (safe expression parser, no `eval`, whitelisted to global functions). | Done. New interactivity must keep using `data-*` + delegation. |
| 2.2 | **Inline app logic** | ✅ | The entire app `<script>` was moved to external **`app.js`**; the pre-paint theme bootstrap moved to **`theme-init.js`**. `index.html` now loads only external scripts (`theme-init.js`, Bootstrap bundle, `branding.js`, `app.js`), so `script-src 'self'` (no `'unsafe-inline'`) is in force. | Done. Optional: add Prettier/ESLint (item 6.2). |

## 3. Dependency / supply chain

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 3.1 | **SRI on Bootstrap Icons** | ✅ | Icons CSS `<link>` now carries `integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"` + `crossorigin="anonymous"` (hash computed from the pinned `bootstrap-icons@1.11.3` package, byte-identical to the jsDelivr file). **Also fixed:** the Bootstrap **JS bundle** SRI hash was wrong (`…Xc4s9bIOgUxi8T…`) and would have blocked the bundle — corrected to the official `…Xc5s9fDVZLES…`. Bootstrap CSS hash verified correct. | Done. |
| 3.2 | **CDN dependency / offline** | ⚠️ | Bootstrap 5.3.3 + Icons 1.11.3 load from jsDelivr; a filtered/offline school network breaks styling/icons. Versions are **pinned** (good). | **Vendor** the assets and add a self-only CSP for offline use ([deployments/AIRGAPPED.md](deployments/AIRGAPPED.md)). |

## 4. Data protection & privacy (FERPA)

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 4.1 | **Unencrypted student PII in `localStorage`** | ❌ | 20 keys (incl. `iep_notes`, `gb_grades`, `behavior_data`, roster) hold FERPA-relevant records, unencrypted, readable by anyone with the browser profile — often a **shared classroom device**. | Enforce device controls (MDM, disk encryption, screen lock, teacher-only OS login); document in onboarding; consider optional client-side encryption with a teacher passphrase. |
| 4.2 | **No backup / import path** | ✅ | **Settings → Data Backup** now has **Export All (JSON)** (writes every Teacher Hub `localStorage` key to a timestamped `teacher-hub-backup-YYYY-MM-DD.json`) and **Import Backup** (validates the file, allow-lists known keys, confirms, restores, reloads). Both are `data-*` wired — no inline handlers. Still advise periodic exports ([docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)). | Done. |
| 4.3 | **No cross-device sync** | ⚠️ (by design) | Data is per-browser; two devices hold independent copies with no merge. | Communicate clearly; if sync is ever needed it requires a backend (a significant scope change from the zero-backend design). |
| 4.4 | **Shared-device privacy** | ❌ | Any user of the device sees all stored records; no login separates users. | Optional edge auth (basic-auth/`oauth2-proxy`) and/or device-level user separation ([docs/SECURITY.md](docs/SECURITY.md)). |

## 5. Authentication / authorization

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 5.1 | **No auth** | ⚠️ (by design) | Acceptable for a single-teacher device tool, but any device access = full data access. | If multi-user is needed, gate at the edge with the school IdP; do not build client-side "auth" (it's not real). |

## 6. Quality / tooling

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 6.1 | **No automated tests** | ✅ | Added a **Playwright smoke suite** at `tests/` (`smoke.spec.js` + `playwright.config.js` + `README.md`): loads the page, switches all 10 tabs, saves a plan and a grade + reloads to prove persistence, exports the Gradebook CSV, exports/re-imports the JSON backup, and applies branding. The config serves the site from the repo root so `../` references resolve. **Note:** the Playwright browser download is blocked by this sandbox's egress policy, so the browser run was not executed here — the same flows were verified headlessly against the real `index.html` + `app.js` with a jsdom harness (all passed). See `tests/README.md`. | Run `npx playwright test --config teacher/tests/playwright.config.js` in a networked env / CI. |
| 6.2 | **No lin/format config** | ⚠️ | Style is consistent by hand but unenforced. Now unblocked (JS lives in `app.js`). | Optional: add Prettier/ESLint targeting `teacher/*.js`. |
| 6.3 | **Empty favicon** | ⚠️ | `../favicon.ico` at repo root is a 0-byte placeholder. **Repo-root concern — out of scope for `teacher/`;** not fabricated here. | Provide a real favicon at the portfolio root. |

## 7. Documentation & deployment set

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 7.1 | Standard doc set | ✅ | `deployments/` ×6, `docs/` ×4, `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml` present. | Keep current as the app changes (standing rule in [CLAUDE.md](CLAUDE.md)). |
| 7.2 | Docker/Render compatibility | ✅ | `Dockerfile` (nginx non-root + `nginx.conf`) and `render.yaml` (static site + headers) provided and valid. | Verify a build (`docker build -f teacher/Dockerfile -t teacherhub .` from repo root) in CI. |

---

### Highest-value next steps
_Done in the latest pass: externalized all inline handlers (2.1/2.2), strict CSP
(1.1), Bootstrap Icons SRI + fixed the wrong bundle SRI (3.1), export/import
backup (4.2), Playwright smoke suite (6.1)._ Remaining, in priority order:

1. **Deploy behind a header-setting host** (1.2 / 4.4) for HSTS + framing +
   optional edge auth — GitHub Pages can't set these.
2. **Vendor the Bootstrap/Icons assets** (3.2) for offline/air-gapped school
   networks, and switch the CSP to `'self'`-only where hosted that way.
3. **Client-side encryption option** for the FERPA data at rest (4.1).
4. **Run the Playwright suite in CI** (6.1) and optionally add Prettier/ESLint (6.2).
5. **Real favicon** at the portfolio root (6.3).
