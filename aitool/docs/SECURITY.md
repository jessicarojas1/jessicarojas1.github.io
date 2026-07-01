# Security — AI Tool Evaluation Framework (`aitool`)

## Security posture summary

`aitool` is a **static, client-side site with no backend, no database, no server-side
auth, and no upload endpoint**. This eliminates entire vulnerability classes (SQLi,
server-side RCE, session hijacking, stored server XSS, insecure deserialization on a
server). The residual security surface is: the **content delivery chain** (CDN/SRI,
transport, headers/CSP), **client-side input handling** (branding), and the honest
acknowledgment that **the site enforces no access control** on its own.

## Identity & authentication

- **The site has no real authentication.** The "Login" modal and role badges are a
  **client-side RBAC demo** (`../roles.js` / `../users.js`), with a session that clears
  when the tab closes. It does not gate content — anyone with the URL can read every
  document.
- **If access must be restricted,** put an identity-aware layer in front of the static
  origin: AWS CloudFront + Cognito/Lambda@Edge or signed URLs, Azure Front Door +
  Entra ID / Static Web Apps auth, oauth2-proxy on a Linux/k8s deployment, or Cloudflare
  Access. Authentication is an **edge/hosting responsibility**, not the app's.
- **Deploy identity** (the only real identity in the system) uses **CI OIDC → cloud
  role / managed identity**, short-lived and least-privilege. No static keys.

## Authorization (RBAC / permissions)

- No server-side authorization exists. The demo roles do not restrict any file.
- Authorization, where required, = the fronting proxy's policy (who may reach the site)
  plus object-store/bucket policy (who may publish). The deploy role should be scoped to
  publish + cache-invalidate only.

## Data protection

### In transit
- Serve **only over HTTPS**; redirect HTTP → HTTPS; enable **HSTS**.
- TLS 1.2 minimum, prefer TLS 1.3; use managed certs (ACM / Key Vault / Let's Encrypt).
- Bootstrap is fetched over HTTPS from `cdn.jsdelivr.net`.

### At rest
- The origin store (S3 / Blob / disk) holds only public, non-sensitive static files;
  enable bucket encryption anyway (SSE-S3/KMS, Azure SSE) as a default.
- **Client data at rest = the user's browser `localStorage`** (`bsTheme`,
  `aitool.branding.v1`, tracker store). It is not encrypted by the app and never leaves
  the browser. Users should treat exported tracker JSON as their own sensitive record.

### Key management
- No application encryption keys (nothing to encrypt server-side).
- TLS keys and any deploy secrets live in the cloud provider's managed store
  (ACM/Key Vault/Secrets Manager) — never in the repo.

## Content-integrity & supply chain

- **Subresource Integrity (SRI):** Bootstrap CSS and JS are pinned with
  `integrity="sha384-…"` + `crossorigin="anonymous"`, so a tampered CDN response is
  rejected by the browser. **Keep hashes in sync on any version bump.**
- **Pinned version:** Bootstrap `5.3.3` is explicit in the URL (no floating `latest`).
- **CDN risk:** availability and privacy depend on jsDelivr; for high-assurance or
  air-gapped use, **vendor** Bootstrap locally (`../deployments/AIRGAPPED.md`).
- **Same-origin scripts** (`../script.js`, `../roles.js`, `../users.js`) load without
  SRI; lower risk (same origin) but consider SRI/fingerprinting — tracked in
  `../OPEN_ITEMS.md`.

## Content Security Policy & client-side XSS

- CSP is emitted by the **hosting layer** (`nginx.conf`, `render.yaml`, and each
  `deployments/*` guide), scoped to `'self'` + `cdn.jsdelivr.net`, with
  `img-src 'self' data:` (for uploaded logo `data:` URLs), `frame-ancestors 'none'`,
  and `base-uri 'self'`.
- ⚠️ **Honest gap:** the pages contain an inline `<head>` theme-bootstrap `<script>` and
  five `onclick="window.print()"` handlers, so the current `script-src` must include
  `'unsafe-inline'`. Remediation (move to `data-*` handlers + external init, or CSP
  hashes) is tracked in `../OPEN_ITEMS.md`. Until then, XSS risk is mitigated by there
  being **no user-generated content rendered from a server and no untrusted HTML sink**.
- **Branding input is sanitized in `branding.js`:** `esc()` HTML-escapes strings,
  `sanitizeLogoUrl()` allow-lists only `http(s)://` and `data:image/...` URLs (blocking
  `javascript:`/other schemes), and `isHex()` validates the accent color. A broken logo
  URL degrades to the text mark via an `error` handler.

## Auditability

- The app writes **no audit log** (no server). Auditability of *who viewed what* comes
  from the fronting layer: CDN/S3/Blob access logs, or the identity-aware proxy's logs.
- Change auditability of the content itself = **git history** (commits, authorship,
  review). Treat the repo as the audit trail for document changes.

## Classification & DLP (CUI / data handling)

- The site publishes **evaluation framework content**, intended to be broadly readable
  within the organization. Do not commit CUI into the HTML.
- **No CUI or PII is transmitted or stored server-side.** Tracker/questionnaire data and
  any "attached" files stay in the browser (client-side `FileReader`/labels; nothing is
  uploaded). This keeps the tool outside the CUI processing boundary.
- If users record CUI in the tracker, that CUI lives only in their local browser — advise
  handling exported JSON per the org's CUI marking/storage rules, and consider the
  fronting-proxy access controls above.

## FIPS readiness

- No cryptography is performed by the application. **FIPS applies to the hosting/edge
  layer**: use FIPS-validated TLS endpoints where required — AWS **GovCloud FIPS
  endpoints** (`*-fips.*.amazonaws.com`, partition `aws-us-gov`) and Azure Government
  (`*.usgovcloudapi.net`) — see `../deployments/AWS.md` and `../deployments/AZURE.md`.

## Operator responsibilities

- Enforce HTTPS + HSTS and keep TLS certs valid/rotated.
- Set and monitor security headers / CSP at the edge; verify post-deploy (`curl -I`).
- Keep Bootstrap version + SRI hashes current; watch for library advisories.
- Manage the deploy identity (OIDC role / managed identity) least-privilege; rotate/
  review trust policy.
- Decide whether content requires access gating and configure the fronting proxy.
- Keep this doc set and `../OPEN_ITEMS.md` accurate as the site changes.

## Secrets rotation

- **No application secrets to rotate.** Rotation applies to: TLS certificates (managed,
  automatic where possible) and CI/deploy credentials — prefer OIDC (short-lived, nothing
  to rotate) over any static key; if a static key must exist, rotate on a schedule and
  on personnel changes.

## Reporting (vulnerability disclosure)

- Report suspected issues to the site owner: **cuevasjessica40@yahoo.com**.
- Include affected page/URL, browser + version, reproduction steps, and any console/CSP
  output. For CDN/edge issues, note the hosting target.
- Target acknowledgment within a few business days; fixes are shipped by redeploying the
  static content (see `DISASTER_RECOVERY.md` restore/redeploy runbook).
