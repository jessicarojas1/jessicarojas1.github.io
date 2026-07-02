# OPEN_ITEMS.md — `cmmc2` production-readiness register

Honest status of what is done vs. outstanding for the CMMC 2.0 Readiness Assessment
Platform. Grouped by theme; each item lists **Impact** and a **Suggested action**.
This is a static, client-side site — several "typical" backend concerns simply do not
apply and are marked as such.

## Legend
`DONE` shipped · `PARTIAL` in place but incomplete · `TODO` not yet started · `N/A` not applicable to a static site

---

## 1. Content-Security-Policy & inline code

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| CSP delivered via `<meta>` tag | DONE | Baseline XSS/deps control present and enforced by the browser | Also emit CSP as a real **HTTP response header** at the edge (nginx/CloudFront/Front Door) so it applies before parse and can use `report-to`. |
| `'unsafe-inline'` in `script-src` and `style-src` | PARTIAL / WART | Weakens XSS protection — any injected inline script would execute | `index.html` uses inline `<script>`, inline `<style>`, and inline event handlers (`onclick=`, `onchange=`, `oninput=`). **Externalize** all handlers/logic into a file, move styles to a stylesheet, then **drop `'unsafe-inline'`** and add per-response **nonces** or hashed script/style. |
| Inline event handlers in `index.html` | TODO | Blocks the repo-wide "no inline handlers" standard; forces `'unsafe-inline'` | Migrate to `addEventListener` (as `branding.js` already does); use `data-*` hooks. |
| `object-src 'none'`, `base-uri 'self'` | DONE | Hardening wins (no plugins, no `<base>` hijack) | Keep. |

> **Deferred this pass (honest):** dropping `'unsafe-inline'` is **not** a small,
> safely-verifiable change here. `index.html` (~2,800 lines) carries a large inline
> `<script>`, inline `<style>`, and 39 inline event handlers; on a static host the CSP
> is delivered via `<meta>`, which cannot mint a per-response **nonce**, so the only
> compliant paths are (a) externalize *all* inline JS/CSS into files plus per-handler
> `addEventListener` rewrites, or (b) enumerate script/style **hashes** — and inline
> handlers can't be hash-allowed at all, so they must be externalized first. That is a
> full behavioral refactor that cannot be browser-verified in this environment, so it is
> left as a tracked item rather than half-done. Note `frame-ancestors` is **ignored** in
> a `<meta>` CSP and therefore belongs in the edge header block (§3), not here.

---

## 2. Subresource Integrity (SRI) & dependency pinning

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Bootstrap CSS `5.3.3` SRI | DONE | CDN tampering of core CSS detected | — |
| Bootstrap JS bundle `5.3.3` SRI | ✅ DONE (hash corrected) | CDN tampering of core JS detected | The `integrity` value was **malformed (63 base64 chars → 47 decoded bytes, not 48)** and did not match the file jsDelivr serves — under SRI enforcement the Bootstrap bundle would have failed to load, breaking modals/nav/dropdowns. Replaced with the real `sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz` (computed from the `bootstrap@5.3.3` npm tarball that jsDelivr mirrors byte-for-byte — verified: the CSS hash computed the same way reproduces the existing, correct CSS hash exactly). |
| Bootstrap Icons `1.11.3` SRI | ✅ DONE | Icon CSS could be swapped by a compromised CDN | Added `integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous"` to the Icons `<link>`. Real hash from the `bootstrap-icons@1.11.3` npm tarball. |
| SheetJS `xlsx.full.min.js` — **unpinned + no SRI** | ✅ DONE | Loaded from `/npm/xlsx` (floating latest); a bad release or CDN compromise runs arbitrary JS in the user's browser | **Pinned to `xlsx@0.18.5`** (the last npm-published release the floating tag resolved to — identical behavior) and added `integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous"`. Real hash from the `xlsx@0.18.5` npm tarball. |
| CDN availability / offline | PARTIAL | If jsDelivr is unreachable, Bootstrap/SheetJS fail and export breaks | Ship the **air-gapped/vendored** variant (see [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md)) for offline/regulated environments; consider self-hosting all assets by default. |

