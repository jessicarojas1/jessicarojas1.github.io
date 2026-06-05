-- SSP versioning and authorization signature fields
ALTER TABLE aegis.ssp_plans
  ADD COLUMN IF NOT EXISTS version               VARCHAR(20)  DEFAULT '1.0',
  ADD COLUMN IF NOT EXISTS revision              INTEGER      DEFAULT 0,
  ADD COLUMN IF NOT EXISTS authorizing_signature VARCHAR(255),
  ADD COLUMN IF NOT EXISTS signature_date        DATE,
  ADD COLUMN IF NOT EXISTS network_arch_filename VARCHAR(500),
  ADD COLUMN IF NOT EXISTS network_arch_data     BYTEA,
  ADD COLUMN IF NOT EXISTS data_flow_filename    VARCHAR(500),
  ADD COLUMN IF NOT EXISTS data_flow_data        BYTEA;
