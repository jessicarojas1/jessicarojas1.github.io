<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Applications;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Connectors;
use Verity\Support\CsvImport;
use Verity\Support\People;
use Verity\Support\Security;

final class ApplicationsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, Authorize::can($user, 'application.view') ? 'application.view' : 'application.view.owned');

        $applications = Applications::list(['search' => trim((string) ($_GET['q'] ?? ''))]);
        if (!Authorize::can($user, 'application.view')) {
            $selfId = $user['person_id'] ?? null;
            $applications = array_values(array_filter($applications, static fn ($a) => $selfId !== null && (int) $a['system_owner_person_id'] === (int) $selfId));
        }

        $canManage = Authorize::can($user, 'application.manage');
        $title = 'Application Catalog';
        $navActive = 'applications';
        $breadcrumbs = ['Applications' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        $people = $canManage ? People::list([], 500, 0) : [];
        require dirname(__DIR__) . '/Views/app_applications.php';
    }

    public static function view(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $id = (int) ($_GET['id'] ?? 0);
        $app = $id > 0 ? Applications::get($id) : null;
        if ($app === null) {
            http_response_code(404);
            echo 'Application not found.';
            return;
        }
        Authorize::requirePermission(
            $user,
            Authorize::can($user, 'application.view') ? 'application.view' : 'application.view.owned',
            ['application_id' => $id]
        );

        $connectors = Applications::connectorsFor($id);
        foreach ($connectors as &$c) {
            $c['sync_history'] = $c['connector_type'] === 'csv_import' ? Applications::syncHistoryFor((int) $c['id'], 5) : [];
        }
        unset($c);
        $entitlements = \Verity\Support\Db::fetchAll(
            'SELECT * FROM entitlement WHERE application_id = :id ORDER BY name', ['id' => $id]
        );
        $canManage = Authorize::can($user, 'application.manage') || Authorize::can($user, 'connector.manage');
        $people = $canManage ? People::list([], 500, 0) : [];

        $title = $app['name'];
        $navActive = 'applications';
        $breadcrumbs = ['Applications' => '/app/applications', $app['name'] => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_application_detail.php';
    }

    public static function save(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'application.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'classification' => (string) ($_POST['classification'] ?? 'internal'),
            'system_owner_person_id' => (int) ($_POST['system_owner_person_id'] ?? 0) ?: null,
            'technical_owner_person_id' => (int) ($_POST['technical_owner_person_id'] ?? 0) ?: null,
            'business_owner_person_id' => (int) ($_POST['business_owner_person_id'] ?? 0) ?: null,
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];
        if ($data['name'] === '') {
            http_response_code(400);
            echo 'Application name is required.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            Applications::update($id, $data, $user['id']);
        } else {
            $id = Applications::create($data, $user['id']);
        }
        header('Location: /app/applications/view?id=' . $id);
    }

    public static function saveConnector(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'connector.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $data = [
            'application_id' => $applicationId,
            'connector_type' => (string) ($_POST['connector_type'] ?? 'manual'),
            'auth_method' => trim((string) ($_POST['auth_method'] ?? '')) ?: null,
            'credential_reference' => trim((string) ($_POST['credential_reference'] ?? '')) ?: null,
            'sync_frequency' => (string) ($_POST['sync_frequency'] ?? 'manual'),
            'default_reviewer_person_id' => (int) ($_POST['default_reviewer_person_id'] ?? 0) ?: null,
            'default_review_frequency' => (string) ($_POST['default_review_frequency'] ?? 'annual'),
            'remediation_mode' => (string) ($_POST['remediation_mode'] ?? 'manual'),
        ];
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            Connectors::update($id, $data, $user['id']);
        } else {
            Connectors::create($data, $user['id']);
        }
        header('Location: /app/applications/view?id=' . $applicationId);
    }

    /**
     * CSV import sync — the first real connector sync execution in this
     * app. The uploaded file is never persisted to disk or trusted by its
     * client-supplied name/MIME type; it is read once from PHP's own
     * randomized tmp_name into memory, size- and row-capped, and handed to
     * CsvImport::run() as a plain string. The connector_id in the request
     * determines the application (and therefore the redirect target) —
     * never a client-supplied application_id — so this cannot be used to
     * import into an application the caller didn't pick from this exact
     * connector's own page.
     */
    public static function syncCsv(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'connector.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }

        $connectorId = (int) ($_POST['connector_id'] ?? 0);
        $connector = $connectorId > 0 ? Connectors::get($connectorId) : null;
        if ($connector === null || $connector['connector_type'] !== 'csv_import') {
            http_response_code(400);
            echo 'Not a valid CSV import connector.';
            return;
        }
        $applicationId = (int) $connector['application_id'];

        $file = $_FILES['csv_file'] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            header('Location: /app/applications/view?id=' . $applicationId . '&csv_error=' . rawurlencode('No file was uploaded.'));
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            header('Location: /app/applications/view?id=' . $applicationId . '&csv_error=' . rawurlencode('Upload failed (error code ' . $file['error'] . ').'));
            return;
        }
        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            header('Location: /app/applications/view?id=' . $applicationId . '&csv_error=' . rawurlencode('Only .csv files are accepted.'));
            return;
        }
        $tmpName = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            http_response_code(400);
            echo 'Invalid upload.';
            return;
        }
        $actualSize = filesize($tmpName);
        if ($actualSize === false || $actualSize > CsvImport::MAX_FILE_BYTES) {
            header('Location: /app/applications/view?id=' . $applicationId . '&csv_error=' . rawurlencode('File exceeds the ' . (CsvImport::MAX_FILE_BYTES / 1024 / 1024) . 'MB limit.'));
            return;
        }
        $contents = file_get_contents($tmpName);
        if ($contents === false) {
            http_response_code(500);
            echo 'Could not read the uploaded file.';
            return;
        }

        $result = CsvImport::run($connectorId, $contents, $user['id']);
        $qs = http_build_query([
            'id' => $applicationId,
            'csv_result' => $result['status'],
            'csv_accounts' => $result['imported_accounts'],
            'csv_entitlements' => $result['imported_entitlements'],
            'csv_failures' => $result['failure_count'],
        ]);
        header('Location: /app/applications/view?' . $qs);
    }
}
