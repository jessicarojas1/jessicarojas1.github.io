# Teacher Hub — Security Guide

This guide describes the real security posture of Teacher Hub **as it is today**,
including honest gaps. It does not claim controls the code lacks.

## Summary / threat model

Teacher Hub is a **static, client-side site with no backend, no server-side auth,
and no data transmission.** That removes whole classes of risk (no server to
breach, no database to exfiltrate, no credentials to steal) but concentrates the
remaining risk in two places:

1. **The browser** — it holds **FERPA-relevant student data** unencrypted in
   `localStorage`, often on a **shared classroom device**.
2. **The delivery path** — third-party CDN assets (Bootstrap/Icons) and the
   **absence of a Content-Security-Policy**, plus heavy use of **inline event
   handlers**, which weaken defense-in-depth against XSS/injection.

## Identity & authentication

**None — and by design.** There is no login, no session, no user accounts, no
password, no API key. Anyone with access to the device/URL can use the app. This
is acceptable for a single-teacher tool on a controlled device but means:

- **Device access = data access.** Physical/OS-level access control (screen lock,
  managed device, teacher-only login on the OS) is the real authentication layer.
- If multi-user separation is ever required, gate the static site at the edge
  (basic-auth / `oauth2-proxy` bound to the school IdP — see
  [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md)).

## Authorization

**Not applicable** — no roles, no permissions, no RBAC. Every feature is available
to whoever opens the page. There is no privilege model to enforce.

## Data protection

### What data exists
`localStorage` keys hold **student PII / education records**: names, grades
(`gb_grades`, `gb_assignments`), behavior (`behavior_data`, `pbis_data`),
communication logs (`comm_log`), **IEP notes (`iep_notes`)**, seating
(`seating_data`), reading levels (`student_levels`), anecdotal notes
(`anecdotal_notes`), and roster (`teacher_settings`). These are **FERPA-relevant
education records.**

### In transit
- The site is served over HTTPS (host/edge responsibility). Enforce HSTS.
- **No application data is transmitted** — there is no server, API, telemetry, or
  analytics. Student data **never leaves the browser**. This is the strongest
  privacy property of the design: no third party, including the site author,
  receives any classroom data.

### At rest
- `localStorage` is **unencrypted** and readable by anyone with access to the
  browser profile / device, and by any script running on the origin.
