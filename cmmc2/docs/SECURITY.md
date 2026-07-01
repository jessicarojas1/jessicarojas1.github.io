# Security — `cmmc2`

Security guide for the CMMC 2.0 Readiness Assessment Platform. `cmmc2` is a **static,
client-side** site: there is **no server, no database, no authentication, and no
server-side storage of any assessment data**. That shape drives the whole model — the
protections that matter are the Content-Security-Policy, subresource integrity, the CDN
supply chain, and the fact that CUI-adjacent data never leaves the browser.

> **Applicability:** Standard server-security topics (authN/authZ enforcement, session
> handling, DB encryption, server secrets) do not apply to the running app and are marked
> N/A below with the nearest real equivalent (the deploy pipeline / the browser).

## 1. Identity & authentication

- **Running app:** none. `index.html` includes a login **modal placeholder** populated by
  the shared `../roles.js`/`../users.js` stubs; it is portfolio chrome and **does not gate
  the assessment**. No credentials are collected, transmitted, or stored by `cmmc2`.
- **Deploy pipeline:** identity is the CI/CD principal, and it should be a **short-lived,
  federated OIDC role / managed identity** (GitHub OIDC → AWS IAM role; Entra federated
  credential → Azure) — never static keys committed to the repo. Least-privilege policies
  are in [`../deployments/AWS.md`](../deployments/AWS.md) and
  [`../deployments/AZURE.md`](../deployments/AZURE.md).

## 2. Authorization

- **Running app:** N/A — no roles/permissions are enforced client-side; all features are
  available to anyone who loads the page. Because there is no shared state, there is nothing
  to authorize.
- **Hosting/edge:** optional gating (basic-auth, `oauth2-proxy`, WAF geo/IP rules) can be
  layered at the web server / CDN if a private deployment is required — see
  [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md).

## 3. Content-Security-Policy (primary control)

Delivered via `<meta http-equiv="Content-Security-Policy">` in `index.html`, byte-for-byte:

```
default-src 'self' blob:; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net blob:; worker-src blob:; object-src 'none'; base-uri 'self';
```

| Directive | Value | Why |
|---|---|---|
| `default-src` | `'self' blob:` | Fallback origin = same-origin; `blob:` allows Blob URLs the export pipeline creates. |
| `script-src` | `'self' https://cdn.jsdelivr.net 'unsafe-inline'` | JS from same-origin + jsDelivr (Bootstrap, SheetJS). `'unsafe-inline'` permits the page's inline `<script>`/handlers — **a known weakness** (see §9). |
| `style-src` | `'self' https://cdn.jsdelivr.net 'unsafe-inline'` | CSS from self + jsDelivr; `'unsafe-inline'` for the inline `<style>` block and Bootstrap inline styles. |
| `img-src` | `'self' https: data: blob:` | Local images, any HTTPS logo URL, `data:` logos (uploaded), and Blob images. |
| `font-src` | `'self' https://cdn.jsdelivr.net` | Bootstrap Icons font from jsDelivr. |
| `connect-src` | `'self' https://cdn.jsdelivr.net blob:` | `fetch`/XHR limited to self + jsDelivr; `blob:` for Blob reads used by export. |
| `worker-src` | `blob:` | Permits blob-backed Web Workers (e.g. worker-based processing in the export path); no remote worker scripts allowed. |
| `object-src` | `'none'` | No `<object>/<embed>/<applet>` — kills a legacy injection class. **Hardening win.** |
| `base-uri` | `'self'` | Blocks `<base>`-tag hijacking of relative URLs. **Hardening win.** |

**Recommended hardening (tracked in [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md)):**
1. Externalize inline scripts/handlers and inline styles, then **remove `'unsafe-inline'`**
   and add per-response **nonces** or hashes.
2. Also emit the CSP as an **HTTP response header** at the edge (the `<meta>` form cannot
   express `frame-ancestors` or `report-to`). Ready-made header blocks are in each
   `deployments/` guide, `nginx.conf`, and `render.yaml`.
3. Add `frame-ancestors 'none'` (header-only) to prevent framing/clickjacking.

## 4. Subresource Integrity (SRI) & supply chain

| Asset | Version | SRI |
|---|---|---|
| Bootstrap CSS | 5.3.3 | ✅ `sha384-QWTKZ…` |
| Bootstrap JS bundle | 5.3.3 | ✅ `sha384-Yvpcr…` |
| Bootstrap Icons | 1.11.3 | ❌ add |
| SheetJS `xlsx.full.min.js` | **unpinned** (`/npm/xlsx`) | ❌ pin + add |

