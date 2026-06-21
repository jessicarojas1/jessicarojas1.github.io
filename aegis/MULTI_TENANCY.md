# AEGIS — Multi-Tenancy (Design & Adoption Plan)

> **Status: NOT IMPLEMENTED.** AEGIS is **single-tenant per deployment** — one
> organization per instance/database. This document is the design and a *phased*,
> reviewable plan to add tenant isolation. **Do not** bolt `tenant_id` onto a few
> tables ad-hoc: partial scoping is how cross-tenant IDOR bugs are born. Adopt the
> defense-in-depth model below (app scoping **and** database Row-Level Security).

## Why RLS (not just `WHERE tenant_id = ?`)

Application-layer scoping is necessary but not sufficient — one forgotten
`WHERE` clause leaks another tenant's CUI/PHI. PostgreSQL **Row-Level Security**
enforces isolation in the database itself, so even a missed clause or a SQLi
returns only the current tenant's rows. Use both.

## Model

- A `tenants` table; every tenant-owned row carries `tenant_id`.
- The app sets a per-connection session variable after authentication; RLS
  policies filter on it. Because AEGIS uses a connection per request (no shared
  pooled transaction state across tenants), the GUC is safe to set per request.

```sql
-- Per table (template — see database/tenancy/rls_template.sql):
ALTER TABLE aegis.risks ADD COLUMN tenant_id BIGINT NOT NULL REFERENCES aegis.tenants(id);
ALTER TABLE aegis.risks ENABLE ROW LEVEL SECURITY;
ALTER TABLE aegis.risks FORCE ROW LEVEL SECURITY;   -- applies even to table owner

CREATE POLICY tenant_isolation ON aegis.risks
  USING      (tenant_id = current_setting('aegis.tenant_id')::bigint)
  WITH CHECK (tenant_id = current_setting('aegis.tenant_id')::bigint);
```

App side (set once per request, right after auth):

```php
// Database::setTenant() — set the GUC the RLS policies read. Parameterized via
// set_config to avoid any injection; the runtime DB role cannot bypass FORCE RLS.
Database::query("SELECT set_config('aegis.tenant_id', ?, false)", [(string)$tenantId]);
```

## Phased adoption (each phase shippable + testable)

1. **Foundation** — ✅ **DONE (inert):** `tenants` table + default tenant
   (migration 026), and `Database::setTenant()/currentTenant()/clearTenant()`
   which drive the `aegis.tenant_id` GUC. The helper → RLS isolation path is
   proven end-to-end against a live Postgres in `tests/integration/tenancy_db.php`
   (the `aegis-integration` workflow). Remaining in this phase: add `tenant_id`
   (nullable) to all tenant-owned tables; backfill existing rows to tenant 1;
   add indexes.
2. **Write path** — ✅ **DONE (inert):** `tenant_id` (DEFAULT 1) added to the 26
   primary entity tables (migration 027); `Database::insert()` auto-stamps it from
   the per-request tenant context (`Database::useTenant()`), bound in `index.php`
   from the authenticated session; tenant resolved at login + SSO. Proven against
   a live Postgres in `tests/integration/tenancy_db.php`. Remaining: detail/child
   tables (e.g. `poam_milestones`, `policy_versions`) before Phase 4.
3. **Read path** — ✅ **DONE (inert):** `ENABLE`/`FORCE ROW LEVEL SECURITY` +
   a `tenant_isolation` policy on all 26 tenant-owned tables (migration 028),
   relying on RLS rather than hand-written `WHERE tenant_id` (one forgotten clause
   is how leaks happen). The policy is **permissive-fallback**: when the
   `aegis.tenant_id` GUC is unset/empty (single-tenant deployments, CLI, cron, and
   any request before auth) all rows are visible, so this stays inert; once a
   request binds a tenant via `Database::setTenant()` — now wired into the request
   lifecycle in `index.php` alongside `useTenant()` — reads **and** writes are
   isolated in the database itself. The cast uses `NULLIF(setting,'')::bigint` so
   an unset/empty GUC can never raise `22P02` regardless of OR evaluation order.
   Proven against a live Postgres in `tests/integration/tenancy_db.php`: cross-tenant
   reads return nothing, a cross-tenant write is rejected by `WITH CHECK`, an
   unbound GUC is permissive, and every tenant table has the policy (coverage
   check). Remaining: child/detail tables (e.g. `poam_milestones`, `policy_versions`).
4. **Enforce** — make `tenant_id NOT NULL` (already the case) and **drop the
   permissive `NULLIF(...) IS NULL` branch** so an unset GUC denies all rows
   (deny-by-default); ensure the runtime app connects as a role that is **subject
   to** RLS (not the owner/superuser, which can bypass non-FORCE RLS) — the
   `aegis_app` least-privilege role already exists and is proven subject to the
   policy in CI.
5. **Admin/cross-tenant** — a separate, audited "platform admin" path with an
   explicit, logged tenant-switch; never an implicit bypass.

## Hard rules

- New tenant-owned table ⇒ add `tenant_id`, an index, and the RLS policy in the
  **same** migration. A CI check should fail if a tenant table lacks a policy.
- The runtime DB role must be **subject to** `FORCE ROW LEVEL SECURITY`.
- Never expose a tenant selector to non-platform-admins; derive tenant from the
  authenticated session only.
- Cross-tenant access (support/admin) is an audited, time-boxed elevation.

## Until then

Run **one organization per instance** with its own database and credentials.
This is an explicit, documented boundary — acceptable for dedicated/on-prem DIB
deployments; required to change only if AEGIS is offered as shared SaaS.
