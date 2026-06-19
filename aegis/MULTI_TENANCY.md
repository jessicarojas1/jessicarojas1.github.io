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

1. **Foundation** — add `tenants` table; add `tenant_id` (nullable) to all
   tenant-owned tables; backfill existing rows to a default tenant; add indexes.
2. **Write path** — stamp `tenant_id` on every INSERT (centralize in
   `Database::insert`); add `Database::setTenant()` from the authenticated user's
   tenant; resolve tenant at login/SSO.
3. **Read path** — add `WHERE tenant_id` to queries (or rely on RLS); add tests
   proving cross-tenant reads return nothing.
4. **Enforce** — make `tenant_id NOT NULL`; `ENABLE`/`FORCE ROW LEVEL SECURITY`
   with the isolation policy on every table; run the runtime app as a role that
   is **subject to** RLS (not the owner/superuser, which can bypass non-FORCE RLS).
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
