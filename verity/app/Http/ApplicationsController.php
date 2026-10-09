<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Applications;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Connectors;
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
}
