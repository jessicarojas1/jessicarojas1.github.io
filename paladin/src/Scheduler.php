<?php
declare(strict_types=1);

/**
 * Scheduler — lightweight, cron-free background sweeps triggered opportunistically
 * on common requests (dashboard / page list). Currently: auto-publish pages whose
 * scheduled_publish_at has passed. Designed to be cheap (indexed lookup) and safe
 * to call on every request — it no-ops when nothing is due.
 */
final class Scheduler {

    /**
     * Publish any draft/in-review page whose scheduled time has arrived.
     * @return int number of pages published this sweep
     */
    public static function runDuePages(): int {
        try {
            $due = Database::fetchAll(
                "SELECT id, space_id, title, owner_id, created_by, current_version
                 FROM pages
                 WHERE scheduled_publish_at IS NOT NULL
                   AND scheduled_publish_at <= NOW()
                   AND status <> 'published'
                   AND deleted_at IS NULL
                 ORDER BY scheduled_publish_at
                 LIMIT 50"
            );
        } catch (\Throwable) {
            return 0; // column may not exist yet (pre-migration) — stay silent
        }

        $count = 0;
        foreach ($due as $p) {
            $id = (int)$p['id'];
            $newVersion = (int)$p['current_version'] + 1;
            try {
                Database::query(
                    "UPDATE pages
                     SET status='published', published_at=NOW(), scheduled_publish_at=NULL,
                         current_version=?, updated_at=NOW()
                     WHERE id=?",
                    [$newVersion, $id]
                );
                Database::insert('page_versions', [
                    'page_id'     => $id,
                    'version'     => $newVersion,
                    'title'       => $p['title'],
                    'body'        => (string)(Database::fetchOne("SELECT body FROM pages WHERE id=?", [$id])['body'] ?? ''),
                    'change_note' => 'Auto-published (scheduled)',
                    'edited_by'   => $p['owner_id'] !== null ? (int)$p['owner_id'] : (int)$p['created_by'],
                ]);
                self::notifyWatchers($id, (int)$p['space_id'], (string)$p['title']);
                if (class_exists('Webhook')) {
                    Webhook::dispatch('page.published', ['id' => $id, 'version' => $newVersion, 'scheduled' => true]);
                }
                if (class_exists('Auth')) {
                    Auth::log('auto_publish_page', 'pages', $id, ['version' => $newVersion]);
                }
                $count++;
            } catch (\Throwable) {
                // Skip a problematic row; keep the sweep resilient.
            }
        }
        return $count;
    }

    /**
     * Auto-expire effective (published) controlled documents whose expiration
     * date has passed. Idempotent and resilient; logs a document_transition and
     * dispatches document.expired for each.
     * @return int number of documents expired this sweep
     */
    public static function runExpiredDocuments(): int {
        try {
            $due = Database::fetchAll(
                "SELECT id, document_code, title
                 FROM documents
                 WHERE status = 'published'
                   AND expiration_date IS NOT NULL
                   AND expiration_date < CURRENT_DATE
                 ORDER BY expiration_date
                 LIMIT 100"
            );
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($due as $d) {
            $id = (int)$d['id'];
            try {
                Database::query("UPDATE documents SET status='expired', updated_at=NOW() WHERE id=? AND status='published'", [$id]);
                if (class_exists('Auth')) {
                    Auth::logSystem('document_auto_expired', 'documents', $id);
                }
                if (class_exists('Webhook')) {
                    Webhook::dispatch('document.expired', [
                        'id' => $id, 'code' => $d['document_code'], 'title' => $d['title'], 'auto' => true,
                    ]);
                }
                $count++;
            } catch (\Throwable) {
                // keep the sweep resilient
            }
        }
        return $count;
    }

    /** Best-effort alert to page + space watchers that a scheduled page went live. */
    private static function notifyWatchers(int $pageId, int $spaceId, string $title): void {
        try {
            $watchers = Database::fetchAll(
                "SELECT DISTINCT user_id FROM watches
                 WHERE (entity_type='page' AND entity_id=?)
                    OR (entity_type='space' AND entity_id=?)",
                [$pageId, $spaceId]
            );
            foreach ($watchers as $w) {
                Database::insert('alerts', [
                    'user_id'  => (int)$w['user_id'],
                    'title'    => 'Scheduled page published',
                    'body'     => '"' . $title . '" went live on its scheduled date.',
                    'severity' => 'info',
                    'link'     => '/pages/' . $pageId,
                    'is_read'  => 'f',
                ]);
            }
        } catch (\Throwable) { /* best effort */ }
    }
}
