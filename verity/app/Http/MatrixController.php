<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\AccessRequests;
use Verity\Support\Applications;
use Verity\Support\Audit;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\DynamicFields;
use Verity\Support\EntitlementAssignments;
use Verity\Support\Matrix;
use Verity\Support\People;
use Verity\Support\RemediationTasks;
use Verity\Support\SavedViews;
use Verity\Support\Security;

final class MatrixController
{
    /** @return array{0:string,1:array<string,mixed>} [resolved view, scope] or exits via Authorize::requirePermission on failure. */
    private static function resolveScope(array $user, array $input): array
    {
        $view = (string) ($input['view'] ?? 'enterprise');
        $scope = [
            'view' => $view,
            'search' => trim((string) ($input['q'] ?? '')),
            'department' => (string) ($input['department'] ?? ''),
            'account_status' => (string) ($input['account_status'] ?? ''),
            'assignment_type' => (string) ($input['assignment_type'] ?? ''),
            'sort' => (string) ($input['sort'] ?? 'person_name'),
            'sort_dir' => (string) ($input['sort_dir'] ?? 'asc'),
        ];

        switch ($view) {
            case 'person':
                $personId = (int) ($input['person_id'] ?? 0);
                $scope['person_id'] = $personId;
                $canAll = Authorize::can($user, 'identity.view');
                Authorize::requirePermission($user, $canAll ? 'identity.view' : 'identity.view.reports', ['subject_person_id' => $personId]);
                break;
            case 'application':
                $appId = (int) ($input['application_id'] ?? 0);
                $scope['application_id'] = $appId;
                $canAll = Authorize::can($user, 'application.view');
                Authorize::requirePermission($user, $canAll ? 'matrix.view.enterprise' : 'matrix.view.application.owned', ['application_id' => $appId]);
                break;
            case 'supervisor':
                Authorize::requirePermission($user, 'matrix.view.supervisor');
                $selfId = $user['person_id'] ?? null;
                $ids = $selfId !== null ? Authorize::reportsOf((int) $selfId) : [];
                if ($selfId !== null) {
                    $ids[] = (int) $selfId;
                }
                $scope['person_ids'] = $ids;
                break;
            case 'privileged':
                Authorize::requirePermission($user, 'matrix.view.privileged');
                break;
            case 'exception':
                Authorize::requirePermission($user, 'matrix.view.exception');
                break;
            case 'enterprise':
            default:
                $scope['view'] = 'enterprise';
                Authorize::requirePermission($user, 'matrix.view.enterprise');
                break;
        }

        return [$scope['view'], $scope];
    }

    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        [$view, $scope] = self::resolveScope($user, $_GET);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $result = Matrix::query($scope, $pageSize, ($page - 1) * $pageSize);

        $applicationId = $scope['application_id'] ?? null;
        $fieldDefinitions = DynamicFields::activeDefinitions($applicationId, 'system_account');

        $applications = Applications::list();
        $departments = People::departments();
        $savedViews = SavedViews::forUser((int) $user['id']);

        $availableViews = [];
        if (Authorize::can($user, 'matrix.view.enterprise')) { $availableViews['enterprise'] = 'Enterprise'; }
        if (Authorize::can($user, 'matrix.view.supervisor')) { $availableViews['supervisor'] = 'Supervisor'; }
        if (Authorize::can($user, 'matrix.view.privileged')) { $availableViews['privileged'] = 'Privileged Access'; }
        if (Authorize::can($user, 'matrix.view.exception')) { $availableViews['exception'] = 'Exception'; }

        // Edit controls (bulk flag-for-removal, per-row grant) only render
        // in the two cases where every visible row is already something
        // the viewer plausibly has authority over: they're an admin
        // (entitlement.manage, any row, any view), or they're looking at
        // their OWN supervisor view (every row there is already scoped to
        // their own reports by Matrix::buildWhere()'s 'supervisor' case).
        // Every other view (enterprise/application/privileged/exception)
        // can show rows for people outside the viewer's authority, so a
        // non-admin never sees edit controls there — the mutating
        // endpoints re-verify per-ACCOUNT via Authorize::canManageAccountAccess()
        // regardless, but showing a button that would mostly 403 is bad UX,
        // not a security boundary in itself.
        $isEntitlementAdmin = Authorize::can($user, 'entitlement.manage');
        $canEdit = $isEntitlementAdmin || ($view === 'supervisor' && Authorize::can($user, 'matrix.view.supervisor'));

