<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * OpenAPI 3.0 description of the REDOUBT REST API (/api/v1). Served as JSON at
 * GET /api/v1/openapi.json and rendered by the API docs page. Hand-built (no
 * external generator) so it works offline / air-gapped.
 */
final class OpenApi
{
    /** @return array<string,mixed> */
    public static function spec(string $host): array
    {
        $pid = ['name' => 'program_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Program to scope the request to'];
        $listResponse = static fn (string $key): array => [
            '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                'program_id' => ['type' => 'integer'], 'count' => ['type' => 'integer'], $key => ['type' => 'array', 'items' => ['type' => 'object']],
            ]]]]],
            '401' => ['description' => 'Unauthorized'],
            '403' => ['description' => 'Forbidden — scope or permission missing'],
        ];
        $module = static fn (string $path, string $summary, string $key): array => [
            'get' => ['summary' => $summary, 'parameters' => [$pid], 'responses' => $listResponse($key)],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'REDOUBT Program Portal API',
                'version' => '1.0.0',
                'description' => "Permission-aware program API. Every response is trimmed to the caller's "
                    . "access (program × company × role × zone); export-controlled documents are never "
                    . "returned to API clients. Authenticate with a bearer API token created in "
                    . "Integrations → API Clients.",
            ],
            'servers' => [['url' => 'https://' . $host . '/api/v1']],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'API token: Authorization: Bearer rk_<ref>_<secret>'],
                ],
            ],
            'paths' => [
                '/health' => ['get' => ['summary' => 'Liveness/status', 'security' => [], 'responses' => ['200' => ['description' => 'OK']]]],
                '/me' => ['get' => ['summary' => 'Caller identity, scopes/programs', 'responses' => ['200' => ['description' => 'OK'], '401' => ['description' => 'Unauthorized']]]],
                '/announcements' => $module('/announcements', 'List announcements (scope announcement.view)', 'announcements'),
                '/documents' => $module('/documents', 'List documents — export-controlled excluded for clients (scope document.view)', 'documents'),
                '/task-orders' => $module('/task-orders', 'List task orders (scope taskorder.view)', 'task-orders'),
                '/jobs' => $module('/jobs', 'List open job requisitions (scope job.view)', 'jobs'),
                '/directory' => $module('/directory', 'List program contacts (scope directory.view)', 'directory'),
                '/milestones' => $module('/milestones', 'List milestones / key dates (scope milestone.view)', 'milestones'),
                '/search' => [
                    'get' => ['summary' => 'Permission-trimmed global search', 'parameters' => [
                        $pid, ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                    ], 'responses' => $listResponse('results')],
                ],
            ],
            'x-webhooks' => [
                'events' => array_keys(Webhooks::EVENTS),
                'signature' => 'HMAC-SHA256 over the raw JSON body, sent as header "X-Redoubt-Signature: sha256=<hex>". Verify with your subscription signing secret.',
            ],
        ];
    }
}
