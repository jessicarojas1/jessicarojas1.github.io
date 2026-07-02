-- Migration 035: Policy/vendor lifecycle + CAPA depth (GRC Phase 4)
-- Idempotent; safe to re-run. Adds:
--   (a) policy expiry date (retired-state handling needs no column — status is
--       a free VARCHAR; expiry alerts need a date),
--   (b) vendor_certifications child table (RLS, like vendor_contracts),
--   (c) CAPA depth — root_cause + preventive_action on issues & audit_findings.
SET search_path TO aegis;

-- ── (a) Policy lifecycle: hard expiry date (review cadence already exists) ──────
ALTER TABLE aegis.policies ADD COLUMN IF NOT EXISTS expires_at DATE;
CREATE INDEX IF NOT EXISTS idx_policies_expires_at ON aegis.policies (expires_at);

-- ── (b) Vendor certification tracking ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aegis.vendor_certifications (
    id                 SERIAL PRIMARY KEY,
    vendor_id          INTEGER NOT NULL REFERENCES aegis.vendors(id) ON DELETE CASCADE,
    certification_type VARCHAR(100) NOT NULL,
    certificate_number VARCHAR(100),
    issuer             VARCHAR(255),
    issued_date        DATE,
    expiry_date        DATE,
    status             VARCHAR(20) NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active','expired','revoked','pending')),
    notes              TEXT,
    owner_id           INTEGER REFERENCES aegis.users(id),
    created_by         INTEGER REFERENCES aegis.users(id),
    created_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    tenant_id          BIGINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_vcert_vendor ON aegis.vendor_certifications (vendor_id);
CREATE INDEX IF NOT EXISTS idx_vcert_expiry ON aegis.vendor_certifications (expiry_date);

ALTER TABLE aegis.vendor_certifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE aegis.vendor_certifications FORCE  ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON aegis.vendor_certifications;
CREATE POLICY tenant_isolation ON aegis.vendor_certifications
    USING (
        NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
        OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint)
    WITH CHECK (
        NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
        OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint);

-- ── (c) CAPA depth: root cause + preventive action ─────────────────────────────
ALTER TABLE aegis.issues          ADD COLUMN IF NOT EXISTS root_cause        TEXT;
ALTER TABLE aegis.issues          ADD COLUMN IF NOT EXISTS preventive_action TEXT;
ALTER TABLE aegis.audit_findings  ADD COLUMN IF NOT EXISTS root_cause        TEXT;
ALTER TABLE aegis.audit_findings  ADD COLUMN IF NOT EXISTS preventive_action TEXT;

-- Widen the issues status CHECK to include the runtime states (pending_review,
-- wont_fix) plus the CAPA reopen state. audit_findings has no DB-level status
-- CHECK (validated in code), so 'reopened' needs no constraint change there.
ALTER TABLE aegis.issues DROP CONSTRAINT IF EXISTS issues_status_check;
ALTER TABLE aegis.issues ADD CONSTRAINT issues_status_check
    CHECK (status IN ('open','in_progress','pending_review','resolved','closed','wont_fix','reopened'));