- **Suggested handling on shared devices:** use a managed/teacher-only OS login;
  enable full-disk encryption on the device; lock the screen; consider a
  dedicated browser profile; clear site data before decommissioning a device
  (accepting that this destroys the data — export first, see
  [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).
- **Key management:** none — there are no keys because there is no encryption or
  server. Do not store secrets in the app; any value in client JS is public.

## Auditability

**No audit log / trail.** The app records no access or change history. The only
observability is the **static host's access logs** (nginx/CloudFront/Front Door)
which show file requests, not in-app actions, and the browser DevTools. If audit
is required, it must be provided at the hosting layer, not the app.

## Classification & DLP

- **Data classification:** student education records — treat as **sensitive PII /
  FERPA-protected**. IEP notes are especially sensitive (special-education
  records).
- **DLP posture:** because nothing is transmitted, network DLP has little to
  inspect. The real DLP concern is the **device**: an unlocked shared device
  exposes all stored records. Enforce device-level DLP/MDM controls.
- The Gradebook **CSV export** (`gradebook.csv`) is an intentional data-egress
  path controlled by the teacher — handle exported files under district data
  policy (store on approved, access-controlled storage).

## FIPS readiness

- The app performs **no cryptography**, so there is no app-level FIPS surface. TLS
  is provided by the host; on AWS GovCloud / Azure Government use **FIPS
  endpoints** and FIPS-validated TLS at the edge
  ([../deployments/AWS.md](../deployments/AWS.md),
  [../deployments/AZURE.md](../deployments/AZURE.md)).

## Known security gaps (honest register)

These are real, verified against the code (`teacher/index.html`,
`teacher/branding.js`). Remediation is tracked in [../OPEN_ITEMS.md](../OPEN_ITEMS.md).

| # | Gap | Evidence | Suggested action |
|---|-----|----------|------------------|
| 1 | **No Content-Security-Policy** | No CSP `<meta>` in `index.html` (siblings cmmc2/cmmi ship one; teacher does not) | Add a CSP — first at the **edge** as a response header (see deployment guides), then a `<meta>`; ultimately a strict CSP without `'unsafe-inline'`. |
| 2 | **Heavy inline event handlers** | ~**109 `onclick`**, **16 `onchange`**, **3 `oninput`** (e.g. `onclick="switchTab(...)"`, `showStd(...)`, `showMgmt(...)`, `showRes(...)`, `showProg(...)`) + inline `<script>`/`<style>` | Externalize to `data-*` attributes wired via `addEventListener` (as `branding.js` already does), then the CSP can drop `'unsafe-inline'`. Repo rule is "no inline event handlers." |
| 3 | **Missing SRI on Bootstrap Icons** | Bootstrap **CSS** (line 8) and **JS bundle** (line 422) have `integrity=`; the Bootstrap **Icons CSS** (line 9) does **not** | Add a `sha384` `integrity` + `crossorigin="anonymous"` to the icons `<link>`, or vendor the assets. |
| 4 | **Third-party CDN dependency** | Bootstrap 5.3.3 + Icons 1.11.3 loaded from `cdn.jsdelivr.net` | Pin (done) + SRI (partial) mitigate tampering; **vendor** for offline/filtered networks ([../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)). |
| 5 | **Unencrypted student PII in `localStorage` on shared devices** | 20 app keys incl. `iep_notes`, `gb_grades`, roster | Device-level controls (MDM, disk encryption, screen lock, teacher-only OS login); teach export + clear-before-decommission. |
| 6 | **No app-level data export/import or backup** | Only the Gradebook CSV export exists | Add "Export all data / Import backup (JSON)"; advise weekly CSV export (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)). |

> **What the app does well (verified):** `branding.js` has **no inline handlers**
> (uses `addEventListener`), **sanitizes** logo URLs to `http(s)://` /
> `data:image/...` only, **HTML-escapes** user-supplied strings before injecting
> them, and **degrades gracefully** on a broken logo. Vendor versions are
> **pinned**, and Bootstrap CSS/JS carry **SRI**. No secrets are present in the
> repo. No data is transmitted anywhere.

## Operator responsibilities

- Serve over **HTTPS** with **HSTS** and add the **security headers + CSP** at the
  edge (the HTML doesn't ship them) — see each deployment guide's §6.
- Keep the **deploy identity** least-privilege and OIDC-based (no static keys).
- Keep Bootstrap/Icons **pinned** and update the **SRI** hash on any bump.
- On shared devices, enforce device access controls and educate teachers on the
  data-loss / privacy properties.
- Do not add analytics/trackers or any code that transmits classroom data — it
  would break the "no PII leaves the browser" guarantee.

## Secrets rotation

**Not applicable to the app** (no secrets). Rotate only **deploy-pipeline
credentials**, and prefer OIDC federation so there is nothing static to rotate
([../deployments/AWS.md](../deployments/AWS.md) §4,
[../deployments/AZURE.md](../deployments/AZURE.md) §4). Rotate TLS certs via the
host's managed renewal.

## Reporting (vulnerability disclosure)

- **Contact:** the portfolio owner — Jessica Rojas, `cuevasjessica40@yahoo.com`
  (or open an issue on the `jessicarojas1.github.io` repository).
- **Scope:** this is a personal-portfolio classroom tool with no backend; the most
  useful reports concern XSS via the branding/settings inputs, CSP bypasses once a
  CSP is added, or dependency (Bootstrap/Icons) supply-chain issues.
- **SLA (best-effort):** acknowledge within a reasonable window; because there is
  no server or user data collection, there is no incident-response obligation for
  server-side breaches — the realistic remediation for most findings is a code
  change committed to the repo and redeployed.
- Please report privately before public disclosure for anything affecting student
  data handling.
