# PALADIN — Demo Guide

A guided walkthrough that shows PALADIN's wiki, QMS/GRC and enterprise-security
capabilities in ~15 minutes. Pair it with `DEPLOYMENT.md` to stand up the
environment.

## 0. Start

- Local: `docker compose up` (app + PostgreSQL), then open the app URL.
- Sign in as an administrator. If branding is unset, the default mark/name apply.
- (Optional) **Settings → Branding**: set a logo URL, org name and accent colour
  to white-label the demo live.

## 1. Wiki / knowledge base (5 min)

1. **Spaces** → create a space (e.g. "Quality Manual"). Note the space key,
   icon/colour, privacy and **sidebar shortcuts**.
2. **Create a page** from a **blueprint** (meeting notes / how-to / runbook).
   Show the **WYSIWYG editor**, then the **Insert macro** toolbar.
3. Add **dynamic macros**: drop a *Children*, *Page tree* and *Recently updated*
   macro — they render live, access-filtered content.
4. Make an edit and reload mid-edit to show **autosave / draft recovery**.
5. Open **History** → **compare** two versions (audit-friendly diff) →
   **restore**.
6. Show **inline comments**, **@mentions**, **reactions**, **watch/favorite**.
7. **Restrict** a child page and show the parent's restriction **inheritance**.
8. Export the page to **PDF / Word**, and the whole space to **Word / PDF ZIP**.

## 2. QMS / document control (5 min)

1. **Documents** → create a controlled document; show **auto-numbering** and
   **revision**.
2. Walk the lifecycle: **draft → in review → approved → published (effective)**.
3. From effective, show **supersede / retire**, and explain **auto-expiry**
   (set an `expiration_date` in the past and reload the dashboard to watch it
   flip to **expired**).
4. **Approvals** → start a **sequential** (or parallel/consensus) approval;
   approve a step.
5. Enable `require_esignature` and approve again to demonstrate the **21 CFR
   Part 11 e-signature**: exact name **plus password re-authentication**; then
   show the immutable signature (meaning, IP, hash) in the **audit trail**.
6. Launch an **acknowledgement campaign**, acknowledge as a user, and export the
   **completion CSV** (evidence).

## 3. Dashboards, reporting & My Work (2 min)

- **My Work** cockpit: my tasks, approvals awaiting me, doc reviews due, items to
  acknowledge.
- **Reports**: Compliance Metrics, Expiring & Overdue (with inline review
  extension), Acknowledgement coverage, **Content health** (orphans + broken
  links).

## 4. Enterprise admin & security (3 min)

- **Administration**: users, roles & **granular permissions**, retention,
  **webhooks** (show the signed deliveries log with **retry/backoff**), API
  tokens, **audit-log viewer**, system health.
- Talk track for security (all verifiable — see `SECURITY.md`):
  - SSO: **SAML** (signed/encrypted) and **OIDC (PKCE)**, **SCIM** provisioning.
  - **MFA (TOTP)** with hashed recovery codes and brute-force throttling.
  - **CSP nonces**, CSRF, parameterised SQL, **object-level access (anti-IDOR)**.
  - **SSRF-guarded** outbound webhooks; **CSV-injection-safe** exports.
  - **Hash-chained, tamper-evident audit log** (`AUDIT_TRAIL.md`).

## Suggested seed data

Use the installer's demo/seed data (see `DEPLOYMENT.md`) so the spaces,
documents, approvals and users above already exist. Reset between demos by
re-running the installer against a fresh database.

## Talking points by audience

- **Quality/Regulatory**: lifecycle states, Part 11 e-signatures, audit trail,
  acknowledgement evidence, review/expiry automation.
- **Security/IT**: SSO/SCIM, MFA, RBAC + object-level access, SSRF/CSV/XSS
  defences, CSP, audit integrity.
- **Knowledge teams**: spaces, macros, versioning/restore, comments/mentions,
  exports, content-health.
