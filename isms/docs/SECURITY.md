# ISMS Document Library — Security Guide

**Applicability:** this is a **Type A static website** — HTML/CSS/JS served to the
browser with **no backend, no database, no server-side auth, no secrets, and no
server-side data processing**. The security model is therefore about **supply
chain, transport, headers/CSP, and client-side input handling** — not server
identity, RBAC enforcement, or data-at-rest encryption of a datastore (there is
none). Where a classic control does not apply, it is stated as such rather than
faked.

## Threat model (what actually applies)

| In scope | Out of scope (no such component) |
|----------|----------------------------------|
| CDN/supply-chain tampering of Bootstrap/icons | Server compromise / RCE |
| Missing/weak transport (HTTP, no HSTS) | Database breach, SQL injection |
| Missing security headers / CSP | Server-side session/token theft |
| Stored/reflected XSS via branding inputs | Server-side auth bypass |
| Data exfiltration (there is none — nothing leaves the browser) | File-upload malware (no server upload path) |
| Hosting-layer misconfig (public bucket, open redirect) | Secrets leakage (no secrets exist) |

## Identity & authentication

- **There is no application authentication.** All documents are public static
  files. The **login modal** rendered by `../roles.js` / `../users.js` is a
  **client-side RBAC demo** — it does not gate any content and must never be
  relied on as a security boundary. Its "session clears when you close this tab."
- **If access restriction is required**, enforce it at the **hosting layer**:
  - nginx/Apache **Basic Auth** or **oauth2-proxy** in front of the site
    (see [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md)),
  - **CloudFront signed URLs/cookies** or **Lambda@Edge/WAF** (AWS),
  - **Front Door / App Service Easy Auth (Entra ID)** (Azure),
  - Kubernetes **Ingress auth annotations** (oauth2-proxy / OIDC).

## Authorization (RBAC / permissions)

Not applicable at the app layer — no server enforces permissions; content is
uniformly public. Any RBAC must be implemented by the gating proxy/CDN above. The
in-page role badges are cosmetic UI only.

## Data protection

- **In transit:** enforce **HTTPS/TLS 1.2+** and **HSTS** at the host/CDN. The
  static artifact does not itself terminate TLS. Redirect HTTP→HTTPS.
- **At rest (client):** the only persisted data is **per-browser `localStorage`**
  — `bsTheme` (theme) and `isms_branding` (`{name, logoUrl, accent}`). This never
  leaves the browser and is not transmitted anywhere. There is **no server-side
  data at rest** and therefore no KMS/key management to operate.
- **At rest (hosting):** if hosted from an object store (S3/Blob), enable
  bucket/container **SSE** (SSE-KMS / Microsoft-managed keys) and **block public
  ACLs**, serving only via the CDN origin-access identity — see the AWS/Azure
  guides.
- **No PII is collected or transmitted.** An uploaded logo is read locally via
  `FileReader.readAsDataURL` into `localStorage`; there is no upload endpoint.

## Client-side input handling (XSS)

`branding.js` is the only component that accepts user input, and it is hardened:

| Input | Control |
|-------|---------|
| Logo URL | `sanitizeLogoUrl()` allow-lists **only** `http(s)://` and `data:image/…`; anything else → `''` (empty → default mark) |
| Uploaded logo | Must be `image/*`; read to a `data:` URL and re-validated by `sanitizeLogoUrl()` |
| Display name | HTML-**escaped** (`escapeHtml`) before injection into the brand mark; capped at 80 chars |
| Accent color | `sanitizeHex()` validates 3-/6-digit hex; invalid → default `#ff5811` |
| Logo `<img>` | Built via `document.createElement`/`.src` (no `innerHTML`); `error` handler falls back to the text mark |

All script wiring — `branding.js`, the hub filter/search module (now `hub.js`),
the pre-paint theme bootstrap (now `theme-init.js`), and the parent scripts — uses
`addEventListener` with **no inline handlers**. The former per-page
`onclick="window.print()"` Print buttons (42 pages) are now `<button … data-print>`,
handled by one delegated listener in `theme-init.js`. There are **no inline event
handlers left** in the project (`grep -c 'onclick='` across `isms/*.html` = 0).

## Content Security Policy & headers

CSP is now emitted **both** per page and at the edge:

- **Per page:** every HTML file ships a `<meta http-equiv="Content-Security-Policy">`
  with **`script-src 'self' https://cdn.jsdelivr.net` (no `'unsafe-inline'`)**, so hosts
  that don't set headers (e.g. GitHub Pages) still constrain scripts.
