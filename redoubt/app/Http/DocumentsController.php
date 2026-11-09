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
                }
                header('Location: /app/documents?program_id=' . $programId . '#doc-' . $id);
                return;
            case 'update':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                Documents::update($id, $programId, self::formData(), $actorId);
                break;
            case 'delete':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                Documents::delete($id, $programId, $actorId);
                break;
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

    /** Stream a portal-stored file as a download. Caller MUST have authorized. */
    private static function streamFile(string $path, string $downloadName, string $mime): void
    {
        // Harden the Content-Disposition filename against header injection.
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName) ?: 'document';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
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
