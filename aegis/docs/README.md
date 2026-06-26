# AEGIS GRC — Engineering Documentation

Complete engineering documentation for the **AEGIS Governance, Risk & Compliance
(GRC) platform** — reverse-engineered from the source so a new team can
understand, deploy, operate, maintain, and extend the application without the
original developers.

> **Stack at a glance:** PHP 8.3 · server-rendered MVC (no framework, zero
> Composer/npm runtime deps) · PostgreSQL · vanilla JS/CSS front-end · Docker on
> Render. ~51 controllers, ~407 routes, 122 tables, 32 migrations.

## Start here
- **New to the app?** → [`EXECUTIVE_OVERVIEW.md`](EXECUTIVE_OVERVIEW.md)
- **Deploying it?** → [`DEPLOYMENT.md`](DEPLOYMENT.md)
- **Changing code?** → [`ARCHITECTURE.md`](ARCHITECTURE.md) + [`MODULES.md`](MODULES.md)
- **Is it healthy?** → [`VALIDATION_REPORT.md`](VALIDATION_REPORT.md)

## Documentation suite

| # | Document | Covers |
|---|---|---|
| 1 | [EXECUTIVE_OVERVIEW.md](EXECUTIVE_OVERVIEW.md) | What AEGIS is, target users, module catalog, differentiators |
| 2 | [ARCHITECTURE.md](ARCHITECTURE.md) | Layers, request lifecycle, runtime, folder structure, sessions, RLS (Mermaid) |
| 3 | [MODULES.md](MODULES.md) | Per-module spec: purpose, features, I/O, business & validation rules, permissions, edge cases (44 modules) |
| 4 | [DEPENDENCIES.md](DEPENDENCIES.md) | Runtime/platform deps, external services, internal dependency map |
| 5 | [DATABASE.md](DATABASE.md) | Tables, relationships, indexes, constraints, migrations, audit chain, ERD (Mermaid) |
| 6 | [API.md](API.md) | Full route & endpoint catalog (method/URL/action/authz), JSON endpoints |
| 7 | [WORKFLOWS.md](WORKFLOWS.md) | Login/MFA, risk lifecycle, approvals, imports, cron — with Mermaid flowcharts |
| 8 | [REQUIREMENTS.md](REQUIREMENTS.md) | ~80 inferred functional requirements with acceptance criteria |
| 9 | [USER_STORIES.md](USER_STORIES.md) | User-story catalog by module & role |
| 10 | [UI_GUIDE.md](UI_GUIDE.md) | Page shell, design tokens, reusable components, the `data-*` event model, a11y |
| 11 | [SECURITY.md](SECURITY.md) | Security architecture, permission matrix, validation matrix |
| 12 | [DEPLOYMENT.md](DEPLOYMENT.md) | Build, deploy, CI/CD, env-var reference, runbook |
| 13 | [TECH_DEBT.md](TECH_DEBT.md) | Debt register, known limitations, future enhancements |
| 14 | [VALIDATION_REPORT.md](VALIDATION_REPORT.md) | Second-pass functional validation results & fixes |
| 15 | [MODERNIZATION.md](MODERNIZATION.md) | Prior modernization pass (changes, verified findings, backlog) |

## Companion files (in `../database/` and repo root)
- `database/schema.sql` — base tables + seed (idempotent; **not** the full schema)
- `database/schema.full.sql` — auto-generated complete 122-table schema (pg_dump)
- `install.php` — **authoritative** installer (schema + migrations + seed)
- `render.yaml`, `Dockerfile`, `docker/Dockerfile.hardened`, `startup.sh` — deploy
- `CLAUDE.md` — project conventions/standards (CSP, RBAC, schema, UI rules)

## Maintenance notes
- Docs are reverse-engineered from code at a point in time; when you change a
  module, update its section in `MODULES.md` and any affected matrix/diagram.
- After adding a migration, regenerate `database/schema.full.sql` (recipe in its header).
- The CI analyzers (`scripts/check_*.php`) encode several conventions documented
  here — keep them green.
</content>
