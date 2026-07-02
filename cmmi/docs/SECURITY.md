# Security — CMMI v2.0 Practice Reference

## Threat model in one line

A static, client-side reference with **no backend, no auth, no database, and no
data transmission**. The realistic attack surface is: (1) tampered third-party
CDN assets, (2) HTML/script injection weakening the CSP, and (3) transport
exposure when served without edge hardening. There is no server to compromise
and no user data to exfiltrate from a server — annotations never leave the
browser.

## Identity & authentication

**None at the application layer** — there is no login, session, cookie, or
account. The only identity that matters is the **deploy pipeline's**: publishing
the artifact should use a CI **OIDC role** (AWS) or **managed identity / OIDC
federated credential** (Azure) with least privilege — never static access keys.
See [../deployments/AWS.md](../deployments/AWS.md) and
[../deployments/AZURE.md](../deployments/AZURE.md).

## Authorization (RBAC / permissions)

**N/A for the site.** The page is fully public and read/annotate-locally for
anyone who loads it; there are no roles or protected actions in the running app.
Access control, if desired, is applied at the edge (e.g. basic-auth /
oauth2-proxy in front of nginx — see
[../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md)),
not in the app.

## Content Security Policy

Enforced via a `<meta http-equiv="Content-Security-Policy">` tag in
`index.html`. Reproduced **exactly**:

```
default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net; worker-src blob:; object-src 'none'; base-uri 'self';
```

Directive-by-directive:

| Directive | Value | Meaning |
|-----------|-------|---------|
| `default-src` | `'self'` | Fallback for unspecified fetch types: same-origin only. **Tighter than the sibling `cmmc2` site**, which allows a top-level `blob:` — this site does not. |
| `script-src` | `'self' https://cdn.jsdelivr.net 'unsafe-inline'` | Scripts from same-origin and jsDelivr (Bootstrap bundle, SheetJS). `'unsafe-inline'` permits the page's inline `<script>` glue and inline event handlers — an **honest limitation** (see below). |
| `style-src` | `'self' https://cdn.jsdelivr.net 'unsafe-inline'` | Styles from same-origin and jsDelivr (Bootstrap CSS, Icons). `'unsafe-inline'` permits the page's inline `<style>` blocks and style attributes. |
| `img-src` | `'self' https: data: blob:` | Images from same-origin, any HTTPS origin, `data:` URLs (uploaded logos), and `blob:` — supports the branding logo (URL or `data:`). |
| `font-src` | `'self' https://cdn.jsdelivr.net` | Fonts from same-origin and jsDelivr (Bootstrap Icons webfont). |
| `connect-src` | `'self' https://cdn.jsdelivr.net` | `fetch`/XHR limited to same-origin and jsDelivr; blocks beaconing to arbitrary hosts. |
| `worker-src` | `blob:` | Web/worker contexts only from `blob:` (SheetJS may spawn one for export); no remote worker scripts. |
| `object-src` | `'none'` | No `<object>`/`<embed>`/`<applet>` — **hardening win**, blocks a legacy plugin/XSS vector. |
| `base-uri` | `'self'` | `<base>` cannot be repointed to an attacker origin — **hardening win** against base-tag injection. |

### Honest limitation — `'unsafe-inline'`

`index.html` and `../cmmidev3.js` rely on inline `<script>`/`<style>` and a few
inline event handlers, so `'unsafe-inline'` must remain for `script-src` and
`style-src`. This weakens the XSS protection the CSP would otherwise provide. The
remediation path (externalize inline code, switch to nonces/hashes, drop
`'unsafe-inline'`) is tracked in [../OPEN_ITEMS.md](../OPEN_ITEMS.md). When the
CSP changes, update this section and
[ARCHITECTURE.md](ARCHITECTURE.md) which copy it verbatim.

## Subresource Integrity (SRI) & CDN/dependency risk

