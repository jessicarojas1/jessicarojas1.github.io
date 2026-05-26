-- Risk module enhancements: multi-select treatment strategies, richer status lifecycle

-- Store selected treatment strategies as a JSON array (e.g. ["mitigate","transfer"])
ALTER TABLE risks ADD COLUMN IF NOT EXISTS treatment_strategies JSONB NOT NULL DEFAULT '[]';

-- Migrate any existing single treatment_type values into the new array column
UPDATE risks
SET treatment_strategies = json_build_array(treatment_type)
WHERE treatment_type IS NOT NULL
  AND treatment_strategies = '[]'::jsonb;

-- Extend risk_treatments with a notes field for completion details
ALTER TABLE risk_treatments ADD COLUMN IF NOT EXISTS completion_notes TEXT;

-- Index to quickly list response actions by status
CREATE INDEX IF NOT EXISTS idx_rt_status ON risk_treatments(status);
