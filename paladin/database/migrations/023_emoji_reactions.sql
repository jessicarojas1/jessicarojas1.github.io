-- ============================================================================
-- Migration 023 — Emoji reactions
-- Extend the single "like" to multiple emoji reactions: add an emoji column and
-- make uniqueness per (entity, user, emoji) so a user can add several distinct
-- reactions. Existing likes become 👍. Idempotent.
-- ============================================================================

ALTER TABLE reactions ADD COLUMN IF NOT EXISTS emoji VARCHAR(8) NOT NULL DEFAULT '👍';

-- Replace the old one-like-per-user uniqueness with per-emoji uniqueness.
ALTER TABLE reactions DROP CONSTRAINT IF EXISTS reactions_entity_type_entity_id_user_id_key;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'reactions_uniq_emoji') THEN
        ALTER TABLE reactions ADD CONSTRAINT reactions_uniq_emoji
            UNIQUE (entity_type, entity_id, user_id, emoji);
    END IF;
END $$;
