# Multi-Tenant Isolation Model — Design

**Status:** Design (NOT an implementation). A full `org_id` rollout requires product decisions and touches every query in a target app; this document specifies the model and the phased path so each app can adopt it deliberately.
**Drivers:** Enterprise Security Review §38.5 ("Tenant isolation model (org_id everywhere) before any multi-tenant SaaS"), H8 (compliance-copilot RLS has no tenant scoping), §16/§19 (authorization & database reviews).
**Reference implementation:** **aegis already has this** — its phased, GUC-bridged Postgres RLS model is the canonical pattern and is cited throughout.

---

## 1. The model in one paragraph

Every tenant-owned row carries an `org_id` (aegis calls it `tenant_id`). The app authenticates a user, determines their **active org**, and **binds it to the database session** via a Postgres GUC (`SET app.org_id = …` / `set_config('app.org_id', …)`). Postgres **Row-Level Security (RLS)** policies on every tenant table enforce `using (org_id = current_setting('app.org_id')::bigint)`, so the database itself denies cross-tenant reads/writes — even if an app-layer filter is forgotten. The org is carried in the JWT/session as an `org` claim. Rollout is **phased and inert-first** (columns → stamping → enforce) to avoid a big-bang migration.

---

## 2. Reference: how aegis already does it

aegis is the in-repo proof of this exact pattern. Cite it; do not re-derive it.

### 2.1 Schema & membership
- `tenants` registry table + a default tenant (id=1), introduced inert: `aegis/database/migrations/026_tenancy_foundation.sql`.
- `tenant_id BIGINT NOT NULL DEFAULT 1` added to **78 tenant-owned tables** (26 primary + 52 child) across migrations `027`, `029`. The authoritative list lives in `Database::tenantTables()` (`aegis/src/Database.php:104-131`).
- Cross-tenant admin is a **flag, not a role**: `users.is_platform_admin` (`aegis/database/migrations/031_platform_admin.sql`).

### 2.2 The GUC bridge (app → Postgres session variable)
`aegis/src/Database.php:77-94`:
```php
// set the session GUC, parameterized (no injection), validates id >= 1
public static function setTenant($id)  { ... "SELECT set_config('aegis.tenant_id', ?, false)" ... }
public static function currentTenant() { ... "SELECT current_setting('aegis.tenant_id', true)" ... }
public static function clearTenant()   { ... "SELECT set_config('aegis.tenant_id', '', false)" ... }
```
Write-path auto-stamping is separate and pure: `Database::useTenant()` / `applyTenantStamp()` (`aegis/src/Database.php:142-165`) stamp `tenant_id` on INSERT when the caller hasn't.

### 2.3 Per-request binding
`aegis/index.php:1170-1187` — on every authenticated request:
```php
if (Auth::check()) {
    $activeTenantId = Auth::activeTenantId();
    Database::useTenant($activeTenantId);  // write-path stamping (PHP-side)
    Database::setTenant($activeTenantId);  // read-path GUC binding (RLS)
}
```
Active org resolution honors time-boxed platform-admin tenant switching (`Auth::activeTenantId()` / `switchTenant()` / `exitTenant()`, `aegis/src/Auth.php:262-326`), and every switch is written to the audit chain (`platform.tenant_switch`).

### 2.4 The RLS predicate
`aegis/database/migrations/028_tenancy_rls.sql` enables `FORCE ROW LEVEL SECURITY` with a **permissive-while-unset** policy:
```sql
CREATE POLICY tenant_isolation ON <table>
  USING (
    NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
    OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint
  );
```
The `IS NULL` branch makes the policy inert (permissive) until the GUC is bound — this is what lets the migration ship safely before every code path binds a tenant. The **enforce** step (deferred) drops that branch to become deny-by-default.

This is the GUC-bridge + phased-RLS pattern the baseline mandates for every new multi-tenant app.

---

## 3. Canonical schema for a new adopter

