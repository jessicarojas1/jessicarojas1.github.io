<?php
declare(strict_types=1);

class WebhookController {

    /** All supported event types selectable in the UI */
    private const EVENT_TYPES = [
        // Risks
        'risk.created'           => 'Risk Created',
        'risk.updated'           => 'Risk Updated',
        'risk.score_high'        => 'Risk Score High',
        'risk.review_due'        => 'Risk Review Due',
        'risk.treatment_overdue' => 'Risk Treatment Overdue',
        // Incidents
        'incident.created'       => 'Incident Created',
        'incident.critical'      => 'Critical Incident Created',
        'incident.closed'        => 'Incident Closed',
        'incident.sla_breach'    => 'Incident SLA Breach',
        // Audits
        'audit.completed'        => 'Audit Completed',
        'audit.finding_created'  => 'Audit Finding Created',
        'audit.overdue'          => 'Audit Overdue',
        // Compliance
        'control.failed'         => 'Control Non-Compliant',
        'compliance.score_drop'  => 'Compliance Score Drop',
        'gap_analysis.submitted' => 'Gap Analysis Submitted',
        // Changes
        'change.submitted'       => 'Change Request Submitted',
        'change.approved'        => 'Change Approved',
        'change.rejected'        => 'Change Rejected',
        'change.emergency'       => 'Emergency Change Filed',
        // Policies
        'policy.approved'        => 'Policy Approved',
        'policy.review_due'      => 'Policy Review Due',
        'policy.expired'         => 'Policy Expired',
        // Vendors
        'vendor.added'           => 'Vendor Added',
        'vendor.risk_high'       => 'Vendor High Risk',
        'vendor.contract_due'    => 'Vendor Contract Due',
        // Issues
        'issue.created'          => 'Issue Created',
        'issue.critical'         => 'Critical Issue Created',
        'issue.sla_overdue'      => 'Issue SLA Overdue',
        // Assets / BCP
        'asset.critical_added'   => 'Critical Asset Added',
        'bcp.exercise_due'       => 'BCP Exercise Due',
        'dr.test_failed'         => 'DR Test Failed',
    ];

    private const PROVIDERS = [
        'generic'          => 'Generic HTTP',
        'slack'            => 'Slack',
        'teams'            => 'Microsoft Teams',
        'jira'             => 'Jira',
        'pagerduty'        => 'PagerDuty',
        'servicenow'       => 'ServiceNow',
        'discord'          => 'Discord',
        'google_chat'      => 'Google Chat',
        'opsgenie'         => 'OpsGenie',
        'datadog'          => 'Datadog',
        'splunk'           => 'Splunk HEC',
    ];

