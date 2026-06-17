<?php
declare(strict_types=1);

class DocumentController {

    /** Allowed lifecycle transitions: from => [to,...]. */
    private const TRANSITIONS = [
        'draft'     => ['in_review', 'archived'],
        'in_review' => ['approved', 'rejected', 'draft'],
        'approved'  => ['published', 'draft'],
        'published' => ['archived', 'obsolete', 'draft'],
        'rejected'  => ['draft', 'archived'],
        'archived'  => ['draft'],
        'obsolete'  => ['archived'],
    ];

    public function index(): void {
        Auth::requirePermission('document.view');
        $type   = Security::sanitizeInput($_GET['type'] ?? '');
        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $space  = !empty($_GET['space']) ? (int)$_GET['space'] : null;
        $q      = Security::sanitizeInput($_GET['q'] ?? '');

        $where = ['1=1']; $params = [];
        if ($type && in_array($type, View::docTypes(), true)) { $where[] = 'd.doc_type = ?'; $params[] = $type; }
        if ($status) { $where[] = 'd.status = ?'; $params[] = $status; }
        if ($space)  { $where[] = 'd.space_id = ?'; $params[] = $space; }
        if ($q) { $where[] = '(d.title ILIKE ? OR d.document_code ILIKE ? OR d.description ILIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        $whereSql = implode(' AND ', $where);

        $documents = Database::fetchAll(
            "SELECT d.*, s.space_key, o.name AS owner_name
             FROM documents d LEFT JOIN spaces s ON s.id=d.space_id LEFT JOIN users o ON o.id=d.owner_id
             WHERE {$whereSql} ORDER BY d.updated_at DESC",
            $params
        );
        $stats = Database::fetchOne(
            "SELECT COUNT(*) total,
                    COUNT(*) FILTER (WHERE status='published') published,
                    COUNT(*) FILTER (WHERE status='in_review') in_review,
                    COUNT(*) FILTER (WHERE status='draft') draft,
                    COUNT(*) FILTER (WHERE review_date < CURRENT_DATE AND status='published') overdue
             FROM documents"
        );
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        require PALADIN_ROOT . '/views/documents/index.php';
    }

    /** Export the (filtered) controlled-document register as CSV. */
    public function exportRegister(): void {
        Auth::requirePermission('document.view');
        $type   = Security::sanitizeInput($_GET['type'] ?? '');
        $status = Security::sanitizeInput($_GET['status'] ?? '');
        $space  = !empty($_GET['space']) ? (int)$_GET['space'] : null;
        $q      = Security::sanitizeInput($_GET['q'] ?? '');

        $where = ['1=1']; $params = [];
        if ($type && in_array($type, View::docTypes(), true)) { $where[] = 'd.doc_type = ?'; $params[] = $type; }
        if ($status) { $where[] = 'd.status = ?'; $params[] = $status; }
        if ($space)  { $where[] = 'd.space_id = ?'; $params[] = $space; }
        if ($q) { $where[] = '(d.title ILIKE ? OR d.document_code ILIKE ? OR d.description ILIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        $whereSql = implode(' AND ', $where);

        $rows = Database::fetchAll(
            "SELECT d.document_code, d.title, d.doc_type, d.status, d.revision, d.classification,
                    s.space_key, o.name AS owner_name, d.review_date, d.expiration_date, d.updated_at
             FROM documents d LEFT JOIN spaces s ON s.id=d.space_id LEFT JOIN users o ON o.id=d.owner_id
             WHERE {$whereSql} ORDER BY d.document_code",
            $params
        );
        Auth::log('export_document_register', 'documents', null, ['count' => count($rows)]);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="document-register-' . date('Ymd') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Code', 'Title', 'Type', 'Status', 'Revision', 'Classification', 'Space', 'Owner', 'Review Date', 'Expiration Date', 'Last Updated']);
        foreach ($rows as $r) {
            Csv::put($out, [
                $r['document_code'], $r['title'], View::docTypeLabel((string)$r['doc_type']), $r['status'],
                $r['revision'], $r['classification'] ?? '', $r['space_key'] ?? '', $r['owner_name'] ?? '',
                $r['review_date'] ?? '', $r['expiration_date'] ?? '', $r['updated_at'],
            ]);
        }
        fclose($out);
    }

    /** Bulk operations on selected documents: archive / label. */
    public function bulk(): void {
        Auth::requirePermission('document.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $action = Security::sanitizeInput($_POST['action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['doc_ids'] ?? '')))));
        if (!$ids) { $_SESSION['flash_error'] = 'No documents selected.'; header('Location: /documents'); return; }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $docs = Database::fetchAll("SELECT id FROM documents WHERE id IN ($place)", $ids);
        $valid = array_map(fn($r) => (int)$r['id'], $docs);
        if (!$valid) { $_SESSION['flash_error'] = 'No matching documents.'; header('Location: /documents'); return; }
        $n = count($valid);

        if ($action === 'archive') {
            $vp = implode(',', array_fill(0, count($valid), '?'));
            // Only archive docs not already archived/obsolete.
            Database::query("UPDATE documents SET status='archived', updated_at=NOW() WHERE id IN ($vp) AND status NOT IN ('archived','obsolete')", $valid);
            $_SESSION['flash_success'] = "Archived up to {$n} document(s).";
        } elseif ($action === 'label') {
            $tagId = (int)($_POST['tag_id'] ?? 0);
            if ($tagId && Database::fetchOne("SELECT 1 FROM tags WHERE id=?", [$tagId])) {
                foreach ($valid as $did) {
                    Database::query("INSERT INTO entity_tags (tag_id, entity_type, entity_id) VALUES (?, 'document', ?) ON CONFLICT DO NOTHING", [$tagId, $did]);
                }
                $_SESSION['flash_success'] = "Labelled {$n} document(s).";
            } else { $_SESSION['flash_error'] = 'Pick a label.'; }
        } else {
            $_SESSION['flash_error'] = 'Unknown bulk action.';
        }
        Auth::log('bulk_documents', 'documents', null, ['action' => $action, 'count' => $n]);
        header('Location: /documents');
    }

    public function view(int $id): void {
        Auth::requirePermission('document.view');
        $doc = Database::fetchOne(
            "SELECT d.*, s.space_key, s.name AS space_name, o.name AS owner_name,
                    r.name AS reviewer_name, a.name AS approver_name, co.name AS checked_out_name
             FROM documents d
             LEFT JOIN spaces s ON s.id=d.space_id
             LEFT JOIN users o ON o.id=d.owner_id
             LEFT JOIN users r ON r.id=d.reviewer_id
             LEFT JOIN users a ON a.id=d.approver_id
             LEFT JOIN users co ON co.id=d.checked_out_by
             WHERE d.id=?", [$id]
        );
        if (!$doc) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $versions = Database::fetchAll(
            "SELECT dv.*, u.name AS author FROM document_versions dv LEFT JOIN users u ON u.id=dv.created_by
             WHERE dv.document_id=? ORDER BY dv.created_at DESC", [$id]
        );
        $acks = Database::fetchAll(
            "SELECT da.*, u.name AS user_name FROM document_acknowledgements da JOIN users u ON u.id=da.user_id
             WHERE da.document_id=? ORDER BY da.acknowledged_at DESC", [$id]
        );
        $myAck = Database::fetchOne("SELECT 1 FROM document_acknowledgements WHERE document_id=? AND user_id=? AND revision=?", [$id, Auth::id(), $doc['revision']]);
        $relations = Database::fetchAll("SELECT * FROM entity_relations WHERE source_type='document' AND source_id=? ORDER BY relation_type", [$id]);
        $comments = Database::fetchAll(
            "SELECT c.*, u.name AS user_name, r.name AS resolver_name
             FROM comments c LEFT JOIN users u ON u.id=c.user_id LEFT JOIN users r ON r.id=c.resolved_by
             WHERE c.entity_type='document' AND c.entity_id=? ORDER BY c.created_at", [$id]
        );
        $approval = Database::fetchOne("SELECT * FROM approval_requests WHERE entity_type='document' AND entity_id=? ORDER BY id DESC LIMIT 1", [$id]);
        $docLike = Reactions::one('document', $id);
        $cReactions = Reactions::summary('comment', array_map(fn($c) => (int)$c['id'], $comments));
        $transitions = self::TRANSITIONS[$doc['status']] ?? [];
        $wfCanEdit = Auth::can('document.edit');
        $wfStatus = Workflow::status('document', $id);
        $wfTransitions = $wfStatus ? Workflow::transitions((int)$wfStatus['template_id'], (int)$wfStatus['state_id']) : [];
        $wfHistory = Workflow::history('document', $id);
        $wfApplicable = $wfCanEdit ? Workflow::applicable($doc['space_id'] !== null ? (int)$doc['space_id'] : null) : [];
        $wfEsign = Workflow::esignatureRequired();
        Recent::track('document', $id, $doc['title']);
        require PALADIN_ROOT . '/views/documents/view.php';
    }

    /** Compare two revisions of a document (line-level redline of the body). */
    public function diff(int $id): void {
        Auth::requirePermission('document.view');
        $doc = Database::fetchOne("SELECT id, title, document_code FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $versions = Database::fetchAll(
            "SELECT dv.id, dv.revision, dv.title, dv.created_at, u.name AS author
             FROM document_versions dv LEFT JOIN users u ON u.id=dv.created_by
             WHERE dv.document_id=? ORDER BY dv.created_at DESC, dv.id DESC", [$id]
        );
        if (count($versions) < 2) {
            $_SESSION['flash_error'] = 'A document needs at least two revisions to compare.';
            header('Location: /documents/' . $id); return;
        }
        // Default: newest (to) vs the one before it (from). Version ids picked from the dropdowns.
        $latestId = (int)$versions[0]['id'];
        $prevId   = (int)$versions[1]['id'];
        $toId   = (int)($_GET['to'] ?? $latestId);
        $fromId = (int)($_GET['from'] ?? $prevId);
        if ($fromId === $toId) { $fromId = $prevId === $toId ? $latestId : $prevId; }

        $from = Database::fetchOne("SELECT * FROM document_versions WHERE id=? AND document_id=?", [$fromId, $id]);
        $to   = Database::fetchOne("SELECT * FROM document_versions WHERE id=? AND document_id=?", [$toId, $id]);
        if (!$from || !$to) { $_SESSION['flash_error'] = 'Those revisions could not be found.'; header('Location: /documents/' . $id); return; }
        // Order older → newer by creation time for a natural reading.
        if (strtotime((string)$from['created_at']) > strtotime((string)$to['created_at'])) { [$from, $to] = [$to, $from]; }

        $bodyDiff  = Diff::lines(Diff::htmlToLines($from['body']), Diff::htmlToLines($to['body']));
        $stats     = Diff::stats($bodyDiff);
        $titleDiff = ($from['title'] ?? '') !== ($to['title'] ?? '');
        require PALADIN_ROOT . '/views/documents/diff.php';
    }

    /** Duplicate a document as a fresh draft ("Copy of …") with a new code, rev 1.0. */
    public function duplicate(int $id): void {
        Auth::requirePermission('document.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $src = Database::fetchOne("SELECT * FROM documents WHERE id = ?", [$id]);
        if (!$src) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $docType = in_array($src['doc_type'], View::docTypes(), true) ? $src['doc_type'] : 'policy';
        $code    = $this->nextCode($docType);
        $title   = mb_substr('Copy of ' . $src['title'], 0, 255);
        $body    = (string)($src['body'] ?? '');

        // Carry over descriptive metadata but reset lifecycle/identity/file fields.
        $data = [
            'document_code' => $code,
            'title'         => $title,
            'doc_type'      => $docType,
            'space_id'      => $src['space_id'] !== null ? (int)$src['space_id'] : null,
            'description'   => $src['description'] ?? null,
            'classification'=> $src['classification'] ?? null,
            'body'          => $body,
            'status'        => 'draft',
            'revision'      => '1.0',
            'requires_ack'  => in_array((string)($src['requires_ack'] ?? ''), ['1','t','true'], true) ? 't' : 'f',
            'owner_id'      => Auth::id(),
            'created_by'    => Auth::id(),
        ];
        $newId = Database::insert('documents', $data);
        Database::insert('document_versions', [
            'document_id' => $newId, 'revision' => '1.0', 'title' => $title,
            'body' => $body, 'change_summary' => 'Duplicated from ' . $src['document_code'], 'status' => 'draft',
            'created_by' => Auth::id(),
        ]);
        // Copy labels (best-effort).
        try {
            Database::query(
                "INSERT INTO entity_tags (tag_id, entity_type, entity_id)
                 SELECT tag_id, 'document', ? FROM entity_tags WHERE entity_type='document' AND entity_id=?
                 ON CONFLICT DO NOTHING", [$newId, $id]
            );
        } catch (Throwable) {}
        Auth::log('duplicate_document', 'documents', $newId, ['from' => $src['document_code'], 'code' => $code]);
        $_SESSION['flash_success'] = "Document duplicated as draft {$code}.";
        header('Location: /documents/' . $newId);
    }

    /**
     * Return a safe local redirect target from the Referer header, falling back
     * to $default. Only same-origin absolute paths (no scheme/host) are allowed,
     * guarding against open redirects.
     */
    private function safeReferer(string $default): string {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref !== '') {
            $path = parse_url($ref, PHP_URL_PATH);
            $host = parse_url($ref, PHP_URL_HOST);
            // Compare hosts without the port (HTTP_HOST carries it, parse_url does not).
            $self = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
            if (is_string($path) && preg_match('#^/[A-Za-z0-9/_\-.?=&]*$#', $path) && ($host === null || $host === $self)) {
                $q = parse_url($ref, PHP_URL_QUERY);
                return $path . ($q ? '?' . $q : '');
            }
        }
        return $default;
    }

    /** Push a document's next review date out by N months from today ("snooze"). */
    public function extendReview(int $id): void {
        Auth::requirePermission('document.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT id, document_code, review_date FROM documents WHERE id = ?", [$id]);
        if (!$doc) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $months = (int)($_POST['months'] ?? 12);
        if (!in_array($months, [3, 6, 12, 24], true)) $months = 12;
        $newDate = date('Y-m-d', strtotime("+{$months} months"));
        Database::update('documents', ['review_date' => $newDate], 'id = ?', [$id]);
        Auth::log('extend_review', 'documents', $id, ['code' => $doc['document_code'], 'months' => $months, 'review_date' => $newDate]);

        $back = $this->safeReferer('/documents/' . $id);
        $_SESSION['flash_success'] = "Review date for {$doc['document_code']} extended to " . View::fmtDate($newDate) . '.';
        header('Location: ' . $back);
    }

    public function createForm(): void {
        Auth::requirePermission('document.create');
        $doc = null;
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $users  = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $templates = Database::fetchAll("SELECT id, name, body, doc_type FROM templates WHERE category='document' AND is_active=TRUE ORDER BY name");
        require PALADIN_ROOT . '/views/documents/form.php';
    }

    public function create(): void {
        Auth::requirePermission('document.create');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $title   = Security::sanitizeInput($_POST['title'] ?? '');
        $docType = Security::sanitizeInput($_POST['doc_type'] ?? 'policy');
        if ($title === '') { $_SESSION['flash_error'] = 'Title is required.'; header('Location: /documents/create'); return; }
        if (!in_array($docType, View::docTypes(), true)) $docType = 'policy';

        $code = $this->nextCode($docType);
        $data = $this->collectMetadata($docType, $code);
        $data['title']      = $title;
        $data['status']     = 'draft';
        $data['body']       = Security::sanitizeHtml($_POST['body'] ?? '');
        $data['created_by'] = Auth::id();

        // Optional file
        if (!empty($_FILES['file']['name'])) {
            $up = Upload::handle($_FILES['file'], 'uploads/documents');
            if (!$up['ok']) { $_SESSION['flash_error'] = $up['error']; header('Location: /documents/create'); return; }
            $data['file_stored_name']   = $up['key'];
            $data['file_original_name'] = $up['name'];
            $data['file_mime']          = $up['mime'];
            $data['file_size']          = $up['size'];
            $data['file_hash']          = $up['hash'];
        }

        $id = Database::insert('documents', $data);
        Database::insert('document_versions', [
            'document_id' => $id, 'revision' => $data['revision'], 'title' => $title,
            'body' => $data['body'], 'change_summary' => 'Initial draft', 'status' => 'draft',
            'file_stored_name' => $data['file_stored_name'] ?? null, 'file_original_name' => $data['file_original_name'] ?? null,
            'file_mime' => $data['file_mime'] ?? null, 'file_size' => $data['file_size'] ?? null,
            'created_by' => Auth::id(),
        ]);
        $this->saveRelations('document', $id);
        Auth::log('create_document', 'documents', $id, ['code' => $code]);
        $_SESSION['flash_success'] = "Document {$code} created.";
        header('Location: /documents/' . $id);
    }

    public function editForm(int $id): void {
        Auth::requirePermission('document.edit');
        $doc = Database::fetchOne("SELECT * FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        if ($doc['checked_out_by'] && (int)$doc['checked_out_by'] !== Auth::id() && Auth::role() !== 'admin') {
            $_SESSION['flash_error'] = 'Document is checked out by another user.'; header('Location: /documents/' . $id); return;
        }
        $spaces = Database::fetchAll("SELECT id, space_key, name FROM spaces WHERE is_archived=FALSE ORDER BY name");
        $users  = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $relations = Database::fetchAll("SELECT * FROM entity_relations WHERE source_type='document' AND source_id=?", [$id]);
        $templates = [];
        require PALADIN_ROOT . '/views/documents/form.php';
    }

    public function update(int $id): void {
        Auth::requirePermission('document.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT * FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); return; }
        if ($doc['checked_out_by'] && (int)$doc['checked_out_by'] !== Auth::id() && Auth::role() !== 'admin') {
            $_SESSION['flash_error'] = 'Document is checked out by another user.'; header('Location: /documents/' . $id); return;
        }

        $docType = Security::sanitizeInput($_POST['doc_type'] ?? $doc['doc_type']);
        if (!in_array($docType, View::docTypes(), true)) $docType = $doc['doc_type'];
        $data = $this->collectMetadata($docType, $doc['document_code']);
        unset($data['document_code']); // never change the code
        $data['title'] = Security::sanitizeInput($_POST['title'] ?? $doc['title']);
        $data['body']  = Security::sanitizeHtml($_POST['body'] ?? '');

        if (!empty($_FILES['file']['name'])) {
            $up = Upload::handle($_FILES['file'], 'uploads/documents');
            if (!$up['ok']) { $_SESSION['flash_error'] = $up['error']; header('Location: /documents/' . $id . '/edit'); return; }
            $data['file_stored_name']   = $up['key'];
            $data['file_original_name'] = $up['name'];
            $data['file_mime']          = $up['mime'];
            $data['file_size']          = $up['size'];
            $data['file_hash']          = $up['hash'];
        }

        Database::update('documents', $data, 'id=?', [$id]);
        Database::query("DELETE FROM entity_relations WHERE source_type='document' AND source_id=?", [$id]);
        $this->saveRelations('document', $id);
        Auth::log('update_document', 'documents', $id);
        $_SESSION['flash_success'] = 'Document updated.';
        header('Location: /documents/' . $id);
    }

    public function transition(int $id): void {
        Auth::requirePermission('document.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT * FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); return; }
        $to = Security::sanitizeInput($_POST['to'] ?? '');
        $allowed = self::TRANSITIONS[$doc['status']] ?? [];
        if (!in_array($to, $allowed, true)) { $_SESSION['flash_error'] = 'Invalid status transition.'; header('Location: /documents/' . $id); return; }

        // Publishing / approving may require permission
        if (in_array($to, ['approved','published'], true) && !Auth::can('document.publish') && !Auth::can('document.approve')) {
            $_SESSION['flash_error'] = 'You do not have permission to approve/publish.'; header('Location: /documents/' . $id); return;
        }
        $data = ['status' => $to];
        if ($to === 'published') {
            $data['published_at']   = date('Y-m-d H:i:s');
            $data['effective_date'] = $doc['effective_date'] ?: date('Y-m-d');
        }
        Database::update('documents', $data, 'id=?', [$id]);
        Auth::log('document_transition', 'documents', $id, ['from' => $doc['status'], 'to' => $to]);
        $event = match ($to) {
            'approved'  => 'document.approved',
            'published' => 'document.published',
            'archived'  => 'document.archived',
            default     => null,
        };
        if ($event !== null) {
            Webhook::dispatch($event, [
                'id' => $id, 'code' => $doc['document_code'], 'title' => $doc['title'],
                'from' => $doc['status'], 'to' => $to, 'actor' => Auth::id(),
            ]);
        }
        $_SESSION['flash_success'] = 'Document moved to ' . str_replace('_', ' ', $to) . '.';
        header('Location: /documents/' . $id);
    }

    public function checkout(int $id): void {
        Auth::requirePermission('document.checkout');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT checked_out_by FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); return; }
        if ($doc['checked_out_by']) { $_SESSION['flash_error'] = 'Already checked out.'; header('Location: /documents/' . $id); return; }
        Database::update('documents', ['checked_out_by' => Auth::id(), 'checked_out_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        Auth::log('checkout_document', 'documents', $id);
        $_SESSION['flash_success'] = 'Document checked out to you.';
        header('Location: /documents/' . $id);
    }

    public function checkin(int $id): void {
        Auth::requirePermission('document.checkout');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT checked_out_by FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); return; }
        if ($doc['checked_out_by'] && (int)$doc['checked_out_by'] !== Auth::id() && Auth::role() !== 'admin') {
            $_SESSION['flash_error'] = 'Checked out by another user.'; header('Location: /documents/' . $id); return;
        }
        Database::update('documents', ['checked_out_by' => null, 'checked_out_at' => null], 'id=?', [$id]);
        Auth::log('checkin_document', 'documents', $id);
        $_SESSION['flash_success'] = 'Document checked in.';
        header('Location: /documents/' . $id);
    }

    public function revise(int $id): void {
        Auth::requirePermission('document.edit');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT * FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); return; }
        $newRev  = Security::sanitizeInput($_POST['revision'] ?? '');
        $summary = Security::sanitizeInput($_POST['change_summary'] ?? '');
        if ($newRev === '') { $_SESSION['flash_error'] = 'New revision number required.'; header('Location: /documents/' . $id); return; }

        // Snapshot current as a version, then bump revision back to draft
        Database::insert('document_versions', [
            'document_id' => $id, 'revision' => $doc['revision'], 'title' => $doc['title'], 'body' => $doc['body'],
            'change_summary' => $summary ?: ('Superseded by ' . $newRev), 'status' => $doc['status'],
            'file_stored_name' => $doc['file_stored_name'], 'file_original_name' => $doc['file_original_name'],
            'file_mime' => $doc['file_mime'], 'file_size' => $doc['file_size'], 'created_by' => Auth::id(),
        ]);
        Database::update('documents', ['revision' => $newRev, 'status' => 'draft'], 'id=?', [$id]);
        Auth::log('revise_document', 'documents', $id, ['from' => $doc['revision'], 'to' => $newRev]);
        $_SESSION['flash_success'] = "New revision {$newRev} started (status: draft).";
        header('Location: /documents/' . $id);
    }

    public function acknowledge(int $id): void {
        Auth::requirePermission('document.acknowledge');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $doc = Database::fetchOne("SELECT revision FROM documents WHERE id=?", [$id]);
        if (!$doc) { http_response_code(404); return; }
        try {
            Database::insert('document_acknowledgements', ['document_id' => $id, 'user_id' => Auth::id(), 'revision' => $doc['revision']]);
            Auth::log('acknowledge_document', 'documents', $id, ['revision' => $doc['revision']]);
            $_SESSION['flash_success'] = 'Acknowledgement recorded. Thank you.';
        } catch (Throwable) {
            $_SESSION['flash_warning'] = 'You have already acknowledged this revision.';
        }
        header('Location: /documents/' . $id);
    }

    public function comment(int $id): void {
        Auth::requirePermission('document.view');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $body = Security::sanitizeInput($_POST['body'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        if ($parentId && !Database::fetchOne("SELECT 1 FROM comments WHERE id=? AND entity_type='document' AND entity_id=? AND parent_id IS NULL", [$parentId, $id])) {
            $parentId = null;
        }
        if ($body !== '') {
            Database::insert('comments', ['entity_type' => 'document', 'entity_id' => $id, 'user_id' => Auth::id(), 'parent_id' => $parentId, 'body' => $body]);
            Auth::log('comment_document', 'documents', $id);
            $dc = Database::fetchOne("SELECT title FROM documents WHERE id=?", [$id]);
            Mentions::process($body, 'document', $id, $dc['title'] ?? null);
            Webhook::dispatch('comment.created', ['entity_type' => 'document', 'entity_id' => $id, 'actor' => Auth::id()]);
        }
        header('Location: /documents/' . $id . '#comments');
    }

    /** Server-rendered PDF of a document's controlled content (real application/pdf). */
    public function pdf(int $id): void {
        Auth::requirePermission('document.view');
        $doc = Database::fetchOne(
            "SELECT d.*, s.name AS space_name, o.name AS owner_name
             FROM documents d LEFT JOIN spaces s ON s.id=d.space_id LEFT JOIN users o ON o.id=d.owner_id WHERE d.id=?",
            [$id]
        );
        if (!$doc) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $meta = [
            'Code'        => (string)$doc['document_code'],
            'Type'        => View::docTypeLabel((string)$doc['doc_type']),
            'Revision'    => (string)$doc['revision'],
            'Status'      => ucfirst((string)$doc['status']),
            'Owner'       => (string)($doc['owner_name'] ?? '—'),
            'Classification' => ucfirst((string)($doc['classification'] ?? 'internal')),
            'Exported'    => date('M j, Y g:ia'),
        ];
        $bytes = Pdf::fromHtml((string)$doc['title'], (string)($doc['body'] ?? ''), $meta);
        Auth::log('export_document_pdf', 'documents', $id);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $doc['document_code'] . '.pdf') . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
    }

    /** Export a document's acknowledgement record (compliance evidence) as CSV. */
    public function exportAcks(int $id): void {
        Auth::requirePermission('document.view');
        $doc = Database::fetchOne("SELECT id, document_code, title FROM documents WHERE id = ?", [$id]);
        if (!$doc) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }

        $rows = Database::fetchAll(
            "SELECT u.name AS user_name, u.email, u.department, da.revision, da.acknowledged_at
             FROM document_acknowledgements da JOIN users u ON u.id = da.user_id
             WHERE da.document_id = ? ORDER BY da.acknowledged_at DESC", [$id]
        );
        Auth::log('export_document_acks', 'documents', $id, ['count' => count($rows)]);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $doc['document_code'] . '-acknowledgements') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Document', 'Title', 'User', 'Email', 'Department', 'Revision', 'Acknowledged At']);
        foreach ($rows as $r) {
            Csv::put($out, [
                $doc['document_code'], $doc['title'], $r['user_name'], $r['email'] ?? '',
                $r['department'] ?? '', $r['revision'], $r['acknowledged_at'],
            ]);
        }
        fclose($out);
    }

    public function download(int $id): void {
        Auth::requirePermission('document.view');
        $doc = Database::fetchOne("SELECT * FROM documents WHERE id=?", [$id]);
        if (!$doc || empty($doc['file_stored_name'])) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        $data = Storage::get($doc['file_stored_name']);
        if ($data === false) { http_response_code(404); require PALADIN_ROOT . '/views/errors/404.php'; return; }
        Auth::log('download_document', 'documents', $id);
        header('Content-Type: ' . ($doc['file_mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['file_original_name'] ?: 'document') . '"');
        header('Content-Length: ' . strlen($data));
        header('X-Content-Type-Options: nosniff');
        echo $data;
    }

    public function delete(int $id): void {
        Auth::requirePermission('document.delete');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::update('documents', ['status' => 'archived'], 'id=?', [$id]);
        Auth::log('archive_document', 'documents', $id);
        $_SESSION['flash_success'] = 'Document archived.';
        header('Location: /documents');
    }

    // ── helpers ───────────────────────────────────────────────────────────
    private function nextCode(string $docType): string {
        return DocNumbering::next($docType);
    }

    private function collectMetadata(string $docType, string $code): array {
        $cls = Security::sanitizeInput($_POST['classification'] ?? 'internal');
        if (!in_array($cls, View::classifications(), true)) $cls = 'internal';
        return [
            'document_code'   => $code,
            'doc_type'        => $docType,
            'space_id'        => !empty($_POST['space_id']) ? (int)$_POST['space_id'] : null,
            'owner_id'        => !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : Auth::id(),
            'reviewer_id'     => !empty($_POST['reviewer_id']) ? (int)$_POST['reviewer_id'] : null,
            'approver_id'     => !empty($_POST['approver_id']) ? (int)$_POST['approver_id'] : null,
            'department'      => Security::sanitizeInput($_POST['department'] ?? '') ?: null,
            'business_unit'   => Security::sanitizeInput($_POST['business_unit'] ?? '') ?: null,
            'classification'  => $cls,
            'revision'        => Security::sanitizeInput($_POST['revision'] ?? '1.0') ?: '1.0',
            'description'     => Security::sanitizeInput($_POST['description'] ?? '') ?: null,
            'effective_date'  => !empty($_POST['effective_date']) ? $_POST['effective_date'] : null,
            'review_date'     => !empty($_POST['review_date']) ? $_POST['review_date'] : null,
            'expiration_date' => !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null,
            'requires_ack'    => !empty($_POST['requires_ack']) ? 't' : 'f',
        ];
    }

    private function saveRelations(string $type, int $id): void {
        $labels = $_POST['relation_label'] ?? [];
        $kinds  = $_POST['relation_type'] ?? [];
        if (!is_array($labels)) return;
        foreach ($labels as $i => $label) {
            $label = Security::sanitizeInput((string)$label);
            if ($label === '') continue;
            $kind = Security::sanitizeInput((string)($kinds[$i] ?? 'related_process'));
            Database::insert('entity_relations', [
                'source_type' => $type, 'source_id' => $id, 'relation_type' => $kind, 'target_label' => $label,
            ]);
        }
    }
}
