-- ─────────────────────────────────────────────────────────────────────────────
-- 005 Enterprise Risk Management enhancements
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Risk score history (auto-logged on every update) ──────────────────────────
CREATE TABLE IF NOT EXISTS risk_score_history (
    id                   SERIAL PRIMARY KEY,
    risk_id              INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    likelihood           INTEGER NOT NULL,
    impact               INTEGER NOT NULL,
    score                INTEGER NOT NULL,
    residual_likelihood  INTEGER,
    residual_impact      INTEGER,
    residual_score       INTEGER,
    status               VARCHAR(50),
    treatment_strategies JSONB,
    changed_by           INTEGER REFERENCES users(id),
    note                 TEXT,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rsh_risk    ON risk_score_history(risk_id, created_at);
CREATE INDEX IF NOT EXISTS idx_rsh_created ON risk_score_history(created_at);

-- ── Risk → Compliance control linkage ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_control_links (
    id                         SERIAL PRIMARY KEY,
    risk_id                    INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    control_implementation_id  INTEGER NOT NULL REFERENCES control_implementations(id) ON DELETE CASCADE,
    effectiveness              VARCHAR(20) NOT NULL DEFAULT 'partial'
                               CHECK (effectiveness IN ('none','partial','substantial','full')),
    notes                      TEXT,
    created_by                 INTEGER REFERENCES users(id),
    created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(risk_id, control_implementation_id)
);
CREATE INDEX IF NOT EXISTS idx_rcl_risk    ON risk_control_links(risk_id);
CREATE INDEX IF NOT EXISTS idx_rcl_control ON risk_control_links(control_implementation_id);

-- ── Causal / related risk links ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_related_links (
    id          SERIAL PRIMARY KEY,
    risk_id     INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    related_id  INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    link_type   VARCHAR(50) NOT NULL DEFAULT 'related'
                CHECK (link_type IN ('related','causes','caused_by','aggregates')),
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(risk_id, related_id)
);
CREATE INDEX IF NOT EXISTS idx_rrl_risk    ON risk_related_links(risk_id);
CREATE INDEX IF NOT EXISTS idx_rrl_related ON risk_related_links(related_id);

-- ── Enterprise columns on risks ───────────────────────────────────────────────

-- Velocity: how quickly could this risk materialise (1=slow/years, 5=immediate)
ALTER TABLE risks ADD COLUMN IF NOT EXISTS velocity INTEGER DEFAULT 3
    CHECK (velocity BETWEEN 1 AND 5);

-- Time horizon to occurrence
ALTER TABLE risks ADD COLUMN IF NOT EXISTS proximity VARCHAR(20) DEFAULT 'medium_term'
    CHECK (proximity IN ('immediate','short_term','medium_term','long_term'));

-- Financial exposure scenarios
ALTER TABLE risks ADD COLUMN IF NOT EXISTS financial_min    DECIMAL(15,2);
ALTER TABLE risks ADD COLUMN IF NOT EXISTS financial_likely DECIMAL(15,2);
ALTER TABLE risks ADD COLUMN IF NOT EXISTS financial_max    DECIMAL(15,2);
ALTER TABLE risks ADD COLUMN IF NOT EXISTS financial_currency VARCHAR(3) DEFAULT 'USD';

-- Hierarchy: a risk can roll up under a parent risk
ALTER TABLE risks ADD COLUMN IF NOT EXISTS parent_risk_id INTEGER REFERENCES risks(id);

-- Formal assessment lifecycle separate from operational status
ALTER TABLE risks ADD COLUMN IF NOT EXISTS assessment_status VARCHAR(20) NOT NULL DEFAULT 'draft'
    CHECK (assessment_status IN ('draft','pending_review','approved'));
ALTER TABLE risks ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES users(id);
ALTER TABLE risks ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP;
ALTER TABLE risks ADD COLUMN IF NOT EXISTS review_notes TEXT;

-- Risk source taxonomy
ALTER TABLE risks ADD COLUMN IF NOT EXISTS risk_source VARCHAR(50)
    CHECK (risk_source IN ('strategic','operational','financial','compliance','technology',
                           'reputational','external','people','project') OR risk_source IS NULL);

-- Confidence in the assessment (low/medium/high)
ALTER TABLE risks ADD COLUMN IF NOT EXISTS confidence VARCHAR(10) DEFAULT 'medium'
    CHECK (confidence IN ('low','medium','high'));

-- Target residual (desired end-state after full treatment)
ALTER TABLE risks ADD COLUMN IF NOT EXISTS target_likelihood INTEGER
    CHECK (target_likelihood BETWEEN 1 AND 5);
ALTER TABLE risks ADD COLUMN IF NOT EXISTS target_impact INTEGER
    CHECK (target_impact BETWEEN 1 AND 5);

CREATE INDEX IF NOT EXISTS idx_risks_parent     ON risks(parent_risk_id)
    WHERE parent_risk_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_risks_assessment ON risks(assessment_status);
CREATE INDEX IF NOT EXISTS idx_risks_source     ON risks(risk_source)
    WHERE risk_source IS NOT NULL;

-- Seed initial score history from current risk data (one-time backfill)
INSERT INTO risk_score_history
    (risk_id, likelihood, impact, score,
     residual_likelihood, residual_impact, residual_score,
     status, treatment_strategies, created_at)
SELECT id, likelihood, impact, inherent_score,
       residual_likelihood, residual_impact, residual_score,
       status, treatment_strategies, created_at
FROM risks
ON CONFLICT DO NOTHING;
