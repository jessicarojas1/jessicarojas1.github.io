-- ============================================================================
-- Migration 014 — Document numbering scheme + auto-archive-on-expiry settings
-- Seeds two settings keys (no DDL). Idempotent. See database/schema.sql.
-- ============================================================================

INSERT INTO settings (key, value, type, description) VALUES
    ('auto_archive_on_expiry', '0', 'boolean', 'Auto-archive controlled documents past their expiration date'),
    ('doc_numbering',          '',  'json',    'Controlled-document numbering scheme')
ON CONFLICT (key) DO NOTHING;