| CDN asset | Version | SRI |
|-----------|---------|-----|
| Bootstrap CSS | 5.3.3 | ✅ `integrity=sha384-…` + `crossorigin` |
| Bootstrap JS bundle | 5.3.3 | ✅ `integrity=sha384-…` + `crossorigin` (hash corrected — was malformed) |
| Bootstrap Icons CSS | 1.11.3 | ✅ `integrity=sha384-XGjxt…` + `crossorigin` |
| SheetJS `xlsx.full.min.js` | **pinned `xlsx@0.18.5`** | ✅ `integrity=sha384-vtjas…` + `crossorigin` |

All four CDN assets now carry SRI. Icons and SheetJS `integrity` + `crossorigin`
were added, SheetJS was pinned from the floating `xlsx` dist tag to `xlsx@0.18.5`,
and the Bootstrap JS bundle `integrity` — which had been **malformed (63 base64
chars → 47 decoded bytes instead of 48) and would have failed SRI enforcement** —
was replaced with the real hash. Hashes were computed with
`openssl dgst -sha384 -binary | openssl base64 -A` from the exact files in the
npm package tarballs (jsDelivr mirrors them byte-for-byte; verified by reproducing
the known-correct Bootstrap CSS hash the same way). See
[../OPEN_ITEMS.md](../OPEN_ITEMS.md). For the strongest posture, vendor all three
locally and drop the CDN from the CSP
([../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)).

## Data protection

- **In transit:** serve over HTTPS with HSTS at the edge. The app makes no
  outbound calls beyond loading CDN assets (constrained by `connect-src`).
- **At rest:** the only stored data is the visitor's `localStorage`
  (annotations, branding, theme) on their own device. Nothing is written to a
  server. The operator holds **no** user data and therefore has no at-rest user
  data to encrypt or breach.
- **No PII leaves the browser.** Practice status, notes, owner, target dates,
  and evidence flags stay in `localStorage`; the Excel export is generated
  client-side and downloaded locally (no upload).

## Auditability

There is no server-side audit trail because there is no server action to audit.
Operationally, **deploy provenance** is the audit record: Git history + CI logs
show who published which artifact. Client-side, CSP violations appear in the
browser console during development.

## Classification & DLP (CUI / data handling)

The shipped content is the **public CMMI v2.0 model reference** (no CUI). Any
sensitivity is introduced by the *user's own annotations*, which remain in their
browser and in whatever JSON snapshot they choose to export. Because nothing is
transmitted, there is no server-side DLP surface; users are responsible for
handling their exported `.xlsx`/JSON per their organization's policy.

## FIPS readiness

The app performs no cryptography. FIPS relevance is limited to the **serving/
deploy** layer: use FIPS-validated TLS endpoints where required — AWS **GovCloud
FIPS** endpoints and Azure **Government** endpoints (`*.usgovcloudapi.net`) are
covered in [../deployments/AWS.md](../deployments/AWS.md) and
[../deployments/AZURE.md](../deployments/AZURE.md).

## Operator responsibilities

- Enforce HTTPS + HSTS and set edge security headers
  (`X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`,
  `X-Frame-Options`/`frame-ancestors`).
- Keep the CSP `<meta>` intact (or tighten it and update these docs).
- Keep CDN pins current (Bootstrap 5.3.3, Bootstrap Icons 1.11.3, SheetJS `xlsx@0.18.5`), re-computing SRI on any bump.
- Use OIDC roles / managed identity for deploys; no static keys committed.
- Ensure every publish includes the parent assets (`../cmmidev3.js` et al.).

## Secrets rotation

**N/A for the app** (it has no secrets). Rotate only the **deploy pipeline
credentials** — and prefer short-lived OIDC tokens so there is nothing static to
rotate. See the AWS/Azure guides.

## Reporting

Report a suspected vulnerability (e.g. an XSS vector in `index.html`/
`../cmmidev3.js`, a tampered dependency, or a misconfigured host) to the
portfolio owner via the contact channels on the main
`jessicarojas1.github.io` site. Include the affected URL/path, reproduction
steps, and browser/console output. As a personal portfolio project there is no
formal SLA; security-relevant reports are triaged ahead of feature work.
