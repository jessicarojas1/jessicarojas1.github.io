-- Migration 007: Risk Extensions
-- Adds risk_acceptances, risk bowtie tables, risk_scenarios,
-- and amber/red threshold columns to risk_appetite.

-- ─────────────────────────────────────────────
-- 7.1 Risk Acceptances
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_acceptances (
    id                       SERIAL PRIMARY KEY,
    risk_id                  INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    accepted_by              INTEGER NOT NULL REFERENCES users(id),
    acceptance_reason        TEXT NOT NULL,
    conditions               TEXT,
    valid_until              DATE NOT NULL,
    status                   VARCHAR(20) NOT NULL DEFAULT 'active'
                             CHECK (status IN ('active','expired','revoked','superseded')),
    risk_score_at_acceptance INTEGER,
    risk_level_at_acceptance VARCHAR(20),
    renewal_required         BOOLEAN NOT NULL DEFAULT FALSE,
    renewed_from             INTEGER REFERENCES risk_acceptances(id),
    revoked_by               INTEGER REFERENCES users(id),
    revoked_at               TIMESTAMP,
    revocation_reason        TEXT,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ra_risk_id ON risk_acceptances(risk_id);
CREATE INDEX IF NOT EXISTS idx_ra_status  ON risk_acceptances(status);

-- ─────────────────────────────────────────────
-- 7.2 Risk Bowtie – Causes
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_bowtie_causes (
    id                      SERIAL PRIMARY KEY,
    risk_id                 INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    description             TEXT NOT NULL,
    cause_type              VARCHAR(30) NOT NULL DEFAULT 'threat'
                            CHECK (cause_type IN ('threat','vulnerability','hazard','event')),
    likelihood_contribution VARCHAR(10) NOT NULL DEFAULT 'medium'
                            CHECK (likelihood_contribution IN ('low','medium','high')),
    sort_order              INTEGER NOT NULL DEFAULT 0,
    created_by              INTEGER REFERENCES users(id),
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbc_risk_id ON risk_bowtie_causes(risk_id);

-- ─────────────────────────────────────────────
-- 7.3 Risk Bowtie – Consequences
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_bowtie_consequences (
    id               SERIAL PRIMARY KEY,
    risk_id          INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    description      TEXT NOT NULL,
    consequence_type VARCHAR(30) NOT NULL DEFAULT 'impact'
                     CHECK (consequence_type IN ('financial','operational','reputational','legal','safety','impact')),
    severity         VARCHAR(20) NOT NULL DEFAULT 'medium'
                     CHECK (severity IN ('low','medium','high','critical')),
    sort_order       INTEGER NOT NULL DEFAULT 0,
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbcons_risk_id ON risk_bowtie_consequences(risk_id);

-- ─────────────────────────────────────────────
-- 7.4 Risk Bowtie – Barriers
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_bowtie_barriers (
    id                          SERIAL PRIMARY KEY,
    risk_id                     INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    side                        VARCHAR(10) NOT NULL CHECK (side IN ('left','right')),
    description                 TEXT NOT NULL,
    barrier_type                VARCHAR(30) NOT NULL DEFAULT 'control'
                                CHECK (barrier_type IN ('control','procedure','training','technology','monitoring')),
    effectiveness               VARCHAR(20) NOT NULL DEFAULT 'partial'
                                CHECK (effectiveness IN ('degraded','partial','substantial','full')),
    control_implementation_id   INTEGER REFERENCES control_implementations(id) ON DELETE SET NULL,
    sort_order                  INTEGER NOT NULL DEFAULT 0,
    created_by                  INTEGER REFERENCES users(id),
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbb_risk_id ON risk_bowtie_barriers(risk_id);

-- ─────────────────────────────────────────────
-- 7.5 Risk Scenarios
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_scenarios (
    id                   SERIAL PRIMARY KEY,
    risk_id              INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    name                 VARCHAR(255) NOT NULL,
    description          TEXT,
    scenario_type        VARCHAR(30) NOT NULL DEFAULT 'stress'
                         CHECK (scenario_type IN ('stress','base','optimistic','catastrophic','regulatory')),
    likelihood_multiplier NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    impact_multiplier    NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    scenario_likelihood  INTEGER CHECK (scenario_likelihood BETWEEN 1 AND 5),
    scenario_impact      INTEGER CHECK (scenario_impact BETWEEN 1 AND 5),
    scenario_score       INTEGER,
    financial_impact_est NUMERIC(15,2),
    probability          NUMERIC(5,2),
    assumptions          TEXT,
    created_by           INTEGER REFERENCES users(id),
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rs_risk_id ON risk_scenarios(risk_id);

-- ─────────────────────────────────────────────
-- 7.6 Risk Appetite – heat-map thresholds
-- ─────────────────────────────────────────────
ALTER TABLE risk_appetite
    ADD COLUMN IF NOT EXISTS amber_threshold INTEGER,
    ADD COLUMN IF NOT EXISTS red_threshold   INTEGER;

COMMENT ON COLUMN risk_appetite.amber_threshold IS 'Score at or above which risk shows amber (warning) on heat maps';
COMMENT ON COLUMN risk_appetite.red_threshold   IS 'Score at or above which risk shows red (critical) on heat maps';
