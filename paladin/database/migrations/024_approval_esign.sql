-- ============================================================================
-- Migration 024 — E-signatures on approval steps
-- Capture a tamper-evident electronic signature (typed name + hash + timestamp)
-- when an approver decides a step. When the 'require_esignature' setting is on,
-- the signature is mandatory and must match the approver's account name.
-- Idempotent.
-- ============================================================================

ALTER TABLE approval_request_steps ADD COLUMN IF NOT EXISTS signature_name VARCHAR(160);
ALTER TABLE approval_request_steps ADD COLUMN IF NOT EXISTS signature_hash VARCHAR(64);
ALTER TABLE approval_request_steps ADD COLUMN IF NOT EXISTS signed_at       TIMESTAMP;