```sql
-- 1. Org registry + membership
create table orgs (
  id          bigint generated always as identity primary key,
  name        text not null,
  created_at  timestamptz not null default now()
);

create table org_members (
  org_id   bigint not null references orgs(id),
  user_id  bigint not null references users(id),
  role     text   not null,            -- org-scoped role
  primary key (org_id, user_id)
);

-- 2. Inert column on every tenant-owned table
alter table controls   add column if not exists org_id bigint not null default 1;
alter table evidence   add column if not exists org_id bigint not null default 1;
alter table poam_items add column if not exists org_id bigint not null default 1;
create index if not exists idx_controls_org on controls(org_id);  -- one per table

-- 3. RLS predicate (Postgres / self-hosted)
alter table controls enable row level security;
alter table controls force row level security;     -- applies even to the table owner
create policy tenant_isolation on controls
  using (org_id = current_setting('app.org_id')::bigint)
  with check (org_id = current_setting('app.org_id')::bigint);
```

**Supabase variant** (compliance-copilot): the org comes from the JWT, not a GUC, so the predicate is:
```sql
using (org_id = (auth.jwt() ->> 'org_id')::bigint)
with check (org_id = (auth.jwt() ->> 'org_id')::bigint)
```
which replaces today's `using (true)`.

---

## 4. JWT / session org claim

