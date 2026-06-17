<?php
declare(strict_types=1);

/**
 * Webhook — outbound HTTP callbacks fired on platform events.
 *
 * Admins register endpoints (URL + optional shared secret + event filter) via
 * the Administration → Webhooks console. When an event occurs, call
 * Webhook::dispatch('page.published', [...]) and every active subscriber whose
 * filter matches receives a signed POST. Delivery is best-effort with a short
 * timeout so it never blocks the request; each attempt is logged.
 */
final class Webhook
{
    /** Canonical event catalogue (key => human label) shown in the admin UI. */
    public const EVENTS = [
        'page.published'        => 'Page published',
        'page.updated'          => 'Page updated',
        'document.published'    => 'Document published',
        'document.approved'     => 'Document approved',
        'document.archived'     => 'Document archived',
        'document.expired'      => 'Document expired',
        'document.superseded'   => 'Document superseded',
        'document.retired'      => 'Document retired',
        'workflow.transitioned' => 'Workflow state changed',
        'approval.requested'    => 'Approval requested',
        'approval.completed'    => 'Approval completed',
        'space.created'         => 'Space created',
        'comment.created'       => 'Comment added',
    ];

    /**
     * Fire an event to every matching active webhook. Never throws — a failing
     * endpoint is logged and the request continues.
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        try {
            $hooks = Database::fetchAll("SELECT * FROM webhooks WHERE is_active = TRUE");
        } catch (\Throwable) {
            return;
        }
        if (!$hooks) return;

        $body = json_encode([
            'event'     => $event,
            'timestamp' => date('c'),
            'data'      => $payload,
        ], JSON_UNESCAPED_SLASHES);
        if ($body === false) return;

        foreach ($hooks as $h) {
            if (!self::subscribes($h['events'] ?? '*', $event)) continue;
            self::deliver($h, $event, $body);
        }
    }

    /** Whether a comma-separated subscription string matches an event ('*' = all). */
    private static function subscribes(string $events, string $event): bool
    {
        $events = trim($events);
        if ($events === '' || $events === '*') return true;
        $list = array_map('trim', explode(',', $events));
        return in_array($event, $list, true);
    }

    /**
     * Deliver one signed POST and record the outcome. Returns the HTTP status
     * code (0 on transport failure). Used by both dispatch() and the "Test"
     * button in the admin UI.
     */
    public static function deliver(array $hook, string $event, string $body): int
    {
        $url = trim((string)($hook['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            self::record((int)$hook['id'], $event, 0, false, 'Invalid URL');
            return 0;
        }
        // SSRF guard: resolve + validate the target is a public address, and pin
        // the connection to that IP so DNS cannot be rebound to an internal host.
        $pinnedIp = Security::safeOutboundIp($url);
        if ($pinnedIp === null) {
            self::record((int)$hook['id'], $event, 0, false, 'Blocked: target resolves to a private, reserved or unresolvable address');
            return 0;
        }
        $parts = parse_url($url);
        $port  = (int)($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));

        $headers = [
            'Content-Type: application/json',
            'User-Agent: PALADIN-Webhook/1.0',
            'X-Paladin-Event: ' . $event,
        ];
        $secret = (string)($hook['secret'] ?? '');
        if ($secret !== '') {
            $headers[] = 'X-Paladin-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $status = 0; $error = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            // Restrict to HTTP(S) and pin the validated public IP (anti-rebinding).
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$parts['host'] . ':' . $port . ':' . $pinnedIp],
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $error = curl_error($ch) ?: 'Request failed';
        } else {
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        curl_close($ch);

        $success = $status >= 200 && $status < 300;
        self::record((int)$hook['id'], $event, $status, $success, $error);

        try {
            if ($success) {
                Database::query(
                    "UPDATE webhooks SET last_status = ?, last_fired_at = NOW(), failure_count = 0, updated_at = NOW() WHERE id = ?",
                    [$status, (int)$hook['id']]
                );
            } else {
                Database::query(
                    "UPDATE webhooks SET last_status = ?, last_fired_at = NOW(), failure_count = failure_count + 1, updated_at = NOW() WHERE id = ?",
                    [$status, (int)$hook['id']]
                );
            }
        } catch (\Throwable) { /* logging only */ }

        return $status;
    }

    private static function record(int $hookId, string $event, int $status, bool $success, ?string $error): void
    {
        try {
            Database::insert('webhook_deliveries', [
                'webhook_id'  => $hookId,
                'event'       => $event,
                'status_code' => $status ?: null,
                'success'     => $success ? 't' : 'f',
                'error'       => $error,
            ]);
        } catch (\Throwable) { /* best effort */ }
    }
}
