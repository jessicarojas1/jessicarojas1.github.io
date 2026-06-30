-- Migration 033: Finding ↔ Risk traceability (GRC Phase 2)
-- Links audit findings to the risks they cause / indicate / are mitigated by.
-- Must run AFTER 016 (audit_findings) and after the core schema (risks, users)
-- so the foreign keys resolve. Tenant-isolated via the same permissive
-- tenant_isolation RLS pattern as migration 029 (inert while the GUC is unset).
SET search_path TO aegis;

CREATE TABLE IF NOT EXISTS aegis.finding_risk_links (
    id                SERIAL PRIMARY KEY,
    finding_id        INTEGER NOT NULL REFERENCES aegis.audit_findings(id) ON DELETE CASCADE,
    risk_id           INTEGER NOT NULL REFERENCES aegis.risks(id) ON DELETE CASCADE,
    relationship_type VARCHAR(30) NOT NULL DEFAULT 'related',
    notes             TEXT,
    created_by        INTEGER REFERENCES aegis.users(id),
    created_at        TIMESTAMP NOT NULL DEFAULT NOW(),
    tenant_id         BIGINT NOT NULL DEFAULT 1,
    UNIQUE (finding_id, risk_id)
);
CREATE INDEX IF NOT EXISTS idx_frl_finding ON aegis.finding_risk_links (finding_id);
CREATE INDEX IF NOT EXISTS idx_frl_risk    ON aegis.finding_risk_links (risk_id);

ALTER TABLE aegis.finding_risk_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE aegis.finding_risk_links FORCE  ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON aegis.finding_risk_links;
CREATE POLICY tenant_isolation ON aegis.finding_risk_links
    USING (
        NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
        OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint)
    WITH CHECK (
        NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
        OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint);
