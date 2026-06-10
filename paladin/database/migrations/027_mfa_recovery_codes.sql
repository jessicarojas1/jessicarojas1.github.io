-- ============================================================================
-- Migration 027 — MFA recovery codes
-- One-time backup codes a user can use to complete two-factor login if they
-- lose their authenticator. Codes are stored hashed (argon2id); each is single
-- use (marked when consumed). Idempotent.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash  TEXT NOT NULL,
    used_at    TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_mfa_recovery_user ON mfa_recovery_codes(user_id) WHERE used_at IS NULL;
