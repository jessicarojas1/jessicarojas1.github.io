<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\AccessRequests;
use Verity\Support\Applications;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Security;

final class AccessRequestsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $canViewAll = Authorize::can($user, 'accessrequest.view') || Authorize::can($user, 'accessrequest.manage');
        $canApproveOwned = Authorize::can($user, 'accessrequest.approve.owned');
        $canCreate = Authorize::can($user, 'accessrequest.create');
        if (!$canViewAll && !$canApproveOwned && !$canCreate) {
            Authorize::requirePermission($user, 'accessrequest.view'); // produces the standard 403
            return;
        }

        $filters = ['status' => trim((string) ($_GET['status'] ?? ''))];
        if (!$canViewAll) {
            // Scoped visibility: your own requests, plus anything you can
            // approve because you own the application — never "everything."
            $personId = $user['person_id'] ?? null;
            $filters['requested_by_user_id'] = $user['id'];
            // AccessRequests::list() ORs requested_by_user_id with
            // approvable_by_person_id only when both are present in a
            // single call isn't supported by the simple AND-based filter
            // builder, so a scoped-visibility user fetches both sets and
            // merges them here rather than complicating the shared query.
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;

        if ($canViewAll) {
            $total = AccessRequests::countAll($filters);
            $requests = AccessRequests::list($filters, $pageSize, ($page - 1) * $pageSize);
        } else {
            $mine = AccessRequests::list(array_merge($filters, ['requested_by_user_id' => $user['id']]), 500, 0);
            $toApprove = $canApproveOwned && ($user['person_id'] ?? null)
                ? AccessRequests::list(array_merge(['status' => $filters['status']], ['approvable_by_person_id' => $user['person_id']]), 500, 0)
                : [];
            $merged = [];
            foreach (array_merge($mine, $toApprove) as $r) {
                $merged[$r['id']] = $r; // de-dupe (you can both own the app and have requested something on it)
            }
            $all = array_values($merged);
            usort($all, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
            $total = count($all);
            $requests = array_slice($all, ($page - 1) * $pageSize, $pageSize);
        }

        $applications = $canCreate ? Applications::list() : [];

        $title = 'Access Requests';
        $navActive = 'accessrequests';
        $breadcrumbs = ['Access Requests' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_access_requests.php';
    }

    /** JSON: accounts in one application, for the New Request form's second step. */
    public static function accountsForApplication(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'accessrequest.create');
        $applicationId = (int) ($_GET['application_id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript(['accounts' => $applicationId > 0 ? AccessRequests::accountsForApplication($applicationId) : []]);
    }

    /** JSON: requestable entitlements for one account, for the New Request form's third step. */
    public static function entitlementsForAccount(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'accessrequest.create');
        $applicationId = (int) ($_GET['application_id'] ?? 0);
        $accountId = (int) ($_GET['system_account_id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript(['entitlements' => ($applicationId > 0 && $accountId > 0) ? AccessRequests::requestableEntitlements($applicationId, $accountId) : []]);
    }

    public static function create(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'accessrequest.create');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        try {
            AccessRequests::create([
                'system_account_id' => (int) ($_POST['system_account_id'] ?? 0),
                'entitlement_id' => (int) ($_POST['entitlement_id'] ?? 0),
                'justification' => trim((string) ($_POST['justification'] ?? '')),
            ], (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/access-requests');
    }

    public static function approve(): void
    {
        self::decide('approve');
    }

    public static function deny(): void
    {
        self::decide('deny');
    }

    private static function decide(string $action): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $request = $id > 0 ? AccessRequests::get($id) : null;
        if ($request === null) {
            http_response_code(404);
            echo 'Access request not found.';
            return;
        }

        $canAll = Authorize::can($user, 'accessrequest.manage');
        Authorize::requirePermission(
            $user,
            $canAll ? 'accessrequest.manage' : 'accessrequest.approve.owned',
            $canAll ? [] : ['application_id' => (int) $request['application_id']]
        );

        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            if ($action === 'approve') {
                AccessRequests::approve($id, $note, (int) $user['id']);
            } else {
                AccessRequests::deny($id, $note, (int) $user['id']);
            }
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/access-requests');
    }

    public static function cancel(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'accessrequest.create');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        try {
            AccessRequests::cancel($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/access-requests');
    }
}
