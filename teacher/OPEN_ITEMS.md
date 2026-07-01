# Teacher Hub — Open Items / Production-Readiness Register

Honest status of what's done vs outstanding, grouped by theme. Every claim is
verified against the real files (`index.html`, `branding.js`). Items are ordered by
impact within each group.

Legend: ✅ done · ⚠️ partial · ❌ outstanding

---

## 1. Content Security & headers

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 1.1 | **Content-Security-Policy** | ❌ | No CSP `<meta>` in `index.html` (siblings cmmc2/cmmi have one; teacher does not). Weakens XSS defense-in-depth. | Add a CSP — first as an **edge response header** (already provided in `nginx.conf`, `render.yaml`, and each deployment guide), then a `<meta>`; ultimately strict without `'unsafe-inline'` (blocked by item 2.1). |
| 1.2 | **Edge security headers** | ⚠️ | HTML ships none; provided in `nginx.conf`/`render.yaml` but the live GitHub Pages host cannot set arbitrary headers. | Deploy behind a host that sets `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`/`frame-ancestors`, HSTS (VM/CloudFront/Front Door/Render). |

## 2. Inline handlers / CSP compatibility

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 2.1 | **Inline event handlers** | ❌ | **~109 `onclick`, 16 `onchange`, 3 `oninput`** (e.g. `onclick="switchTab(...)"`, `showStd`, `showMgmt`, `showRes`, `showProg`) + inline `<script>`/`<style>`. Violates the repo "no inline event handlers" rule and forces `'unsafe-inline'` in any CSP. | Externalize handlers to `data-*` attributes wired with `addEventListener` (the pattern `branding.js` already uses). Then drop `'unsafe-inline'` from the CSP (item 1.1). |
| 2.2 | **Inline app logic** | ⚠️ | The entire app is inline `<script>` in `index.html`; can't apply `script-src 'self'` without `'unsafe-inline'`. | Move app JS to an external `app.js` alongside `branding.js`; then `script-src 'self'` becomes possible. |

## 3. Dependency / supply chain

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 3.1 | **SRI on Bootstrap Icons** | ❌ | Bootstrap **CSS** (line 8) and **JS bundle** (line 422) carry `integrity=`; the **Icons CSS** (line 9) does not. A tampered CDN icons file would load unverified. | Add `integrity="sha384-…"` + `crossorigin="anonymous"` to the icons `<link>`, or vendor it. |
| 3.2 | **CDN dependency / offline** | ⚠️ | Bootstrap 5.3.3 + Icons 1.11.3 load from jsDelivr; a filtered/offline school network breaks styling/icons. Versions are **pinned** (good). | **Vendor** the assets and add a self-only CSP for offline use ([deployments/AIRGAPPED.md](deployments/AIRGAPPED.md)). |

## 4. Data protection & privacy (FERPA)

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 4.1 | **Unencrypted student PII in `localStorage`** | ❌ | 20 keys (incl. `iep_notes`, `gb_grades`, `behavior_data`, roster) hold FERPA-relevant records, unencrypted, readable by anyone with the browser profile — often a **shared classroom device**. | Enforce device controls (MDM, disk encryption, screen lock, teacher-only OS login); document in onboarding; consider optional client-side encryption with a teacher passphrase. |
| 4.2 | **No backup / import path** | ❌ | Only the Gradebook **CSV export** exists; there is no "export all / import" and no sync. Clearing site data is unrecoverable. | Add an **Export all data (JSON) / Import backup** feature; advise weekly CSV export ([docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)). |
| 4.3 | **No cross-device sync** | ⚠️ (by design) | Data is per-browser; two devices hold independent copies with no merge. | Communicate clearly; if sync is ever needed it requires a backend (a significant scope change from the zero-backend design). |
| 4.4 | **Shared-device privacy** | ❌ | Any user of the device sees all stored records; no login separates users. | Optional edge auth (basic-auth/`oauth2-proxy`) and/or device-level user separation ([docs/SECURITY.md](docs/SECURITY.md)). |

## 5. Authentication / authorization

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 5.1 | **No auth** | ⚠️ (by design) | Acceptable for a single-teacher device tool, but any device access = full data access. | If multi-user is needed, gate at the edge with the school IdP; do not build client-side "auth" (it's not real). |

## 6. Quality / tooling

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 6.1 | **No automated tests** | ❌ | No unit/e2e tests; regressions in tab switching, save/load, CSV export could ship unnoticed. | Add a lightweight Playwright smoke test: load page, switch each tab, save a plan/grade + reload, export CSV, apply branding. |
| 6.2 | **No lin/format config** | ⚠️ | Style is consistent by hand but unenforced. | Optional: add Prettier/ESLint for the externalized JS once app logic is moved out of `index.html`. |
| 6.3 | **Empty favicon** | ⚠️ | `../favicon.ico` at repo root is a 0-byte placeholder. | Provide a real favicon at the portfolio root. |

## 7. Documentation & deployment set

| # | Item | Status | Impact | Suggested action |
|---|------|--------|--------|------------------|
| 7.1 | Standard doc set | ✅ | `deployments/` ×6, `docs/` ×4, `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml` present. | Keep current as the app changes (standing rule in [CLAUDE.md](CLAUDE.md)). |
| 7.2 | Docker/Render compatibility | ✅ | `Dockerfile` (nginx non-root + `nginx.conf`) and `render.yaml` (static site + headers) provided and valid. | Verify a build (`docker build -f teacher/Dockerfile -t teacherhub .` from repo root) in CI. |

---

### Highest-value next steps
1. **Externalize inline handlers** (2.1) → then ship a **strict CSP** (1.1).
2. **Add data export/import + backup guidance** (4.2) — protects irreplaceable
   classroom data.
3. **Add SRI to Bootstrap Icons** (3.1) and consider **vendoring** (3.2).
4. **Add a Playwright smoke test** (6.1).
