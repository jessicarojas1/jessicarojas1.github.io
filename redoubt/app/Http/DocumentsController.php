<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Documents;
use Redoubt\Support\Security;

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
            'create' => Authorize::can($user, 'document.create', ['program_id' => $programId]),
            'edit'   => Authorize::can($user, 'document.edit', ['program_id' => $programId]),
        ];
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
                Documents::register($programId, self::formData(), $actorId);
                break;
            case 'update':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                Documents::update($id, $programId, self::formData(), $actorId);
                break;
            case 'delete':
                Authorize::requirePermission($user, 'document.edit', ['program_id' => $programId]);
                Documents::delete($id, $programId, $actorId);
                break;
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
        $url = Documents::resolveOpenUrl($row);
        if ($url === null) {
            self::plain(404, 'No document link available.');
            return;
        }
        Audit::log('document.open', 'document#' . $id, $programId, $user['id'] ?? null);
        header('Location: ' . $url);
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
