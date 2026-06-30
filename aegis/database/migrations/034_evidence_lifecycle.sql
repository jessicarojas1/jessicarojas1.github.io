-- Migration 034: Evidence lifecycle (GRC Phase 3)
-- Adds an approval/rejection workflow + a tamper-aware download log to the
-- evidence_files store, and surfaces freshness via the existing expires_at.
-- Idempotent; safe to re-run. RLS mirrors the permissive tenant_isolation
-- pattern from migration 029 (inert while the aegis.tenant_id GUC is unset).
SET search_path TO aegis;

-- ── Approval/rejection workflow on evidence_files ──────────────────────────────
ALTER TABLE aegis.evidence_files
    ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) NOT NULL DEFAULT 'pending';
ALTER TABLE aegis.evidence_files
    ADD COLUMN IF NOT EXISTS reviewed_by   INTEGER REFERENCES aegis.users(id);
ALTER TABLE aegis.evidence_files
    ADD COLUMN IF NOT EXISTS reviewed_at   TIMESTAMP;
ALTER TABLE aegis.evidence_files
    ADD COLUMN IF NOT EXISTS review_notes  TEXT;
-- updated_at: lets Database::update() auto-stamp evidence_files on review.
ALTER TABLE aegis.evidence_files
    ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMP NOT NULL DEFAULT NOW();

-- Constrain review_status to the known states (added separately so the ALTER is
-- idempotent — DROP first, then re-add).
ALTER TABLE aegis.evidence_files DROP CONSTRAINT IF EXISTS evidence_files_review_status_chk;
ALTER TABLE aegis.evidence_files
    ADD CONSTRAINT evidence_files_review_status_chk
    CHECK (review_status IN ('pending','approved','rejected'));

CREATE INDEX IF NOT EXISTS idx_ef_review_status ON aegis.evidence_files (review_status);
CREATE INDEX IF NOT EXISTS idx_ef_expires_at    ON aegis.evidence_files (expires_at);

-- ── Download log (who pulled which evidence file, when, from where) ─────────────
CREATE TABLE IF NOT EXISTS aegis.evidence_downloads (
    id          SERIAL PRIMARY KEY,
    evidence_id INTEGER NOT NULL REFERENCES aegis.evidence_files(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES aegis.users(id),
    ip_address  VARCHAR(50),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    tenant_id   BIGINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_ed_evidence ON aegis.evidence_downloads (evidence_id);
CREATE INDEX IF NOT EXISTS idx_ed_user     ON aegis.evidence_downloads (user_id);

ALTER TABLE aegis.evidence_downloads ENABLE ROW LEVEL SECURITY;
ALTER TABLE aegis.evidence_downloads FORCE  ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON aegis.evidence_downloads;
CREATE POLICY tenant_isolation ON aegis.evidence_downloads
    USING (
        NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
        OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint)
    WITH CHECK (
        NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
        OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint);
