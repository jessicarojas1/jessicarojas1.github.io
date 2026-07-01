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
| Bootstrap 5.3.3 CSS + JS loaded from jsDelivr **with SRI** (`integrity` + `crossorigin`) | ✅ | Tampered CDN asset is rejected by the browser | Keep hashes pinned; update hash on every version bump |
| Devicon SVG icons from jsDelivr **without SRI** | ⚠️ | Footer social icons could be swapped if the CDN/path were compromised; low blast radius (images only) | Add SRI, self-host the two SVGs, or inline them |
| CDN availability is a hard dependency for styling/JS | ⚠️ | If jsDelivr is unreachable, layout/interactivity degrade | Vendor assets for offline/air-gapped or high-assurance hosting — see [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) |
| No dependency-pinning manifest (no `package.json`/lockfile) | ⚠️ | Versions are pinned only in the HTML `src`/`href` | Document the pinned versions (done in README); optionally add a lockfile if a bundler is ever introduced |

## 2. Transport, headers & CSP

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| **No `Content-Security-Policy`** meta tag in the HTML | ❌ | Without a CSP the browser won't constrain script/style/connect sources | Ship CSP at the hosting layer — provided in [`nginx.conf`](nginx.conf) and [`render.yaml`](render.yaml); replicate on CloudFront/Front Door/App Service |
| Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) | ⚠️ | Not set by the static files themselves | Applied at the edge/server (see `nginx.conf`, `render.yaml`, and each deployment guide) |
| TLS / HTTPS | ⚠️ | Not enforced by the artifact | Enforce at host (CloudFront/Front Door/ingress/nginx); redirect HTTP→HTTPS + HSTS |
| CSP currently needs `'unsafe-inline'` | ⚠️ | Per-page `onclick="window.print()"` Print buttons (42 pages) + pre-paint theme snippet + inline branding styles + inline hub script require it | To reach a nonce/hash-based CSP: replace the Print `onclick` with a `data-*` + `addEventListener` hook, externalize the inline hub script and theme snippet, replace inline `style=` color attributes with classes |
| Inline `onclick` on Print buttons | ⚠️ | 42 document pages use `onclick="window.print()"`; `branding.js` + the hub use `addEventListener` (clean) | Externalize to a delegated `addEventListener` handler, then drop `'unsafe-inline'` from `script-src` |

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