    // ------------------------------------------------------------------ index
    public function index(): void
    {
        Auth::requirePermission('admin');

        $activeModule = 'admin_webhooks';

        $endpoints = Database::fetchAll(
            "SELECT e.*,
                    u.name AS creator_name,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id)               AS total_deliveries,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status='delivered') AS delivered_count,
                    (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.endpoint_id = e.id AND d.status='failed')    AS failed_count,
                    (SELECT MAX(d.created_at) FROM webhook_deliveries d WHERE d.endpoint_id = e.id)      AS last_delivery_at,
                    (SELECT d.status FROM webhook_deliveries d WHERE d.endpoint_id = e.id ORDER BY d.created_at DESC LIMIT 1) AS last_delivery_status
               FROM webhook_endpoints e
               LEFT JOIN users u ON e.created_by = u.id
              ORDER BY e.created_at DESC"
        );

        $eventTypes = self::EVENT_TYPES;
        $providers  = self::PROVIDERS;

        require AEGIS_ROOT . '/views/admin/webhooks.php';
    }

    // ------------------------------------------------------------ createForm
    public function createForm(): void
    {
        Auth::requirePermission('admin');

        $activeModule = 'admin_webhooks';
        $eventTypes   = self::EVENT_TYPES;
        $providers    = self::PROVIDERS;

        require AEGIS_ROOT . '/views/admin/webhook_form.php';
    }

    // --------------------------------------------------------------- create
    public function create(): void
    {
        Auth::requirePermission('admin');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $name           = Security::sanitizeInput($_POST['name'] ?? '');
        $url            = trim($_POST['url'] ?? '');
        $provider       = $_POST['provider'] ?? 'generic';
        $secret         = Security::sanitizeInput($_POST['secret'] ?? '');
        $rawHeaders     = trim($_POST['custom_headers'] ?? '');
        $selectedEvents = $_POST['event_types'] ?? [];

        $errors = [];

        if (!$name) {
            $errors[] = 'Name is required.';
        }
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $errors[] = 'A valid HTTP or HTTPS URL is required.';
        }
        if (!array_key_exists($provider, self::PROVIDERS)) {
            $provider = 'generic';
        }

        // Validate and filter event types
        $validEvents = array_filter((array) $selectedEvents, fn($e) => array_key_exists($e, self::EVENT_TYPES));

        // Validate custom headers JSON if provided
        $customHeaders = '{}';
        if ($rawHeaders !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (!is_array($decoded)) {
                $errors[] = 'Custom headers must be valid JSON object.';
            } else {
                $customHeaders = json_encode($decoded);
            }
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            header('Location: /admin/webhooks/new');
            return;
        }

        $newId = Database::insert('webhook_endpoints', [
            'name'           => $name,
            'url'            => $url,
            'secret'         => $secret,
            'event_types'    => json_encode(array_values($validEvents)),
            'provider'       => $provider,
            'custom_headers' => $customHeaders,
            'is_active'      => true,
            'created_by'     => Auth::id(),
        ]);

        Auth::log('webhook_created', 'webhook_endpoints', $newId, ['name' => $name, 'provider' => $provider]);

        header('Location: /admin/webhooks?created=1');
    }

    // ------------------------------------------------------------ editForm
    public function editForm(string $id): void
    {
        Auth::requirePermission('admin');

        $endpoint = Database::fetchOne(
            "SELECT * FROM webhook_endpoints WHERE id = ?",
            [(int) $id]
        );

        if (!$endpoint) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $activeModule = 'admin_webhooks';
        $eventTypes   = self::EVENT_TYPES;
        $providers    = self::PROVIDERS;

        // Decode stored JSON for the form
        $endpoint['event_types_arr']    = json_decode($endpoint['event_types'] ?? '[]', true) ?? [];
        $endpoint['custom_headers_str'] = $endpoint['custom_headers'] !== '{}'
            ? json_encode(json_decode($endpoint['custom_headers'], true), JSON_PRETTY_PRINT)
            : '';

        require AEGIS_ROOT . '/views/admin/webhook_form.php';
    }

    // --------------------------------------------------------------- update
    public function update(string $id): void
    {
        Auth::requirePermission('admin');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $endpoint = Database::fetchOne(
            "SELECT * FROM webhook_endpoints WHERE id = ?",
            [(int) $id]
        );

        if (!$endpoint) {
            http_response_code(404);
            return;
        }

        $name           = Security::sanitizeInput($_POST['name'] ?? '');
        $url            = trim($_POST['url'] ?? '');
        $provider       = $_POST['provider'] ?? 'generic';
        $secret         = Security::sanitizeInput($_POST['secret'] ?? '');
        $rawHeaders     = trim($_POST['custom_headers'] ?? '');
        $selectedEvents = $_POST['event_types'] ?? [];

        $errors = [];

        if (!$name) {
            $errors[] = 'Name is required.';
        }
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $errors[] = 'A valid HTTP or HTTPS URL is required.';
        }
        if (!array_key_exists($provider, self::PROVIDERS)) {
            $provider = 'generic';
        }

        $validEvents = array_filter((array) $selectedEvents, fn($e) => array_key_exists($e, self::EVENT_TYPES));

        $customHeaders = '{}';
        if ($rawHeaders !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (!is_array($decoded)) {
                $errors[] = 'Custom headers must be valid JSON object.';
            } else {
                $customHeaders = json_encode($decoded);
            }
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            header('Location: /admin/webhooks/' . (int) $id . '/edit');
            return;
        }

        Database::query(
            "UPDATE webhook_endpoints
                SET name           = ?,
                    url            = ?,
                    secret         = ?,
                    event_types    = ?::jsonb,
                    provider       = ?,
                    custom_headers = ?::jsonb
              WHERE id = ?",
            [
                $name,
                $url,
                $secret,
                json_encode(array_values($validEvents)),
                $provider,
                $customHeaders,
                (int) $id,
            ]
        );

        Auth::log('webhook_updated', 'webhook_endpoints', (int) $id, ['name' => $name]);

        header('Location: /admin/webhooks?updated=1');
    }

    // ---------------------------------------------------------- toggleActive
    public function toggleActive(string $id): void
    {
        Auth::requirePermission('admin');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $endpoint = Database::fetchOne(
            "SELECT id, is_active, name FROM webhook_endpoints WHERE id = ?",
            [(int) $id]
        );

        if (!$endpoint) {
            http_response_code(404);
            return;
        }

        $newState = $endpoint['is_active'] ? false : true;

        Database::query(
            "UPDATE webhook_endpoints SET is_active = ? WHERE id = ?",
            [$newState, (int) $id]
        );

        Auth::log('webhook_toggled', 'webhook_endpoints', (int) $id, ['is_active' => $newState]);

        header('Location: /admin/webhooks');
    }

    // ------------------------------------------------------------ deliveries
    public function deliveries(string $id): void
    {
        Auth::requirePermission('admin');

        $endpoint = Database::fetchOne(
            "SELECT * FROM webhook_endpoints WHERE id = ?",
            [(int) $id]
        );

        if (!$endpoint) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $deliveries = Database::fetchAll(
            "SELECT * FROM webhook_deliveries
              WHERE endpoint_id = ?
              ORDER BY created_at DESC
              LIMIT 50",
            [(int) $id]
        );

        $activeModule = 'admin_webhooks';

        require AEGIS_ROOT . '/views/admin/webhook_deliveries.php';
    }

    // --------------------------------------------------------------- delete
    public function delete(string $id): void
    {
        Auth::requirePermission('admin');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $endpoint = Database::fetchOne(
            "SELECT id, name FROM webhook_endpoints WHERE id = ?",
            [(int) $id]
        );

        if (!$endpoint) {
            http_response_code(404);
            return;
        }

        // Deliveries are CASCADE-deleted by FK
        Database::query(
            "DELETE FROM webhook_endpoints WHERE id = ?",
            [(int) $id]
        );

        Auth::log('webhook_deleted', 'webhook_endpoints', (int) $id, ['name' => $endpoint['name']]);

        header('Location: /admin/webhooks?deleted=1');
    }
}