- **Edge:** [`../nginx.conf`](../nginx.conf) (container image — serves only `/isms/`, so
  it also drops script `'unsafe-inline'`) and [`../render.yaml`](../render.yaml) (Render
  static site — publishes the **whole repo root**, so it *keeps* script `'unsafe-inline'`
  for the parent portfolio pages that still use inline handlers; the ISMS pages remain
  strict via their `<meta>`). Replicate the same values on CloudFront, Azure Front Door /
  Static Web Apps `staticwebapp.config.json`, or k8s ingress annotations.

Baseline policy shipped (per-page `<meta>` / `nginx.conf` — the strict form):

```
Content-Security-Policy: default-src 'self';
  script-src 'self' https://cdn.jsdelivr.net;
  style-src  'self' 'unsafe-inline' https://cdn.jsdelivr.net;
  img-src    'self' data: https://cdn.jsdelivr.net;
  font-src   'self' https://cdn.jsdelivr.net;
  connect-src 'self'; object-src 'none'; base-uri 'self';
  frame-ancestors 'none'; form-action 'self'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

(`frame-ancestors` is set at the edge only; it is ignored inside a `<meta>` CSP.)
`'unsafe-inline'` is now required **only by `style-src`** — for inline `style=` color
attributes and branding accent styles. **Remaining hardening path:** move those inline
`style=` colors to CSS classes, then drop `'unsafe-inline'` from `style-src` too.

## Supply chain (dependency / CDN risk)

- **Bootstrap 5.3.3** CSS + JS are **SRI-pinned** (`integrity="sha384-…"
  crossorigin="anonymous"`) — a tampered CDN response is rejected by the browser. The
  bundle-JS hash was **corrected** to the real
  `sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz` (recomputed
  from the `bootstrap@5.3.3` npm tarball; the prior value was invalid and the browser
  would have rejected the bundle).
- **Devicon SVG icons** (footer GitHub/LinkedIn) are loaded from jsDelivr
  **without SRI**. Note the `integrity` attribute is **not honored on `<img>`**, so real
  SRI is impossible; the CSP restricts `img-src` to `'self' data: https://cdn.jsdelivr.net`.
  Self-hosting/inlining the two SVGs is the proper close-out (tracked in OPEN_ITEMS.md).
- **Highest-assurance option:** vendor all third-party assets and remove the CDN
  entirely — see [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md). This
  also lets you narrow the CSP to `'self'`.
- Rebuild/rescan the container image on base-image CVEs (Trivy/Grype).

## Auditability

- **Application audit log:** none (no server). Change history is **git** — every
  document edit is a reviewable, attributable commit.
- **Access audit:** provided by the hosting layer (nginx/CloudFront/Front Door/
  Static Web Apps access logs). Enable and ship these where audit trails are
  required.

## Classification & DLP (CUI / data handling)

The documents are **templates/reference material**, not live records containing
CUI/PII. Adopters populating templates (e.g. risk register, incident report) with
real data must classify and handle that data per their own Data Classification
Policy (`pol-005`) — but note this static site has **no field to submit such data
to a server**; any data entered into template forms stays in the browser/printout.
Do not repurpose this site to collect regulated data without adding a properly
controlled backend.

## FIPS readiness

No cryptographic module runs in the app (no server, no key management). FIPS
considerations apply only to the **hosting/transport layer**:

- Use **FIPS-validated TLS** at the edge (CloudFront/Front Door/ALB) and, for US
  gov, **FIPS regional endpoints** and the **GovCloud (`aws-us-gov`) / Azure
  Government (`*.usgovcloudapi.net`)** partitions — see the AWS/Azure guides.
- Object-store SSE with FIPS-validated KMS where required.

## Operator responsibilities

1. Enforce HTTPS + HSTS and the security headers/CSP above at the host/CDN.
2. Keep Bootstrap SRI hashes correct on every version bump.
3. Restrict who can publish to the origin (object store / repo) — least
   privilege, prefer CI OIDC roles / managed identity over static keys.
4. Enable access logging and TLS-expiry/uptime monitoring.
5. If gating access, deploy and maintain the auth proxy/CDN control.
6. Rebuild + rescan the container image on dependency/base CVEs.

## Secrets rotation

**Not applicable to the running site** — it holds no secrets. The only secrets in
the picture belong to the **deploy pipeline** (cloud deploy identity). Prefer
**short-lived, keyless** credentials (GitHub OIDC → cloud role / managed
identity); if a static key is unavoidable, rotate on the provider's schedule and
store it in a secrets manager, never in the repo.

## Reporting

Report a suspected vulnerability (e.g. a tampered CDN asset, a missing header on
the production host, or an XSS in branding handling) via the contact channels on
the parent portfolio (`../contact.html`). Include the URL, browser, and steps to
reproduce. Because the site is static and public, there is no data-breach
exposure of stored records — triage focuses on integrity of the served content
and the hosting configuration.
