-- 019: SSP extended fields — company info, approval, certification, boundary, environment, inventory

-- Company / organization info
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS company_name             VARCHAR(255);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS duns_number              VARCHAR(50);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS cage_code                VARCHAR(50);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS framework                VARCHAR(255);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS assessment_scope         TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS presentation_mode        VARCHAR(50)  DEFAULT 'standard';

-- Approval information
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS approval_status          VARCHAR(50);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS approval_date            DATE;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS approval_notes           TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS approver_name            VARCHAR(255);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS approver_title           VARCHAR(255);

-- Digital certification of affirming official
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS certifying_official_name  VARCHAR(255);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS certifying_official_title VARCHAR(255);
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS certification_date        DATE;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS certification_statement   TEXT;

-- Extended system boundary
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS boundary_description      TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS info_systems_apps         TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS endpoints_user_devices    TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS servers_storage           TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS physical_security         TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS access_control_auth       TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS general_system_purpose    TEXT;

-- System environment
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS topology_description      TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS maintenance_info          TEXT;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS system_details            TEXT;

-- Complex list data stored as JSONB arrays
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS team_contacts             JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS contracts                 JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS data_inventory            JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS hardware_inventory        JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS software_inventory        JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS network_devices           JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS other_connected_systems   JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS server_inventory          JSONB DEFAULT '[]'::jsonb;
ALTER TABLE aegis.ssp_plans ADD COLUMN IF NOT EXISTS user_device_types         JSONB DEFAULT '[]'::jsonb;
