-- ============================================================================
-- Migration 013 — Acknowledgement Campaigns (QMS)
-- Targeted, due-dated read-and-understand campaigns for controlled documents,
-- layered on top of the existing document_acknowledgements receipts.
-- Idempotent. Part of the combined schema (see database/schema.sql).
-- ============================================================================

CREATE TABLE IF NOT EXISTS ack_campaigns (
    id             SERIAL PRIMARY KEY,
    document_id    INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    revision       VARCHAR(20) NOT NULL,
    title          VARCHAR(200) NOT NULL,
    audience       VARCHAR(20) NOT NULL DEFAULT 'all',  -- all | role | space
    audience_value VARCHAR(64),                          -- role_key or space id (as text)
    due_date       DATE,
    status         VARCHAR(20) NOT NULL DEFAULT 'active',-- active | closed
    created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ack_campaigns_doc ON ack_campaigns(document_id);

-- The resolved audience, captured at launch so the denominator is stable.
CREATE TABLE IF NOT EXISTS ack_campaign_targets (
    id          SERIAL PRIMARY KEY,
    campaign_id INTEGER NOT NULL REFERENCES ack_campaigns(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notified_at TIMESTAMP,
    UNIQUE (campaign_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_ack_targets_campaign ON ack_campaign_targets(campaign_id);
