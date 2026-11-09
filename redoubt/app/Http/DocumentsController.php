<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Documents;
use Redoubt\Support\DocumentComments;
use Redoubt\Support\ReviewWorkflow;
use Redoubt\Support\Security;
use Throwable;

/**
 * Documents module (Annex H). Lists SharePoint-backed document references,
 * gated per zone through the Authorize engine (incl. the US-person export gate
 * and company scoping). The open endpoint re-authorizes before redirecting to
 * the document — defense in depth for CUI/ITAR content.
 */
final class DocumentsController
{
    /** GET /app/documents */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Documents require the database (DATABASE_URL). See docs/DEPLOYMENT.md.');
            return;
        }
        $programs = self::programs($user, 'document.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program where you may view documents.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'document.view', ['program_id' => $programId]);

        $zone = isset($_GET['zone']) && in_array($_GET['zone'], Documents::ZONES, true) ? $_GET['zone'] : null;
        $items = Documents::listForUser($user, $programId, $zone);
        $can = [
            'create'  => Authorize::can($user, 'document.create', ['program_id' => $programId]),
            'edit'    => Authorize::can($user, 'document.edit', ['program_id' => $programId]),
            'review'  => Authorize::can($user, 'review.submit', ['program_id' => $programId]),
            'comment' => Authorize::can($user, 'document.comment', ['program_id' => $programId]),
        ];
        $docIds = array_column($items, 'id');
        // Each document's own approval state (latest linked review item, if any).
        $reviewStatus = ReviewWorkflow::statusForDocuments($docIds, $programId);
        // Read-only discussion threads (one query for the page).
        $comments = DocumentComments::forDocuments($docIds, $programId);
        // Prior file versions (one query for the page).
        $versions = Documents::versionsForDocuments($docIds, $programId);
        $flash = isset($_GET['err']) ? ['type' => 'error', 'text' => (string) $_GET['err']] : null;
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_documents.php';
    }

    /** POST /app/documents */
    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $programId = (int) ($_POST['program_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $actorId = $user['id'] ?? null;

        switch ($action) {
            case 'register':
                Authorize::requirePermission($user, 'document.create', ['program_id' => $programId]);
                $data = self::formData();
                // Optional file upload (e.g. a diagram). Validated + stored server-side.
                $file = $_FILES['file'] ?? null;
                if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $data = array_merge($data, Documents::storeUpload($file));
                        if ($data['title'] === '' || $data['title'] === null) {
                            $data['title'] = $data['original_name'];
                        }
                    } catch (Throwable $e) {
                        header('Location: /app/documents?program_id=' . $programId . '&err=' . rawurlencode($e->getMessage()));
                        return;
                    }
                }
                Documents::register($programId, $data, $actorId);
                break;
            case 'comment':
                $id = (int) ($_POST['id'] ?? 0);
                $row = Documents::get($id, $programId);
                if ($row === null || !Documents::canSee($user, $row, $programId)
                    || !Authorize::can($user, 'document.comment', ['program_id' => $programId])) {
                    self::plain(403, '403 Forbidden — you cannot comment on this document.');
                    return;
                }
                $body = trim((string) ($_POST['body'] ?? ''));
                if ($body !== '') {
                    DocumentComments::add($id, $programId, $actorId, $body);
                    DocumentComments::notifyParticipants($row, $programId, $actorId);
                }
                header('Location: /app/documents?program_id=' . $programId . '#doc-' . $id);
                return;
            case 'delete_comment':
                $cid = (int) ($_POST['comment_id'] ?? 0);
                $c = DocumentComments::get($cid, $programId);
                if ($c === null) {
                    self::plain(404, 'Comment not found.');
                    return;
                }
                $isAuthor = $c['author_id'] !== null && (int) $c['author_id'] === (int) $actorId;
                if (!$isAuthor && !Authorize::can($user, 'document.edit', ['program_id' => $programId])) {
                    self::plain(403, '403 Forbidden — only the author or a document editor may delete a comment.');
                    return;
                }
                DocumentComments::delete($cid, $programId, $actorId);
                header('Location: /app/documents?program_id=' . $programId . '#doc-' . (int) $c['document_id']);
                return;
            case 'update':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                Documents::update($id, $programId, self::formData(), $actorId);
                break;
            case 'delete':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                Documents::delete($id, $programId, $actorId);
                break;
            case 'upload_version':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                $file = $_FILES['file'] ?? null;
                if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    header('Location: /app/documents?program_id=' . $programId . '&err=' . rawurlencode('Choose a file to upload as the new version.'));
                    return;
                }
                try {
                    Documents::addVersion($id, $programId, Documents::storeUpload($file), $actorId);
                } catch (Throwable $e) {
                    header('Location: /app/documents?program_id=' . $programId . '&err=' . rawurlencode($e->getMessage()));
                    return;
                }
                header('Location: /app/documents?program_id=' . $programId . '#doc-' . $id);
                return;
            case 'start_review':
                // Begin an approval workflow for this document. Requires review.submit
                // AND that the caller can actually see the document (defense in depth
                // for CUI/ITAR), so a review is never opened on an inaccessible doc.
                Authorize::requirePermission($user, 'review.submit', ['program_id' => $programId]);
                $row = Documents::get($id, $programId);
                if ($row === null || !Documents::canSee($user, $row, $programId)) {
                    self::plain(403, '403 Forbidden — document not found or not visible to you.');
                    return;
                }
                ReviewWorkflow::create($programId, [
                    'title'       => (string) ($row['title'] ?? 'Document') . ' — approval',
                    'kind'        => 'document',
                    'description' => 'Approval workflow for document reference #' . $id . '.',
                    'document_id' => $id,
                ], $actorId);
                header('Location: /app/reviews?program_id=' . $programId);
                return;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/documents?program_id=' . $programId);
    }

    /** GET /app/documents/open?id=&program_id= — re-authorize, then redirect. */
    public static function open(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $programId = (int) ($_GET['program_id'] ?? 0);
        $id = (int) ($_GET['id'] ?? 0);
        $row = Documents::get($id, $programId);
        if ($row === null) {
            self::plain(404, 'Not found.');
            return;
        }
        if (!Documents::canSee($user, $row, $programId)) {
            Audit::log('authz.deny', 'document.open#' . $id, $programId, $user['id'] ?? null);
            self::plain(403, '403 Forbidden.');
            return;
        }
        // Portal-stored upload: stream it (already authorized). Served as an
        // attachment with nosniff so active content (e.g. SVG) can't execute inline.
        $path = Documents::storedFilePath($row);
        if ($path !== null) {
            Audit::log('document.open', 'document#' . $id, $programId, $user['id'] ?? null);
            self::streamFile($path, (string) ($row['original_name'] ?? 'document'), (string) ($row['mime_type'] ?? 'application/octet-stream'));
            return;
        }
        $url = Documents::resolveOpenUrl($row);
        if ($url === null) {
            self::plain(404, 'No document link available.');
            return;
        }
        Audit::log('document.open', 'document#' . $id, $programId, $user['id'] ?? null);
        header('Location: ' . $url);
    }

    /**
     * GET /app/documents/preview?id=&program_id= — re-authorize, then render a
     * stored raster image INLINE (png/jpg/gif/webp only). Anything not proven
     * previewable falls back to the authorized download, so SVG/Office/PDF never
     * render inline. Used by the thumbnail <img> on the documents page.
     */
    public static function preview(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $programId = (int) ($_GET['program_id'] ?? 0);
        $id = (int) ($_GET['id'] ?? 0);
        $row = Documents::get($id, $programId);
        if ($row === null) {
            self::plain(404, 'Not found.');
            return;
        }
        if (!Documents::canSee($user, $row, $programId)) {
            Audit::log('authz.deny', 'document.preview#' . $id, $programId, $user['id'] ?? null);
            self::plain(403, '403 Forbidden.');
            return;
        }
        $path = Documents::storedFilePath($row);
        if ($path === null || !Documents::isPreviewable($row)) {
            // Not an inline-safe image — send them to the authorized download.
            header('Location: /app/documents/open?program_id=' . $programId . '&id=' . $id);
            return;
        }
        self::streamFile($path, (string) ($row['original_name'] ?? 'image'), (string) $row['mime_type'], 'inline');
    }

    /**
     * GET /app/documents/version?id=&v=&program_id= — download a prior version of a
     * document. Re-authorizes against the PARENT document (canSee, incl. the export
     * gate), then streams the version blob as an attachment.
     */
    public static function version(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $programId = (int) ($_GET['program_id'] ?? 0);
        $id = (int) ($_GET['id'] ?? 0);
        $vid = (int) ($_GET['v'] ?? 0);
        $row = Documents::get($id, $programId);
        if ($row === null) {
            self::plain(404, 'Not found.');
            return;
        }
        if (!Documents::canSee($user, $row, $programId)) {
            Audit::log('authz.deny', 'document.version#' . $id, $programId, $user['id'] ?? null);
            self::plain(403, '403 Forbidden.');
            return;
        }
        $v = Documents::versionFilePath($vid, $id, $programId);
        if ($v === null) {
            self::plain(404, 'Version not found.');
            return;
        }
        Audit::log('document.version.download', 'document#' . $id . ' v#' . $vid, $programId, $user['id'] ?? null);
        self::streamFile($v['path'], $v['original_name'], $v['mime_type']);
    }

    /**
     * Stream a portal-stored file. Caller MUST have authorized. `inline` is only
     * ever passed for raster images proven safe by Documents::isPreviewable; every
     * other file is served as an attachment, so active content cannot execute.
     */
    private static function streamFile(string $path, string $downloadName, string $mime, string $disposition = 'attachment'): void
    {
        // Harden the Content-Disposition filename against header injection.
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName) ?: 'document';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) (filesize($path) ?: 0));
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** @return array<string,mixed> */
    private static function formData(): array
    {
        $scope = [];
        foreach (explode(',', (string) ($_POST['company_scope'] ?? '')) as $tok) {
            $tok = trim($tok);
            if ($tok !== '') {
                $scope[] = ctype_digit($tok) ? (int) $tok : $tok;
            }
        }
        return [
            'title'             => trim((string) ($_POST['title'] ?? '')),
            'zone'              => (string) ($_POST['zone'] ?? 'project'),
            'company_scope'     => $scope,
            'export_controlled' => !empty($_POST['export_controlled']),
            'cui_marked'        => !empty($_POST['cui_marked']),
            'web_url'           => trim((string) ($_POST['web_url'] ?? '')) ?: null,
            'sp_item_id'        => trim((string) ($_POST['sp_item_id'] ?? '')) ?: null,
        ];
    }

    /** @return array<int,string> */
    private static function programs(array $user, string $perm): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, $perm, ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }
        return $out;
    }

    private static function plain(int $status, string $msg): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
}
