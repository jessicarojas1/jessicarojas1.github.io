-- Database::update() always stamps updated_at; ensure approval_request_steps has it.
ALTER TABLE approval_request_steps ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW();
