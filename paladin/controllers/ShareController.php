<?php
declare(strict_types=1);

/**
 * ShareController — "Share this page": notify selected users with a stable,
 * move-proof permalink to a page/document/blog post and an optional message.
 * The link itself is the canonical ID-based URL, which never changes when
 * content is moved or renamed.
 */
class ShareController {

    private const PATH = ['page' => '/pages/', 'document' => '/documents/', 'blog' => '/blog/', 'process' => '/processes/'];
    private const TABLE = ['page' => 'pages', 'document' => 'documents', 'blog' => 'blog_posts', 'process' => 'processes'];
    private const VIEW_PERM = ['page' => 'page.view', 'document' => 'document.view', 'blog' => 'page.view', 'process' => 'process.view'];

    public function send(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $type = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $id   = (int)($_POST['entity_id'] ?? 0);
        if (!isset(self::PATH[$type]) || $id <= 0) { http_response_code(400); return; }

        $perm = self::VIEW_PERM[$type] ?? null;
        if ($perm && !Auth::can($perm)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        $titleCol = $type === 'process' ? 'name' : 'title';
        $row = Database::fetchOne("SELECT {$titleCol} AS t FROM " . self::TABLE[$type] . " WHERE id = ?", [$id]);
        if (!$row) { http_response_code(404); return; }

        $link = self::PATH[$type] . $id;
        $message = Security::sanitizeInput($_POST['message'] ?? '');
        $recipients = $_POST['recipients'] ?? [];
        if (!is_array($recipients)) $recipients = [$recipients];
        $who = Auth::user()['name'] ?? 'Someone';
        $sent = 0;
        foreach ($recipients as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0 || $rid === Auth::id()) continue;
            if (!Database::fetchOne("SELECT 1 FROM users WHERE id = ? AND is_active = TRUE", [$rid])) continue;
            Database::insert('alerts', [
                'user_id'  => $rid,
                'title'    => $who . ' shared “' . mb_substr((string)$row['t'], 0, 120) . '”',
                'body'     => $message !== '' ? $message : ('Take a look at this ' . $type . '.'),
                'severity' => 'info',
                'link'     => $link,
            ]);
            $sent++;
        }
        Auth::log('share_' . $type, $type, $id, ['recipients' => $sent]);
        $_SESSION['flash_success'] = $sent > 0 ? "Shared with {$sent} ".($sent===1?'person':'people').'.' : 'Link ready to copy.';
        header('Location: ' . $link);
    }
}