        $title = 'Enterprise Access Matrix';
        $navActive = 'matrix';
        $breadcrumbs = ['Access Matrix' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_matrix.php';
    }

    /** Permission-aware CSV export — re-runs the identical scoped query, capped, never the raw browser-side table. */
    public static function export(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        [$view, $scope] = self::resolveScope($user, $_GET);

        $result = Matrix::query($scope, 5000, 0); // safety cap; enterprise-scale export is a documented Phase 8 follow-up
        $fieldDefinitions = DynamicFields::activeDefinitions($scope['application_id'] ?? null, 'system_account');

        Audit::log('matrix.export', 'view#' . $view, null, ['row_count' => count($result['rows'])], null, $user['id']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="verity-access-matrix-' . $view . '-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        $headerRow = ['Person', 'Department', 'Application', 'Account', 'Account Status', 'Entitlement', 'Assignment Type', 'Risk Level', 'Granted At', 'Expires At'];
        foreach ($fieldDefinitions as $fd) {
            $headerRow[] = $fd['label'];
        }
        fputcsv($out, $headerRow, ',', '"', '\\');
        foreach ($result['rows'] as $r) {
            $row = [
                $r['person_name'] ?? '(unmatched)',
                $r['department'] ?? '',
                $r['application_name'],
                $r['username'] ?? $r['external_account_id'],
                $r['account_status'],
                $r['entitlement_name'] ?? '',
                $r['assignment_type'] ?? '',
                $r['risk_level'] ?? '',
                $r['granted_at'] ?? '',
                $r['expires_at'] ?? '',
            ];
            foreach ($fieldDefinitions as $fd) {
                $row[] = $r['dynamic'][$fd['field_key']] ?? '';
            }
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
    }

    public static function saveView(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'savedview.manage.own');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            http_response_code(400);
            echo 'Name is required.';
            return;
        }
        $shared = !empty($_POST['is_shared']) && Authorize::can($user, 'savedview.manage.shared');
        $config = [
            'view' => (string) ($_POST['view'] ?? 'enterprise'),
            'q' => (string) ($_POST['q'] ?? ''),
            'department' => (string) ($_POST['department'] ?? ''),
            'account_status' => (string) ($_POST['account_status'] ?? ''),
            'assignment_type' => (string) ($_POST['assignment_type'] ?? ''),
        ];
        SavedViews::create((int) $user['id'], $name, $config['view'], $config, $shared, $user['id']);
        header('Location: /app/matrix?' . http_build_query(array_filter($config)));
    }