---

## 3. Edge security headers

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| CSP as HTTP header | TODO | Only the `<meta>` CSP applies today | Add at the edge (see guides). |
| `Strict-Transport-Security` | TODO | No HSTS pinning | Add `max-age=63072000; includeSubDomains; preload` at TLS terminator. |
| `X-Content-Type-Options: nosniff` | TODO | MIME sniffing risk | Add at edge. |
| `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options`/`frame-ancestors` | TODO | Referrer leakage, feature exposure, clickjacking | Add `Referrer-Policy: no-referrer`, a minimal `Permissions-Policy`, and `frame-ancestors 'none'`. |
| `Cache-Control` strategy | PARTIAL | Default server caching only | Long-cache fingerprinted static assets; short/`no-cache` for `index.html`. See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md). |

Each [`deployments/`](deployments/) guide includes a ready header block for its target.

---

## 4. Parent-asset coupling (monorepo)

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| `index.html` depends on parent `../` assets | PARTIAL / WART | A standalone deploy of only `cmmc2/` loses theme, nav chrome, favicon, search unless those files are also shipped | Either (a) copy `../theme.css`, `../favicon.ico`, `../users.js`, `../roles.js`, `../script.js`, `../analytics.js`, `../siteSearch.js` into the deploy artifact, or (b) inline/self-contain the needed CSS/JS so `cmmc2/` is portable. Every deployment guide documents (a). |
| Navbar brand → `../index.html` | PARTIAL | Standalone deploy has a dangling Home link | Rewrite the brand/Home target for standalone hosting, or ship the portfolio `index.html`. |

---

## 5. Testing & CI

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Automated tests | TODO | No regression safety on SPRS math, POA&M generation, or export | Add unit tests for the score/POA&M functions (extract them from the inline script first) and a headless smoke test (Playwright) asserting entry `200`, no console CSP violations, a control can be marked, score updates, and an `.xlsx` downloads. |
| Link / HTML validation | TODO | Broken links or invalid markup ship silently | Add an `htmlproofer`/link-check job in CI; surface a build badge in the README. |
| CSP violation monitoring | TODO | Violations invisible in prod | Add `report-to`/`report-uri` and a collector. |

---

## 6. Data & persistence

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| `localStorage`-only persistence | PARTIAL / BY DESIGN | Assessment + branding live only in the visitor's browser; clearing site data or switching browser/device loses everything; nothing is backed up server-side | Keep client-side by design (CUI never leaves the browser), but add **explicit JSON export/import** of the full assessment (not just the `.xlsx` report) so users can back up and move data. A Blob JSON export already exists for some data — extend to full round-trip import. |
| No server-side storage of CUI | DONE / BY DESIGN | Strong privacy posture — no CUI transmitted or stored server-side | Document clearly (done in [`docs/SECURITY.md`](docs/SECURITY.md)). |

---

## 7. Accessibility & UX

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Keyboard/ARIA coverage | PARTIAL | Some interactions may be mouse-centric | Audit accordions/status buttons/modal for focus order and ARIA; test with a screen reader. |
| Reduced-motion | TODO | Transitions ignore `prefers-reduced-motion` | Gate CSS transitions behind the media query. |

---

## 8. Not applicable (static, client-side site)

| Concern | Why N/A |
|---|---|
| Database / migrations / `schema.sql` | No database. |
| Server auth / RBAC / session security | No backend; the login modal is a shared UI stub and gates nothing. |
| Background worker / queue / cron | None. |
| Server file uploads / malware scanning / object-storage writes | The only "upload" is an in-browser logo read to a `data:` URL. |
| Secrets management / rotation (app runtime) | The running site holds no secrets. Deploy **pipeline** identity uses OIDC roles — see the deployment guides. |
| Ollama / GPU acceleration | No AI feature. |

---

_Keep this register honest and current. When an item is fixed, move it to `DONE` (or delete
it) and update the corresponding [`docs/`](docs/) and [`deployments/`](deployments/) files
in the same change._
