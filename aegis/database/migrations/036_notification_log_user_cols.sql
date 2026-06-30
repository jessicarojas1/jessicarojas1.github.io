-- 036_notification_log_user_cols.sql
-- Reconcile notification_log with scripts/send_notifications.php, which throttles
-- and audits per user/entity. Older installs created notification_log without
-- user_id / entity_type, so the notifier's alreadyNotified()/logNotification()
-- helpers failed with "column user_id does not exist". Idempotent.
ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS user_id     INTEGER;
ALTER TABLE notification_log ADD COLUMN IF NOT EXISTS entity_type VARCHAR(100);
