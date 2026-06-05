<?php
/**
 * AEGIS GRC — Webhook dispatcher and delivery engine.
 *
 * Webhook::dispatch() creates pending delivery records (no HTTP).
 * Webhook::send()     is called by the cron script to do the actual HTTP POST.
 */
class Webhook {

    /**
     * Record pending deliveries for every active endpoint subscribed to $eventType.
     * Does NOT make any HTTP requests; that is handled by the cron script.
     */
    public static function dispatch(string $eventType, array $payload): void
    {
        // Find all active endpoints whose event_types JSON array contains this event
        $endpoints = Database::fetchAll(
            "SELECT * FROM webhook_endpoints
              WHERE is_active = TRUE
                AND event_types @> ?::jsonb",
            [json_encode([$eventType])]
        );

        if (empty($endpoints)) {
            return;
        }

        foreach ($endpoints as $endpoint) {
            Database::insert('webhook_deliveries', [
                'endpoint_id'  => $endpoint['id'],
                'event_type'   => $eventType,
                'payload'      => json_encode($payload),
                'status'       => 'pending',
                'attempts'     => 0,
                'next_retry_at'=> date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Perform the actual HTTP delivery of one webhook_deliveries row.
     * Returns true on HTTP 2xx, false otherwise.
     * Response code and body are set by reference so the caller can persist them.
     *
     * @param array $delivery  Row from webhook_deliveries
     * @param array $endpoint  Row from webhook_endpoints
     * @param int   &$responseCode  Populated with the HTTP status code received
     * @param string &$responseBody Populated with raw response body
     */
    public static function send(
        array $delivery,
        array $endpoint,
        int &$responseCode = 0,
        string &$responseBody = ''
    ): bool {
        $provider   = $endpoint['provider'] ?? 'generic';
        $eventType  = $delivery['event_type'];
        $rawPayload = is_array($delivery['payload'])
            ? $delivery['payload']
            : (json_decode($delivery['payload'], true) ?? []);

        $formatted = self::formatPayload($provider, $eventType, $rawPayload);
        $body      = json_encode($formatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $targetUrl    = $endpoint['url'];
        $resolveEntry = null; // populated below for CURLOPT_RESOLVE to pin the IP (DNS rebinding prevention)

        // SSRF prevention: resolve hostname and reject private/reserved IP ranges
        // (PagerDuty is overridden to a fixed known URL below, so check after that override)
        if ($provider !== 'pagerduty') {
            $host     = parse_url($targetUrl, PHP_URL_HOST);
            $resolved = $host ? gethostbyname($host) : '';
            if (!$resolved || filter_var($resolved, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                error_log('[AEGIS] Webhook SSRF blocked: ' . $targetUrl);
                return false;
            }
            // Pin cURL to the already-validated IP so a second DNS lookup can't rebind to a private range
            $port         = parse_url($targetUrl, PHP_URL_SCHEME) === 'https' ? 443 : 80;
            $resolveEntry = ["{$host}:{$port}:{$resolved}"];
        }

        // For PagerDuty: routing_key may come from a custom_headers JSON or url query param
        if ($provider === 'pagerduty') {
            $customHeaders = is_array($endpoint['custom_headers'])
                ? $endpoint['custom_headers']
                : (json_decode($endpoint['custom_headers'] ?? '{}', true) ?? []);
            $routingKey = $customHeaders['routing_key'] ?? null;
            if (!$routingKey) {
                // Try extracting from URL query string
                $parsed = parse_url($targetUrl);
                parse_str($parsed['query'] ?? '', $qs);
                $routingKey = $qs['routing_key'] ?? '';
            }
            // PagerDuty Events API v2 endpoint
            $targetUrl = 'https://events.pagerduty.com/v2/enqueue';
            // Inject routing key into payload
            $formatted['routing_key'] = $routingKey;
            $body = json_encode($formatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Build HTTP headers
        $secret    = Security::decryptSetting($endpoint['secret'] ?? '');
        $signature = $secret ? self::sign($body, $secret) : '';

        $headers = [
            'Content-Type: application/json',
            'User-Agent: AEGIS-GRC/1.0',
            'X-AEGIS-Event: ' . $eventType,
        ];

        if ($signature) {
            $headers[] = 'X-AEGIS-Signature: sha256=' . $signature;
        }

        // Merge any custom headers stored on the endpoint
        $customHeaders = is_array($endpoint['custom_headers'])
            ? $endpoint['custom_headers']
            : (json_decode($endpoint['custom_headers'] ?? '{}', true) ?? []);

        foreach ($customHeaders as $hName => $hValue) {
            if (strcasecmp($hName, 'routing_key') === 0) {
                continue; // internal key, not an HTTP header
            }
            $headers[] = $hName . ': ' . $hValue;
        }

        // Execute via cURL
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL            => $targetUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($resolveEntry !== null) {
            $curlOpts[CURLOPT_RESOLVE] = $resolveEntry;
        }
        curl_setopt_array($ch, $curlOpts);

        $responseBody = (string) curl_exec($ch);
        $responseCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $responseBody = 'cURL error: ' . $curlError;
            $responseCode = 0;
            return false;
        }

        return $responseCode >= 200 && $responseCode < 300;
    }

    /**
     * Shape the payload according to the provider's expected format.
     */
    public static function formatPayload(string $provider, string $eventType, array $payload): array
    {
        switch ($provider) {

            case 'slack':
                $summary = $payload['title'] ?? $payload['name'] ?? $payload['summary'] ?? $eventType;
                $detail  = $payload['description'] ?? $payload['detail'] ?? '';
                $text    = "*Event*: `{$eventType}`\n*Summary*: {$summary}";
                if ($detail) {
                    $text .= "\n*Detail*: " . substr($detail, 0, 300);
                }
                return [
                    'text'   => "AEGIS GRC alert: {$eventType}",
                    'blocks' => [
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => $text,
                            ],
                        ],
                    ],
                ];

            case 'pagerduty':
                $summary  = $payload['title'] ?? $payload['name'] ?? $payload['summary'] ?? $eventType;
                $severity = 'warning';
                // Map AEGIS risk/severity levels to PagerDuty severity
                $rawSeverity = strtolower($payload['severity'] ?? $payload['risk_level'] ?? '');
                if (in_array($rawSeverity, ['critical', 'high'], true)) {
                    $severity = 'critical';
                } elseif ($rawSeverity === 'medium') {
                    $severity = 'warning';
                } elseif (in_array($rawSeverity, ['low', 'info'], true)) {
                    $severity = 'info';
                }
                return [
                    'routing_key'  => '', // filled in by send() from custom_headers or URL
                    'event_action' => 'trigger',
                    'payload'      => [
                        'summary'        => $summary,
                        'severity'       => $severity,
                        'source'         => 'AEGIS GRC',
                        'custom_details' => $payload,
                    ],
                ];

            case 'jira':
                $summary     = $payload['title'] ?? $payload['name'] ?? $eventType;
                $description = $payload['description'] ?? $payload['detail'] ?? '';
                return [
                    'fields' => [
                        'summary'     => $summary,
                        'description' => [
                            'type'    => 'doc',
                            'version' => 1,
                            'content' => [
                                [
                                    'type'    => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => $description ?: "AEGIS event: {$eventType}",
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'issuetype' => ['name' => 'Bug'],
                    ],
                ];

            case 'teams':
                return [
                    '@type'      => 'MessageCard',
                    '@context'   => 'http://schema.org/extensions',
                    'themeColor' => '6366f1',
                    'summary'    => $payload['text'] ?? $payload['title'] ?? 'AEGIS Alert',
                    'sections'   => [[
                        'activityTitle'    => $payload['title'] ?? 'AEGIS GRC Notification',
                        'activitySubtitle' => $payload['text']  ?? '',
                        'facts'            => array_map(fn($k,$v) => ['name'=>$k,'value'=>(string)$v], array_keys($payload['fields']??[]), array_values($payload['fields']??[])),
                    ]],
                ];

            case 'discord':
                return [
                    'content'  => null,
                    'embeds'   => [[
                        'title'       => $payload['title'] ?? 'AEGIS GRC',
                        'description' => $payload['text']  ?? '',
                        'color'       => 6579697, // #6466f1 indigo in decimal
                        'footer'      => ['text' => 'AEGIS GRC'],
                    ]],
                ];

            case 'google_chat':
                return [
                    'text' => ($payload['title'] ?? 'AEGIS GRC') . "\n" . ($payload['text'] ?? ''),
                ];

            case 'opsgenie':
                $summary  = $payload['title'] ?? $payload['name'] ?? $payload['summary'] ?? $eventType;
                $priority = 'P3';
                $rawSeverity = strtolower($payload['severity'] ?? $payload['risk_level'] ?? '');
                if (in_array($rawSeverity, ['critical'], true)) {
                    $priority = 'P1';
                } elseif ($rawSeverity === 'high') {
                    $priority = 'P2';
                } elseif ($rawSeverity === 'medium') {
                    $priority = 'P3';
                } elseif (in_array($rawSeverity, ['low', 'info'], true)) {
                    $priority = 'P5';
                }
                return [
                    'message'  => $summary,
                    'alias'    => str_replace(['.', ' '], '-', $eventType) . '-' . ($payload['id'] ?? ''),
                    'source'   => 'AEGIS GRC',
                    'priority' => $priority,
                    'details'  => array_map('strval', $payload),
                ];

            case 'generic':
            case 'servicenow':
            default:
                // Pass through as-is; caller receives the raw AEGIS payload
                return $payload;
        }
    }

    /**
     * Generate an HMAC-SHA256 hex digest for the request body.
     */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
