<?php
class DocumentController {

    private static function uploadDir(): string {
        return AEGIS_ROOT . '/uploads/documents';
    }

    public function index(): void {
        Auth::requireAuth();

        $filter = Security::sanitizeInput($_GET['status'] ?? '');
        $search = Security::sanitizeInput($_GET['q'] ?? '');
        $class  = Security::sanitizeInput($_GET['classification'] ?? '');

        $where  = ['1=1'];
        $params = [];
        if ($filter) { $where[] = 'd.status = ?'; $params[] = $filter; }
        if ($class)  { $where[] = 'd.classification = ?'; $params[] = $class; }
        if ($search) { $where[] = "(d.title ILIKE ? OR d.doc_number ILIKE ?)"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
        $whereStr = implode(' AND ', $where);

        $documents = Database::fetchAll(
            "SELECT d.*, u.name as owner_name
             FROM documents d LEFT JOIN users u ON d.owner_id = u.id
             WHERE {$whereStr} ORDER BY d.updated_at DESC",
            $params
        );

        $pageTitle    = 'Documents';
        $activeModule = 'documents';
        $breadcrumbs  = [['Documents', null]];
        ob_start();
        require AEGIS_ROOT . '/views/documents/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('policy.write');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
        $pageTitle    = 'New Document';
        $activeModule = 'documents';
        $breadcrumbs  = [['Documents', '/documents'], ['New', null]];
        ob_start();
        require AEGIS_ROOT . '/views/documents/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('policy.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $title          = Security::sanitizeInput($_POST['title'] ?? '');
        $docNumber      = Security::sanitizeInput($_POST['doc_number'] ?? '');
        $description    = Security::sanitizeInput($_POST['description'] ?? '');
        $category       = Security::sanitizeInput($_POST['category'] ?? '');
        $classification = in_array($_POST['classification'] ?? '', ['public','internal','confidential','restricted'])
            ? $_POST['classification'] : 'internal';
        $ownerId        = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id();
        $approverId     = !empty($_POST['approver_id']) ? (int)$_POST['approver_id'] : null;
        $reviewFreq     = Security::sanitizeInput($_POST['review_frequency'] ?? 'annual');
        $nextReview     = !empty($_POST['next_review_date']) ? Security::sanitizeInput($_POST['next_review_date']) : null;
        $expiry         = !empty($_POST['expiry_date']) ? Security::sanitizeInput($_POST['expiry_date']) : null;
        $tags           = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));

        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /documents/create'); exit;
        }

        Database::query(
            "INSERT INTO documents (title, doc_number, description, category, classification, status,
             owner_id, approver_id, review_frequency, next_review_date, expiry_date, tags, created_by)
             VALUES (?,?,?,?,?,'draft',?,?,?,?,?,?,?)",
            [$title, $docNumber, $description, $category, $classification,
             $ownerId, $approverId, $reviewFreq, $nextReview, $expiry,
             json_encode(array_values($tags)), Auth::id()]
        );
        $doc = Database::fetchOne("SELECT id FROM documents WHERE title = ? AND created_by = ? ORDER BY id DESC LIMIT 1",
            [$title, Auth::id()]);

        Auth::log('create_document', 'documents', $doc['id'] ?? null);
        header('Location: /documents/' . ($doc['id'] ?? '')); exit;
    }

    public function view(string $id): void {
        Auth::requireAuth();
        $id  = (int)$id;
        $doc = $this->getDoc($id);
        if (!$doc) { http_response_code(404); require AEGIS_ROOT . '/views/errors/404.php'; return; }

        $versions = Database::fetchAll(
            "SELECT dv.*, u.name as uploader_name FROM document_versions dv
             LEFT JOIN users u ON dv.uploaded_by = u.id
             WHERE dv.document_id = ? ORDER BY dv.uploaded_at DESC",
            [$id]
        );
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");

        $pageTitle    = $doc['title'];
        $activeModule = 'documents';
        $breadcrumbs  = [['Documents', '/documents'], [Security::h($doc['title']), null]];
        ob_start();
        require AEGIS_ROOT . '/views/documents/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(string $id): void {
        Auth::requirePermission('policy.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id     = (int)$id;
        $doc    = $this->getDoc($id);
        if (!$doc) { http_response_code(404); return; }

        $classification = in_array($_POST['classification'] ?? '', ['public','internal','confidential','restricted'])
            ? $_POST['classification'] : $doc['classification'];
        $newStatus = in_array($_POST['status'] ?? '', ['draft','under_review','approved','published','archived','expired'])
            ? $_POST['status'] : $doc['status'];

        $tags = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));

        Database::query(
            "UPDATE documents SET title=?, description=?, category=?, classification=?, status=?,
             owner_id=?, approver_id=?, review_frequency=?, next_review_date=?, expiry_date=?,
             tags=?, updated_at=NOW()
             WHERE id=?",
            [
                Security::sanitizeInput($_POST['title'] ?? $doc['title']),
                Security::sanitizeInput($_POST['description'] ?? ''),
                Security::sanitizeInput($_POST['category'] ?? ''),
                $classification, $newStatus,
                !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : $doc['owner_id'],
                !empty($_POST['approver_id']) ? (int)$_POST['approver_id'] : null,
                Security::sanitizeInput($_POST['review_frequency'] ?? 'annual'),
                !empty($_POST['next_review_date']) ? $_POST['next_review_date'] : null,
                !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
                json_encode(array_values($tags)),
                $id,
            ]
        );

        Auth::log('update_document', 'documents', $id, ['status' => $newStatus]);
        header('Location: /documents/' . $id . '?saved=1'); exit;
    }

    public function uploadVersion(string $id): void {
        Auth::requirePermission('policy.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $id  = (int)$id;
        $doc = $this->getDoc($id);
        if (!$doc) { http_response_code(404); return; }

        $file = $_FILES['document_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Upload error.';
            header("Location: /documents/{$id}"); exit;
        }

        $allowedMimes = [
            'application/pdf', 'text/plain', 'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowedMimes)) {
            $_SESSION['flash_error'] = 'File type not allowed.';
            header("Location: /documents/{$id}"); exit;
        }

        if ($file['size'] > 50 * 1024 * 1024) {
            $_SESSION['flash_error'] = 'File too large (max 50MB).';
            header("Location: /documents/{$id}"); exit;
        }

        $dir = self::uploadDir();
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
        $stored     = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
        $fileHash   = hash_file('sha256', $file['tmp_name']);
        $version    = Security::sanitizeInput($_POST['version'] ?? '');
        $summary    = Security::sanitizeInput($_POST['change_summary'] ?? '');

        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            $_SESSION['flash_error'] = 'Failed to save file.';
            header("Location: /documents/{$id}"); exit;
        }

        Database::query(
            "INSERT INTO document_versions (document_id, version, file_name, stored_name, mime_type, file_size, file_hash, change_summary, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [$id, $version ?: $doc['current_version'], basename($file['name']), $stored,
             $mimeType, $file['size'], $fileHash, $summary, Auth::id()]
        );

        if ($version) {
            Database::query("UPDATE documents SET current_version = ?, updated_at = NOW() WHERE id = ?", [$version, $id]);
        }

        Auth::log('upload_document_version', 'documents', $id);
        header("Location: /documents/{$id}?uploaded=1"); exit;
    }

    private function getDoc(int $id): ?array {
        return Database::fetchOne(
            "SELECT d.*, o.name as owner_name, a.name as approver_name
             FROM documents d
             LEFT JOIN users o ON d.owner_id = o.id
             LEFT JOIN users a ON d.approver_id = a.id
             WHERE d.id = ?",
            [$id]
        );
    }
}
