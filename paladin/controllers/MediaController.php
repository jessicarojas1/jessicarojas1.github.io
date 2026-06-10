<?php
declare(strict_types=1);

/**
 * MediaController — images uploaded from the rich-text editor.
 *
 * upload(): accepts an image (multipart, CSRF via the AJAX token), validates it
 * is an allowed image type, stores it through Storage, and returns a stable
 * /media/{id} URL. serve(): streams the image inline (auth-gated) with a
 * long cache. Referencing images by integer id keeps the embedded <img src>
 * a site-relative URL (survives HTML sanitization) and avoids exposing keys.
 */
class MediaController
{
    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
    private const IMAGE_EXTS  = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public function upload(): void
    {
        Auth::requirePermission('page.create');
        header('Content-Type: application/json');
        // AJAX CSRF: token supplied via header or field.
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!Security::validateCsrf((string)$token)) {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'csrf']); return;
        }
        if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            http_response_code(422); echo json_encode(['ok' => false, 'error' => 'No file uploaded.']); return;
        }
        $file = $_FILES['image'];
        $ext  = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $mime = (function_exists('mime_content_type') && is_file($file['tmp_name']))
            ? (mime_content_type($file['tmp_name']) ?: '') : '';
        if (!in_array($ext, self::IMAGE_EXTS, true) || ($mime !== '' && !in_array($mime, self::IMAGE_MIMES, true))) {
            http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Only PNG, JPG, GIF, WebP or SVG images are allowed.']); return;
        }
        // 8 MB cap for inline editor images.
        if ((int)$file['size'] > 8 * 1024 * 1024) {
            http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Image must be 8 MB or smaller.']); return;
        }

        try {
            $key = Storage::put('storage/media', $file['tmp_name'], $file['name']);
        } catch (\Throwable $e) {
            error_log('[PALADIN media] ' . $e->getMessage());
            http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Storage failed.']); return;
        }
        $id = Database::insert('media', [
            'stored_key'    => $key,
            'original_name' => mb_substr((string)$file['name'], 0, 255),
            'mime'          => $mime ?: 'application/octet-stream',
            'size'          => (int)$file['size'],
            'uploaded_by'   => Auth::id(),
        ]);
        Auth::log('upload_media', 'media', $id);
        echo json_encode(['ok' => true, 'id' => $id, 'url' => '/media/' . $id, 'csrf' => Security::generateCsrfToken()]);
    }

    public function serve(int $id): void
    {
        Auth::requireAuth();
        $m = Database::fetchOne("SELECT * FROM media WHERE id = ?", [$id]);
        if (!$m) { http_response_code(404); return; }
        $data = Storage::get($m['stored_key']);
        if ($data === false) { http_response_code(404); return; }
        $mime = in_array($m['mime'], self::IMAGE_MIMES, true) ? $m['mime'] : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($data));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        // SVG can embed scripts: sandbox it (neutralises any active markup on
        // direct navigation) and force download rather than inline rendering.
        if ($mime === 'image/svg+xml') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            header('Content-Disposition: attachment; filename="image.svg"');
        } else {
            header('Content-Disposition: inline');
        }
        echo $data;
    }
}
