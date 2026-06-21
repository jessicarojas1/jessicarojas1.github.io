-- Migration 031 — Multi-tenancy Phase 5: platform admin (cross-tenant) flag
--
-- A "platform admin" is the SaaS operator role that sits ABOVE tenant admins and
-- may switch into a tenant context — fully audited, explicit, and time-boxed
-- (see Auth::switchTenant / exitTenant). It is intentionally NOT a normal
-- module.action permission, because tenant `admin` users bypass the permission
-- check (Auth::can returns true for admin); cross-tenant power must therefore be
-- gated by a dedicated flag that no tenant role grants.
--
-- DEFAULT FALSE: no existing user becomes a platform admin. Idempotent.

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_platform_admin BOOLEAN NOT NULL DEFAULT FALSE;
