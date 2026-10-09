<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\People;
use Verity\Support\Security;

final class IdentitiesController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();

        $canAll = Authorize::can($user, 'identity.view');
        $canReports = Authorize::can($user, 'identity.view.reports');
        if (!$canAll && !$canReports) {
            Authorize::requirePermission($user, 'identity.view');
        }

        $filters = [
            'search' => trim((string) ($_GET['q'] ?? '')),
            'department' => (string) ($_GET['department'] ?? ''),
            'employment_status' => (string) ($_GET['employment_status'] ?? ''),
            'identity_type' => (string) ($_GET['identity_type'] ?? ''),
        ];
        if (!$canAll && $canReports) {
            $selfId = $user['person_id'] ?? null;
            $ids = $selfId !== null ? Authorize::reportsOf((int) $selfId) : [];
            if ($selfId !== null) {
                $ids[] = (int) $selfId;
            }
            $filters['person_ids'] = $ids;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 25;
        $total = People::count($filters);
        $people = People::list($filters, $pageSize, ($page - 1) * $pageSize);
        $departments = People::departments();

        $title = 'Identity Directory';
        $navActive = 'identities';
        $breadcrumbs = ['Identities' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_identities.php';
    }

    public static function view(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $id = (int) ($_GET['id'] ?? 0);
        $person = $id > 0 ? People::get($id) : null;
        if ($person === null) {
            http_response_code(404);
            echo 'Identity not found.';
            return;
        }

        $canAll = Authorize::can($user, 'identity.view');
        Authorize::requirePermission($user, $canAll ? 'identity.view' : 'identity.view.reports', ['subject_person_id' => $id]);

        $accounts = \Verity\Support\People::accountsFor($id);
        $reports = People::directReports($id);

        $title = $person['display_name'];
        $navActive = 'identities';
        $breadcrumbs = ['Identities' => '/app/identities', $person['display_name'] => null];
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_identity_detail.php';
    }
}