- Mint an `org` (or `org_id`) claim into the access token at login, alongside the existing `role`/`sub` claims (apex already carries an `org` claim — `apex/src/Auth.php:24-39`; sentinel mints jti'd access tokens — `sentinel-qms/backend/app/core/security.py`).
- The claim is **authoritative for binding**: the app reads it and `SET app.org_id` (self-hosted) or lets Supabase RLS read `auth.jwt()->>'org_id'`.
- **Switching orgs mints a new token** (or, for platform admins, a time-boxed switch as in aegis). The claim must be validated against `org_members` at issue time — never trust a client-supplied org.
- Carry `tenant_id`/`org_id` into the central audit event (see `CENTRAL_AUDIT.md` §4.4) for cross-tenant correlation and detection of `tenant_switch` events.

---

## 5. The Postgres RLS predicate pattern (self-hosted apps)

Bind once per request/connection, after auth, before any tenant query:
```sql
SELECT set_config('app.org_id', $1, false);   -- $1 = authenticated org id, parameterized
```
Then every policy is simply `using (org_id = current_setting('app.org_id')::bigint)`. Notes:
- Use `set_config(..., false)` (session scope) on a per-request connection, or `true` (transaction scope) when using a shared pool — match aegis's choice to your pooling model.
- `FORCE ROW LEVEL SECURITY` so the app's own DB role cannot bypass RLS.
- The GUC must be **parameterized** (never string-concatenated) — aegis does this at `Database.php:77-82`.
- Background jobs / cron / CLI paths MUST bind a tenant too, or run as a designated bypass role — this is the main reason the **enforce** phase is deferred until all paths are audited.

---

## 6. Migration / backfill strategy (mirrors aegis's phased PRs)

Roll out per app in **inert-first** phases so nothing breaks until the final flip:

| Phase | What | Inert? | aegis analog |
|---|---|---|---|
| **P0 — Foundation** | Add `orgs` + `org_members`; seed default org (id=1). | Yes | mig `026` |
| **P1 — Columns (inert)** | Add `org_id NOT NULL DEFAULT 1` + index to every tenant table. Nothing reads it yet. | Yes | mig `027` |
| **P2 — Stamping (inert)** | App stamps `org_id` on every INSERT from the active org (auto-stamp helper). Reads still global. | Yes | `applyTenantStamp` |
| **P3 — Backfill** | Assign historical rows to their correct org (default org, or a data-driven mapping). One-shot, reversible. | Yes | — |
| **P4 — RLS permissive-while-unset** | Enable `FORCE RLS` with the `IS NULL OR …` predicate; bind the GUC per request. Behaves identically until every path binds. | Yes | mig `028`/`029` |
| **P5 — Enforce (deny-by-default)** | Drop the `IS NULL` branch; unbound sessions now see nothing. Requires all cron/CLI/jobs to bind first. | **No — the flip** | deferred in aegis (gated on SaaS decision) |

Each phase is its own PR; P0–P4 are safe to ship continuously, P5 is a deliberate cutover after full path coverage and load testing.

---

## 7. Per-app applicability assessment

| App | Multi-tenant? | Current state | Next steps |
|---|---|---|---|
| **aegis** | **Yes** | **Reference implementation.** GUC bridge + 78-table `tenant_id` + permissive-while-unset RLS live (mig `026`–`029`, `031`); platform-admin switching audited. **At P4.** | Decide on SaaS → execute **P5 enforce** (drop the `IS NULL` branch); ensure all CLI/cron bind a tenant first. |
| **compliance-copilot** | **Yes (broken)** | **H8.** RLS enabled but every policy is `using (true)` and there is **no `org_id`** on `controls`/`evidence`/`poam_items`/`app_settings` (`supabase/schema.sql:77-109`). Writes currently go via the service-role key server-side, which masks the gap. | Full P0→P5: add `orgs`/`org_members` + `org_id` columns; mint `org_id` JWT claim; rewrite each policy to `using (org_id = (auth.jwt()->>'org_id')::bigint)`; backfill; remove blanket service-role writes or scope them. **Highest-priority adopter.** |
| **paladin** | **Partial — space-scoped, not org-scoped** | Strong **object-level** isolation via `space_members` + `SpaceAccess::canView/Contribute/Manage` (`paladin/src/SpaceAccess.php:41-62`). This is per-space, not per-tenant; a single deployment = one org today. | If offered as multi-org SaaS, layer `org_id` above spaces (P0–P5) so spaces nest under an org. Otherwise: keep space-scoping, no org work needed. |
| **sentinel-qms** | **Single-tenant per deployment** | Granular RBAC + record sharing (`app/core/iam.py`), but no `org_id`. Deployed one-org-per-instance. | Only if consolidating to shared-instance SaaS: adopt P0–P5 with the GUC bridge (SQLAlchemy `SET app.org_id` per request). Otherwise N/A. |
| **citadel** | **Single-tenant / per-deployment** | Scanner backend; users belong to one instance. | N/A unless offered as shared SaaS. |
| **apex** | **Claim present, no enforcement** | JWT already carries an `org` claim (`apex/src/Auth.php:24-39`) but no `org_id` columns or RLS. | If it becomes a real multi-tenant API: wire the existing `org` claim → `SET app.org_id` + RLS (P1–P5). |
| **aeromarkup** | **Single-tenant** | No auth at all today (Addendum A.1) — fix auth first; tenancy is downstream. | Add authn/authz per `SECURITY_BASELINE.md` first; only then consider org scoping. |

---

## 8. Concrete next steps (priority order)

1. **compliance-copilot (closes H8)** — the only app where the gap is actively exploitable in the intended multi-tenant product. Execute P0→P5 with Supabase-flavored policies (`auth.jwt()->>'org_id'`). Replace every `using (true)`.
2. **aegis P5 enforce** — turn the existing permissive RLS into deny-by-default once the SaaS decision is made and all non-request paths (CLI/cron) bind a tenant. Smallest change with the biggest assurance gain because everything else is already built.
3. **Bake the pattern into the baseline** — any **new** multi-tenant app starts at P0 with the GUC bridge + `FORCE RLS`; this is now an acceptance item in `SECURITY_BASELINE.md` §5.
4. **Defer** sentinel/citadel/apex/aeromarkup org work until a shared-instance SaaS decision exists; the design above is ready when they need it.

---

## 9. Status

| Item | Status |
|---|---|
| Model, schema, GUC predicate, JWT claim, phased migration | **Design — ready to implement** |
| aegis P0–P4 | **Already implemented** (cited reference) |
| aegis P5 enforce | Design-only — **pending product/SaaS decision** |
| compliance-copilot full rollout | Design-only — **pending product decision** (but H8 fix is the recommended first build) |
| sentinel/citadel/apex/aeromarkup | Design-only — **N/A until shared-SaaS decision** |