The unpinned SheetJS is the **highest supply-chain risk**: a floating `latest` served by a
compromised or mis-published CDN release would execute arbitrary JS in the user's browser
with full access to the (CUI-adjacent) `localStorage` assessment. **Action:** pin an exact
version and add SRI, or vendor SheetJS locally (default in the air-gapped build). The CSP's
restriction of `script-src` to `cdn.jsdelivr.net` limits, but does not eliminate, this risk.

## 5. Data protection

- **In transit:** serve exclusively over **TLS** (HSTS recommended). The CDN assets are
  fetched over HTTPS. Configure TLS at the host/CDN — see the deployment guides.
- **At rest:** the only persisted data is in the **visitor's browser `localStorage`**
  (`cmmc2.branding.v1` for branding; assessment keys for control status/notes/flags).
  There is **no server-side storage** and thus no server-side encryption to manage.
- **Key management:** N/A — the app holds no keys/secrets. Deploy-pipeline secrets (if any)
  belong in the CI provider's OIDC flow / a secrets manager, never in the repo.

## 6. Auditability

- **Running app:** no server audit log (no server). Client actions are not centrally logged.
- **Edge:** CDN/web-server access logs record requests to the static files (not their
  content). Enable and retain them per your policy (S3/CloudFront logs, Front Door logs,
  nginx `access_log`).
- **CSP reporting:** add `report-to`/`report-uri` (header CSP) to collect violation reports
  as a lightweight tamper/XSS signal.

## 7. Classification & DLP (CUI handling)

- The tool is designed so that **CUI-adjacent assessment data never leaves the browser**.
  There is no upload, no API call carrying assessment content, and no server persistence.
  The only outbound network traffic is fetching static assets from the CDN.
- **Operator caution:** the assessment lives in `localStorage` on whatever device the user
  runs it. On shared/kiosk machines that is a data-remanence concern — advise users to run
  it on a controlled endpoint and to clear site data when finished. The `.xlsx`/JSON exports
  are the user's responsibility to store per their CUI handling requirements.
- For regulated/disconnected environments use the **air-gapped** build
  ([`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md)) so there are **zero**
  external calls.

## 8. FIPS readiness

- The app performs **no cryptography**, so there is no app-level FIPS boundary to certify.
- FIPS relevance is at the **hosting/transport** layer: for DoD deployments use
  **FIPS 140-validated TLS endpoints** — AWS **GovCloud** FIPS endpoints
  (`s3-fips.*.amazonaws.com`, `*.us-gov-west-1`) and **Azure Government**
  (`*.usgovcloudapi.net`) in the appropriate partitions. See
  [`../deployments/AWS.md`](../deployments/AWS.md) and
  [`../deployments/AZURE.md`](../deployments/AZURE.md).

## 9. Known limitations (honest)

| Limitation | Impact | Mitigation / action |
|---|---|---|
| `'unsafe-inline'` scripts+styles | Weakens XSS defense | Externalize inline code; drop `'unsafe-inline'`; add nonces/hashes |
| Inline event handlers in `index.html` | Forces `'unsafe-inline'`; violates repo standard | Migrate to `addEventListener` |
| SheetJS unpinned, no SRI | Supply-chain RCE-in-browser risk | Pin + SRI or vendor |
| Icons no SRI | CDN tamper risk | Add SRI |
| CSP only via `<meta>` | No `frame-ancestors`/reporting | Add CSP + security headers at edge |
| `localStorage`-only data | Per-browser; remanence on shared devices | User guidance; add JSON export/import backup |

## 10. Operator responsibilities

1. Serve over TLS with HSTS; add the security-header block from your deployment guide.
2. Keep CDN pins current and add the missing SRI/pins (SheetJS, Icons).
3. Enable and retain edge access logs; wire CSP reporting if feasible.
4. Restrict the deploy pipeline to a least-privilege OIDC role/managed identity.
5. For CUI/regulated use, deploy the air-gapped vendored build.

## 11. Reporting a vulnerability

Report security issues to the repository owner (portfolio maintainer,
`cuevasjessica40@yahoo.com`) or via a private GitHub advisory on the
`jessicarojas1.github.io` repository. Please include reproduction steps and affected
version/commit. As a static site, most fixes ship as a content update (edit + redeploy);
target acknowledgement within a few business days for this personal-portfolio project.
