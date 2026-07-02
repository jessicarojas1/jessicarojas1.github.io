# OPEN_ITEMS.md — CMMI v2.0 Practice Reference

Honest production-readiness register for `cmmi/`. Grouped by theme; each item has
**Status**, **Impact**, and **Suggested action**. This is a client-side static
site with no backend, so many classic risks (SQLi, server auth, secrets) are
**N/A** — noted where relevant.

Legend: ✅ done · 🟡 partial · 🔴 outstanding

---

## 1. Content Security Policy & inline code

- 🟡 **`'unsafe-inline'` still required for `script-src` and `style-src`.**
  - **Why:** `index.html` contains inline `<style>` blocks, inline `<script>`
    glue (theme bootstrap, export/print wiring), and a few inline event handlers;
    `../cmmidev3.js` also injects markup. The CSP therefore keeps
    `script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'` and the matching
    `style-src`.
  - **Impact:** A successful HTML-injection point could execute inline script;
    `'unsafe-inline'` weakens the XSS mitigation the CSP would otherwise give.
  - **Suggested action:** Externalize inline `<script>`/`<style>` into files,
    replace inline event handlers with `addEventListener` / `data-*` hooks, then
    move to per-response **nonces** or **hashes** and drop `'unsafe-inline'`.
    Update the CSP verbatim copies in `docs/SECURITY.md` and
    `docs/ARCHITECTURE.md` when done.
  - **Deferred this pass (honest):** dropping `'unsafe-inline'` is not a small,
    safely-verifiable change. `index.html` has a large inline `<script>`, inline
    `<style>`, and a few inline event handlers, and `../cmmidev3.js` injects
    markup; the CSP ships via `<meta>`, which cannot mint a per-response nonce,
    so the compliant paths are to externalize all inline JS/CSS (and rewrite
    handlers to `addEventListener`) or enumerate script/style hashes — a full
    behavioral refactor that can't be browser-verified here. Left tracked rather
    than half-done. `frame-ancestors` is ignored in a `<meta>` CSP, so prefer it
    in the edge header block (§7).

- ✅ **Hardening wins already in place:** `default-src 'self'` (tighter than the
  sibling `cmmc2` site — no top-level `blob:`), `object-src 'none'`,
  `base-uri 'self'`, and `worker-src blob:` scoped narrowly.

## 2. Subresource Integrity (SRI) coverage on CDN assets

- ✅ **All four CDN assets now carry SRI.**
  - **Bootstrap 5.3.3 CSS:** already pinned + SRI (unchanged).
  - **Bootstrap 5.3.3 JS bundle:** the `integrity` value was **malformed
    (63 base64 chars → 47 decoded bytes instead of 48)** and did not match the
    file jsDelivr serves — under SRI enforcement the bundle would have failed to
    load, breaking modals / nav collapse / dropdowns. Replaced with the real
    `sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz`.
  - **Bootstrap Icons 1.11.3 CSS:** added
    `integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous"`.
  - **SheetJS:** **pinned to `xlsx@0.18.5`** (was the floating `npm/xlsx` tag)
    and added
    `integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous"`.
  - **How the hashes were verified:** each hash was computed
    (`openssl dgst -sha384 -binary | openssl base64 -A`) from the exact file
    inside the corresponding npm package tarball, which jsDelivr mirrors
    byte-for-byte. Sanity check: computing the Bootstrap **CSS** hash the same
    way reproduces the already-present, correct CSS hash exactly, confirming the
    method matches what the CDN serves.
  - **Impact (closed):** a compromised/tampered CDN response for Icons or
    SheetJS is now caught by the browser; SheetJS runs at export time with DOM
    access, so pinning + SRI on it was the highest-value supply-chain fix.

## 3. Parent-asset coupling (`../cmmidev3.js` and friends)

- 🔴 **Heavy dependency on repo-root assets.**
  - **Why:** `cmmi/index.html` loads `../cmmidev3.js` (~227 KB — the entire
    practice dataset + render/filter/export engine) plus `../theme.css`,
    `../favicon.ico`, `../users.js`, `../roles.js`, `../script.js`,
    `../analytics.js`, `../siteSearch.js`. The folder does **not** render or
    export standalone.
  - **Impact:** Any standalone deploy of `cmmi/` that forgets the parent files
    ships a blank/broken page. Container build context and CI must include them.
  - **Suggested action:** Either (a) always build/deploy with the repo root as
    context (current Dockerfile/render approach), or (b) vendor a copy of
    `cmmidev3.js` + the shared assets into `cmmi/` and rewrite the `../` paths for
    a truly self-contained artifact. Document whichever is chosen.

- 🟡 **`../cmmidev3.js` is a single ~227 KB unminified file.**
  - **Impact:** Larger first paint / parse cost; unversioned filename means cache
    busting relies on server cache-control.
  - **Suggested action:** Consider minifying and content-hashing the filename for
    long-lived caching; keep an unhashed dev copy.

## 4. Testing & CI

- 🔴 **No automated tests and no CI for this subfolder.**
  - **Impact:** Regressions in filtering, status persistence, or Excel export are
    only caught manually.
  - **Suggested action:** Add a smoke test (headless browser: load `/cmmi/`,
    assert practices render, set a status, reload, assert it persisted, trigger
    export and assert an `.xlsx` downloads) and an HTML/link lint in CI.

## 5. Persistence model

- 🟡 **All user state is `localStorage`, per-browser.**
  - **Why:** status/notes/owner/target-date/flags/evidence
    (`cmmi2_*` keys), branding (`cmmi.branding.v1`), theme (`bsTheme`).
  - **Impact:** No sync across devices/browsers; clearing site data or using
    private browsing loses annotations. This is by design (no backend), but users
    must understand it.
  - **Suggested action:** Keep the existing JSON export/import (snapshot of
    `cmmi2_*` keys) as the backup mechanism; document it in-app and in
    `docs/DISASTER_RECOVERY.md`. Optionally add explicit "Export/Import
    annotations" buttons if not already prominent.

## 6. CDN / offline availability

- 🟡 **Runtime depends on `cdn.jsdelivr.net`.**
  - **Impact:** If jsDelivr is unreachable, Bootstrap/Icons/SheetJS fail; the
    page renders unstyled and export breaks.
  - **Suggested action:** For offline/air-gapped use, vendor the three assets
    locally and tighten the CSP to drop the CDN origin — see
    [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md).

## 7. Edge security headers

- 🔴 **Only the CSP is set (via `<meta>`); no transport/edge headers.**
  - **Missing:** `Strict-Transport-Security`, `X-Content-Type-Options`,
    `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options`/`frame-ancestors`.
  - **Impact:** Weaker defense-in-depth (clickjacking, MIME sniffing, referrer
    leakage) when served from a plain static host.
  - **Suggested action:** Set these at the edge (nginx/CloudFront/Front
    Door/Static Web Apps) — concrete snippets are in each `deployments/*` guide
    and `docs/SECURITY.md`. Prefer `frame-ancestors 'self'` in the CSP too.

---

## Not applicable (client-side static site)

| Concern | Status |
|---------|--------|
| Server-side auth / RBAC | N/A — no backend, no login |
| SQL injection / DB hardening | N/A — no database |
| Secrets management (app runtime) | N/A — no runtime secrets; only the deploy pipeline has an identity |
| Database migrations / background worker | N/A — none exist |
| Server file uploads / object storage | N/A — logo "upload" is a client-side `data:` URL in `localStorage` |
| Ollama / GPU / AI inference | N/A — no AI feature |
