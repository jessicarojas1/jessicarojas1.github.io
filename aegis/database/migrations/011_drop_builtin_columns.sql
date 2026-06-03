-- Drop legacy built-in/paid marker columns; no packages are seeded by default
ALTER TABLE aegis.standards DROP COLUMN IF EXISTS is_builtin;
ALTER TABLE aegis.compliance_packages DROP COLUMN IF EXISTS is_paid;
