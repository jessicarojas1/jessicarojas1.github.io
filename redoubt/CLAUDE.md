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

- **CUI/ITAR are in scope.** REDOUBT must hold controlled data up to CUI and
  ITAR/export-controlled information. Baseline boundary is GCC High + a Gov-cloud
  (or air-gapped) enclave; enforce US-person access gating on export-controlled
  zones and FIPS-validated crypto. Commercial Render is for non-CUI pilots only.
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
- **Deploy targets:** must stay Docker- and Render-deployable; production/CUI runs in
  an authorized Gov/enclave boundary, not commercial Render.

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
