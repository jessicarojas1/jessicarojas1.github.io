-- ── Awareness Training Programs ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS aegis.awareness_programs (
    id            SERIAL PRIMARY KEY,
    title         VARCHAR(255) NOT NULL,
    description   TEXT,
    content_type  VARCHAR(30)  DEFAULT 'document',
    content_body  TEXT,
    content_url   VARCHAR(500),
    due_date      DATE,
    status        VARCHAR(20)  DEFAULT 'active',
    created_by    INTEGER REFERENCES aegis.users(id),
    created_at    TIMESTAMP    DEFAULT NOW(),
    updated_at    TIMESTAMP    DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.awareness_assignments (
    id              SERIAL PRIMARY KEY,
    program_id      INTEGER NOT NULL REFERENCES aegis.awareness_programs(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES aegis.users(id) ON DELETE CASCADE,
    completed       BOOLEAN   DEFAULT FALSE,
    completed_at    TIMESTAMP,
    notes           TEXT,
    UNIQUE(program_id, user_id)
);

-- ── Account Reviews (Access Certification) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS aegis.account_reviews (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    scope        TEXT,
    reviewer_id  INTEGER REFERENCES aegis.users(id),
    status       VARCHAR(20)  DEFAULT 'pending',
    due_date     DATE,
    completed_at TIMESTAMP,
    created_by   INTEGER REFERENCES aegis.users(id),
    created_at   TIMESTAMP    DEFAULT NOW(),
    updated_at   TIMESTAMP    DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.account_review_items (
    id             SERIAL PRIMARY KEY,
    review_id      INTEGER NOT NULL REFERENCES aegis.account_reviews(id) ON DELETE CASCADE,
    account_name   VARCHAR(255) NOT NULL,
    user_full_name VARCHAR(255),
    system_name    VARCHAR(255),
    access_level   VARCHAR(100),
    decision       VARCHAR(20)  DEFAULT 'pending',
    decision_notes TEXT,
    reviewed_at    TIMESTAMP,
    reviewed_by    INTEGER REFERENCES aegis.users(id)
);

-- ── Data Privacy (RoPA + Data Subject Requests) ───────────────────────────────
CREATE TABLE IF NOT EXISTS aegis.privacy_records (
    id                      SERIAL PRIMARY KEY,
    name                    VARCHAR(255) NOT NULL,
    description             TEXT,
    controller_name         VARCHAR(255),
    processor_name          VARCHAR(255),
    purpose                 TEXT,
    legal_basis             VARCHAR(50),
    data_subject_categories TEXT,
    data_categories         TEXT,
    recipients              TEXT,
    third_country_transfers TEXT,
    retention_period        VARCHAR(255),
    security_measures       TEXT,
    dpia_required           BOOLEAN   DEFAULT FALSE,
    dpia_completed          BOOLEAN   DEFAULT FALSE,
    dpia_date               DATE,
    status                  VARCHAR(20) DEFAULT 'active',
    created_by              INTEGER REFERENCES aegis.users(id),
    created_at              TIMESTAMP   DEFAULT NOW(),
    updated_at              TIMESTAMP   DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.data_subject_requests (
    id           SERIAL PRIMARY KEY,
    request_type VARCHAR(50),
    subject_name VARCHAR(255),
    subject_email VARCHAR(255),
    description  TEXT,
    status       VARCHAR(20) DEFAULT 'open',
    due_date     DATE,
    completed_at TIMESTAMP,
    assigned_to  INTEGER REFERENCES aegis.users(id),
    notes        TEXT,
    created_at   TIMESTAMP   DEFAULT NOW(),
    updated_at   TIMESTAMP   DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_awareness_assignments_program ON aegis.awareness_assignments(program_id);
CREATE INDEX IF NOT EXISTS idx_account_review_items_review   ON aegis.account_review_items(review_id);
CREATE INDEX IF NOT EXISTS idx_privacy_records_status        ON aegis.privacy_records(status);
CREATE INDEX IF NOT EXISTS idx_dsr_status                    ON aegis.data_subject_requests(status);
