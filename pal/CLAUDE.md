# CLAUDE.md — PAL (Enterprise Process Asset Library)

Inherits the repository root `CLAUDE.md` and global rules. Where the root rules name helpers from this repo's other PHP apps (`Security::h()`, `Auth::requirePermission()`, `Database::update()`), apply their intent with Symfony/Vue equivalents: escaping by Twig/Vue interpolation, `#[IsGranted(Permission::X)]` + voters, DBAL with bound parameters.

## Scope and phases
- **DMS only.** Refuse scope creep into training, CAPA, NCR, risk, audit management, acknowledgements, ERP or PM.
- Phase 1 (foundation) is done. Each later phase exits only when its criteria in `docs/PHASE0_ARCHITECTURE_PROPOSAL.md` §16 are verified.

## Invariants that must never regress
- Approved revisions are immutable; approvals bind to a manifest hash (Phase 3+).
- Exactly one effective revision per document per scope, enforced by a DB exclusion constraint (Phase 5).
- Release is idempotent (Phase 5).
- The audit trail is append-only, written in the same transaction as the change, and hash-chained. Never add a code path that updates or deletes `audit_event`.
- All authorization is server-side, applied in queries as well as on single objects. The frontend router guard is UI only.
- The app database role never owns tables (see `deploy/sql/roles.sql`).
- No tokens in the browser (BFF). No `v-html`, no inline scripts/handlers, CSP stays strict.

## Engineering conventions
- Backend modules: `Platform`, `Audit`, `Identity`, `Settings` (and future `Repository`, `Revisions`, `Workflow`, `Publication`, …). Boundaries are enforced by `backend/deptrac.yaml`, so update it when adding a module.
- Read DB values through `App\Platform\Database\Row` (no blind casts). Write SQL with bound parameters only.
- Expected API errors throw `App\Platform\Http\ApiProblem`. Request bodies go through `JsonBody::decode()`.
- Every state-changing endpoint: CSRF header (automatic for `/api`), a permission check, an audit event in the same transaction.
- PHPStan: `max` on `src/`, level 6 on `tests/`. Tests run against real PostgreSQL (`pal_test`, rebuilt from migrations each run).
- Every migration also updates `database/schema.sql`; `SchemaParityTest` fails on drift.
- Frontend colours come from tokens in `frontend/src/css/app.scss`; accent-derived colours come from `src/branding.ts` (keeps WCAG AA for any brand colour).

## Definition of done (every change)
1. Tests added or updated, and the full suites pass (`php vendor/bin/phpunit`; `npm test`).
2. PHPStan, Deptrac, ESLint and vue-tsc are clean; `composer audit` and `npm audit` show no high or critical findings.
3. A security audit and a UI audit were run for features (root CLAUDE.md), and findings were fixed or recorded in `OPEN_ITEMS.md`.
4. The doc set is current in the same change: `deployments/` ×6, `docs/` ×4 (ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY), `README.md`, `OPEN_ITEMS.md`, `Dockerfile`, `render.yaml`, `database/schema.sql`, plus `docs/DECISION_LOG.md`, `docs/REQUIREMENTS_TRACEABILITY.md` and ADRs when decisions change.
5. **Public repo:** never commit real tenant IDs, hostnames, org structure, document content or secrets. Only `.env.example` files are committed.
6. End every session with: work completed, files changed, tests executed, outstanding defects, architectural decisions, recommended next task.
