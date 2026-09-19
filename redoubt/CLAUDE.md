# CLAUDE.md — REDOUBT (GMRE Program Portal Framework)

Project guidance for REDOUBT. Follows the repo-wide rules in the parent
`../CLAUDE.md` (security/UI audits, standard doc set, schema currency, push to
main). This file adds REDOUBT-specific context.

## What this is

A reusable, secure **program portal framework**: a PHP presentation/orchestration
layer over Microsoft 365/SharePoint (document system of record) and Entra ID
(identity). One framework, many isolated program instances. Current phase:
**Discovery** — only the Discovery Package (`app/Views/discovery.php`) and a
deployable scaffold exist. Do **not** claim MVP features exist; see `OPEN_ITEMS.md`.

## Standing rules for this project

- **CUI/ITAR are in scope; runtime is portable Kubernetes.** The same image
  deploys to any authorized CUI boundary — **on-prem**, **Azure Government
  (AKS)**, or **AWS GovCloud (EKS)**; identity + documents are always in Microsoft
  365 / Azure **GCC High**. Use GCC High endpoints only
  (`login.microsoftonline.us`, `graph.microsoft.us`, `*.sharepoint.us`) — never
  the commercial `.com` equivalents. Enforce US-person access gating on
  export-controlled zones and FIPS-validated crypto. Prefer secretless workload
  identity (Entra Workload Identity on AKS; Entra federated credentials on EKS/
  on-prem OIDC). Commercial Render is for the non-CUI discovery microsite only.
- **Customer/COR access is contractually authorized** — the Customer/COR library
  is in MVP; nothing reaches that zone without an explicit customer-approved gate.
- **Discovery before features.** Do not build portal modules until the blocking
  decisions in `OPEN_ITEMS.md` (which authorized enclave/ATO, export-control gate,
  GCC High tenant, customer access) are resolved.
- **Authoritative systems of record.** The portal aggregates/links; it must not
  become a shadow copy of documents, financials, jobs, or contracts data. Store
  only portal-owned content, metadata/references, config, and audit.
- **Security is server-side.** Authorize every request and every search result on
  the server against (program × company × role × zone). UI hiding is cosmetic only.
- **Isolation model.** Per-program SharePoint site collection; per-company library +
  Entra group. Never per-item SharePoint ACLs (sprawl anti-pattern).
- **PHP conventions** mirror the AEGIS family: `Security::` (nonce, `h()`, CSRF),
  parameterized queries only, secrets from env/secrets manager (never commit `.env`).
- **CSP compliance:** no inline event handlers; inline `<script>`/`<style>` carry the
  per-request nonce (see `public/index.php`).
- **Keep the doc set current.** Update `docs/`, `deployments/`, `README.md`,
  `OPEN_ITEMS.md`, and `database/schema.sql` in the same change as any feature,
  migration, or config change. `schema.sql` must always reflect the full combined schema.
- **Deploy targets:** must stay Docker-deployable; production runs on **Kubernetes**
  (on-prem / Azure Gov AKS / AWS GovCloud EKS) — one image, no per-target forks;
  single hardened Linux host is the fallback. Render is kept working for the
  non-CUI discovery microsite only — never point it at controlled data.

## Roadmap gates (see the package §22 and `OPEN_ITEMS.md`)

Phase 0 Discovery (now) → Phase 1 MVP (auth, home, announcements, docs, customer
library, task orders, jobs, directory, search, content admin) → Phase 2 workflow
automation → Phase 3 replication platform → Phase 4 integrations → Phase 5 analytics/AI.

## Per-milestone checklist (repo standard)

- [ ] Security & compliance audit (CSP, XSS, CSRF, SQLi, authz, uploads, redirects)
- [ ] UI consistency check (handlers, modals, filters, empty states, dark mode, breadcrumbs)
- [ ] `database/schema.sql` updated to reflect all migrations
- [ ] Standard doc set present & current (`deployments/` ×6, `docs/` ×4, root files)
- [ ] Fix all findings before marking the milestone complete
