# ISMS Document Library — Open Items (Production-Readiness Register)

An honest account of what is done vs. outstanding for this **Type A static
website**. Because there is no backend, database, or server-side auth, most of
the classic web-app risk surface does not apply — but a static site has its own
real concerns (supply chain, transport, headers). Items are grouped by theme with
**impact** and a **suggested action**.

Legend: ✅ done · ⚠️ partial / caveat · ❌ not done

---

## 1. Supply chain / dependencies

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Bootstrap 5.3.3 CSS + JS loaded from jsDelivr **with SRI** (`integrity` + `crossorigin`) | ✅ | Tampered CDN asset is rejected by the browser | **Corrected:** the Bootstrap **bundle JS** `integrity` was an invalid hash (browser would reject the bundle → modals/collapse/nav toggle broken). Recomputed the real `sha384` from the `bootstrap@5.3.3` npm tarball (CSS hash matched the tarball → CDN parity confirmed) and pinned `sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz` on all 43 pages. CSS hash was already correct. Update on every version bump. |
| Devicon SVG icons from jsDelivr **without SRI** | ⚠️ | Footer social icons could be swapped if the CDN/path were compromised; low blast radius (images only) | **Deferred:** the `integrity` attribute is **not honored by browsers on `<img>`** (only `<link>`/`<script>`), so real SRI is impossible here. Mitigated for now by CSP `img-src 'self' data: https://cdn.jsdelivr.net` (only that CDN path). Proper fix is to **self-host/inline the two SVGs** and drop the CDN from `img-src` — a repeated edit across 43 pages, left for a dedicated pass. |
| CDN availability is a hard dependency for styling/JS | ⚠️ | If jsDelivr is unreachable, layout/interactivity degrade | Vendor assets for offline/air-gapped or high-assurance hosting — see [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) |
| No dependency-pinning manifest (no `package.json`/lockfile) | ⚠️ | Versions are pinned only in the HTML `src`/`href` | Document the pinned versions (done in README); optionally add a lockfile if a bundler is ever introduced |

## 2. Transport, headers & CSP

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| **No `Content-Security-Policy`** meta tag in the HTML | ✅ | The browser now constrains script/style/img/connect sources even on hosts that don't set headers (e.g. GitHub Pages) | **Fixed:** every page (43) ships `<meta http-equiv="Content-Security-Policy">` with **`script-src 'self' https://cdn.jsdelivr.net` (no `'unsafe-inline'`)**. Edge CSP still provided in [`nginx.conf`](nginx.conf) (also tightened — image serves only `/isms/`) and [`render.yaml`](render.yaml) (keeps `'unsafe-inline'` because it publishes the whole repo root, incl. parent portfolio pages that still use inline handlers). |
| Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) | ⚠️ | Not set by the static files themselves | Applied at the edge/server (see `nginx.conf`, `render.yaml`, and each deployment guide) |
| TLS / HTTPS | ⚠️ | Not enforced by the artifact | Enforce at host (CloudFront/Front Door/ingress/nginx); redirect HTTP→HTTPS + HSTS |
| CSP `script-src` needs `'unsafe-inline'` | ✅ | **Removed for scripts.** All scripts are external now; `style-src` still needs `'unsafe-inline'` for inline `style=` color attributes + branding accent styles (tracked separately below) | **Fixed for scripts:** externalized the pre-paint theme snippet → `theme-init.js`, the hub filter/search script → `hub.js`, and replaced the Print `onclick` with `data-print` + a delegated handler. `script-src` dropped `'unsafe-inline'` in every page `<meta>` and in `nginx.conf`. Remaining: inline `style=` attributes still require `style-src 'unsafe-inline'` — a larger CSS-class refactor. |
| Inline `onclick` on Print buttons | ✅ | Compliant with the no-inline-handler rule | **Fixed:** all 42 document pages now use `<button … data-print>`; a single delegated `addEventListener('click', … closest('[data-print]'))` in `theme-init.js` calls `window.print()`. Verified: `grep -c 'onclick='` across `isms/*.html` = 0. |

## 3. Authentication / authorization

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Login modal is a **client-side RBAC demo** (`../roles.js`, `../users.js`) | ⚠️ | It is **not** a security boundary — all content is public static HTML regardless of "login" | Treat all documents as public; if access control is required, gate at the host (Basic Auth / oauth2-proxy / Front Door / CloudFront signed URLs) — see [deployments/SINGLE_LINUX_SERVER.md](deployments/SINGLE_LINUX_SERVER.md) |
| No server-side sessions / tokens / cookies | ✅ (by design) | No session risk to manage | N/A |

## 4. Client-side data & branding

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Branding persisted in `localStorage['isms_branding']` (per-browser) | ⚠️ | Branding is **not** shared across browsers/users and is lost if storage is cleared | Documented in [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md); for org-wide branding, edit `branding.js` defaults in the repo |
| Theme persisted in `localStorage['bsTheme']` (default `dark`) | ✅ | Per-browser preference | N/A |
| Logo URL sanitized (`http(s)://` / `data:image/…`), name HTML-escaped, accent hex-validated | ✅ | Mitigates stored-XSS via branding inputs | Keep the sanitizers in `branding.js` on any change |
| Uploaded logo stored as a `data:` URL in `localStorage` | ✅ | Never leaves the browser; no server upload path exists | N/A |

## 5. Content accuracy & governance

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Documents are **templates/reference**, not a live controlled ISMS | ⚠️ | Adopters must tailor scope, owners, dates, and controls | State clearly to consumers; use the Document Control Procedure (`pro-003`) when adopting |
| No automated link/anchor checker | ❌ | Broken internal links could go unnoticed | Add a CI link-check (e.g. `lychee`/`htmltest`) — see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| No automated HTML/CSP lint in CI | ❌ | Inline-handler / header regressions caught only by review | Add a CI job that greps for inline `on*=` handlers and asserts headers |

## 6. Operations

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Container image is non-root, pinned base, healthchecked | ✅ | Good baseline | Rebuild on base-image CVEs; scan with Trivy/Grype |
| No monitoring/alerting on the hosted site | ❌ | Outages/expired certs may go unnoticed | Add uptime + TLS-expiry checks at the host/CDN |
| Git is the single source of truth; artifact is rebuildable | ✅ | Fast, clean recovery | See [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md) |

---

### Not applicable (stated for completeness)

Database migrations · background workers/queues · server secrets & rotation ·
server-side file uploads/scanning · Ollama / GPU / AI inference — **none exist**
in this project (it is a static document library). See
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the explicit N/A notes.
