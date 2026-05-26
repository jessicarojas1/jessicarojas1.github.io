<?php
declare(strict_types=1);

class EvidenceController {

    private static function uploadDir(): string {
        $dir = AEGIS_ROOT . '/uploads';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    private static function allowedExtensions(): array {
        $row = Database::fetchOne("SELECT value FROM settings WHERE key = 'upload_allowed_types'");
        $raw = $row['value'] ?? 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,txt,csv,zip';
        return array_map('trim', explode(',', $raw));
    }

    private static function maxBytes(): int {
        $row = Database::fetchOne("SELECT value FROM settings WHERE key = 'upload_max_size_mb'");
        return (int)($row['value'] ?? 20) * 1024 * 1024;
    }

    public function upload(): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $entityType = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $entityId   = (int)($_POST['entity_id'] ?? 0);
        $description = Security::sanitizeInput($_POST['description'] ?? '');
        $expiresAt  = Security::sanitizeInput($_POST['expires_at'] ?? '');

        $validTypes = ['control','risk','audit','incident','policy','vendor','issue'];
        if (!in_array($entityType, $validTypes) || $entityId <= 0) {
            $_SESSION['flash_error'] = 'Invalid entity reference.';
            $this->redirectBack(); return;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'File upload failed or no file selected.';
            $this->redirectBack(); return;
        }

        $file = $_FILES['file'];
        if ($file['size'] > self::maxBytes()) {
            $_SESSION['flash_error'] = 'File exceeds maximum allowed size.';
            $this->redirectBack(); return;
        }

        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::allowedExtensions())) {
            $_SESSION['flash_error'] = 'File type not allowed.';
            $this->redirectBack(); return;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest   = self::uploadDir() . '/' . $stored;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $_SESSION['flash_error'] = 'Could not save uploaded file.';
            $this->redirectBack(); return;
        }

        $hash = hash_file('sha256', $dest);

        Database::insert('evidence_files', [
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'original_name'=> $origName,
            'stored_name'  => $stored,
            'mime_type'    => mime_content_type($dest) ?: 'application/octet-stream',
            'file_size'    => $file['size'],
            'file_hash'    => $hash,
            'description'  => $description ?: null,
            'expires_at'   => $expiresAt ?: null,
            'uploaded_by'  => Auth::id(),
        ]);

        Auth::log('upload_evidence', $entityType, $entityId, ['file' => $origName]);

        $_SESSION['flash_success'] = 'Evidence file uploaded successfully.';
        $this->redirectBack();
    }

    /**
     * Verify that the current user has read access to the entity owning the evidence file.
     * Returns false if access should be denied.
     */
    private static function canAccessEntity(string $entityType, int $entityId): bool {
        // Admin can access everything
        if (Auth::role() === 'admin') return true;

        // Map entity types to the permission module
        $moduleMap = [
            'control'  => 'compliance',
            'risk'     => 'risk',
            'audit'    => 'audit',
            'incident' => 'incident',
            'policy'   => 'policy',
            'vendor'   => 'vendor',
            'issue'    => 'issue',
        ];
        $module = $moduleMap[$entityType] ?? null;
        if (!$module) return false;

        return Auth::can($module . '.read');
    }

    public function download(string $id): void {
        Auth::requireAuth();
        $id = (int)$id;

        $rec = Database::fetchOne("SELECT * FROM evidence_files WHERE id = ?", [$id]);
        if (!$rec) { http_response_code(404); echo 'File not found.'; return; }

        // IDOR check: verify user has read access to the parent entity
        if (!self::canAccessEntity($rec['entity_type'], (int)$rec['entity_id'])) {
            http_response_code(403); echo 'Access denied.'; return;
        }

        // Validate stored_name to prevent path traversal from DB compromise
        $storedName = basename($rec['stored_name']);
        if ($storedName !== $rec['stored_name'] || !preg_match('/^[0-9a-f]+\.[a-z0-9]+$/i', $storedName)) {
            http_response_code(400); echo 'Invalid file reference.'; return;
        }
        $path = self::uploadDir() . '/' . $storedName;
        if (!file_exists($path)) { http_response_code(404); echo 'File missing from storage.'; return; }

        // Use RFC 5987 encoding for the filename to prevent header injection
        $safeAscii    = preg_replace('/[^\x20-\x7E]/', '_', $rec['original_name']);
        $safeAscii    = str_replace(['"', '\\', "\r", "\n"], '_', $safeAscii);
        $encodedName  = rawurlencode($rec['original_name']);

        $mimeType = $rec['mime_type'] ?: 'application/octet-stream';
        // Whitelist safe MIME types; force download for anything else
        $safeMimes = ['application/pdf','text/plain','text/csv',
                      'image/png','image/jpeg','image/gif',
                      'application/zip',
                      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                      'application/msword','application/vnd.ms-excel'];
        if (!in_array($mimeType, $safeMimes)) {
            $mimeType = 'application/octet-stream';
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $safeAscii . '"; filename*=UTF-8\'\'' . $encodedName);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function delete(string $id): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id  = (int)$id;
        $rec = Database::fetchOne("SELECT * FROM evidence_files WHERE id = ?", [$id]);
        if (!$rec) { $_SESSION['flash_error'] = 'File not found.'; $this->redirectBack(); return; }

        // Only the uploader or an admin can delete evidence
        if ((int)$rec['uploaded_by'] !== Auth::id() && Auth::role() !== 'admin') {
            $_SESSION['flash_error'] = 'Permission denied.'; $this->redirectBack(); return;
        }

        // Validate stored_name to prevent path traversal from DB compromise
        $storedName = basename($rec['stored_name']);
        if ($storedName !== $rec['stored_name'] || !preg_match('/^[0-9a-f]+\.[a-z0-9]+$/i', $storedName)) {
            $_SESSION['flash_error'] = 'Invalid file reference.'; $this->redirectBack(); return;
        }

        $path = self::uploadDir() . '/' . $storedName;
        if (file_exists($path)) @unlink($path);

        Database::query("DELETE FROM evidence_files WHERE id = ?", [$id]);

        Auth::log('delete_evidence', $rec['entity_type'], (int)$rec['entity_id'], ['file' => $rec['original_name']]);

        $_SESSION['flash_success'] = 'Evidence file deleted.';
        $this->redirectBack();
    }

    public function listForEntity(): void {
        Auth::requireAuth();
        header('Content-Type: application/json');

        $entityType = Security::sanitizeInput($_GET['entity_type'] ?? '');
        $entityId   = (int)($_GET['entity_id'] ?? 0);

        if (!$entityType || !$entityId) { echo '[]'; return; }

        // IDOR check: verify user has read access to the parent entity
        if (!self::canAccessEntity($entityType, $entityId)) {
            http_response_code(403); echo json_encode(['error' => 'Access denied']); return;
        }

        $files = Database::fetchAll(
            "SELECT ef.id, ef.original_name, ef.file_size, ef.mime_type,
                    ef.description, ef.expires_at, ef.created_at,
                    u.name AS uploaded_by_name
             FROM evidence_files ef
             LEFT JOIN users u ON u.id = ef.uploaded_by
             WHERE ef.entity_type = ? AND ef.entity_id = ?
             ORDER BY ef.created_at DESC",
            [$entityType, $entityId]
        );
        echo json_encode($files);
    }

    private function redirectBack(): void {
        $ref = $_SERVER['HTTP_REFERER'] ?? '/';
        // Validate the referer is a local path only — no external redirects
        $parsed = parse_url($ref);
        if (!empty($parsed['host'])) {
            // Has a host — only allow if it matches our own server
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (trim($parsed['host'], '/') !== trim($host, '/')) {
                header('Location: /');
                return;
            }
        }
        $safePath = $parsed['path'] ?? '/';
        if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $safePath)) {
            $safePath = '/';
        }
        header('Location: ' . $safePath);
    }
}
