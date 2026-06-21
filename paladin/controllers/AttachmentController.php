<?php
declare(strict_types=1);

/**
 * AttachmentController — serves and removes files attached to entities
 * (pages, documents, …). Access is gated by the parent entity's permissions.
 */
class AttachmentController {

    /** entity_type => [viewPerm, editPerm] */
    private const PERMS = [
        'page'     => ['page.view', 'page.edit'],
        'document' => ['document.view', 'document.edit'],
        'process'  => ['process.view', 'process.edit'],
    ];

    private function load(int $id): ?array {
        return Database::fetchOne("SELECT * FROM attachments WHERE id = ?", [$id]);
    }

    /**
     * Object-level access check (prevents IDOR): a global view/edit permission
     * is not enough — the requester must also be able to see the parent entity,
     * honouring private-space membership and per-page restrictions. Mirrors the
     * gating the entity's own view route applies. Unknown entity types fall back
     * to the global permission already checked by the caller.
     */
    private function canAccessParent(array $att): bool {
        $eid = (int)$att['entity_id'];
        switch ($att['entity_type']) {
            case 'page':
                $page = Database::fetchOne(
                    "SELECT p.*, s.is_private AS space_private
                     FROM pages p JOIN spaces s ON s.id = p.space_id
                     WHERE p.id = ? AND p.deleted_at IS NULL", [$eid]
                );
                if (!$page) { return false; }
                if (!SpaceAccess::canView(['id' => (int)$page['space_id'], 'is_private' => $page['space_private']])) { return false; }
                return PageAccess::canView($page);
            case 'document':
                $doc = Database::fetchOne(
                    "SELECT d.id, d.space_id, s.is_private AS space_private
                     FROM documents d LEFT JOIN spaces s ON s.id = d.space_id WHERE d.id = ?", [$eid]
                );
                if (!$doc) { return false; }
                return $doc['space_id'] === null
                    || SpaceAccess::canView(['id' => (int)$doc['space_id'], 'is_private' => $doc['space_private']]);
            case 'process':
                $proc = Database::fetchOne(
                    "SELECT p.id, p.space_id, s.is_private AS space_private
                     FROM processes p LEFT JOIN spaces s ON s.id = p.space_id WHERE p.id = ?", [$eid]
                );
                if (!$proc) { return false; }
                return $proc['space_id'] === null
                    || SpaceAccess::canView(['id' => (int)$proc['space_id'], 'is_private' => $proc['space_private']]);
        }
        return true;
    }

    public function download(int $id): void {
        Auth::requireAuth();
        $att = $this->load($id);
        if (!$att) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $perms = self::PERMS[$att['entity_type']] ?? null;
        if ($perms && !Auth::can($perms[0])) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        if (!$this->canAccessParent($att)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        $data = Storage::get($att['stored_name']);
        if ($data === false) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        Auth::log('download_attachment', $att['entity_type'], (int)$att['entity_id']);
        header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $att['original_name'] ?: 'file') . '"');
        header('Content-Length: ' . strlen($data));
        header('X-Content-Type-Options: nosniff');
        echo $data;
    }

    /** Raster image types safe to render inline (SVG is excluded — it can carry script). */
    private const PREVIEWABLE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Serve an image attachment INLINE for preview. Hardened: same auth +
     * object-level access as download(), but only whitelisted raster types are
     * served, with a locked-down CSP and nosniff so the response can never be
     * treated as anything executable. Non-image (and SVG) attachments 404.
     */
    public function preview(int $id): void {
        Auth::requireAuth();
        $att = $this->load($id);
        if (!$att) { http_response_code(404); return; }
        $mime = strtolower((string)($att['mime_type'] ?? ''));
        if (!in_array($mime, self::PREVIEWABLE, true)) { http_response_code(404); return; }
        $perms = self::PERMS[$att['entity_type']] ?? null;
        if ($perms && !Auth::can($perms[0])) { http_response_code(403); return; }
        if (!$this->canAccessParent($att)) { http_response_code(403); return; }

        $data = Storage::get($att['stored_name']);
        if ($data === false) { http_response_code(404); return; }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $att['original_name'] ?: 'image') . '"');
        header('Content-Length: ' . strlen($data));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
        header('Cache-Control: private, max-age=300');
        echo $data;
    }

    public function delete(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $att = $this->load($id);
        if (!$att) { http_response_code(404); return; }
        $perms = self::PERMS[$att['entity_type']] ?? null;
        if ($perms && !Auth::can($perms[1])) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }
        if (!$this->canAccessParent($att)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        try { Storage::delete($att['stored_name']); } catch (Throwable) {}
        Database::query("DELETE FROM attachments WHERE id = ?", [$id]);
        Auth::log('delete_attachment', $att['entity_type'], (int)$att['entity_id']);
        $_SESSION['flash_success'] = 'Attachment removed.';
        $back = match ($att['entity_type']) {
            'page'     => '/pages/' . (int)$att['entity_id'],
            'document' => '/documents/' . (int)$att['entity_id'],
            'process'  => '/processes/' . (int)$att['entity_id'],
            default    => '/',
        };
        header('Location: ' . $back);
    }
}
