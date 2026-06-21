-- Migration 026 — Multi-tenancy foundation (Phase 1 of MULTI_TENANCY.md)
--
-- Creates the tenant registry and a default tenant. This is INERT: no existing
-- table carries tenant_id yet and no RLS is enabled, so behavior is unchanged.
-- It establishes the building blocks (registry + the aegis.tenant_id GUC set by
-- Database::setTenant()) that the per-table RLS rollout (Phase 4) builds on.
-- Idempotent.

CREATE TABLE IF NOT EXISTS tenants (
    id         BIGSERIAL PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    slug       VARCHAR(100) UNIQUE NOT NULL,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- The single existing organization maps to tenant 1 (the backfill target when
-- tenant_id columns are later added to existing tables).
INSERT INTO tenants (id, name, slug)
VALUES (1, 'Default Organization', 'default')
ON CONFLICT (id) DO NOTHING;

-- Keep the identity sequence ahead of the explicitly-seeded id=1.
SELECT setval(pg_get_serial_sequence('tenants', 'id'),
              GREATEST((SELECT MAX(id) FROM tenants), 1));
