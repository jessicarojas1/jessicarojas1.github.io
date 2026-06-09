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

    public function download(int $id): void {
        Auth::requireAuth();
        $att = $this->load($id);
        if (!$att) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $perms = self::PERMS[$att['entity_type']] ?? null;
        if ($perms && !Auth::can($perms[0])) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

        $data = Storage::get($att['stored_name']);
        if ($data === false) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        Auth::log('download_attachment', $att['entity_type'], (int)$att['entity_id']);
        header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $att['original_name'] ?: 'file') . '"');
        header('Content-Length: ' . strlen($data));
        header('X-Content-Type-Options: nosniff');
        echo $data;
    }

    public function delete(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $att = $this->load($id);
        if (!$att) { http_response_code(404); return; }
        $perms = self::PERMS[$att['entity_type']] ?? null;
        if ($perms && !Auth::can($perms[1])) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return; }

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
