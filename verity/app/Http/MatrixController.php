<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Applications;
use Verity\Support\Audit;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\DynamicFields;
use Verity\Support\Matrix;
use Verity\Support\People;
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
}
