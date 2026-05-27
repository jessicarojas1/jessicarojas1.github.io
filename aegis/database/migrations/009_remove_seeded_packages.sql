-- Remove the compliance packages that were seeded automatically during install.
-- Users should start with an empty package list and import/create their own.
-- Domains are level=1 rows in compliance_objectives (no separate compliance_domains table).

DELETE FROM aegis.audit_items
WHERE objective_id IN (
    SELECT id FROM aegis.compliance_objectives
    WHERE package_id IN (SELECT id FROM aegis.compliance_packages WHERE imported_by IS NULL)
);

UPDATE aegis.audits SET package_id = NULL
WHERE package_id IN (SELECT id FROM aegis.compliance_packages WHERE imported_by IS NULL);

DELETE FROM aegis.audit_schedules
WHERE package_id IN (SELECT id FROM aegis.compliance_packages WHERE imported_by IS NULL);

DELETE FROM aegis.compliance_packages WHERE imported_by IS NULL;
