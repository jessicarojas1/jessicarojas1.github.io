-- Migration 008: User notification preferences
-- Creates the user_notification_prefs table used by the Notifications settings page.

CREATE TABLE IF NOT EXISTS user_notification_prefs (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_type VARCHAR(100) NOT NULL,
    enabled           BOOLEAN NOT NULL DEFAULT TRUE,
    digest_mode       VARCHAR(50) NOT NULL DEFAULT 'immediate'
                          CHECK (digest_mode IN ('immediate','daily','weekly')),
    digest_time       TIME DEFAULT '08:00',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, notification_type)
);

CREATE INDEX IF NOT EXISTS idx_unp_user ON user_notification_prefs(user_id);