    public static function deleteView(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'savedview.manage.own');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $view = SavedViews::get($id);
        if ($view !== null && ((int) $view['owner_user_id'] === (int) $user['id'] || Authorize::can($user, 'savedview.manage.shared'))) {
            SavedViews::delete($id, $user['id']);
        }
        header('Location: /app/matrix');
    }

    /** JSON: entitlements an account can be granted (doesn't already hold) within its own application, for the Matrix's "+ Add Entitlement" picker. */
    public static function entitlementsForAccount(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $accountId = (int) ($_GET['system_account_id'] ?? 0);
        self::requireAccountEditAccess($user, $accountId);
        $applicationId = (int) ($_GET['application_id'] ?? 0);
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript(['entitlements' => ($applicationId > 0 && $accountId > 0) ? AccessRequests::requestableEntitlements($applicationId, $accountId) : []]);
    }

    /** Grant a new entitlement directly from the Matrix — same engine and admin-or-supervisor rule as the Identity detail page and campaign review. */
    public static function grant(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $systemAccountId = (int) ($_POST['system_account_id'] ?? 0);
        self::requireAccountEditAccess($user, $systemAccountId);
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $isAdmin = Authorize::can($user, 'entitlement.manage');
        $actingPersonId = $user['person_id'] ?? null;
        $entitlementId = (int) ($_POST['entitlement_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            EntitlementAssignments::grant($systemAccountId, $entitlementId, (int) $user['id'], $actingPersonId !== null ? (int) $actingPersonId : null, $isAdmin, $note);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: ' . self::returnTo(['granted' => 1]));
    }

    /** Flags a single entitlement assignment for removal — a tracked remediation task, never an immediate delete; see EntitlementAssignments.php's doc comment for why. */
    public static function flagForRemoval(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $systemAccountId = (int) ($_POST['system_account_id'] ?? 0);
        self::requireAccountEditAccess($user, $systemAccountId);
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $entitlementId = (int) ($_POST['entitlement_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            RemediationTasks::create([
                'system_account_id' => $systemAccountId,
                'entitlement_id' => $entitlementId,
                'task_type' => 'remove_access',
                'description' => $note ?? 'Flagged for removal from the Access Matrix.',
            ], (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: ' . self::returnTo(['flagged' => 1]));
    }

    /**
     * Flag several entitlement assignments for removal in one submit — the
     * bulk toolbar. RemediationTasks::createBulk()/create() carry NO
     * authorization logic of their own (by design — see RemediationTasks.php;
     * the single-row flagForRemoval() above gets its authorization entirely
     * from requireAccountEditAccess() before ever calling create()), so
     * THIS method is the only thing standing between a submitted batch and
     * the database — each (account, entitlement) pair is individually
     * checked against Authorize::canManageAccountAccess() BEFORE being
     * included in what's passed to createBulk(); a pair outside the
     * caller's authority is counted as a failure, never silently dropped
     * and never silently allowed through just because other pairs in the
     * same batch were legitimate. A coarse gate first allows only an admin
     * or someone looking at their own supervisor view to attempt this at
     * all, matching exactly which callers ever see the bulk toolbar
     * rendered (MatrixController::index()'s $canEdit).
     */
    public static function flagForRemovalBulk(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $isAdmin = Authorize::can($user, 'entitlement.manage');
        if (!$isAdmin && !Authorize::can($user, 'matrix.view.supervisor')) {
            Authorize::requirePermission($user, 'entitlement.manage'); // no legitimate path here; produces the standard 403 + audit denial
            return;
        }
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        // Keyed by assignment_id (app_matrix.php's checkbox name="pairs[<assignment_id>][system_account_id]"),
        // so a row whose checkbox was left unchecked contributes ONLY its
        // always-present hidden entitlement_id field under that key, never
        // a system_account_id — filtered out below by requiring both.
        // Indexed array-of-pairs avoids the index-misalignment a sparse
        // checked-only array and an unconditionally-submitted parallel
        // array would otherwise create between which account and which
        // entitlement actually belong together.
        $submittedPairs = (array) ($_POST['pairs'] ?? []);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;

        $authorizedPairs = [];
        $deniedCount = 0;
        foreach ($submittedPairs as $pair) {
            if (!isset($pair['system_account_id']) || $pair['system_account_id'] === '') {
                continue; // this row's checkbox was never checked
            }
            $accountId = (int) $pair['system_account_id'];
            if (Authorize::canManageAccountAccess($user, $accountId)) {
                $authorizedPairs[] = ['system_account_id' => $accountId, 'entitlement_id' => isset($pair['entitlement_id']) && $pair['entitlement_id'] !== '' ? (int) $pair['entitlement_id'] : null];
            } else {
                $deniedCount++;
            }
        }
        $result = RemediationTasks::createBulk($authorizedPairs, $note, (int) $user['id']);
        header('Location: ' . self::returnTo(['bulk_ok' => $result['succeeded'], 'bulk_failed' => count($result['failed']) + $deniedCount]));
    }

    /** Admin (entitlement.manage) or that ACCOUNT's real owner's supervisor — see Authorize::canManageAccountAccess()'s own doc comment for why this always derives the account's actual owner itself. */
    private static function requireAccountEditAccess(array $user, int $systemAccountId): void
    {
        if (Authorize::canManageAccountAccess($user, $systemAccountId)) {
            return;
        }
        Audit::denied('authz.deny.entitlement.manage', 'system_account#' . $systemAccountId, $user['id'] ?? null);
        http_response_code(403);
        echo 'You are not authorized to edit this account\'s access.';
        exit;
    }

    /** Redirect back to the Matrix with the current view/filters/page preserved, plus a result flag. */
    private static function returnTo(array $extraQuery): string
    {
        $qs = (string) ($_POST['return_qs'] ?? '');
        parse_str($qs, $query);
        $query = array_merge($query, $extraQuery);
        return '/app/matrix?' . http_build_query($query);
    }
}
