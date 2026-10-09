<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Applications;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\DynamicFields;
use Verity\Support\Security;

final class DynamicFieldsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'dynamicfield.manage');

        $fields = DynamicFields::all();
        $applications = Applications::list();

        $title = 'Dynamic Fields';
        $navActive = 'dynamicfields';
        $breadcrumbs = ['Dynamic Fields' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_dynamic_fields.php';
    }

    public static function save(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'dynamicfield.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }

        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'retire') {
            DynamicFields::retire((int) ($_POST['id'] ?? 0), $user['id']);
            header('Location: /app/admin/dynamic-fields');
            return;
        }

        $options = trim((string) ($_POST['options'] ?? ''));
        $data = [
            'field_key' => trim((string) ($_POST['field_key'] ?? '')),
            'label' => trim((string) ($_POST['label'] ?? '')),
            'field_type' => (string) ($_POST['field_type'] ?? 'text'),
            'entity_type' => (string) ($_POST['entity_type'] ?? 'system_account'),
            'application_id' => (int) ($_POST['application_id'] ?? 0) ?: null,
            'options' => $options !== '' ? array_map('trim', explode(',', $options)) : null,
            'required' => !empty($_POST['required']),
        ];
        if ($data['field_key'] === '' || $data['label'] === '') {
            http_response_code(400);
            echo 'Field key and label are required.';
            return;
        }

        if ($action === 'replace') {
            DynamicFields::replace((int) ($_POST['replaces_id'] ?? 0), $data, $user['id']);
        } else {
            DynamicFields::create($data, $user['id']);
        }
        header('Location: /app/admin/dynamic-fields');
    }
}
