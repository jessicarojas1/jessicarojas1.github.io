-- Remove the compliance packages that were seeded automatically during install.
-- Users should start with an empty package list and import/create their own.

-- audit_items FK → compliance_objectives has no CASCADE; must delete first
DELETE FROM aegis.audit_items
WHERE objective_id IN (
    SELECT co.id FROM aegis.compliance_objectives co
    JOIN aegis.compliance_domains cd ON co.domain_id = cd.id
    WHERE cd.package_id IN (SELECT id FROM aegis.compliance_packages WHERE imported_by IS NULL)
);

UPDATE aegis.audits SET package_id = NULL
WHERE package_id IN (SELECT id FROM aegis.compliance_packages WHERE imported_by IS NULL);

DELETE FROM aegis.audit_schedules
WHERE package_id IN (SELECT id FROM aegis.compliance_packages WHERE imported_by IS NULL);

-- compliance_domains/objectives cascade automatically via ON DELETE CASCADE
DELETE FROM aegis.compliance_packages WHERE imported_by IS NULL;
