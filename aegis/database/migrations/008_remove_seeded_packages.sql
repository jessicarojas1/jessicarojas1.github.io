-- Remove the compliance packages that were seeded automatically during install.
-- Users should start with an empty package list and import/create their own.
-- Audits that reference these packages are nullified first (no CASCADE on the FK).

UPDATE audits SET package_id = NULL
WHERE package_id IN (SELECT id FROM compliance_packages WHERE imported_by IS NULL);

DELETE FROM audit_schedules
WHERE package_id IN (SELECT id FROM compliance_packages WHERE imported_by IS NULL);

-- compliance_objectives cascade automatically via ON DELETE CASCADE
DELETE FROM compliance_packages WHERE imported_by IS NULL;
