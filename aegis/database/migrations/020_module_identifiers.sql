-- Migration 020: Add system-generated human-readable identifiers to all modules
-- Each module gets a unique sequential ID (e.g. AUD-0001, CHG-0001, etc.)
-- Existing records are unaffected (identifier will be NULL until regenerated).

-- Audits
ALTER TABLE audits ADD COLUMN IF NOT EXISTS audit_number VARCHAR(20) UNIQUE;

-- Change Requests
ALTER TABLE change_requests ADD COLUMN IF NOT EXISTS change_number VARCHAR(20) UNIQUE;

-- Assets
ALTER TABLE assets ADD COLUMN IF NOT EXISTS asset_code VARCHAR(20) UNIQUE;

-- Threats
ALTER TABLE threats ADD COLUMN IF NOT EXISTS threat_number VARCHAR(20) UNIQUE;

-- BCP Plans
ALTER TABLE bcp_plans ADD COLUMN IF NOT EXISTS plan_code VARCHAR(20) UNIQUE;

-- Treatment Plans
ALTER TABLE treatment_plans ADD COLUMN IF NOT EXISTS plan_code VARCHAR(20) UNIQUE;

-- Vendors: rename prefix from VEN- to VND- (column already exists as vendor_code)
-- No schema change needed; only the controller generation prefix changes.

-- Projects: column already exists as project_code; prefix changes from PROJ- to PRJ-
-- No schema change needed; only the controller generation prefix changes.

-- Policies: policy_number column already exists; auto-generation added to controller.
-- No schema change needed.
